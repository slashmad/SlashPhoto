<?php
/**
 * Front-end assets.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Frontend;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Shop\ShopPage;

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
	public const PROOFING_MODULE = '@slashphoto/proofing';
	public const SHOP_MODULE     = '@slashphoto/shop';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		// Registered early: block.json refers to the stylesheet handle.
		add_action( 'init', [ $this, 'register_assets' ], 5 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'script_module_data_' . self::LIGHTBOX_MODULE, [ $this, 'lightbox_data' ] );
		add_filter( 'script_module_data_' . self::PROOFING_MODULE, [ $this, 'proofing_data' ] );
		add_filter( 'script_module_data_' . self::SHOP_MODULE, [ $this, 'shop_data' ] );
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

		$needed    = is_singular( PostType::POST_TYPE ) || is_post_type_archive( PostType::POST_TYPE );
		$post      = get_queried_object();
		$directory = $post instanceof \WP_Post && has_shortcode( $post->post_content, GalleryDirectory::SHORTCODE );

		/**
		 * Filters whether the SlashPhoto front-end stylesheet is enqueued.
		 *
		 * Themes that style galleries completely themselves can return false.
		 *
		 * @param bool $needed Whether the current view shows a gallery.
		 */
		if ( apply_filters( 'slashphoto_enqueue_frontend_styles', $needed || $directory ) ) {
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
			self::PROOFING_MODULE,
			SLASHPHOTO_URL . 'assets/js/proofing.js',
			[],
			self::version( 'assets/js/proofing.js' )
		);

		wp_register_script_module(
			self::SHOP_MODULE,
			SLASHPHOTO_URL . 'assets/js/shop.js',
			[],
			self::version( 'assets/js/shop.js' )
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
	 * Data passed to the proofing module. The REST nonce is only useful to
	 * the logged-in user it was made for.
	 *
	 * @param array $data Existing data.
	 * @return array
	 */
	public function proofing_data( $data ) {
		$logged_in = is_user_logged_in();
		return array_merge(
			(array) $data,
			[
				'restUrl'  => esc_url_raw( rest_url( 'slashphoto/v1/selections/' ) ),
				'nonce'    => $logged_in ? wp_create_nonce( 'wp_rest' ) : '',
				'loggedIn' => $logged_in,
				'loginUrl' => wp_login_url(),
				'i18n'     => [
					'select'        => __( 'Add to selection', 'slashphoto' ),
					'deselect'      => __( 'Remove from selection', 'slashphoto' ),
					/* translators: %d: number of selected images */
					'count'         => __( '%d selected', 'slashphoto' ),
					/* translators: 1: number of selected images, 2: maximum */
					'countMax'      => __( '%1$d of %2$d selected', 'slashphoto' ),
					/* translators: %d: number of favourites */
					'localCount'    => __( 'Favourites: %d, saved in this browser only.', 'slashphoto' ),
					'logIn'         => __( 'Log in to send them to the photographer', 'slashphoto' ),
					'showSelected'  => __( 'Show only selected', 'slashphoto' ),
					'send'          => __( 'Send selection…', 'slashphoto' ),
					'sendNow'       => __( 'Send to the photographer', 'slashphoto' ),
					'cancel'        => __( 'Cancel', 'slashphoto' ),
					'message'       => __( 'Message to the photographer (optional)', 'slashphoto' ),
					'sendHelp'      => __( 'After sending, the selection is locked. The photographer can open it again if you need to change it.', 'slashphoto' ),
					/* translators: 1: number of images, 2: date */
					'sent'          => __( 'Selection sent on %2$s (%1$d selected).', 'slashphoto' ),
					'note'          => __( 'Note', 'slashphoto' ),
					'noteLabel'     => __( 'Note for the photographer about this image', 'slashphoto' ),
					'save'          => __( 'Save', 'slashphoto' ),
					'error'         => __( 'Your selection could not be saved. Check your connection and try again.', 'slashphoto' ),
					'selectionName' => __( 'Your selection', 'slashphoto' ),
				],
			]
		);
	}

	/**
	 * Data passed to the shop module. The REST nonce lets logged-in clients
	 * order from galleries limited to their account.
	 *
	 * @param array $data Existing data.
	 * @return array
	 */
	public function shop_data( $data ) {
		$page = ShopPage::page_id();
		return array_merge(
			(array) $data,
			[
				'restUrl' => esc_url_raw( rest_url( 'slashphoto/v1/shop/' ) ),
				'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'cartUrl' => $page ? (string) get_permalink( $page ) : '',
				'i18n'    => [
					'cart'              => __( 'Cart', 'slashphoto' ),
					'buy'               => __( 'Buy this photo', 'slashphoto' ),
					/* translators: %d: number of items */
					'countOne'          => __( 'Cart: %d item', 'slashphoto' ),
					/* translators: %d: number of items */
					'count'             => __( 'Cart: %d items', 'slashphoto' ),
					'hint'              => __( 'Open a photo to order prints or downloads.', 'slashphoto' ),
					'goToCart'          => __( 'Go to cart', 'slashphoto' ),
					'add'               => __( 'Add', 'slashphoto' ),
					/* translators: %s: product name */
					'addProduct'        => __( 'Add %s to cart', 'slashphoto' ),
					/* translators: %s: product name */
					'added'             => __( 'Added: %s.', 'slashphoto' ),
					/* translators: %s: product name */
					'quantityFor'       => __( 'Quantity, %s', 'slashphoto' ),
					'printOptions'      => __( 'Paper and framing', 'slashphoto' ),
					/* translators: %s: print product name */
					'printOptionsFor'   => __( 'Paper and framing, %s', 'slashphoto' ),
					'close'             => __( 'Close', 'slashphoto' ),
					'empty'             => __( 'Your cart is empty.', 'slashphoto' ),
					'unavailable'       => __( 'Some photos or options are no longer available and were removed from the cart.', 'slashphoto' ),
					'remove'            => __( 'Remove', 'slashphoto' ),
					/* translators: %s: product and photo */
					'removeLine'        => __( 'Remove %s', 'slashphoto' ),
					'subtotal'          => __( 'Subtotal', 'slashphoto' ),
					'shipping'          => __( 'Shipping', 'slashphoto' ),
					'total'             => __( 'Total', 'slashphoto' ),
					'fillIn'            => __( 'Fill in the marked fields.', 'slashphoto' ),
					/* translators: 1: number of photos, 2: product such as "Download" */
					'packageOf'         => __( '%1$d photos you choose, each: %2$s', 'slashphoto' ),
					/* translators: %s: product such as "Download" */
					'packageAll'        => __( 'Every photo in this gallery, each: %s', 'slashphoto' ),
					'addToPackage'      => __( 'Add to package', 'slashphoto' ),
					/* translators: %s: package name */
					'addToPackageName'  => __( 'Add this photo to %s', 'slashphoto' ),
					/* translators: 1: package name, 2: photos chosen, 3: package size */
					'addedToPackage'    => __( 'Added to %1$s: %2$d of %3$d photos.', 'slashphoto' ),
					/* translators: %s: package name */
					'alreadyInPackage'  => __( 'This photo is already in %s.', 'slashphoto' ),
					/* translators: %s: product name */
					'alreadyInCart'     => __( '%s is already in your cart.', 'slashphoto' ),
					/* translators: 1: photos chosen, 2: package size */
					'packageLeft'       => __( '%1$d of %2$d photos chosen. Open more photos to add them.', 'slashphoto' ),
					/* translators: 1: photos chosen, 2: package size */
					'packageFull'       => __( '%1$d of %2$d photos chosen.', 'slashphoto' ),
					/* translators: %d: number of photos */
					'packageWhole'      => __( 'All %d photos', 'slashphoto' ),
					/* translators: %s: photo title */
					'removeFromPackage' => __( 'Remove %s from the package', 'slashphoto' ),
					/* translators: %s: discount code */
					'couponApplied'     => __( 'Code %s applied.', 'slashphoto' ),
					/* translators: %s: discount code */
					'discount'          => __( 'Discount (%s)', 'slashphoto' ),
					'giftCardApplied'   => __( 'Gift card applied.', 'slashphoto' ),
					/* translators: %s: last four characters of the gift card code */
					'giftCardRow'       => __( 'Gift card (…%s)', 'slashphoto' ),
					'due'               => __( 'To pay', 'slashphoto' ),
					'error'             => __( 'Something went wrong. Check your connection and try again.', 'slashphoto' ),
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
