<?php
/**
 * Who may see a gallery: passwords, clients, expiry, terms, inheritance.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;

final class AccessRulesTest extends AccessTestCase {

	public function test_sub_gallery_inherits_the_parent_password(): void {
		$parent = $this->gallery( [ 'post_password' => 'secret' ] );
		$child  = $this->gallery( [ 'post_parent' => $parent ] );
		$this->image( $child );
		$this->as_anonymous();

		$html = $this->render_page( get_permalink( $child ) );
		$this->assertStringNotContainsString( 'slashphoto-gallery', $html );
		$this->assertStringNotContainsString( 'Secret intro', $html );
		$this->assertStringContainsString( 'post_password', $html, 'The password form is shown.' );

		$this->enter_password( 'secret' );
		$html = $this->render_page( get_permalink( $child ) );
		$this->assertStringContainsString( 'slashphoto-gallery', $html );
	}

	public function test_own_password_wins_and_wrong_password_fails(): void {
		$parent = $this->gallery( [ 'post_password' => 'parent-pass' ] );
		$child  = $this->gallery(
			[
				'post_parent'   => $parent,
				'post_password' => 'child-pass',
			]
		);
		$this->as_anonymous();

		$this->enter_password( 'parent-pass' );
		$this->assertFalse( Access::core_password_required( get_post( $parent ) ) );
		$this->assertFalse( \SlashPhoto\Plugin::instance()->access()->can_view( $child ) );

		$this->enter_password( 'child-pass' );
		$this->assertTrue( \SlashPhoto\Plugin::instance()->access()->can_view( $child ) );
	}

	public function test_client_galleries_need_the_right_account(): void {
		$client  = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$gallery = $this->clients_gallery( [ $client ] );
		$this->image( $gallery );

		$this->as_anonymous();
		$html = $this->render_page( get_permalink( $gallery ) );
		$this->assertStringContainsString( 'slashphoto-notice--login', $html );
		$this->assertStringNotContainsString( 'Secret intro', $html );
		$this->assertStringNotContainsString( 'slashphoto-item', $html );
		$this->assertSame( '', get_the_excerpt( $gallery ) );

		$this->as_user();
		$html = $this->render_page( get_permalink( $gallery ) );
		$this->assertStringContainsString( 'slashphoto-notice--denied', $html );
		$this->assertStringNotContainsString( 'slashphoto-item', $html );

		wp_set_current_user( $client );
		$html = $this->render_page( get_permalink( $gallery ) );
		$this->assertStringContainsString( 'Secret intro', $html );
		$this->assertStringContainsString( 'slashphoto-item', $html );

		$this->as_user( 'editor' );
		$this->assertStringContainsString( 'slashphoto-item', $this->render_page( get_permalink( $gallery ) ) );
	}

	public function test_client_rule_is_inherited_by_sub_galleries(): void {
		$client = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$parent = $this->clients_gallery( [ $client ] );
		$child  = $this->gallery( [ 'post_parent' => $parent ] );
		$this->image( $child );

		$this->as_user();
		$this->assertStringNotContainsString( 'slashphoto-item', $this->render_page( get_permalink( $child ) ) );

		wp_set_current_user( $client );
		$this->assertStringContainsString( 'slashphoto-item', $this->render_page( get_permalink( $child ) ) );
	}

	public function test_expired_galleries_are_closed_except_for_editors(): void {
		$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$gallery   = $this->gallery( [], [ Access::META_EXPIRES => $yesterday ] );
		$this->image( $gallery );

		$this->as_anonymous();
		$html = $this->render_page( get_permalink( $gallery ) );
		$this->assertStringContainsString( 'slashphoto-notice--expired', $html );
		$this->assertStringNotContainsString( 'slashphoto-item', $html );

		$this->as_user( 'editor' );
		$this->assertStringContainsString( 'slashphoto-item', $this->render_page( get_permalink( $gallery ) ) );

		update_post_meta( $gallery, Access::META_EXPIRES, wp_date( 'Y-m-d' ) );
		$this->as_anonymous();
		$this->assertStringContainsString( 'slashphoto-item', $this->render_page( get_permalink( $gallery ) ), 'Open until the end of the last day.' );
	}

	public function test_drafts_are_never_shown_to_visitors(): void {
		$gallery = $this->gallery( [ 'post_status' => 'draft' ] );
		$this->as_anonymous();
		$this->assertFalse( \SlashPhoto\Plugin::instance()->access()->can_view( $gallery ) );
	}

	public function test_terms_gate_images_until_accepted(): void {
		$gallery = $this->gallery( [], [ Terms::META_TERMS => 'Do not share these photos.' ] );
		$this->image( $gallery );
		$this->as_anonymous();

		$html = $this->render_page( get_permalink( $gallery ) );
		$this->assertStringContainsString( 'Do not share these photos.', $html );
		$this->assertStringContainsString( 'name="action" value="slashphoto_accept_terms"', $html );
		$this->assertStringNotContainsString( 'slashphoto-item', $html );

		$_COOKIE[ Terms::COOKIE_PREFIX . $gallery ] = str_repeat( 'a', 64 );
		$this->assertStringNotContainsString( 'slashphoto-item', $this->render_page( get_permalink( $gallery ) ), 'A forged cookie is rejected.' );

		$this->accept_terms( $gallery );
		$this->assertStringContainsString( 'slashphoto-item', $this->render_page( get_permalink( $gallery ) ) );

		update_post_meta( $gallery, Terms::META_TERMS, 'New wording.' );
		$this->assertStringNotContainsString( 'slashphoto-item', $this->render_page( get_permalink( $gallery ) ), 'Changed terms must be accepted again.' );
	}

	public function test_terms_of_the_parent_apply_to_sub_galleries(): void {
		$parent = $this->gallery( [], [ Terms::META_TERMS => 'Parent terms.' ] );
		$child  = $this->gallery( [ 'post_parent' => $parent ] );
		$this->image( $child );
		$this->as_anonymous();

		$this->assertStringContainsString( 'Parent terms.', $this->render_page( get_permalink( $child ) ) );
		$this->accept_terms( $parent );
		$this->assertStringContainsString( 'slashphoto-item', $this->render_page( get_permalink( $child ) ) );
	}

	public function test_accepting_terms_never_grants_access_and_needs_a_nonce(): void {
		$client  = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$gallery = $this->clients_gallery( [ $client ] );
		update_post_meta( $gallery, Terms::META_TERMS, 'Terms.' );
		$this->as_anonymous();
		$terms = new Terms( \SlashPhoto\Plugin::instance()->access() );
		add_action(
			'slashphoto_terms_handled',
			static function (): void {
				throw new \RuntimeException( 'handled' );
			}
		);

		$_POST = [
			'gallery'  => $gallery,
			'agree'    => '1',
			'_wpnonce' => wp_create_nonce( Terms::ACTION . '_' . $gallery ),
		];
		try {
			$terms->handle_accept();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'handled', $e->getMessage() );
		}
		$this->assertArrayNotHasKey( Terms::COOKIE_PREFIX . $gallery, $_COOKIE );

		$_POST['_wpnonce'] = 'forged';
		$this->expectException( \WPDieException::class );
		$terms->handle_accept();
	}

	public function test_accepting_terms_sets_only_a_signed_cookie(): void {
		$gallery = $this->gallery( [], [ Terms::META_TERMS => 'Terms.' ] );
		$this->as_anonymous();
		$meta_before = get_post_meta( $gallery );
		add_action(
			'slashphoto_terms_handled',
			static function (): void {
				throw new \RuntimeException( 'handled' );
			}
		);

		$_POST = [
			'gallery'  => $gallery,
			'agree'    => '1',
			'_wpnonce' => wp_create_nonce( Terms::ACTION . '_' . $gallery ),
		];
		try {
			( new Terms( \SlashPhoto\Plugin::instance()->access() ) )->handle_accept();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$this->assertSame( Terms::token( get_post( $gallery ) ), $_COOKIE[ Terms::COOKIE_PREFIX . $gallery ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reading the cookie the handler set.
		$this->assertSame( $meta_before, get_post_meta( $gallery ), 'Nothing about the visitor is stored.' );
	}

	public function tear_down(): void {
		$_POST = [];
		parent::tear_down();
	}
}
