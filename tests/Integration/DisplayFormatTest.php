<?php
/**
 * WebP and AVIF display copies.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\Uploads;
use SlashPhoto\Media\DisplayCopy;
use SlashPhoto\Media\Reprocessor;
use SlashPhoto\Settings;

final class DisplayFormatTest extends AccessTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	private function format( string $format ): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), [ 'display_format' => $format ] ) );
	}

	private function upload( int $gallery ): int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = [
			'name'     => 'photo.jpg',
			'tmp_name' => TestImages::jpeg( 1600, 1200 ),
		];
		return (int) Uploads::for_gallery( $gallery, static fn() => media_handle_sideload( $file, $gallery ) );
	}

	public function test_gallery_uploads_get_webp_copies_and_keep_a_jpeg_original(): void {
		$this->assertTrue( DisplayCopy::formats()['webp'], 'The test server writes WebP.' );
		$this->format( 'webp' );
		$id   = $this->upload( $this->gallery() );
		$meta = wp_get_attachment_metadata( $id );

		$this->assertStringEndsWith( '.webp', get_attached_file( $id ) );
		$this->assertSame( 'image/webp', wp_getimagesize( get_attached_file( $id ) )['mime'] );
		foreach ( $meta['sizes'] as $size ) {
			$this->assertSame( 'image/webp', $size['mime-type'] );
		}
		$this->assertSame( 'image/jpeg', wp_getimagesize( wp_get_original_image_path( $id ) )['mime'], 'Originals keep their format.' );
	}

	public function test_avif_and_only_for_galleries(): void {
		if ( ! DisplayCopy::formats()['avif'] ) {
			$this->markTestSkipped( 'This server cannot write AVIF.' );
		}
		$this->format( 'avif' );
		$this->assertStringEndsWith( '.avif', get_attached_file( $this->upload( $this->gallery() ) ) );

		$outside = self::factory()->attachment->create_upload_object( TestImages::jpeg( 1200, 800 ) );
		$this->assertStringEndsWith( '.jpg', get_attached_file( $outside ), 'Other media are not converted.' );
	}

	public function test_reprocessing_converts_earlier_photos(): void {
		$gallery = $this->gallery();
		$id      = $this->upload( $gallery );
		$this->assertStringEndsWith( '.jpg', get_attached_file( $id ) );

		$this->format( 'webp' );
		$this->assertTrue( Reprocessor::image( $id ) );
		$this->assertStringEndsWith( '.webp', get_attached_file( $id ) );
		$this->assertSame( 'image/jpeg', wp_getimagesize( wp_get_original_image_path( $id ) )['mime'] );
	}

	public function test_webp_copies_are_watermarked(): void {
		$png = imagecreatetruecolor( 200, 100 );
		imagefill( $png, 0, 0, imagecolorallocate( $png, 255, 255, 255 ) );
		$file = wp_tempnam( 'mark.png' ) . '.png';
		imagepng( $png, $file );
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				[
					'display_format'     => 'webp',
					'watermark_id'       => self::factory()->attachment->create_upload_object( $file ),
					'watermark_position' => 'center',
					'watermark_scale'    => 50,
					'watermark_opacity'  => 60,
				]
			)
		);
		$id      = $this->upload( $this->gallery( [], [ \SlashPhoto\Media\Watermark::META => 'on' ] ) );
		$picture = imagecreatefromstring( (string) file_get_contents( get_attached_file( $id ) ) );
		$this->assertNotFalse( $picture );
		$this->assertGreaterThan( 160, imagecolorat( $picture, (int) ( imagesx( $picture ) / 2 ), (int) ( imagesy( $picture ) / 2 ) ) & 0xFF, 'Stamped: the test image is rgb(40, 90, 140).' );
		$this->assertNotEmpty( wp_get_attachment_metadata( $id )['slashphoto_watermarked'] );
	}

	public function test_setting_is_sanitised(): void {
		$this->assertSame( 'jpeg', Settings::sanitize( [ 'display_format' => 'gif' ] )['display_format'] );
		$this->assertSame( 'avif', Settings::sanitize( [ 'display_format' => 'avif' ] )['display_format'] );
	}
}
