<?php
/**
 * Plugin bootstrap.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\AccessGuard;
use SlashPhoto\Access\Terms;
use SlashPhoto\Admin\AccessSettings;
use SlashPhoto\Blocks\GalleryBlock;
use SlashPhoto\Frontend\Assets;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Frontend\ThemeCompat;
use SlashPhoto\Gallery\GalleryMeta;
use SlashPhoto\Gallery\GalleryRenderer;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Gallery\Uploads;
use SlashPhoto\Media\Panorama;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's services into WordPress.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Template loader shared by all front-end services.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Access rules.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Gallery renderer.
	 *
	 * @var GalleryRenderer
	 */
	private GalleryRenderer $renderer;

	/**
	 * Gallery block.
	 *
	 * @var GalleryBlock
	 */
	private GalleryBlock $gallery_block;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all hooks. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted    = true;
		$this->templates = new TemplateLoader();

		add_action( 'init', [ $this, 'load_textdomain' ] );

		$panorama = new Panorama();
		$panorama->register_hooks();

		$this->access = new Access();
		$terms        = new Terms( $this->access );
		$terms->register_hooks();
		( new AccessGuard( $this->access, $this->templates ) )->register_hooks();

		( new PostType() )->register_hooks();
		( new GalleryMeta() )->register_hooks();
		( new Uploads() )->register_hooks();

		$this->renderer = new GalleryRenderer( $this->templates, new GalleryRepository(), $panorama, $this->access, $terms );
		( new ThemeCompat( $this->renderer ) )->register_hooks();
		( new Assets() )->register_hooks();

		$this->gallery_block = new GalleryBlock( $this->renderer, $this->access, $this->templates );
		$this->gallery_block->register_hooks();

		( new AccessSettings() )->register_hooks();
		( new Privacy() )->register_hooks();

		/**
		 * Fires after SlashPhoto has registered its hooks.
		 *
		 * Extensions (payment gateways, print labs, importers) should hook in here.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'slashphoto_loaded', $this );
	}

	/**
	 * Returns the template loader.
	 */
	public function templates(): TemplateLoader {
		return $this->templates;
	}

	/**
	 * Returns the access rules.
	 */
	public function access(): Access {
		return $this->access;
	}

	/**
	 * Returns the gallery renderer.
	 */
	public function renderer(): GalleryRenderer {
		return $this->renderer;
	}

	/**
	 * Returns the gallery block.
	 */
	public function gallery_block(): GalleryBlock {
		return $this->gallery_block;
	}

	/**
	 * Loads bundled translations. Translations from translate.wordpress.org
	 * in wp-content/languages take precedence.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'slashphoto', false, dirname( plugin_basename( SLASHPHOTO_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: register rewrite rules for the post types, then flush them.
	 */
	public static function activate(): void {
		PostType::register();
		GalleryMeta::ensure_role();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation: remove our rewrite rules. Content is never deleted here.
	 */
	public static function deactivate(): void {
		unregister_post_type( PostType::POST_TYPE );
		flush_rewrite_rules();
	}
}
