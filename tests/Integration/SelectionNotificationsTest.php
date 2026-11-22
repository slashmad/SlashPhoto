<?php
/**
 * Client confirmation email and selection counts in the gallery list.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Admin\GalleryList;
use SlashPhoto\Proofing\Notifications;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Selections;
use SlashPhoto\Settings;
use WP_Post;

final class SelectionNotificationsTest extends AccessTestCase {

	private int $photographer;

	private int $client;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		// The test site is "localhost", which PHPMailer rejects as a sender domain.
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@example.org' );
		$this->photographer = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'photographer@example.org',
			]
		);
		$this->client       = self::factory()->user->create(
			[
				'role'       => 'slashphoto_client',
				'user_email' => 'kim@example.org',
			]
		);
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		unset( $GLOBALS['wp_query'] );
		parent::tear_down();
	}

	private function root( array $meta = [] ): WP_Post {
		return get_post(
			$this->gallery(
				[
					'post_author' => $this->photographer,
					'post_title'  => 'Summer wedding',
				],
				array_merge( [ Proofing::META => 'on' ], $meta )
			)
		);
	}

	private function selection( WP_Post $root, int $user_id, array $items, bool $sent ): WP_Post {
		$selection = Selections::find_or_create( $root, $user_id );
		Selections::save_items( $selection, $items );
		if ( $sent ) {
			Selections::submit( $selection, 'Love them' );
		}
		return get_post( $selection->ID );
	}

	private function mail( int $index ) {
		return tests_retrieve_phpmailer_instance()->get_sent( $index );
	}

	public function test_client_gets_a_copy_of_the_sent_selection(): void {
		$root  = $this->root();
		$first = $this->image( $root->ID );
		$other = $this->image( $root->ID );
		wp_set_current_user( $this->client );

		foreach ( [ [ $other, 'Crop tighter' ], [ $first, '' ] ] as [ $id, $note ] ) {
			$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/selections/' . $root->ID . '/items' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					[
						'id'       => $id,
						'selected' => true,
						'note'     => $note,
					]
				)
			);
			$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		}
		$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/selections/' . $root->ID . '/submit' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'message' => 'Love them' ] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		$this->assertSame( 'photographer@example.org', $this->mail( 0 )->to[0][0] );
		$copy = $this->mail( 1 );
		$this->assertSame( 'kim@example.org', $copy->to[0][0] );
		$this->assertStringContainsString( 'Summer wedding', $copy->subject );
		$this->assertStringContainsString( '2 images', $copy->body );
		$this->assertStringContainsString( 'Love them', $copy->body );
		$this->assertStringContainsString( get_permalink( $root ), $copy->body );
		$this->assertStringContainsString( 'Reply-To: photographer@example.org', $copy->header );

		// The client's order and notes are kept.
		$this->assertMatchesRegularExpression(
			'/^- ' . preg_quote( Proofing::file_label( $other ), '/' ) . ': Crop tighter\R- ' . preg_quote( Proofing::file_label( $first ), '/' ) . '\R/m',
			$copy->body
		);
	}

	public function test_copy_follows_the_setting_and_the_filter(): void {
		$root      = $this->root();
		$selection = $this->selection( $root, $this->client, [ $this->image( $root->ID ) => '' ], true );
		$notify    = new Notifications( new Access() );

		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'selection_client_email' => false ] ) );
		$notify->client_confirmation( $selection, $root );
		$this->assertFalse( $this->mail( 0 ) );

		$filter = static fn(): bool => true;
		add_filter( 'slashphoto_selection_client_email', $filter );
		$notify->client_confirmation( $selection, $root );
		remove_filter( 'slashphoto_selection_client_email', $filter );
		$this->assertSame( 'kim@example.org', $this->mail( 0 )->to[0][0] );

		$this->assertFalse( Settings::sanitize( [ '_section' => 'general_proofing' ] )['selection_client_email'] );
		$this->assertTrue( Settings::sanitize( [] )['selection_client_email'] );
	}

	public function test_secret_link_galleries_are_not_linked(): void {
		$root      = $this->root( [ Access::META_MODE => Access::MODE_LINK ] );
		$key       = Access::link_key( $root, true );
		$selection = $this->selection( $root, $this->client, [ $this->image( $root->ID ) => '' ], true );

		( new Notifications( new Access() ) )->client_confirmation( $selection, $root );

		$copy = $this->mail( 0 );
		$this->assertSame( 'kim@example.org', $copy->to[0][0] );
		$this->assertStringNotContainsString( get_permalink( $root ), $copy->body );
		$this->assertStringNotContainsString( $key, $copy->body . $copy->header );
	}

	public function test_counts_sent_and_unsent_selections_per_gallery(): void {
		$root    = $this->root();
		$other   = $this->root();
		$empty   = $this->root();
		$second  = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$third   = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$image   = $this->image( $root->ID );
		$another = $this->image( $other->ID );
		$this->selection( $root, $this->client, [ $image => '' ], true );
		$this->selection( $root, $second, [ $image => '' ], false );
		$this->selection( $root, $third, [ $image => '' ], true );
		$this->selection( $other, $this->client, [ $another => '' ], false );

		$this->assertSame(
			[
				$root->ID  => [
					'submitted' => 2,
					'choosing'  => 1,
				],
				$other->ID => [
					'submitted' => 0,
					'choosing'  => 1,
				],
			],
			Selections::counts( [ $root->ID, $other->ID, $empty->ID ] )
		);
		$this->assertSame( [], Selections::counts( [] ) );
	}

	public function test_gallery_list_column_links_counts_for_editors_only(): void {
		$root = $this->root();
		$this->selection( $root, $this->client, [ $this->image( $root->ID ) => '' ], true );
		$list = new GalleryList( new Access() );

		$columns = $list->columns(
			[
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			]
		);
		$this->assertSame( [ 'cb', 'title', 'slashphoto_visibility', 'slashphoto_selections', 'date' ], array_keys( $columns ) );

		$cell = function () use ( $root ): string {
			ob_start();
			( new GalleryList( new Access() ) )->column( 'slashphoto_selections', $root->ID );
			return (string) ob_get_clean();
		};

		wp_set_current_user( $this->photographer );
		$html = $cell();
		$this->assertStringContainsString( '1 sent', $html );
		$this->assertStringContainsString( 'gallery_id=' . $root->ID, $html );
		$this->assertStringContainsString( 'tab=submitted', $html );
		$this->assertStringNotContainsString( 'choosing', $html );

		// Someone who may not edit the gallery never sees its clients' activity.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertStringNotContainsString( 'sent', $cell() );
	}
}
