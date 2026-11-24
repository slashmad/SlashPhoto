<?php
/**
 * The customer's private account page.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Frontend;

use SlashPhoto\Access\Access;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Selections;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\ShopPage;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/** Brings together the signed-in customer's galleries, selections and orders. */
final class AccountPage {

	public const SHORTCODE    = 'slashphoto_account';
	public const CREATE       = 'slashphoto_create_account_page';
	private const ORDER_LIMIT = 12;

	/**
	 * Whether the front-end request has been marked private before rendering.
	 *
	 * @var bool
	 */
	private bool $private_request = false;

	/**
	 * Reuses the existing rules for gallery and selection access.
	 *
	 * @param Access         $access    Gallery permissions.
	 * @param Proofing       $proofing  Selection permissions.
	 * @param TemplateLoader $templates Theme-aware template loader.
	 */
	public function __construct( private Access $access, private Proofing $proofing, private TemplateLoader $templates ) {}

	/** Registers the shortcode, response headers and page setup action. */
	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
		add_action( 'template_redirect', [ $this, 'prepare' ], 0 );
		add_action( 'admin_post_' . self::CREATE, [ $this, 'create_page' ] );
	}

	/**
	 * Checks whether an existing page can host the account shortcode.
	 *
	 * @param WP_Post|null $page Candidate page.
	 */
	public static function is_account_page( ?WP_Post $page ): bool {
		return $page && 'page' === $page->post_type && 'publish' === $page->post_status
			&& '' === $page->post_password && has_shortcode( $page->post_content, self::SHORTCODE )
			&& ! has_shortcode( $page->post_content, ShopPage::SHORTCODE );
	}

	/** Returns the configured page ID, or zero when the page is unavailable. */
	public static function page_id(): int {
		$id = (int) Settings::get( 'account_page' );
		return $id && self::is_account_page( get_post( $id ) ) ? $id : 0;
	}

	/** Returns the account URL when a page has been configured. */
	public static function url(): string {
		$id = self::page_id();
		return $id ? (string) get_permalink( $id ) : '';
	}

	/** Whether this is the customer's normal account page request. */
	public static function is_current(): bool {
		$id = self::page_id();
		return $id && is_page( $id ) && ! is_preview() && ! is_feed() && ! is_embed() && ! wp_is_json_request();
	}

	/** Keeps the page out of shared caches and search indexes. */
	public function prepare(): void {
		$this->private_request = self::is_current() && ! headers_sent();
		if ( ! $this->private_request ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared by page-cache plugins.
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * Lists published galleries explicitly assigned to the current account.
	 *
	 * @return WP_Post[]
	 */
	public function assigned_galleries(): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}
		$galleries = get_posts(
			[
				'post_type'              => PostType::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'orderby'                => [
				'date' => 'DESC',
				'ID'   => 'DESC',
					],
				'update_post_term_cache' => false,
				'meta_key'               => Access::META_MODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Gallery access mode is stored as post meta.
				'meta_value'             => Access::MODE_CLIENTS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		return array_filter(
			$galleries,
			fn( WP_Post $gallery ): bool => in_array( $user_id, Access::clients( $gallery ), true )
				&& $this->access->account_allowed( $gallery, $user_id ) && $this->access->can_view( $gallery )
		);
	}

	/** Renders account data only on the prepared, private page. */
	public function render(): string {
		if ( ! $this->private_request || ! self::is_current() ) {
			return '';
		}
		$url     = self::url();
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $this->templates->render( 'account.php', [ 'login_url' => wp_login_url( $url ) ] );
		}

		$selections = [];
		foreach ( Selections::for_user( $user_id, -1 ) as $selection ) {
			$gallery = $selection->post_parent ? get_post( $selection->post_parent ) : null;
			if ( $gallery && 'publish' === $gallery->post_status && $this->proofing->can_select( $gallery ) ) {
				$selections[] = [
					'gallery'   => $gallery,
					'submitted' => Selections::submitted_at( $selection ),
				];
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order history cursor.
		$before = isset( $_GET['sp_before_order'] ) ? absint( $_GET['sp_before_order'] ) : 0;
		$orders = Orders::for_account( $user_id, $before, self::ORDER_LIMIT + 1 );
		$next   = count( $orders ) > self::ORDER_LIMIT;
		if ( $next ) {
			array_pop( $orders );
		}
		return $this->templates->render(
			'account.php',
			[
				'logout_url' => wp_logout_url( $url ),
				'galleries'  => $this->assigned_galleries(),
				'selections' => $selections,
				'orders'     => $orders,
				'checkout'   => ShopPage::page_id() ? ShopPage::url() : '',
				'latest_url' => $before ? $url : '',
				'older_url'  => $next ? add_query_arg( 'sp_before_order', end( $orders )['id'], $url ) : '',
			]
		);
	}

	/** Creates and selects an ordinary WordPress page for the account. */
	public function create_page(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::CREATE );
		if ( ! self::page_id() ) {
			$page = get_page_by_path( 'photo-account' );
			$id   = self::is_account_page( $page ) ? $page->ID : wp_insert_post(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'My account', 'slashphoto' ),
					'post_name'    => 'photo-account',
					'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
				],
				true
			);
			if ( is_wp_error( $id ) ) {
				wp_die( esc_html( $id->get_error_message() ) );
			}
			$settings                 = Settings::all();
			$settings['account_page'] = $id;
			update_option( Settings::OPTION, $settings );
		}
		wp_safe_redirect( add_query_arg( 'tab', 'galleries', Settings::admin_url() ), 303 );
		exit;
	}
}
