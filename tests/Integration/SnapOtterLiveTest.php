<?php
/**
 * Tests against a real SnapOtter server.
 *
 * Skipped unless SLASHPHOTO_SNAPOTTER_URL (and optionally
 * SLASHPHOTO_SNAPOTTER_USER / _PASSWORD) are set, for example:
 *
 *   docker run -d --name slashphoto-snapotter --network <wp-env network> \
 *     --network-alias snapotter -e SNAPOTTER_ANALYTICS_DEFAULT=off \
 *     -e SKIP_MUST_CHANGE_PASSWORD=true snapotter/snapotter:2.3.0
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/slashphoto \
 *     env SLASHPHOTO_SNAPOTTER_URL=http://snapotter:1349 vendor/bin/phpunit --filter SnapOtterLive
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\Importer;
use SlashPhoto\Settings;
use SlashPhoto\SnapOtter\Connection;

/**
 * @group external-snapotter
 */
final class SnapOtterLiveTest extends AccessTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! getenv( 'SLASHPHOTO_SNAPOTTER_URL' ) ) {
			$this->markTestSkipped( 'Set SLASHPHOTO_SNAPOTTER_URL to test against a real SnapOtter server.' );
		}
	}

	public function tear_down(): void {
		Connection::disconnect();
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_connect_convert_and_disconnect(): void {
		$result = Connection::connect_with_login(
			(string) getenv( 'SLASHPHOTO_SNAPOTTER_URL' ),
			false !== getenv( 'SLASHPHOTO_SNAPOTTER_USER' ) ? (string) getenv( 'SLASHPHOTO_SNAPOTTER_USER' ) : 'admin',
			false !== getenv( 'SLASHPHOTO_SNAPOTTER_PASSWORD' ) ? (string) getenv( 'SLASHPHOTO_SNAPOTTER_PASSWORD' ) : 'admin'
		);
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertNotEmpty( Connection::data()['version'] );
		$this->assertIsString( Connection::test() );

		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				[
					'snapotter_mode' => 'all',
					'display_size'   => 1600,
				]
			)
		);
		$gallery = $this->gallery();

		$jpeg = wp_tempnam( 'jpg' );
		copy( TestImages::jpeg( 4000, 3000 ), $jpeg );
		$id = Importer::import( $jpeg, 'DSC_9000.jpg', $gallery );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 'snapotter', get_post_meta( $id, Importer::META_MADE_BY, true ), 'SnapOtter made the copy, not the WordPress fallback.' );
		$this->assertSame( 1600, $meta['width'] );
		$this->assertSame( [ 4000, 3000 ], array_slice( wp_getimagesize( wp_get_original_image_path( $id ) ), 0, 2 ) );

		if ( class_exists( '\Imagick' ) && \Imagick::queryFormats( 'TIFF' ) ) {
			$image = new \Imagick();
			$image->newImage( 3000, 4500, 'rgb(140,90,40)' );
			$image->setImageFormat( 'tiff' );
			$tiff = wp_tempnam( 'tif' );
			$image->writeImage( $tiff );
			$id = Importer::import( $tiff, 'scan.tif', $gallery );
			$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
			$this->assertSame( 1600, wp_get_attachment_metadata( $id )['height'] );
			$this->assertStringEndsWith( '.tif', wp_get_original_image_path( $id ) );
		}
	}
}
