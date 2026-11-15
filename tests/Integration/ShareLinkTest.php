<?php
/**
 * Secret share links.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\AccessGuard;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Plugin;

final class ShareLinkTest extends AccessTestCase {

	private function link_gallery( array $args = [] ): int {
		return $this->gallery( $args, [ Access::META_MODE => Access::MODE_LINK ] );
	}

	/** Opens a URL with ?key= and returns after the guard set its cookies. */
	private function open( string $url ): void {
		$this->go_to( $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
		add_action(
			'slashphoto_share_link_opened',
			static function (): void {
				throw new \RuntimeException( 'opened' );
			}
		);
		try {
			( new AccessGuard( Plugin::instance()->access(), new TemplateLoader() ) )->template_redirect();
			$this->fail( 'The share link should redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'opened', $e->getMessage() );
		}
		$_GET = [];
	}

	public function test_gallery_is_closed_without_the_link(): void {
		$gallery = $this->link_gallery();
		$this->image( $gallery );
		$this->as_anonymous();

		$html = $this->render_page( get_permalink( $gallery ) );
		$this->assertStringContainsString( 'slashphoto-notice--link', $html );
		$this->assertStringNotContainsString( 'Secret intro', $html );
		$this->assertSame( [], $this->rest( '/wp/v2/media', [ 'parent' => $gallery ] )->get_data() );
		$archive = new \WP_Query( [ 'post_type' => PostType::POST_TYPE ] );
		$this->assertNotContains( $gallery, wp_list_pluck( $archive->posts, 'ID' ) );
	}

	public function test_opening_the_link_unlocks_the_gallery_and_its_sub_galleries(): void {
		$gallery = $this->link_gallery();
		$child   = $this->gallery( [ 'post_parent' => $gallery ] );
		$this->image( $child );
		$url = Access::share_url( get_post( $gallery ) );
		$this->as_anonymous();

		$this->assertMatchesRegularExpression( '/[?&]key=[a-f0-9]{32}$/', $url );
		$this->assertFalse( Plugin::instance()->access()->can_view( $child ) );

		$this->open( $url );

		$this->assertTrue( Plugin::instance()->access()->can_view( $gallery ) );
		$this->assertStringContainsString( 'slashphoto-item', $this->render_page( get_permalink( $child ) ) );
	}

	public function test_wrong_or_malformed_keys_do_nothing(): void {
		$gallery = $this->link_gallery();
		Access::share_url( get_post( $gallery ) );
		$this->as_anonymous();

		$this->open( add_query_arg( 'key', str_repeat( 'a', 32 ), get_permalink( $gallery ) ) );
		$this->open( add_query_arg( 'key', '<script>', get_permalink( $gallery ) ) );

		$this->assertFalse( Plugin::instance()->access()->can_view( $gallery ) );
		$this->assertArrayNotHasKey( Access::LINK_COOKIE_PREFIX . $gallery, array_filter( $_COOKIE ) );
	}

	public function test_a_new_link_locks_out_earlier_visitors(): void {
		$gallery = $this->link_gallery();
		$this->as_anonymous();
		$this->open( Access::share_url( get_post( $gallery ) ) );
		$this->assertTrue( Plugin::instance()->access()->can_view( $gallery ) );

		Access::rotate_link_key( get_post( $gallery ) );
		Plugin::instance()->access()->flush();

		$this->assertFalse( Plugin::instance()->access()->can_view( $gallery ) );
	}

	public function test_link_is_only_visible_to_editors_over_rest(): void {
		$gallery = $this->link_gallery();
		$route   = '/wp/v2/' . PostType::POST_TYPE . '/' . $gallery;

		$this->as_user( 'editor' );
		$data = $this->rest( $route, [ 'context' => 'edit' ] )->get_data();
		$this->assertStringContainsString( 'key=', $data['slashphoto_share_url'] );
		$this->assertArrayNotHasKey( Access::META_LINK_KEY, $data['meta'] );

		$this->as_anonymous();
		$this->assertSame( 404, $this->rest( $route )->get_status(), 'Link-only galleries are hidden from REST.' );
	}

	public function test_rotating_needs_edit_rights(): void {
		$gallery = $this->link_gallery();
		$old     = Access::share_url( get_post( $gallery ) );
		$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/galleries/' . $gallery . '/share-link' );

		$this->as_user( 'subscriber' );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		$this->as_user( 'editor' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( $old, $response->get_data()['url'] );
	}

	public function tear_down(): void {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( str_starts_with( $name, Access::LINK_COOKIE_PREFIX ) ) {
				unset( $_COOKIE[ $name ] );
			}
		}
		parent::tear_down();
	}
}
