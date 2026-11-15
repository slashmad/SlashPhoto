<?php
/**
 * Receipts and invoices.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Admin\OrdersPage;
use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Gateways\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * A printable page for an order: an invoice while a manual payment is
 * outstanding, a receipt once paid. Browsers print it or save it as PDF,
 * so no PDF library is bundled.
 *
 * - Customers: the order link with &receipt=1 (same key as the order).
 * - The shop: admin-post.php?action=slashphoto_receipt&order=<id> with a nonce.
 */
final class Receipts {

	public const QUERY  = 'receipt';
	public const ACTION = 'slashphoto_receipt';

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
		// Before the theme renders the shop page.
		add_action( 'template_redirect', [ $this, 'handle_customer' ], 5 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_admin' ] );
	}

	/**
	 * Customer's link to the receipt.
	 *
	 * @param array $order Order.
	 */
	public static function url( array $order ): string {
		return add_query_arg( self::QUERY, '1', Orders::url( $order ) );
	}

	/**
	 * Shop's link to the receipt.
	 *
	 * @param int $order_id Order.
	 */
	public static function admin_url( int $order_id ): string {
		return wp_nonce_url( add_query_arg( 'order', $order_id, admin_url( 'admin-post.php?action=' . self::ACTION ) ), self::ACTION . '_' . $order_id );
	}

	/**
	 * Which document an order gets: "receipt", "invoice" or "confirmation".
	 *
	 * @param array $order Order.
	 */
	public static function type( array $order ): string {
		if ( in_array( $order['status'], [ 'paid', 'completed', 'refunded' ], true ) ) {
			return 'receipt';
		}
		return 'on-hold' === $order['status'] ? 'invoice' : 'confirmation';
	}

	/**
	 * Serves the customer's receipt.
	 */
	public function handle_customer(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key is the authorisation.
		if ( ! isset( $_GET[ self::QUERY ], $_GET['order'], $_GET['key'] ) || ! ShopPage::page_id() || ! is_page( ShopPage::page_id() ) ) {
			return;
		}
		$order = Orders::get( absint( $_GET['order'] ) );
		$key   = sanitize_key( wp_unslash( $_GET['key'] ) );
		// phpcs:enable
		if ( ! $order || ! Orders::verify_key( $order, $key ) ) {
			return;
			// The shop page then says the link is not valid.
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $this->render( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
		exit;
	}

	/**
	 * Serves a receipt to the shop.
	 */
	public function handle_admin(): void {
		$id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		if ( ! current_user_can( OrdersPage::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to see orders.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		$order = Orders::get( $id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'slashphoto' ), '', [ 'response' => 404 ] );
		}
		nocache_headers();
		echo $this->render( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
		exit;
	}

	/**
	 * The receipt page.
	 *
	 * @param array $order Order.
	 */
	public function render( array $order ): string {
		$type    = self::type( $order );
		$gateway = Gateways::get( (string) $order['gateway'] );
		$titles  = [
			/* translators: %d: order number */
			'receipt'      => __( 'Receipt %d', 'slashphoto' ),
			/* translators: %d: order number */
			'invoice'      => __( 'Invoice %d', 'slashphoto' ),
			/* translators: %d: order number */
			'confirmation' => __( 'Order %d', 'slashphoto' ),
		];
		$name    = (string) Settings::get( 'business_name' );
		return $this->templates->render(
			'receipt.php',
			[
				'order'        => $order,
				'items'        => Orders::items( (int) $order['id'] ),
				'type'         => $type,
				'title'        => sprintf( $titles[ $type ], $order['id'] ),
				'seller'       => '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'details'      => (string) Settings::get( 'business_details' ),
				'note'         => (string) Settings::get( 'receipt_note' ),
				'payment'      => $gateway ? $gateway->title() : ( 'free' === $order['gateway'] ? __( 'No payment needed', 'slashphoto' ) : (string) $order['gateway'] ),
				'instructions' => 'invoice' === $type && $gateway ? $gateway->instructions( $order ) : '',
				'money'        => static fn( int $amount ): string => Money::format( $amount, (string) $order['currency'] ),
				'date'         => static fn( ?string $utc ): string => $utc ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $utc . ' UTC' ) ) : '',
			]
		);
	}
}
