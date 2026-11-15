<?php
/**
 * Unguessable storage for gallery images.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\Uploads;

final class UploadsTest extends AccessTestCase {

	private function sideload( int $parent_id ): int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = [
			'name'     => 'DSC6751.jpg',
			'tmp_name' => TestImages::jpeg( 400, 300 ),
		];
		return (int) media_handle_sideload( $file, $parent_id );
	}

	public function test_gallery_uploads_get_random_folder_and_name(): void {
		$gallery = $this->gallery();
		$id      = Uploads::for_gallery( $gallery, fn() => $this->sideload( $gallery ) );
		$path    = get_attached_file( $id );
		$key     = Uploads::key( $gallery );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{24}$/', $key );
		$this->assertStringContainsString( '/slashphoto/' . $key . '/', $path );
		$this->assertMatchesRegularExpression( '#/DSC6751-[a-f0-9]{12}\.jpg$#', $path );
		$this->assertFileExists( dirname( $path ) . '/index.php' );
		$this->assertFileExists( dirname( $path, 2 ) . '/index.php' );
	}

	public function test_each_gallery_has_its_own_key(): void {
		$this->assertNotSame( Uploads::key( $this->gallery() ), Uploads::key( $this->gallery() ) );
	}

	public function test_other_uploads_are_untouched(): void {
		$post = self::factory()->post->create();
		$path = get_attached_file( $this->sideload( $post ) );

		$this->assertStringNotContainsString( '/slashphoto/', $path );
		$this->assertMatchesRegularExpression( '#/[0-9]{4}/[0-9]{2}/DSC6751(-[0-9]+)?[.]jpg$#', $path );
	}

	public function test_rest_upload_target_is_captured_only_for_editable_galleries(): void {
		$gallery = $this->gallery();
		$uploads = new Uploads();
		$request = new \WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_param( 'post', $gallery );

		$this->as_user( 'subscriber' );
		$uploads->rest_before( null, [], $request );
		$this->assertSame( 0, Uploads::current_gallery(), 'Users who cannot edit the gallery do not get its folder.' );

		$this->as_user( 'editor' );
		$uploads->rest_before( null, [], $request );
		$this->assertSame( $gallery, Uploads::current_gallery() );
		$uploads->rest_after( null, [], $request );
		$this->assertSame( 0, Uploads::current_gallery() );
	}
}
