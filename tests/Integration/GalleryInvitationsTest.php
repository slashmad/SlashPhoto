<?php
/**
 * Gallery invitations and inherited access.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Admin\GalleryInvitations;
use SlashPhoto\Plugin;

final class GalleryInvitationsTest extends AccessTestCase {

	public function test_invites_only_an_allowed_account_using_the_inherited_secret_link(): void {
		$allowed = $this->as_user();
		$email   = get_userdata( $allowed )->user_email;
		$this->as_user( 'administrator' );
		$parent  = $this->gallery(
			[ 'post_password' => 'password-keep-out-of-email' ],
			[ Access::META_MODE => Access::MODE_LINK ]
		);
		$child   = $this->clients_gallery( [ $allowed ], [ 'post_parent' => $parent ] );
		$key     = Access::rotate_link_key( get_post( $parent ) );
		$gallery = get_post( $child );
		$invites = new GalleryInvitations( Plugin::instance()->access() );
		$mail    = [];
		$capture = static function ( $result, array $message ) use ( &$mail ) {
			$mail[] = $message;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		try {
			$this->assertSame( 'account', $invites->send( $gallery, 'unassigned@example.org' ) );
			$this->assertSame( 'email', $invites->send( $gallery, "bad@example.org\nsecond@example.org" ) );
			$this->assertSame( [], $mail );
			$this->assertSame( 'sent', $invites->send( $gallery, $email, '<b>Pick your favourites</b>' ) );
			$this->assertSame( $email, $mail[0]['to'] );
			$this->assertStringContainsString( add_query_arg( 'key', $key, get_permalink( $child ) ), $mail[0]['message'] );
			$this->assertStringContainsString( 'Sign in', $mail[0]['message'] );
			$this->assertStringContainsString( 'Use the gallery password', $mail[0]['message'] );
			$this->assertStringContainsString( 'Pick your favourites', $mail[0]['message'] );
			$this->assertStringNotContainsString( 'password-keep-out-of-email', $mail[0]['message'] );
			$this->assertSame( $key, Access::link_key( get_post( $parent ) ) );
			$this->assertSame( [ $allowed ], Access::clients( $gallery ) );

			update_post_meta( $parent, Access::META_EXPIRES, '2020-01-01' );
			$this->assertSame( 'closed', $invites->send( $gallery, $email ) );
			$this->assertCount( 1, $mail );
		} finally {
			remove_filter( 'pre_wp_mail', $capture, 10 );
		}
	}

	public function test_child_author_cannot_share_a_parent_secret_link(): void {
		$admin  = $this->as_user( 'administrator' );
		$parent = $this->gallery( [ 'post_author' => $admin ], [ Access::META_MODE => Access::MODE_LINK ] );
		Access::rotate_link_key( get_post( $parent ) );
		$author = $this->as_user( 'author' );
		$user   = wp_get_current_user();
		$user->add_cap( 'edit_pages' );
		$user->add_cap( 'edit_published_pages' );
		$child  = $this->gallery(
			[
				'post_parent' => $parent,
				'post_author' => $author,
			]
		);
		$access = Plugin::instance()->access();
		$this->assertTrue( $access->is_editor( get_post( $child ) ) );
		$this->assertFalse( $access->is_editor( get_post( $parent ) ) );
		$this->assertSame( 'parent', ( new GalleryInvitations( $access ) )->send( get_post( $child ), 'guest@example.org' ) );
	}
}
