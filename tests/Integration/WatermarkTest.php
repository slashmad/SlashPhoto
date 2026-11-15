<?php
/**
 * Watermarked previews and protected originals.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\Uploads;
use SlashPhoto\Media\Watermark;
use SlashPhoto\Plugin;
use SlashPhoto\Settings;

final class WatermarkTest extends AccessTestCase {

	public function set_up(): void {
		parent::set_up();
		// An opaque white PNG, so stamped pixels are easy to detect.
		$image = imagecreatetruecolor( 200, 100 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 255, 255, 255 ) );
		$file = wp_tempnam( 'mark.png' ) . '.png';
		imagepng( $image, $file );
		$mark = self::factory()->attachment->create_upload_object( $file );
		update_option(
			Settings::OPTION,
			[
				'watermark_id'       => $mark,
				'watermark_position' => 'center',
				'watermark_scale'    => 50,
				'watermark_opacity'  => 60,
			]
		);
	}

	private function upload( int $gallery, int $width = 1200, int $height = 800, ?string $xmp = null ): int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = [
			'name'     => 'photo.jpg',
			'tmp_name' => TestImages::jpeg( $width, $height, $xmp ),
		];
		return (int) Uploads::for_gallery( $gallery, static fn() => media_handle_sideload( $file, $gallery ) );
	}

	/** Blue level of the centre pixel; the test images are rgb(40, 90, 140). */
	private static function centre_blue( string $file ): int {
		$image = imagecreatefromstring( (string) file_get_contents( $file ) );
		return ( imagecolorat( $image, (int) ( imagesx( $image ) / 2 ), (int) ( imagesy( $image ) / 2 ) ) ) & 0xFF;
	}

	public function test_web_copies_are_stamped_and_the_original_is_untouched(): void {
		$gallery = $this->gallery( [], [ Watermark::META => 'on' ] );
		$id      = $this->upload( $gallery );
		$meta    = wp_get_attachment_metadata( $id );
		$main    = get_attached_file( $id );

		$this->assertStringEndsWith( '-scaled.jpg', $main, 'A separate display copy is made even for small images.' );
		$this->assertMatchesRegularExpression( '#^o[a-f0-9]{24}/photo-[a-f0-9]{12}\.jpg$#', $meta['original_image'] );
		$this->assertTrue( $meta['slashphoto_watermarked'] );

		$original = wp_get_original_image_path( $id );
		$this->assertFileExists( $original );
		$this->assertFileExists( dirname( $original ) . '/index.php' );
		$this->assertEqualsWithDelta( 140, self::centre_blue( $original ), 6, 'The original keeps its pixels.' );
		$this->assertGreaterThan( 180, self::centre_blue( $main ), 'The display copy carries the watermark.' );
		$this->assertGreaterThan( 180, self::centre_blue( dirname( $main ) . '/' . $meta['sizes']['medium']['file'] ) );
	}

	public function test_original_cannot_be_derived_from_the_preview_url(): void {
		$gallery = $this->gallery( [], [ Watermark::META => 'on' ] );
		$main    = get_attached_file( $this->upload( $gallery ) );

		$this->assertFileDoesNotExist( str_replace( '-scaled.jpg', '.jpg', $main ) );
	}

	public function test_galleries_without_watermark_keep_clean_images(): void {
		$gallery = $this->gallery();
		$id      = $this->upload( $gallery );

		$this->assertEmpty( wp_get_attachment_metadata( $id )['slashphoto_watermarked'] ?? null );
		$this->assertEqualsWithDelta( 140, self::centre_blue( get_attached_file( $id ) ), 6 );
	}

	public function test_large_originals_are_moved_even_without_watermark(): void {
		$gallery = $this->gallery();
		$id      = $this->upload( $gallery, 3000, 2000 );
		$meta    = wp_get_attachment_metadata( $id );

		$this->assertMatchesRegularExpression( '#^o[a-f0-9]{24}/#', $meta['original_image'] );
		$this->assertFileExists( wp_get_original_image_path( $id ) );
		$this->assertFileDoesNotExist( str_replace( '-scaled.jpg', '.jpg', get_attached_file( $id ) ) );
	}

	public function test_360_panoramas_are_not_stamped(): void {
		$gallery = $this->gallery( [], [ Watermark::META => 'on' ] );
		$id      = $this->upload( $gallery, 2000, 1000, TestImages::gpano_xmp( 2000, 1000 ) );

		$this->assertArrayNotHasKey( 'slashphoto_watermarked', wp_get_attachment_metadata( $id ) );
		$this->assertEqualsWithDelta( 140, self::centre_blue( get_attached_file( $id ) ), 6 );
	}

	public function test_setting_is_inherited_and_falls_back_to_the_site_default(): void {
		$watermark = new Watermark( Plugin::instance()->access() );
		$parent    = $this->gallery( [], [ Watermark::META => 'on' ] );
		$child     = $this->gallery( [ 'post_parent' => $parent ] );
		$opted_out = $this->gallery( [ 'post_parent' => $parent ], [ Watermark::META => 'off' ] );
		$plain     = $this->gallery();

		$this->assertTrue( $watermark->enabled_for( $child ) );
		$this->assertFalse( $watermark->enabled_for( $opted_out ) );
		$this->assertFalse( $watermark->enabled_for( $plain ) );

		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'watermark_default' => true ] ) );
		$this->assertTrue( $watermark->enabled_for( $plain ) );

		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'watermark_id' => 0 ] ) );
		$this->assertFalse( $watermark->enabled_for( $child ), 'No watermark image, no watermark.' );
	}

	public function test_positions(): void {
		$this->assertSame( [ [ 400, 350 ] ], Watermark::positions( 'center', 1000, 800, 200, 100 ) );
		$this->assertGreaterThan( 10, count( Watermark::positions( 'tile', 1000, 800, 100, 50 ) ) );
		[ [ $x, $y ] ] = Watermark::positions( 'bottom-right', 1000, 800, 200, 100 );
		$this->assertLessThanOrEqual( 1000, $x + 200 );
		$this->assertLessThanOrEqual( 800, $y + 100 );
	}

	public function test_settings_are_sanitised(): void {
		$jpeg     = self::factory()->attachment->create_upload_object( TestImages::jpeg( 10, 10 ) );
		$settings = Settings::sanitize(
			[
				'watermark_id'       => $jpeg,
				'watermark_position' => 'nowhere',
				'watermark_scale'    => 500,
				'watermark_opacity'  => -3,
			]
		);

		$this->assertSame( 0, $settings['watermark_id'], 'Only PNG files.' );
		$this->assertSame( 'center', $settings['watermark_position'] );
		$this->assertSame( 100, $settings['watermark_scale'] );
		$this->assertSame( 5, $settings['watermark_opacity'] );
	}
}
