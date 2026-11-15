<?php
/**
 * Shared fixtures for access tests.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Gallery\PostType;
use WP_UnitTestCase;

abstract class AccessTestCase extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The WordPress test suite unregisters all meta keys after every test.
		if ( ! registered_meta_key_exists( 'post', Access::META_MODE, PostType::POST_TYPE ) ) {
			( new \SlashPhoto\Gallery\GalleryMeta() )->register();
		}
	}

	protected function gallery( array $args = [], array $meta = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_type'    => PostType::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => 'Gallery',
					'post_content' => '<p>Secret intro</p>',
				],
				$args
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	protected function image( int $gallery_id ): int {
		return self::factory()->attachment->create_upload_object( TestImages::jpeg( 400, 300 ), $gallery_id );
	}

	protected function clients_gallery( array $client_ids, array $args = [] ): int {
		return $this->gallery(
			$args,
			[
				Access::META_MODE    => Access::MODE_CLIENTS,
				Access::META_CLIENTS => $client_ids,
			]
		);
	}

	protected function as_anonymous(): void {
		wp_set_current_user( 0 );
	}

	protected function as_user( string $role = 'slashphoto_client' ): int {
		$id = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $id );
		return $id;
	}

	protected function enter_password( string $password ): void {
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher                                 = new \PasswordHash( 8, true );
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( $password );
	}

	protected function accept_terms( int $source_id ): void {
		$_COOKIE[ Terms::COOKIE_PREFIX . $source_id ] = Terms::token( get_post( $source_id ) );
	}

	protected function render_page( string $url ): string {
		$this->go_to( $url );
		$output = '';
		while ( have_posts() ) {
			the_post();
			$output .= apply_filters( 'the_content', get_the_content() );
		}
		wp_reset_postdata();
		return $output;
	}

	protected function rest( string $route, array $params = [] ) {
		$request = new \WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( str_starts_with( $name, Terms::COOKIE_PREFIX ) ) {
				unset( $_COOKIE[ $name ] );
			}
		}
		\SlashPhoto\Plugin::instance()->access()->flush();
		wp_dequeue_style( 'slashphoto-frontend' );
		wp_dequeue_script_module( '@slashphoto/lightbox' );
		parent::tear_down();
	}
}
