<?php
/**
 * Display copies and private originals for every gallery image.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Settings;

final class DisplayCopyTest extends AccessTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_small_gallery_images_still_get_a_separate_display_copy(): void {
		$gallery = $this->gallery();
		$id      = self::factory()->attachment->create_upload_object( TestImages::jpeg( 1200, 800 ), $gallery );
		$meta    = wp_get_attachment_metadata( $id );
		$main    = get_attached_file( $id );

		$this->assertSame( 1198, $meta['width'] );
		$this->assertMatchesRegularExpression( '#^o[a-f0-9]{24}/#', $meta['original_image'] );
		$original = wp_get_original_image_path( $id );
		$this->assertFileExists( $original );
		$this->assertSame( [ 1200, 800 ], array_slice( wp_getimagesize( $original ), 0, 2 ) );
		$this->assertFileExists( dirname( $original ) . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( dirname( $original ) . '/.htaccess' ) );
		$this->assertNotSame( dirname( $main ), dirname( $original ) );
	}

	public function test_large_images_use_the_configured_long_edge(): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), [ 'display_size' => 1600 ] ) );
		$gallery = $this->gallery();
		$id      = self::factory()->attachment->create_upload_object( TestImages::jpeg( 2000, 3000 ), $gallery );
		$meta    = wp_get_attachment_metadata( $id );

		$this->assertSame( 1600, $meta['height'] );
		$this->assertSame( 1067, $meta['width'] );
	}

	public function test_images_outside_galleries_are_left_to_wordpress(): void {
		$id   = self::factory()->attachment->create_upload_object( TestImages::jpeg( 1200, 800 ) );
		$meta = wp_get_attachment_metadata( $id );

		$this->assertSame( 1200, $meta['width'] );
		$this->assertArrayNotHasKey( 'original_image', $meta );
	}

	public function test_settings_are_clamped(): void {
		$clean = Settings::sanitize(
			[
				'display_size'     => 99999,
				'display_quality'  => 5,
				'snapotter_mode'   => 'everything',
				'snapotter_min_mp' => 0,
			]
		);
		$this->assertSame( 8192, $clean['display_size'] );
		$this->assertSame( 60, $clean['display_quality'] );
		$this->assertSame( 'heavy', $clean['snapotter_mode'] );
		$this->assertSame( 1, $clean['snapotter_min_mp'] );
	}
}
