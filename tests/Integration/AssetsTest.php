<?php
/**
 * Front-end asset tests.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Frontend\Assets;
use SlashPhoto\Gallery\PostType;
use WP_UnitTestCase;

final class AssetsTest extends WP_UnitTestCase {

	private function printed_modules(): string {
		ob_start();
		wp_script_modules()->print_enqueued_script_modules();
		wp_script_modules()->print_import_map();
		return (string) ob_get_clean();
	}

	public function test_vendor_manifest_points_at_bundled_files(): void {
		$manifest = Assets::vendor_manifest();

		$this->assertArrayHasKey( '@slashphoto/vendor/three', $manifest );
		$this->assertArrayHasKey( '@slashphoto/vendor/psv-core', $manifest );
		foreach ( $manifest as $id => $lib ) {
			$this->assertFileExists( SLASHPHOTO_DIR . 'assets/vendor/' . $lib['module'], $id );
			$this->assertFileExists( dirname( SLASHPHOTO_DIR . 'assets/vendor/' . $lib['module'] ) . '/LICENSE', "{$id} must ship its licence." );
			$this->assertSame( 'MIT', $lib['license'], "{$id} must stay GPL-compatible." );
		}
	}

	public function test_lightbox_loads_on_galleries_and_viewer_stays_lazy(): void {
		$gallery_id = self::factory()->post->create( [ 'post_type' => PostType::POST_TYPE ] );
		$this->go_to( get_permalink( $gallery_id ) );

		do_action( 'wp_enqueue_scripts' );
		$html = $this->printed_modules();

		$this->assertStringContainsString( 'assets/js/lightbox.js', $html );
		$this->assertMatchesRegularExpression( '#"@slashphoto/panorama-viewer":"[^"]+panorama-viewer\.js#', $html, 'The viewer must be in the import map.' );
		$this->assertStringNotContainsString( 'panorama-viewer.js?ver=' . SLASHPHOTO_VERSION . '" type="module"', $html );
		$this->assertDoesNotMatchRegularExpression( '#<link rel="modulepreload"[^>]+(three|photo-sphere-viewer|panorama-viewer)#', $html, 'Viewer libraries must not be preloaded.' );
	}

	public function test_lightbox_can_be_disabled(): void {
		$gallery_id = self::factory()->post->create( [ 'post_type' => PostType::POST_TYPE ] );
		$this->go_to( get_permalink( $gallery_id ) );
		add_filter( 'slashphoto_enable_lightbox', '__return_false' );

		do_action( 'wp_enqueue_scripts' );

		$this->assertStringNotContainsString( 'assets/js/lightbox.js', $this->printed_modules() );
	}

	public function test_lightbox_data_has_viewer_style_and_strings(): void {
		$data = ( new Assets() )->lightbox_data( [] );

		$this->assertStringContainsString( 'photo-sphere-viewer-core-', $data['viewerStyle'] );
		$this->assertSame( '%1$d of %2$d', $data['i18n']['counter'] );
	}

	public function test_dev_versions_change_with_the_file(): void {
		$this->assertStringStartsWith( SLASHPHOTO_VERSION, Assets::version( 'assets/js/lightbox.js' ) );
		$this->assertSame( SLASHPHOTO_VERSION, Assets::version( 'does/not/exist.js' ) );
	}
}
