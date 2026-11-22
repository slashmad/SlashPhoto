<?php
/**
 * New display copies for existing gallery images.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Media\Reprocessor;
use SlashPhoto\Media\Watermark;
use SlashPhoto\Settings;
use SlashPhoto\Shop\DownloadSizes;

final class ReprocessTest extends AccessTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	private function large_image( int $gallery ): int {
		return self::factory()->attachment->create_upload_object( TestImages::jpeg( 1600, 1200 ), $gallery );
	}

	private static function longest( string $file ): int {
		$size = wp_getimagesize( $file );
		return max( (int) $size[0], (int) $size[1] );
	}

	public function test_new_copies_use_current_settings_and_unguessable_paths(): void {
		$gallery      = $this->gallery();
		$image        = $this->large_image( $gallery );
		$old_main     = get_attached_file( $image );
		$old_original = wp_get_original_image_path( $image );
		$hash         = sha1_file( $old_original );
		$this->assertSame( 1598, self::longest( $old_main ), 'Uploaded without a gallery folder, at the default size.' );
		$this->assertStringNotContainsString( '/slashphoto/', $old_main );

		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'display_size' => 1024 ] ) );
		$this->assertTrue( Reprocessor::image( $image ) );

		$main     = get_attached_file( $image );
		$original = wp_get_original_image_path( $image );
		$this->assertSame( 1024, self::longest( $main ) );
		$this->assertMatchesRegularExpression( '#/slashphoto/[a-f0-9]{24}/[^/]+-[a-f0-9]{12}-scaled\.jpg$#', $main );
		$this->assertMatchesRegularExpression( '#/slashphoto/[a-f0-9]{24}/o[a-f0-9]{24}/[^/]+\.jpg$#', $original );
		$this->assertSame( $hash, sha1_file( $original ), 'The original is unchanged.' );
		$this->assertFileExists( dirname( $original ) . '/.htaccess' );
		$this->assertFileDoesNotExist( $old_main );
		$this->assertFileDoesNotExist( $old_original );
		$this->assertDirectoryDoesNotExist( dirname( $old_original ) );
		foreach ( wp_get_attachment_metadata( $image )['sizes'] as $size ) {
			$this->assertFileExists( dirname( $main ) . '/' . $size['file'] );
		}
		$this->assertSame( $gallery, wp_get_post_parent_id( $image ) );
	}

	public function test_a_watermark_switched_on_later_reaches_existing_photos(): void {
		$gallery = $this->gallery();
		$image   = $this->large_image( $gallery );
		$blue    = static function ( string $file ): int {
			$picture = imagecreatefromstring( (string) file_get_contents( $file ) );
			return imagecolorat( $picture, (int) ( imagesx( $picture ) / 2 ), (int) ( imagesy( $picture ) / 2 ) ) & 0xFF;
		};
		$this->assertLessThan( 160, $blue( get_attached_file( $image ) ), 'Unstamped: the test image is rgb(40, 90, 140).' );

		$png = imagecreatetruecolor( 200, 100 );
		imagefill( $png, 0, 0, imagecolorallocate( $png, 255, 255, 255 ) );
		$file = wp_tempnam( 'mark.png' ) . '.png';
		imagepng( $png, $file );
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::all(),
				[
					'watermark_id'       => self::factory()->attachment->create_upload_object( $file ),
					'watermark_position' => 'center',
					'watermark_scale'    => 50,
					'watermark_opacity'  => 60,
				]
			)
		);
		update_post_meta( $gallery, Watermark::META, 'on' );

		$this->assertTrue( Reprocessor::image( $image ) );
		$this->assertGreaterThan( 160, $blue( get_attached_file( $image ) ) );
		$this->assertLessThan( 160, $blue( wp_get_original_image_path( $image ) ), 'The original is never stamped.' );
		$this->assertNotEmpty( wp_get_attachment_metadata( $image )['slashphoto_watermarked'] );
	}

	public function test_public_originals_of_old_uploads_become_private(): void {
		$image    = $this->large_image( $this->gallery() );
		$original = wp_get_original_image_path( $image );
		$main     = get_attached_file( $image );
		// As before SlashPhoto protected originals: next to the display copy.
		$public = dirname( $main ) . '/' . wp_basename( $original );
		rename( $original, $public );
		$meta                   = wp_get_attachment_metadata( $image );
		$meta['original_image'] = wp_basename( $public );
		wp_update_attachment_metadata( $image, $meta );
		$this->assertSame( $public, wp_get_original_image_path( $image ) );

		$this->assertTrue( Reprocessor::image( $image ) );
		$this->assertFileDoesNotExist( $public );
		$this->assertMatchesRegularExpression( '#/o[a-f0-9]{24}/#', wp_get_original_image_path( $image ) );
	}

	public function test_failures_leave_the_image_unchanged(): void {
		$image    = $this->large_image( $this->gallery() );
		$main     = get_attached_file( $image );
		$original = wp_get_original_image_path( $image );
		$meta     = wp_get_attachment_metadata( $image );
		$files    = static fn(): array => array_merge( (array) glob( wp_get_upload_dir()['basedir'] . '/slashphoto/*/*' ), (array) glob( wp_get_upload_dir()['basedir'] . '/slashphoto/*/o*/*' ) );
		$before   = $files();

		$break  = static fn() => [];
		add_filter( 'wp_generate_attachment_metadata', $break, 99 );
		$result = Reprocessor::image( $image );
		remove_filter( 'wp_generate_attachment_metadata', $break, 99 );

		$this->assertWPError( $result );
		$this->assertSame( $main, get_attached_file( $image ) );
		$this->assertSame( $meta, wp_get_attachment_metadata( $image ) );
		$this->assertFileExists( $main );
		$this->assertFileExists( $original );
		$left = array_filter( array_diff( array_filter( $files(), 'is_file' ), $before ), static fn( string $file ): bool => ! in_array( wp_basename( $file ), [ 'index.php', '.htaccess' ], true ) );
		$this->assertSame( [], $left, 'New files were removed; only folder protection remains.' );

		wp_delete_file( $original );
		wp_delete_file( $main );
		$this->assertSame( 'slashphoto_reprocess_missing', Reprocessor::image( $image )->get_error_code() );
		$this->assertSame( 'slashphoto_reprocess_type', Reprocessor::image( self::factory()->post->create() )->get_error_code() );
	}

	public function test_sized_download_copies_of_the_old_original_are_removed(): void {
		$image = $this->large_image( $this->gallery() );
		$copy  = DownloadSizes::file( $image, 640 );
		$this->assertFileExists( $copy['path'] );

		$this->assertTrue( Reprocessor::image( $image ) );
		$this->assertFileDoesNotExist( $copy['path'] );
		$this->assertSame( '', get_post_meta( $image, DownloadSizes::META, true ) );
	}

	public function test_batches_continue_after_the_last_id_and_need_edit_rights(): void {
		$gallery = $this->gallery();
		$first   = $this->image( $gallery );
		$second  = $this->image( $gallery );
		$this->image( $this->gallery() );

		$result = Reprocessor::batch( $gallery, 0, 0.0 );
		$this->assertSame( 1, $result['done'] );
		$this->assertSame( $first, $result['next'] );
		$this->assertSame( 1, $result['remaining'] );
		$this->assertSame( 2, $result['total'] );
		$result = Reprocessor::batch( $gallery, $result['next'], 0.0 );
		$this->assertSame( $second, $result['next'] );
		$this->assertSame( 0, $result['remaining'] );

		$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/galleries/' . $gallery . '/reprocess' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		$this->as_user( 'author' );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		$this->as_user( 'editor' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['done'] );
	}
}
