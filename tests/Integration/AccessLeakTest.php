<?php
/**
 * Every path through which a protected gallery or its images could leak.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\AccessGuard;
use SlashPhoto\Access\Terms;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Plugin;

final class AccessLeakTest extends AccessTestCase {

	private function guard(): AccessGuard {
		return new AccessGuard( Plugin::instance()->access(), new TemplateLoader() );
	}

	public function test_rest_media_collection_hides_protected_images(): void {
		$protected = $this->gallery( [ 'post_password' => 'secret' ] );
		$child     = $this->gallery( [ 'post_parent' => $protected ] );
		$public    = $this->gallery();
		$this->image( $protected );
		$this->image( $child );
		$public_image = $this->image( $public );
		$this->as_anonymous();

		$unattached = self::factory()->attachment->create_upload_object( TestImages::jpeg( 10, 10 ) );
		$this->assertSame( [], $this->rest( '/wp/v2/media', [ 'parent' => $protected ] )->get_data(), 'Not even unattached files (regression).' );
		$this->assertSame( [], $this->rest( '/wp/v2/media', [ 'parent' => $child ] )->get_data(), 'Inherited protection.' );
		$all = wp_list_pluck( $this->rest( '/wp/v2/media', [ 'per_page' => 100 ] )->get_data(), 'id' );
		sort( $all );
		$this->assertSame( [ $public_image, $unattached ], $all );

		$this->enter_password( 'secret' );
		Plugin::instance()->access()->flush();
		$this->assertCount( 1, $this->rest( '/wp/v2/media', [ 'parent' => $protected ] )->get_data() );
	}

	public function test_rest_media_single_item_is_not_found(): void {
		$client  = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$gallery = $this->clients_gallery( [ $client ] );
		$image   = $this->image( $gallery );

		$this->as_anonymous();
		$this->assertSame( 404, $this->rest( '/wp/v2/media/' . $image )->get_status() );

		wp_set_current_user( $client );
		$this->assertSame( 200, $this->rest( '/wp/v2/media/' . $image )->get_status() );
	}

	public function test_rest_gallery_endpoints(): void {
		$client = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$hidden = $this->clients_gallery( [ $client ], [ 'post_title' => 'Smith wedding' ] );
		$listed = $this->gallery( [ 'post_password' => 'secret' ] );
		$child  = $this->gallery( [ 'post_parent' => $listed ] );
		$route  = '/wp/v2/' . PostType::POST_TYPE;
		$this->as_anonymous();

		$ids = wp_list_pluck( $this->rest( $route, [ 'per_page' => 100 ] )->get_data(), 'id' );
		$this->assertNotContains( $hidden, $ids );
		$this->assertContains( $listed, $ids, 'Password galleries are listed by title, like WordPress does.' );
		$this->assertSame( 404, $this->rest( $route . '/' . $hidden )->get_status() );
		$this->assertSame( [], $this->rest( $route, [ 'include' => [ $hidden ] ] )->get_data(), '?include= must not bypass the exclusion.' );

		$data = $this->rest( $route . '/' . $child )->get_data();
		$this->assertSame( '', $data['content']['rendered'], 'Inherited password hides content over REST.' );
	}

	public function test_rest_view_context_does_not_expose_settings(): void {
		$client  = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$gallery = $this->gallery( [], [ Terms::META_TERMS => 'Terms.' ] );
		update_post_meta( $gallery, '_slashphoto_clients', [ $client ] );
		$this->as_anonymous();

		$meta = $this->rest( '/wp/v2/' . PostType::POST_TYPE . '/' . $gallery )->get_data()['meta'];

		$this->assertArrayHasKey( '_slashphoto_access', $meta, 'Meta is exposed to the block editor.' );
		$this->assertArrayNotHasKey( '_slashphoto_clients', $meta );
		$this->assertArrayNotHasKey( '_slashphoto_terms', $meta );
		$this->assertArrayNotHasKey( '_slashphoto_expires', $meta );
	}

	public function test_editors_can_update_settings_but_others_cannot(): void {
		$gallery = $this->gallery();
		$client  = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$route   = '/wp/v2/' . PostType::POST_TYPE . '/' . $gallery;
		$update  = function () use ( $route, $client ) {
			$request = new \WP_REST_Request( 'POST', $route );
			$request->set_body_params(
				[
					'meta' => [
						'_slashphoto_access'  => 'clients',
						'_slashphoto_clients' => [ $client, 999999 ],
					],
				]
			);
			return rest_get_server()->dispatch( $request );
		};

		$this->as_user( 'author' );
		$this->assertSame( 403, $update()->get_status() );
		$this->assertSame( 'public', get_post_meta( $gallery, '_slashphoto_access', true ), 'Unchanged (registered default).' );

		$this->as_user( 'editor' );
		$this->assertSame( 200, $update()->get_status() );
		$this->assertSame( 'clients', get_post_meta( $gallery, '_slashphoto_access', true ) );
		$this->assertSame( [ $client ], get_post_meta( $gallery, '_slashphoto_clients', true ), 'Unknown user IDs are dropped.' );
	}

	public function test_attachment_page_is_not_found(): void {
		$gallery = $this->gallery( [ 'post_password' => 'secret' ] );
		$image   = $this->image( $gallery );
		$this->as_anonymous();

		$this->go_to( get_attachment_link( $image ) );
		$this->guard()->template_redirect();

		$this->assertTrue( is_404() );
	}

	public function test_listings_search_and_adjacent_links_hide_client_galleries(): void {
		$client = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$first  = $this->gallery(
			[
				'post_title' => 'Alpha public',
				'post_date'  => '2026-01-01 10:00:00',
			]
		);
		$hidden = $this->clients_gallery(
			[ $client ],
			[
				'post_title' => 'Smith wedding',
				'post_date'  => '2026-01-02 10:00:00',
			]
		);
		$this->as_anonymous();

		$archive = new \WP_Query( [ 'post_type' => PostType::POST_TYPE ] );
		$this->assertNotContains( $hidden, wp_list_pluck( $archive->posts, 'ID' ) );

		$search = new \WP_Query( [ 's' => 'Smith' ] );
		$this->assertSame( [], $search->posts );

		$this->go_to( get_permalink( $first ) );
		$this->assertSame( '', get_adjacent_post( false, '', false ) );

		wp_set_current_user( $client );
		$archive = new \WP_Query( [ 'post_type' => PostType::POST_TYPE ] );
		$this->assertContains( $hidden, wp_list_pluck( $archive->posts, 'ID' ) );
	}

	public function test_sitemaps_skip_every_protected_gallery(): void {
		$public     = $this->gallery();
		$password   = $this->gallery( [ 'post_password' => 'secret' ] );
		$child      = $this->gallery( [ 'post_parent' => $password ] );
		$with_terms = $this->gallery( [], [ Terms::META_TERMS => 'Terms.' ] );
		$this->as_anonymous();

		$provider = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
		$urls     = wp_list_pluck( $provider->get_url_list( 1, PostType::POST_TYPE ), 'loc' );

		$this->assertContains( get_permalink( $public ), $urls );
		foreach ( [ $password, $child, $with_terms ] as $id ) {
			$this->assertNotContains( get_permalink( $id ), $urls );
		}
	}

	public function test_protected_galleries_are_noindex(): void {
		$gallery = $this->gallery( [ 'post_password' => 'secret' ] );
		$this->go_to( get_permalink( $gallery ) );

		$robots = $this->guard()->robots( [] );

		$this->assertTrue( $robots['noindex'] );
	}

	public function test_oembed_is_refused(): void {
		$client  = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$gallery = $this->clients_gallery( [ $client ] );
		$image   = $this->image( $this->gallery( [ 'post_password' => 'secret' ] ) );
		$this->as_anonymous();

		$this->assertSame( 0, apply_filters( 'oembed_request_post_id', $gallery, get_permalink( $gallery ) ) );
		$this->assertSame( 0, apply_filters( 'oembed_request_post_id', $image, get_permalink( $image ) ) );
	}

	public function test_sub_gallery_listing_hides_or_locks_children(): void {
		$client = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$parent = $this->gallery();
		$hidden = $this->clients_gallery(
			[ $client ],
			[
				'post_parent' => $parent,
				'post_title'  => 'Hidden child',
			]
		);
		$locked = $this->gallery(
			[
				'post_parent'   => $parent,
				'post_password' => 'secret',
				'post_title'    => 'Locked child',
			]
		);
		set_post_thumbnail( $locked, $this->image( $locked ) );
		$this->as_anonymous();

		$html = $this->render_page( get_permalink( $parent ) );

		$this->assertStringNotContainsString( 'Hidden child', $html );
		$this->assertStringContainsString( 'Locked child', $html );
		$this->assertStringContainsString( 'slashphoto-album-cover--locked', $html );
		$this->assertStringNotContainsString( '<img', $html, 'The cover of a locked child is not shown.' );
		unset( $hidden );
	}
}
