<?php
/**
 * Plugin bootstrap.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

use SlashPhoto\Frontend\Assets;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Frontend\ThemeCompat;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\PostType;
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

		( new PostType() )->register_hooks();
		( new ThemeCompat( $this->templates, new GalleryRepository(), $panorama ) )->register_hooks();
		( new Assets() )->register_hooks();

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
