<?php
/**
 * One-time sign-in links for gallery clients.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\SignInLinks;
use SlashPhoto\Admin\GalleryInvitations;
use SlashPhoto\Plugin;
use SlashPhoto\Settings;

final class SignInLinksTest extends AccessTestCase {

	private array $mail = [];

	private $capture;

	public function set_up(): void {
		parent::set_up();
		$this->mail    = [];
		$this->capture = function ( $result, array $message ) {
			$this->mail[] = $message;
			return true;
		};
		add_filter( 'pre_wp_mail', $this->capture, 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', $this->capture, 10 );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	private function links(): SignInLinks {
		return new SignInLinks( Plugin::instance()->access(), Plugin::instance()->templates() );
	}

	private function client( string $email = 'kim@example.org' ): \WP_User {
		return get_userdata(
			self::factory()->user->create(
				[
					'role'       => 'slashphoto_client',
					'user_email' => $email,
				]
			)
		);
	}

	private static function value( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return (string) $query[ SignInLinks::QUERY ];
	}

	public function test_only_accounts_that_cannot_edit_content_get_links(): void {
		$this->assertTrue( SignInLinks::eligible( $this->client() ) );
		$this->assertTrue( SignInLinks::eligible( get_userdata( self::factory()->user->create( [ 'role' => 'subscriber' ] ) ) ) );
		foreach ( [ 'contributor', 'author', 'editor', 'administrator' ] as $role ) {
			$user = get_userdata( self::factory()->user->create( [ 'role' => $role ] ) );
			$this->assertFalse( SignInLinks::eligible( $user ), $role );
			$this->assertSame( '', SignInLinks::create( $user, null, HOUR_IN_SECONDS ), $role );
		}
	}

	public function test_link_works_once_and_only_its_hash_is_stored(): void {
		$client  = $this->client();
		$gallery = get_post( $this->clients_gallery( [ $client->ID ] ) );
		$url     = SignInLinks::create( $client, $gallery, HOUR_IN_SECONDS );
		$value   = self::value( $url );
		$token   = substr( $value, strpos( $value, '-' ) + 1 );

		$this->assertStringStartsWith( home_url( '/' ), $url );
		$this->assertStringNotContainsString( $token, (string) wp_json_encode( get_user_meta( $client->ID ) ) );

		// Opening the link (GET) does not use it up.
		$this->assertSame( $gallery->ID, SignInLinks::verify( $value, false )['gallery'] );
		$this->assertSame( $client->ID, SignInLinks::verify( $value, true )['user']->ID );
		$this->assertNull( SignInLinks::verify( $value, true ), 'Used once.' );
		$this->assertSame( '', (string) get_user_meta( $client->ID, SignInLinks::META, true ) );

		// Tampered values.
		$other = SignInLinks::create( $client, null, HOUR_IN_SECONDS );
		$value = self::value( $other );
		$this->assertNull( SignInLinks::verify( substr( $value, 0, -1 ) . ( str_ends_with( $value, '0' ) ? '1' : '0' ), false ) );
		$this->assertNull( SignInLinks::verify( ( $client->ID + 1 ) . substr( $value, strpos( $value, '-' ) ), false ) );
		$this->assertNull( SignInLinks::verify( 'abc', false ) );

		// A client who becomes an editor can no longer use earlier links.
		$client->set_role( 'editor' );
		$this->assertNull( SignInLinks::verify( $value, false ) );
	}

	public function test_links_expire_and_only_the_newest_few_are_kept(): void {
		$client  = $this->client();
		$expired = self::value( SignInLinks::create( $client, null, HOUR_IN_SECONDS ) );
		$tokens  = get_user_meta( $client->ID, SignInLinks::META, true );
		foreach ( $tokens as $hash => $token ) {
			$tokens[ $hash ]['expires'] = time() - 1;
		}
		update_user_meta( $client->ID, SignInLinks::META, $tokens );
		$this->assertNull( SignInLinks::verify( $expired, false ) );

		$values = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$values[] = self::value( SignInLinks::create( $client, null, HOUR_IN_SECONDS ) );
		}
		$this->assertNull( SignInLinks::verify( $values[0], false ), 'Oldest dropped.' );
		$this->assertNotNull( SignInLinks::verify( $values[5], false ) );
		$this->assertCount( 5, get_user_meta( $client->ID, SignInLinks::META, true ) );
	}

	public function test_requested_links_go_only_to_allowed_clients_once_a_minute(): void {
		$client = $this->client();
		$this->client( 'other@example.org' );
		$editor  = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_email' => 'editor@example.org',
			]
		);
		$gallery = get_post( $this->clients_gallery( [ $client->ID, $editor ], [ 'post_title' => 'Wedding' ] ) );
		$links   = $this->links();

		$this->assertFalse( $links->send_requested( 'nobody@example.org', $gallery ) );
		$this->assertFalse( $links->send_requested( 'other@example.org', $gallery ) );
		$this->assertFalse( $links->send_requested( 'editor@example.org', $gallery ) );
		$this->assertFalse( $links->send_requested( 'not an address', $gallery ) );
		$this->assertSame( [], $this->mail );

		$this->assertTrue( $links->send_requested( 'kim@example.org', $gallery ) );
		$this->assertSame( 'kim@example.org', $this->mail[0]['to'] );
		$this->assertStringContainsString( 'Wedding', $this->mail[0]['message'] );
		preg_match( '#https?://\S+#', $this->mail[0]['message'], $match );
		$this->assertSame( $gallery->ID, SignInLinks::verify( self::value( $match[0] ), false )['gallery'] );

		$this->assertFalse( $links->send_requested( 'kim@example.org', $gallery ), 'Throttled.' );
		$this->assertCount( 1, $this->mail );

		update_post_meta( $gallery->ID, Access::META_EXPIRES, '2020-01-01' );
		delete_transient( 'slashphoto_signin_' . $client->ID );
		$this->assertFalse( $links->send_requested( 'kim@example.org', $gallery ), 'Ended galleries send nothing.' );
	}

	public function test_notice_offers_the_form_only_when_enabled_and_logged_out(): void {
		$gallery = get_post( $this->clients_gallery( [ $this->client()->ID ] ) );
		$render  = static fn() => Plugin::instance()->templates()->render(
			'access-notice.php',
			[
				'gallery' => $gallery,
				'type'    => 'login',
				'signin'  => SignInLinks::form(),
			]
		);

		wp_set_current_user( 0 );
		$html = $render();
		$this->assertStringContainsString( 'name="action" value="' . SignInLinks::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="gallery" value="' . $gallery->ID . '"', $html );

		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'signin_links' => false ] ) );
		$this->assertNull( SignInLinks::form() );
		$this->assertStringNotContainsString( SignInLinks::ACTION, $render() );
		$this->assertFalse( Settings::sanitize( [ '_section' => 'general_visitors' ] )['signin_links'] );
	}

	public function test_invitations_to_client_galleries_include_a_link(): void {
		$client = $this->client();
		$this->as_user( 'administrator' );
		$gallery = get_post( $this->clients_gallery( [ $client->ID ] ) );
		$invites = new GalleryInvitations( Plugin::instance()->access() );

		$this->assertSame( 'sent', $invites->send( $gallery, 'kim@example.org' ) );
		preg_match_all( '#https?://\S+#', $this->mail[0]['message'], $urls );
		$signin = array_values( array_filter( $urls[0], static fn( string $url ): bool => str_contains( $url, SignInLinks::QUERY ) ) );
		$this->assertCount( 1, $signin );
		$this->assertSame( $gallery->ID, SignInLinks::verify( self::value( $signin[0] ), false )['gallery'] );

		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'signin_links' => false ] ) );
		$this->assertSame( 'sent', $invites->send( $gallery, 'kim@example.org' ) );
		$this->assertStringNotContainsString( SignInLinks::QUERY, $this->mail[1]['message'] );
		$this->assertStringContainsString( 'Sign in with the WordPress account', $this->mail[1]['message'] );
	}
}
