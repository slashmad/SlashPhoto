<?php
/**
 * Checkout and order pages.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Frontend\Assets;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Gateways\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * The [slashphoto_checkout] shortcode, placed on an ordinary page so the
 * theme renders it like any other content. It shows the cart and checkout
 * form, or, with ?order=…&key=…, the customer's order with its downloads.
 *
 * Order views are never cached, are not indexed and send no referrer, so
 * the key in the address does not leak to other sites.
 */
final class ShopPage {

	public const SHORTCODE = 'slashphoto_checkout';

	public const GIFT_CARDS = 'slashphoto_gift_cards';
	public const CREATE     = 'slashphoto_create_shop_page';

	/**
	 * Templates.
	 *
	 * @var TemplateLoader
	 */
	private TemplateLoader $templates;

	/**
	 * Constructor.
	 *
	 * @param TemplateLoader $templates Templates.
	 */
	public function __construct( TemplateLoader $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render' ] );
		add_shortcode( self::GIFT_CARDS, [ $this, 'gift_cards' ] );
		add_action( 'template_redirect', [ $this, 'headers' ] );
		add_filter( 'wp_robots', [ $this, 'robots' ] );
		add_action( 'admin_post_' . self::CREATE, [ $this, 'create_page' ] );
	}

	/**
	 * The page with the shortcode, or 0.
	 */
	public static function page_id(): int {
		$id = (int) Settings::get( 'shop_page' );
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/**
	 * Address of the checkout page.
	 */
	public static function url(): string {
		$id = self::page_id();
		return $id ? (string) get_permalink( $id ) : home_url( '/' );
	}

	/**
	 * The page with the terms of purchase, or 0.
	 */
	public static function terms_page(): int {
		$id = (int) Settings::get( 'shop_terms_page' );
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/**
	 * Whether the current request shows the shop page.
	 */
	private static function is_shop_page(): bool {
		$id = self::page_id();
		return $id && is_page( $id );
	}

	/**
	 * No caching and no referrer on the shop page.
	 */
	public function headers(): void {
		if ( ! self::is_shop_page() ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * Keeps the shop page out of search engines.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public function robots( $robots ) {
		if ( self::is_shop_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Renders the shortcode.
	 */
	public function render(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view; the key is the authorisation.
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		if ( $order_id || '' !== $key ) {
			$query = [
				'session_id' => isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '',
				'token'      => isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '',
			];
			return $this->order_view( $order_id, $key, $query );
		}
		// phpcs:enable

		wp_enqueue_style( Assets::STYLE_HANDLE );
		wp_enqueue_script_module( Assets::SHOP_MODULE );
		$gateways = [];
		foreach ( Gateways::available() as $gateway ) {
			$gateways[] = [
				'id'          => $gateway->id(),
				'title'       => $gateway->title(),
				'description' => $gateway->description(),
			];
		}
		$countries = CheckoutRest::countries();
		return $this->templates->render(
			'checkout.php',
			[
				'gateways'   => $gateways,
				'countries'  => $countries,
				// Countries with their own VAT rate first, then the rest.
				'residence'  => Settings::get( 'tax_rates' ) ? Countries::options( array_values( array_unique( array_merge( $countries, array_keys( (array) Settings::get( 'tax_rates' ) ) ) ) ) ) : [],
				'terms_page' => self::terms_page(),
				'login_url'  => wp_login_url( self::url() ),
			]
		);
	}

	/**
	 * Gift cards for sale ([slashphoto_gift_cards]); empty when not sold.
	 */
	public function gift_cards(): string {
		if ( ! GiftCards::enabled() ) {
			return '';
		}
		wp_enqueue_style( Assets::STYLE_HANDLE );
		wp_enqueue_script_module( Assets::SHOP_MODULE );
		$currency = (string) Settings::get( 'currency' );
		$months   = (int) Settings::get( 'gift_card_months' );
		return $this->templates->render(
			'gift-cards.php',
			[
				'cards'    => array_map(
					static fn( int $amount ): array => [
						'amount' => $amount,
						'label'  => Money::format( $amount, $currency ),
						'name'   => GiftCards::name( $amount, $currency ),
					],
					GiftCards::amounts()
				),
				'months'   => $months,
				'cart_url' => self::page_id() ? self::url() : '',
			]
		);
	}

	/**
	 * Renders an order for its customer.
	 *
	 * @param int    $order_id Order.
	 * @param string $key      Key from the link.
	 * @param array  $query    Query arguments for the gateway.
	 */
	private function order_view( int $order_id, string $key, array $query ): string {
		wp_enqueue_style( Assets::STYLE_HANDLE );
		$order = $order_id ? Orders::get( $order_id ) : null;
		if ( ! $order || ! Orders::verify_key( $order, $key ) ) {
			return $this->templates->render( 'order.php', [ 'order' => null ] );
		}
		$gateway = Gateways::get( (string) $order['gateway'] );
		if ( $gateway ) {
			$order = $gateway->on_return( $order, $query );
		}
		$items = Orders::items( (int) $order['id'] );
		$open  = Orders::downloads_open( $order );
		foreach ( $items as &$item ) {
			$item['download'] = $open && 'download' === $item['kind'] ? Downloads::url( $order, $item ) : '';
		}
		unset( $item );

		return $this->templates->render(
			'order.php',
			[
				'order'        => $order,
				'items'        => $items,
				'status'       => Orders::labels()[ $order['status'] ] ?? $order['status'],
				'instructions' => $gateway && in_array( $order['status'], [ 'pending', 'on-hold' ], true ) ? $gateway->instructions( $order ) : '',
				'money'        => static fn( int $amount ): string => Money::format( $amount, (string) $order['currency'] ),
				'has_download' => in_array( 'download', array_column( $items, 'kind' ), true ),
				'open'         => $open,
			]
		);
	}

	/**
	 * Creates the checkout page from the settings screen.
	 */
	public function create_page(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::CREATE );
		$id = wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Cart', 'slashphoto' ),
				'post_name'    => 'photo-cart',
				'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
			]
		);
		if ( $id && ! is_wp_error( $id ) ) {
			$settings              = Settings::all();
			$settings['shop_page'] = (int) $id;
			update_option( Settings::OPTION, $settings );
		}
		wp_safe_redirect( Settings::admin_url( '', 'slashphoto-shop' ) );
		exit;
	}
}
