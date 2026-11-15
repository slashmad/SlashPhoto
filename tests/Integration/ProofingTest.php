<?php
/**
 * Proofing: client selections.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Plugin;
use SlashPhoto\Privacy;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Selections;

final class ProofingTest extends AccessTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		wp_dequeue_script_module( '@slashphoto/proofing' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function proofing_gallery( array $args = [], array $meta = [] ): int {
		return $this->gallery( $args, array_merge( [ Proofing::META => 'on' ], $meta ) );
	}

	private function proofing(): Proofing {
		return Plugin::instance()->proofing();
	}

	private function call( string $method, string $route, array $body = [] ) {
		$request = new \WP_REST_Request( $method, '/slashphoto/v1/selections/' . $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function pick( int $gallery, int $image, bool $selected = true, ?string $note = null ) {
		$body = [
			'id'       => $image,
			'selected' => $selected,
		];
		if ( null !== $note ) {
			$body['note'] = $note;
		}
		return $this->call( 'POST', $gallery . '/items', $body );
	}

	private function ids( $response ): array {
		return wp_list_pluck( $response->get_data()['items'], 'id' );
	}

	public function test_root_is_the_nearest_gallery_with_proofing_on(): void {
		$top   = $this->proofing_gallery();
		$child = $this->gallery( [ 'post_parent' => $top ] );
		$off   = $this->gallery( [ 'post_parent' => $top ], [ Proofing::META => 'off' ] );
		$own   = $this->gallery( [ 'post_parent' => $top ], [ Proofing::META => 'on' ] );
		$plain = $this->gallery();

		$this->assertSame( $top, $this->proofing()->root( $child )->ID );
		$this->assertNull( $this->proofing()->root( $off ) );
		$this->assertSame( $own, $this->proofing()->root( $own )->ID );
		$this->assertNull( $this->proofing()->root( $plain ) );
	}

	public function test_file_label_strips_random_suffix_and_scaled_marker(): void {
		$gallery = $this->gallery();
		$image   = $this->image( $gallery );
		$meta    = wp_get_attachment_metadata( $image );
		unset( $meta['original_image'] );
		wp_update_attachment_metadata( $image, $meta );
		update_attached_file( $image, '/tmp/x/DSC_1234-0123456789ab.jpg' );
		$this->assertSame( 'DSC_1234', Proofing::file_label( $image ) );

		update_attached_file( $image, '/tmp/x/DSC_1234-0123456789ab-scaled.jpg' );
		$this->assertSame( 'DSC_1234', Proofing::file_label( $image ) );
	}

	public function test_anonymous_visitors_cannot_store_selections(): void {
		$gallery = $this->proofing_gallery();
		$image   = $this->image( $gallery );
		$this->as_anonymous();

		$this->assertSame( 401, $this->call( 'GET', (string) $gallery )->get_status() );
		$this->assertSame( 401, $this->pick( $gallery, $image )->get_status() );
		$this->assertSame( [], Selections::for_root( $gallery ) );
	}

	public function test_client_selects_notes_and_submits(): void {
		$gallery = $this->proofing_gallery();
		$a       = $this->image( $gallery );
		$b       = $this->image( $gallery );
		$client  = $this->as_user();

		$this->assertSame( [], $this->call( 'GET', (string) $gallery )->get_data()['items'] );

		$this->assertSame( [ $a ], $this->ids( $this->pick( $gallery, $a ) ) );
		$this->assertSame( [ $a, $b ], $this->ids( $this->pick( $gallery, $b, true, " Crop <b>tighter</b>\n" ) ) );
		$this->assertSame( [ $b ], $this->ids( $this->pick( $gallery, $a, false ) ) );

		$state = $this->call( 'GET', (string) $gallery )->get_data();
		$this->assertSame( 'Crop tighter', $state['items'][0]['note'] );
		$this->assertNull( $state['submitted'] );

		$sent = $this->call( 'POST', $gallery . '/submit', [ 'message' => 'Thanks!' ] );
		$this->assertSame( 200, $sent->get_status() );
		$this->assertNotNull( $sent->get_data()['submitted'] );

		$selection = Selections::find( $gallery, $client );
		$this->assertSame( (string) $client, $selection->post_author );
		$this->assertSame( 'Thanks!', Selections::message( $selection ) );

		// Locked until the photographer reopens it.
		$this->assertSame( 409, $this->pick( $gallery, $a )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $gallery . '/submit' )->get_status() );
		Selections::reopen( $selection );
		$this->assertSame( 200, $this->pick( $gallery, $a )->get_status() );
	}

	public function test_submitting_an_empty_selection_is_refused(): void {
		$gallery = $this->proofing_gallery();
		$this->image( $gallery );
		$this->as_user();
		$this->assertSame( 400, $this->call( 'POST', $gallery . '/submit' )->get_status() );
	}

	public function test_sub_gallery_images_go_into_the_root_selection(): void {
		$gallery = $this->proofing_gallery();
		$child   = $this->gallery( [ 'post_parent' => $gallery ] );
		$image   = $this->image( $child );
		$this->as_user();

		$this->assertSame( [ $image ], $this->ids( $this->pick( $gallery, $image ) ) );
		// The sub-gallery is not a root of its own.
		$this->assertSame( 404, $this->pick( $child, $image )->get_status() );
	}

	public function test_galleries_without_proofing_or_access_look_missing(): void {
		$plain   = $this->gallery();
		$image   = $this->image( $plain );
		$owner   = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$private = $this->proofing_gallery(
			[],
			[
				Access::META_MODE    => Access::MODE_CLIENTS,
				Access::META_CLIENTS => [ $owner ],
			]
		);
		$theirs  = $this->image( $private );
		$this->as_user();

		$this->assertSame( 404, $this->call( 'GET', (string) $plain )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', (string) $private )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '999999' )->get_status() );
		$this->assertSame( 'slashphoto_not_found', $this->pick( $private, $theirs )->get_data()['code'] );

		// An image from another gallery cannot be added to a gallery the user can see.
		$open = $this->proofing_gallery();
		$this->assertSame( 404, $this->pick( $open, $image )->get_status() );
		$this->assertSame( 404, $this->pick( $open, $theirs )->get_status() );
	}

	public function test_images_of_locked_sub_galleries_are_refused(): void {
		$gallery = $this->proofing_gallery();
		$locked  = $this->gallery(
			[
				'post_parent'   => $gallery,
				'post_password' => 'secret',
			]
		);
		$image   = $this->image( $locked );
		$this->as_user();

		$this->assertSame( 404, $this->pick( $gallery, $image )->get_status() );
		$this->enter_password( 'secret' );
		$this->assertSame( 200, $this->pick( $gallery, $image )->get_status() );
	}

	public function test_password_and_terms_apply_to_the_root(): void {
		$gallery = $this->proofing_gallery( [ 'post_password' => 'pw' ] );
		$image   = $this->image( $gallery );
		$this->as_user();
		$this->assertSame( 404, $this->pick( $gallery, $image )->get_status() );
		$this->enter_password( 'pw' );
		$this->assertSame( 200, $this->pick( $gallery, $image )->get_status() );

		$terms = $this->proofing_gallery( [], [ \SlashPhoto\Access\Terms::META_TERMS => 'Be nice.' ] );
		$other = $this->image( $terms );
		$this->assertSame( 404, $this->pick( $terms, $other )->get_status() );
		$this->accept_terms( $terms );
		$this->assertSame( 200, $this->pick( $terms, $other )->get_status() );
	}

	public function test_maximum_is_enforced_and_merge_respects_it(): void {
		$gallery = $this->proofing_gallery( [], [ Proofing::META_MAX => 2 ] );
		$images  = [ $this->image( $gallery ), $this->image( $gallery ), $this->image( $gallery ) ];
		$other   = $this->image( $this->gallery() );
		$this->as_user();

		$merged = $this->call( 'POST', $gallery . '/merge', [ 'ids' => [ $other, $images[0], $images[1], $images[2] ] ] );
		$this->assertSame( [ $images[0], $images[1] ], $this->ids( $merged ) );
		$this->assertSame( 2, $merged->get_data()['max'] );

		$full = $this->pick( $gallery, $images[2] );
		$this->assertSame( 409, $full->get_status() );
		$this->assertSame( 'slashphoto_selection_full', $full->get_data()['code'] );
		// Notes can still be changed on chosen images.
		$this->assertSame( 200, $this->pick( $gallery, $images[0], true, 'B&W' )->get_status() );
	}

	public function test_submit_emails_the_photographer_only_what_is_needed(): void {
		$photographer = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'photographer@example.org',
			]
		);
		$gallery      = $this->proofing_gallery(
			[
				'post_author' => $photographer,
				'post_title'  => 'Summer wedding',
			]
		);
		$image        = $this->image( $gallery );
		$client       = self::factory()->user->create(
			[
				'role'         => 'slashphoto_client',
				'display_name' => 'Kim Client',
				'user_email'   => 'kim@example.org',
			]
		);
		wp_set_current_user( $client );
		$this->pick( $gallery, $image );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		// The test site is "localhost", which PHPMailer rejects as a sender domain.
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@example.org' );
		$this->call( 'POST', $gallery . '/submit', [ 'message' => 'Love them' ] );

		$mailer = tests_retrieve_phpmailer_instance();
		$mail   = $mailer->get_sent();
		$this->assertSame( 'photographer@example.org', $mail->to[0][0] );
		$this->assertStringContainsString( 'Summer wedding', $mail->subject );
		$this->assertStringContainsString( 'Kim Client', $mail->body );
		$this->assertStringContainsString( 'Love them', $mail->body );
		$this->assertStringNotContainsString( '203.0.113.9', $mail->body . $mail->header );
		$this->assertStringContainsString( 'Reply-To: kim@example.org', $mail->header );
	}

	public function test_selections_are_deleted_with_the_gallery_and_the_user(): void {
		$gallery = $this->proofing_gallery();
		$image   = $this->image( $gallery );
		$client  = $this->as_user();
		$this->pick( $gallery, $image );
		$this->assertNotNull( Selections::find( $gallery, $client ) );

		wp_delete_post( $gallery, true );
		$this->assertNull( Selections::find( $gallery, $client ) );

		$second = $this->proofing_gallery();
		$this->pick( $second, $this->image( $second ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $client );
		$this->assertSame( [], Selections::for_user( $client ) );
	}

	public function test_privacy_export_and_erase(): void {
		$gallery = $this->proofing_gallery( [ 'post_title' => 'Portraits' ] );
		$image   = $this->image( $gallery );
		$client  = $this->as_user();
		$this->pick( $gallery, $image, true, 'Retouch' );
		$email = get_userdata( $client )->user_email;

		$export = Privacy::export( $email );
		$this->assertCount( 1, $export['data'] );
		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );
		$this->assertContains( 'Portraits', $values );
		$this->assertStringContainsString( 'Retouch', implode( ' ', $values ) );

		$erased = Privacy::erase( $email );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertSame( [], Selections::for_user( $client ) );
	}

	public function test_selections_are_not_exposed(): void {
		$gallery = $this->proofing_gallery();
		$this->as_user();
		$this->pick( $gallery, $this->image( $gallery ) );

		$this->assertArrayNotHasKey( '/wp/v2/' . Selections::POST_TYPE, rest_get_server()->get_routes() );
		$this->assertFalse( is_post_type_viewable( Selections::POST_TYPE ) );
		$query = new \WP_Query(
			[
				'post_type' => 'any',
				's'         => get_the_title( $gallery ),
			]
		);
		$this->assertNotContains( Selections::POST_TYPE, wp_list_pluck( $query->posts, 'post_type' ) );
	}

	public function test_gallery_markup_marks_proofing_and_loads_the_module(): void {
		$gallery = $this->proofing_gallery();
		$child   = $this->gallery( [ 'post_parent' => $gallery ] );
		$this->image( $child );
		$this->as_anonymous();

		$html = Plugin::instance()->renderer()->render( get_post( $child ) );
		$this->assertStringContainsString( 'data-slashphoto-proofing="' . $gallery . '"', $html );
		ob_start();
		wp_script_modules()->print_enqueued_script_modules();
		$this->assertStringContainsString( 'assets/js/proofing.js', (string) ob_get_clean() );
	}

	public function test_no_proofing_markup_when_off(): void {
		$gallery = $this->gallery();
		$this->image( $gallery );
		$html = Plugin::instance()->renderer()->render( get_post( $gallery ) );
		$this->assertStringNotContainsString( 'data-slashphoto-proofing', $html );
	}

	public function test_photographer_can_reopen_through_admin_action(): void {
		$gallery = $this->proofing_gallery();
		$image   = $this->image( $gallery );
		$client  = $this->as_user();
		$this->pick( $gallery, $image );
		$this->call( 'POST', $gallery . '/submit' );
		$selection = Selections::find( $gallery, $client );

		// Clients cannot reopen their own selection.
		$box                  = new \SlashPhoto\Admin\SelectionsBox( $this->proofing() );
		$_GET                 = [
			'selection' => $selection->ID,
			'operation' => 'reopen',
		];
		$_REQUEST['_wpnonce'] = wp_create_nonce( \SlashPhoto\Admin\SelectionsBox::ACTION . '_reopen_' . $selection->ID );
		try {
			$box->handle_action();
			$this->fail( 'Expected wp_die.' );
		} catch ( \WPDieException $e ) {
			$this->assertNotSame( 0, Selections::submitted_at( $selection ) );
		}
		$_GET     = [];
		$_REQUEST = [];
	}
}
