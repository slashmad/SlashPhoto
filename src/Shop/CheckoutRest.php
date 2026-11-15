<?php
/**
 * REST API for the cart and checkout.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Settings;
use SlashPhoto\Shop\Gateways\Gateways;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /slashphoto/v1/shop/quote  { items } → prices for the cart
 * POST /slashphoto/v1/shop/orders { items, total, customer…, gateway } → { redirect }
 *
 * Open to visitors (guest checkout). Logged-in visitors send the REST nonce
 * so galleries limited to their account count as visible. Nothing is
 * stored for a quote. An order stores only what the customer typed in and
 * what they bought.
 */
final class CheckoutRest {

	public const NAMESPACE = 'slashphoto/v1';

	/**
	 * Shop rules.
	 *
	 * @var Shop
	 */
	private Shop $shop;

	/**
	 * Constructor.
	 *
	 * @param Shop $shop Shop rules.
	 */
	public function __construct( Shop $shop ) {
		$this->shop = $shop;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$items = [
			'type'     => 'array',
			'required' => true,
			'maxItems' => Shop::MAX_LINES,
			'items'    => [
				'type'       => 'object',
				'properties' => [
					'image'   => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'product' => [
						'type'    => 'string',
						'pattern' => '^[a-f0-9]{8}$',
					],
					'qty'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Shop::MAX_QTY,
					],
				],
			],
		];
		$text  = static fn( int $max, bool $required = false ): array => [
			'type'      => 'string',
			'maxLength' => $max,
			'required'  => $required,
		];

		register_rest_route(
			self::NAMESPACE,
			'/shop/quote',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'quote' ],
				'permission_callback' => '__return_true',
				'args'                => [ 'items' => $items ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/shop/orders',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_order' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'items'   => $items,
					'total'   => [
						'type'     => 'integer',
						'required' => true,
					],
					'email'   => $text( 200, true ),
					'name'    => $text( 200, true ),
					'phone'   => $text( 50 ),
					'address' => [
						'type'       => 'object',
						'properties' => [
							'line1'    => $text( 200 ),
							'line2'    => $text( 200 ),
							'postcode' => $text( 20 ),
							'city'     => $text( 100 ),
							'country'  => $text( 2 ),
						],
					],
					'note'    => $text( 2000 ),
					'gateway' => $text( 30, true ),
					'terms'   => [ 'type' => 'boolean' ],
					// Honeypot: people never see or fill this field.
					'website' => $text( 200 ),
				],
			]
		);
	}

	/**
	 * Prices the cart.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function quote( WP_REST_Request $request ): WP_REST_Response {
		$response = rest_ensure_response( self::present( $this->shop->quote( (array) $request['items'] ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Creates an order and starts payment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_order( WP_REST_Request $request ) {
		if ( '' !== trim( (string) $request['website'] ) ) {
			return new WP_Error( 'slashphoto_order_rejected', __( 'The order could not be placed.', 'slashphoto' ), [ 'status' => 400 ] );
		}

		$quote = $this->shop->quote( (array) $request['items'] );
		if ( ! $quote['lines'] ) {
			return new WP_Error( 'slashphoto_cart_empty', __( 'Your cart is empty.', 'slashphoto' ), [ 'status' => 400 ] );
		}
		if ( $quote['unavailable'] || (int) $request['total'] !== $quote['total'] ) {
			return new WP_Error(
				'slashphoto_cart_changed',
				__( 'Your cart has changed: some prices or images are no longer the same. Check it and try again.', 'slashphoto' ),
				[
					'status' => 409,
					'quote'  => self::present( $quote ),
				]
			);
		}

		$email = sanitize_email( (string) $request['email'] );
		$name  = sanitize_text_field( (string) $request['name'] );
		if ( ! is_email( $email ) ) {
			return self::invalid( 'email', __( 'Enter a valid email address.', 'slashphoto' ) );
		}
		if ( '' === $name ) {
			return self::invalid( 'name', __( 'Enter your name.', 'slashphoto' ) );
		}

		$address = [];
		if ( $quote['needs_shipping'] ) {
			$raw     = (array) $request['address'];
			$address = [
				'line1'    => sanitize_text_field( (string) ( $raw['line1'] ?? '' ) ),
				'line2'    => sanitize_text_field( (string) ( $raw['line2'] ?? '' ) ),
				'postcode' => sanitize_text_field( (string) ( $raw['postcode'] ?? '' ) ),
				'city'     => sanitize_text_field( (string) ( $raw['city'] ?? '' ) ),
				'country'  => strtoupper( sanitize_text_field( (string) ( $raw['country'] ?? '' ) ) ),
			];
			foreach ( [ 'line1', 'postcode', 'city' ] as $field ) {
				if ( '' === $address[ $field ] ) {
					return self::invalid( $field, __( 'Enter the delivery address for your prints.', 'slashphoto' ) );
				}
			}
			if ( ! in_array( $address['country'], self::countries(), true ) ) {
				return self::invalid( 'country', __( 'Prints cannot be sent to that country.', 'slashphoto' ) );
			}
		}

		if ( ShopPage::terms_page() && ! $request['terms'] ) {
			return self::invalid( 'terms', __( 'Accept the terms of purchase to place the order.', 'slashphoto' ) );
		}

		$free    = 0 === $quote['total'];
		$gateway = Gateways::available()[ (string) $request['gateway'] ] ?? null;
		if ( ! $gateway && ! $free ) {
			return self::invalid( 'gateway', __( 'Choose how to pay.', 'slashphoto' ) );
		}

		$id = Orders::create(
			[
				'user_id'       => get_current_user_id(),
				'email'         => $email,
				'name'          => $name,
				'phone'         => sanitize_text_field( (string) $request['phone'] ),
				'address'       => $address,
				'customer_note' => sanitize_textarea_field( (string) $request['note'] ),
				'gateway'       => $free ? 'free' : $gateway->id(),
			],
			$quote
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$order = (array) Orders::get( $id );

		if ( $free ) {
			Orders::mark_paid( $id );
			return rest_ensure_response( [ 'redirect' => Orders::url( $order ) ] );
		}

		$redirect = $gateway->start( $order, Orders::items( $id ) );
		if ( is_wp_error( $redirect ) ) {
			Orders::set_status( $id, 'failed' );
			$redirect->add_data( [ 'status' => 502 ] );
			return $redirect;
		}
		return rest_ensure_response( [ 'redirect' => $redirect ] );
	}

	/**
	 * Countries prints can be sent to.
	 *
	 * @return string[] ISO 3166-1 alpha-2 codes.
	 */
	public static function countries(): array {
		$codes = preg_split( '/[\s,;]+/', strtoupper( (string) Settings::get( 'shipping_countries' ) ) );
		return array_values( array_filter( (array) $codes, static fn( string $code ): bool => (bool) preg_match( '/^[A-Z]{2}$/', $code ) ) );
	}

	/**
	 * A validation error for one field.
	 *
	 * @param string $field   Field.
	 * @param string $message Message.
	 */
	private static function invalid( string $field, string $message ): WP_Error {
		return new WP_Error(
			'slashphoto_checkout_invalid',
			$message,
			[
				'status' => 400,
				'field'  => $field,
			]
		);
	}

	/**
	 * A quote with formatted amounts, for the browser.
	 *
	 * @param array $quote Quote.
	 */
	public static function present( array $quote ): array {
		$currency = $quote['currency'];
		$money    = static fn( int $amount ): string => Money::format( $amount, $currency );
		$rate     = $quote['tax_rate'];
		$percent  = number_format_i18n( $rate, floor( $rate ) === $rate ? 0 : 1 );
		return [
			'lines'         => array_map(
				static fn( array $line ): array => [
					'key'     => $line['key'],
					'image'   => $line['image'],
					'product' => $line['product'],
					'kind'    => $line['kind'],
					'name'    => $line['name'],
					'title'   => $line['title'],
					'thumb'   => $line['thumb'],
					'qty'     => $line['qty'],
					'unit'    => $money( $line['unit'] ),
					'total'   => $money( $line['total'] ),
				],
				$quote['lines']
			),
			'unavailable'   => $quote['unavailable'],
			'subtotal'      => $money( $quote['subtotal'] ),
			'shipping'      => $quote['needs_shipping'] ? $money( $quote['shipping'] ) : '',
			'tax'           => $quote['tax'] > 0 ? $money( $quote['tax'] ) : '',
			'taxLabel'      => $quote['prices_include_tax']
				/* translators: %s: tax rate */
				? sprintf( __( 'Including VAT (%s %%)', 'slashphoto' ), $percent )
				/* translators: %s: tax rate */
				: sprintf( __( 'VAT (%s %%)', 'slashphoto' ), $percent ),
			'total'         => $money( $quote['total'] ),
			'totalMinor'    => $quote['total'],
			'needsShipping' => $quote['needs_shipping'],
		];
	}
}
