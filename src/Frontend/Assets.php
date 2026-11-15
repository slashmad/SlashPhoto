<?php
/**
 * Front-end assets.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Frontend;

use SlashPhoto\Gallery\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues front-end styles and script modules only where needed.
 *
 * JavaScript uses WordPress script modules (native ES modules with an import
 * map). The lightbox is the only module loaded with the page; the 360° viewer
 * and its libraries (three.js, Photo Sphere Viewer) are dynamic imports that
 * the browser fetches the first time a visitor opens a 360° image.
 */
final class Assets {

	public const STYLE_HANDLE    = 'slashphoto-frontend';
	public const LIGHTBOX_MODULE = '@slashphoto/lightbox';
	public const VIEWER_MODULE   = '@slashphoto/panorama-viewer';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		// Registered early: block.json refers to the stylesheet handle.
		add_action( 'init', [ $this, 'register_assets' ], 5 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'script_module_data_' . self::LIGHTBOX_MODULE, [ $this, 'lightbox_data' ] );
	}

	/**
	 * Registers the stylesheet and script modules.
	 */
	public function register_assets(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			SLASHPHOTO_URL . 'assets/css/frontend.css',
			[],
			self::version( 'assets/css/frontend.css' )
		);
		$this->register_modules();
	}

	/**
	 * Enqueues assets on gallery views. The gallery block enqueues them
	 * itself wherever it is rendered.
	 */
	public function enqueue(): void {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			$this->register_assets();
		}

		$needed = is_singular( PostType::POST_TYPE ) || is_post_type_archive( PostType::POST_TYPE );

		/**
		 * Filters whether the SlashPhoto front-end stylesheet is enqueued.
		 *
		 * Themes that style galleries completely themselves can return false.
		 *
		 * @param bool $needed Whether the current view shows a gallery.
		 */
		if ( apply_filters( 'slashphoto_enqueue_frontend_styles', $needed ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}

		/**
		 * Filters whether the lightbox (including the 360° viewer) is loaded.
		 *
		 * Return false to keep plain links to the full-size images, for
		 * example when the theme or another plugin provides a lightbox.
		 *
		 * @param bool $needed Whether the current view shows a gallery.
		 */
		if ( apply_filters( 'slashphoto_enable_lightbox', $needed ) ) {
			wp_enqueue_script_module( self::LIGHTBOX_MODULE );
		}
	}

	/**
	 * Registers our script modules and the bundled third-party modules.
	 */
	public function register_modules(): void {
		foreach ( self::vendor_manifest() as $id => $lib ) {
			wp_register_script_module( $id, SLASHPHOTO_URL . 'assets/vendor/' . $lib['module'], $lib['deps'], $lib['version'] );
		}

		wp_register_script_module(
			self::VIEWER_MODULE,
			SLASHPHOTO_URL . 'assets/js/panorama-viewer.js',
			[ '@slashphoto/vendor/psv-core', '@slashphoto/vendor/psv-visible-range' ],
			self::version( 'assets/js/panorama-viewer.js' )
		);

		wp_register_script_module(
			self::LIGHTBOX_MODULE,
			SLASHPHOTO_URL . 'assets/js/lightbox.js',
			[
				[
					'id'     => self::VIEWER_MODULE,
					'import' => 'dynamic',
				],
			],
			self::version( 'assets/js/lightbox.js' )
		);
	}

	/**
	 * Data passed to the lightbox module (read from a JSON script tag).
	 *
	 * @param array $data Existing data.
	 * @return array
	 */
	public function lightbox_data( $data ) {
		$manifest = self::vendor_manifest();
		$psv      = $manifest['@slashphoto/vendor/psv-core'] ?? null;

		return array_merge(
			(array) $data,
			[
				'viewerStyle' => $psv && $psv['style'] ? add_query_arg( 'ver', $psv['version'], SLASHPHOTO_URL . 'assets/vendor/' . $psv['style'] ) : '',
				'i18n'        => [
					'dialog'     => __( 'Image viewer', 'slashphoto' ),
					'close'      => __( 'Close', 'slashphoto' ),
					'previous'   => __( 'Previous image', 'slashphoto' ),
					'next'       => __( 'Next image', 'slashphoto' ),
					/* translators: 1: current image number, 2: total number of images */
					'counter'    => __( '%1$d of %2$d', 'slashphoto' ),
					'loading'    => __( 'Loading…', 'slashphoto' ),
					'viewer360'  => __( '360° panorama. Drag to look around.', 'slashphoto' ),
					'noWebGL'    => __( 'Your browser cannot show 360° images. Showing the flat image instead.', 'slashphoto' ),
					'fullscreen' => __( 'Fullscreen', 'slashphoto' ),
				],
			]
		);
	}

	/**
	 * Returns the cache-busting version of one of our files: the plugin
	 * version, plus the file's modification time in development builds.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 */
	public static function version( string $relative ): string {
		if ( ! str_contains( SLASHPHOTO_VERSION, '-dev' ) ) {
			return SLASHPHOTO_VERSION;
		}
		$mtime = @filemtime( SLASHPHOTO_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file falls back to the plain version.
		return SLASHPHOTO_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	/**
	 * Returns the generated vendor manifest (see bin/vendor.mjs).
	 *
	 * @return array<string, array{package: string, version: string, license: string, module: string, style: ?string, deps: string[]}>
	 */
	public static function vendor_manifest(): array {
		static $manifest = null;
		if ( null === $manifest ) {
			$file     = SLASHPHOTO_DIR . 'assets/vendor/manifest.php';
			$manifest = is_readable( $file ) ? (array) require $file : [];
		}
		return $manifest;
	}
}
