<?php
/**
 * Plugin bootstrap.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\AccessGuard;
use SlashPhoto\Access\EmailGate;
use SlashPhoto\Access\ShareLinks;
use SlashPhoto\Access\Terms;
use SlashPhoto\Admin\AccessSettings;
use SlashPhoto\Admin\DonationsPage;
use SlashPhoto\Admin\GalleryList;
use SlashPhoto\Admin\GalleryOverview;
use SlashPhoto\Admin\GalleryInvitations;
use SlashPhoto\Admin\GalleryWorkspace;
use SlashPhoto\Admin\ImportPage;
use SlashPhoto\Admin\OrdersPage;
use SlashPhoto\Admin\SelectionsBox;
use SlashPhoto\Admin\SelectionsPage;
use SlashPhoto\Admin\ShopSettings;
use SlashPhoto\Admin\SnapOtterSettings;
use SlashPhoto\Admin\UploadPanel;
use SlashPhoto\Admin\VisitorsPage;
use SlashPhoto\Admin\SettingsPage;
use SlashPhoto\Blocks\GalleryBlock;
use SlashPhoto\Frontend\Assets;
use SlashPhoto\Frontend\GalleryDirectory;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Frontend\ThemeCompat;
use SlashPhoto\Gallery\GalleryMeta;
use SlashPhoto\Gallery\GalleryRenderer;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Gallery\ChunkedUploads;
use SlashPhoto\Gallery\Importer;
use SlashPhoto\Gallery\Uploads;
use SlashPhoto\Media\DisplayCopy;
use SlashPhoto\Media\Originals;
use SlashPhoto\Media\Panorama;
use SlashPhoto\Media\Watermark;
use SlashPhoto\Proofing\Notifications;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Selections;
use SlashPhoto\Proofing\SelectionsRest;
use SlashPhoto\Shop\CheckoutRest;
use SlashPhoto\Shop\Coupons;
use SlashPhoto\Shop\Downloads;
use SlashPhoto\Shop\DownloadSizes;
use SlashPhoto\Shop\Emails;
use SlashPhoto\Shop\Gateways\MollieGateway;
use SlashPhoto\Shop\Gateways\PayPalGateway;
use SlashPhoto\Shop\Gateways\StripeGateway;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\Receipts;
use SlashPhoto\Shop\Schema;
use SlashPhoto\Shop\Shop;
use SlashPhoto\Shop\ShopPage;
use SlashPhoto\Visitors\Stats;
use SlashPhoto\Visitors\VisitorLog;

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
	 * Proofing rules.
	 *
	 * @var Proofing
	 */
	private Proofing $proofing;

	/**
	 * Shop rules.
	 *
	 * @var Shop
	 */
	private Shop $shop;

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
		$terms        = new Terms( $this->access, new EmailGate( $this->access ) );
		$terms->register_hooks();
		$terms->email_gate()->register_hooks();
		( new VisitorLog() )->register_hooks();
		( new Stats( $this->access ) )->register_hooks();
		( new AccessGuard( $this->access, $this->templates ) )->register_hooks();
		( new ShareLinks() )->register_hooks();
		( new DisplayCopy() )->register_hooks();
		( new Originals() )->register_hooks();
		( new Watermark( $this->access ) )->register_hooks();

		$this->proofing = new Proofing( $this->access, $terms );
		( new Selections() )->register_hooks();
		( new SelectionsRest( $this->proofing ) )->register_hooks();
		( new Notifications( $this->access ) )->register_hooks();

		( new PostType() )->register_hooks();
		( new GalleryMeta() )->register_hooks();
		( new Uploads() )->register_hooks();
		( new Importer() )->register_hooks();
		( new ChunkedUploads() )->register_hooks();

		$this->shop = new Shop( $this->access, $terms );
		( new Schema() )->register_hooks();
		( new PriceLists() )->register_hooks();
		( new Coupons() )->register_hooks();
		( new Orders() )->register_hooks();
		( new CheckoutRest( $this->shop ) )->register_hooks();
		( new ShopPage( $this->templates ) )->register_hooks();
		( new Downloads() )->register_hooks();
		( new DownloadSizes() )->register_hooks();
		( new Receipts( $this->templates ) )->register_hooks();
		( new Emails() )->register_hooks();
		( new StripeGateway() )->register_hooks();
		( new PayPalGateway() )->register_hooks();
		( new MollieGateway() )->register_hooks();

		$galleries      = new GalleryRepository();
		$this->renderer = new GalleryRenderer( $this->templates, $galleries, $panorama, $this->access, $terms, $this->proofing, $this->shop );
		( new ThemeCompat( $this->renderer ) )->register_hooks();
		( new GalleryDirectory( $this->access, $galleries, $this->templates ) )->register_hooks();
		( new Assets() )->register_hooks();

		$this->gallery_block = new GalleryBlock( $this->renderer, $this->access, $this->templates );
		$this->gallery_block->register_hooks();

		$access_settings = new AccessSettings();
		$access_settings->register_hooks();
		$gallery_list = new GalleryList( $this->access );
		$gallery_list->register_hooks();
		( new GalleryOverview( $gallery_list, $this->access ) )->register_hooks();
		$selections = new SelectionsBox( $this->proofing );
		$selections->register_hooks();
		( new SelectionsPage( $this->proofing ) )->register_hooks();
		$upload = new UploadPanel();
		$upload->register_hooks();
		$invitations = new GalleryInvitations( $this->access );
		$invitations->register_hooks();
		( new GalleryWorkspace( $this->access, new GalleryRepository(), $access_settings, $selections, $upload, $invitations ) )->register_hooks();
		( new SnapOtterSettings() )->register_hooks();
		( new SettingsPage() )->register_hooks();
		( new ShopSettings() )->register_hooks();
		( new OrdersPage() )->register_hooks();
		( new ImportPage() )->register_hooks();
		( new VisitorsPage() )->register_hooks();
		( new DonationsPage() )->register_hooks();
		( new Privacy() )->register_hooks();

		/**
		 * Fires after SlashPhoto has registered its hooks.
		 *
		 * Extensions (payment gateways via slashphoto_gateways, print labs, importers) should hook in here.
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
	 * Returns the proofing rules.
	 */
	public function proofing(): Proofing {
		return $this->proofing;
	}

	/**
	 * Returns the shop rules.
	 */
	public function shop(): Shop {
		return $this->shop;
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
		PriceLists::register();
		Coupons::register();
		GalleryMeta::ensure_role();
		Schema::install();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation: remove our rewrite rules. Content is never deleted here.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( ChunkedUploads::CRON );
		wp_clear_scheduled_hook( Orders::CRON );
		wp_clear_scheduled_hook( VisitorLog::CRON );
		unregister_post_type( PostType::POST_TYPE );
		flush_rewrite_rules();
	}
}
