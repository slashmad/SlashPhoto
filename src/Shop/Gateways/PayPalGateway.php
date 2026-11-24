<?php
/**
 * PayPal Checkout.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop\Gateways;

use SlashPhoto\Secrets;
use SlashPhoto\Shop\GiftCards;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\ShopPage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Payment through PayPal's Orders API: the customer approves the payment on
 * paypal.com (PayPal balance, cards, Pay Later where PayPal offers it) and
 * comes back to the order page, where the payment is captured. No PayPal
 * script is loaded on the site. A verified webhook (optional) captures and
 * confirms payments when the customer does not come back.
 */
final class PayPalGateway implements Gateway {

	public const OPTION  = 'slashphoto_paypal';
	public const LIVE    = 'https://api-m.paypal.com';
	public const SANDBOX = 'https://api-m.sandbox.paypal.com';

	/**
	 * Registers the webhook route.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Registers the webhook endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			'slashphoto/v1',
			'/paypal/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'webhook' ],
				// Verified with PayPal's API before anything is changed.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Address to enter at developer.paypal.com.
	 */
	public static function webhook_url(): string {
		return rest_url( 'slashphoto/v1/paypal/webhook' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'paypal';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'PayPal', 'slashphoto' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Pay with your PayPal account or a card on PayPal’s page.', 'slashphoto' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		$data = self::data();
		return '' !== (string) ( $data['client_id'] ?? '' ) && '' !== self::secret();
	}

	/**
	 * Stored settings: client_id, secret (encrypted), sandbox, webhook_id.
	 *
	 * @return array
	 */
	public static function data(): array {
		$data = get_option( self::OPTION, [] );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * The client secret, or ''.
	 */
	private static function secret(): string {
		$stored = (string) ( self::data()['secret'] ?? '' );
		return '' === $stored ? '' : Secrets::decrypt( $stored, 'paypal' );
	}

	/**
	 * Whether the sandbox is used.
	 */
	public static function sandbox(): bool {
		return ! empty( self::data()['sandbox'] );
	}

	/**
	 * Checks and stores the credentials. An empty secret keeps the stored one.
	 *
	 * @param string $client_id  Client ID.
	 * @param string $secret     Secret.
	 * @param bool   $sandbox    Sandbox.
	 * @param string $webhook_id Webhook ID (optional).
	 * @return true|WP_Error
	 */
	public static function save( string $client_id, string $secret, bool $sandbox, string $webhook_id ) {
		$data   = self::data();
		$secret = '' !== $secret ? $secret : self::secret();
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{20,200}$/', $client_id ) || ! preg_match( '/^[A-Za-z0-9_\-]{20,200}$/', $secret ) ) {
			return new WP_Error( 'slashphoto_paypal_credentials', __( 'Enter the client ID and secret of a PayPal REST app (developer.paypal.com → Apps & Credentials).', 'slashphoto' ) );
		}
		if ( '' !== $webhook_id && ! preg_match( '/^[A-Z0-9]{10,40}$/', $webhook_id ) ) {
			return new WP_Error( 'slashphoto_paypal_webhook', __( 'The webhook ID is shown next to the webhook in your PayPal app.', 'slashphoto' ) );
		}
		delete_transient( self::token_key( $client_id, $sandbox ) );
		$token = self::token( $client_id, $secret, $sandbox );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		update_option(
			self::OPTION,
			array_merge(
				$data,
				[
					'client_id'  => $client_id,
					'secret'     => Secrets::encrypt( $secret, 'paypal' ),
					'sandbox'    => $sandbox,
					'webhook_id' => $webhook_id,
				]
			),
			false
		);
		return true;
	}

	/**
	 * Removes the credentials.
	 */
	public static function disconnect(): void {
		$data = self::data();
		delete_transient( self::token_key( (string) ( $data['client_id'] ?? '' ), self::sandbox() ) );
		delete_option( self::OPTION );
	}

	/**
	 * Amount as PayPal expects it.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency.
	 */
	private static function money( int $amount, string $currency ): array {
		return [
			'currency_code' => strtoupper( $currency ),
			'value'         => Money::to_input( $amount, $currency ),
		];
	}

	/**
	 * Starts payment.
	 *
	 * @param array $order Order.
	 * @param array $items Order lines.
	 * @return string|WP_Error Address to send the customer to.
	 */
	public function start( array $order, array $items ) {
		$currency = (string) $order['currency'];
		$lines    = [];
		$sum      = 0;
		foreach ( $items as $item ) {
			if ( $item['parent_id'] ) {
				continue;
			}
			$lines[] = [
				'name'        => mb_substr( $item['name'] . ( '' !== $item['file_label'] ? ' – ' . $item['file_label'] : '' ), 0, 127 ),
				'quantity'    => (string) $item['quantity'],
				'unit_amount' => self::money( (int) $item['unit_price'], $currency ),
				'category'    => 'print' === $item['kind'] ? 'PHYSICAL_GOODS' : 'DIGITAL_GOODS',
			];
			$sum    += (int) $item['unit_price'] * (int) $item['quantity'];
		}
		$breakdown = [
			'item_total' => self::money( $sum, $currency ),
			'shipping'   => self::money( (int) $order['shipping'], $currency ),
		];
		if ( ! $order['prices_include_tax'] && $order['tax'] > 0 ) {
			$breakdown['tax_total'] = self::money( (int) $order['tax'], $currency );
		}
		// A gift card pays like a discount as far as PayPal is concerned.
		$reduction = (int) $order['discount'] + (int) $order['gift_card_amount'];
		if ( $reduction > 0 ) {
			$breakdown['discount'] = self::money( $reduction, $currency );
		}
		$body   = [
			'intent'         => 'CAPTURE',
			'purchase_units' => [
				[
					'reference_id' => (string) $order['id'],
					'custom_id'    => (string) $order['id'],
					'invoice_id'   => 'slashphoto-' . $order['id'] . '-' . substr( (string) $order['token'], 0, 8 ),
					/* translators: %d: order number */
					'description'  => sprintf( __( 'Order %d', 'slashphoto' ), $order['id'] ),
					'amount'       => array_merge( self::money( GiftCards::due( $order ), $currency ), [ 'breakdown' => $breakdown ] ),
					'items'        => $lines,
				],
			],
			'payment_source' => [
				'paypal' => [
					'experience_context' => [
						'brand_name'          => mb_substr( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), 0, 127 ),
						'user_action'         => 'PAY_NOW',
						'shipping_preference' => 'NO_SHIPPING',
						'return_url'          => Orders::url( $order ),
						'cancel_url'          => ShopPage::url(),
					],
				],
			],
		];
		$result = self::request( 'POST', '/v2/checkout/orders', $body, 'slashphoto-order-' . $order['id'] . '-' . $order['token'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$approve = '';
		foreach ( (array) ( $result['links'] ?? [] ) as $link ) {
			if ( in_array( $link['rel'] ?? '', [ 'payer-action', 'approve' ], true ) ) {
				$approve = (string) $link['href'];
			}
		}
		if ( empty( $result['id'] ) || ! str_starts_with( $approve, 'https://' ) ) {
			return new WP_Error( 'slashphoto_paypal_response', __( 'PayPal sent an unexpected answer.', 'slashphoto' ) );
		}
		Orders::update( (int) $order['id'], [ 'gateway_ref' => (string) $result['id'] ] );
		return $approve;
	}

	/**
	 * Captures the payment when the customer comes back from PayPal.
	 *
	 * @param array $order Order.
	 * @param array $query Query arguments ("token" is PayPal's order ID).
	 */
	public function on_return( array $order, array $query ): array {
		$token = (string) ( $query['token'] ?? '' );
		if ( 'pending' === $order['status'] && '' !== $token && $token === $order['gateway_ref'] ) {
			self::capture( $order );
		}
		return (array) Orders::get( (int) $order['id'] );
	}

	/**
	 * Captures an approved PayPal order and marks ours paid.
	 *
	 * @param array $order Order.
	 */
	private static function capture( array $order ): void {
		$ref    = rawurlencode( (string) $order['gateway_ref'] );
		$result = self::request( 'POST', '/v2/checkout/orders/' . $ref . '/capture', null, 'slashphoto-capture-' . $order['id'] . '-' . $order['token'] );
		if ( is_wp_error( $result ) && 422 === (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			// Already captured (for example by the webhook): read the order.
			$result = self::request( 'GET', '/v2/checkout/orders/' . $ref );
		}
		if ( is_wp_error( $result ) ) {
			return;
		}
		$capture = $result['purchase_units'][0]['payments']['captures'][0] ?? [];
		if ( 'COMPLETED' === ( $result['status'] ?? '' ) && 'COMPLETED' === ( $capture['status'] ?? '' ) ) {
			Orders::mark_paid( (int) $order['id'] );
		} elseif ( 'DECLINED' === ( $capture['status'] ?? '' ) ) {
			Orders::set_status( (int) $order['id'], 'failed' );
		}
	}

	/**
	 * Payment text for the customer while the order waits for payment.
	 *
	 * @param array $order Order.
	 */
	public function instructions( array $order ): string {
		return 'pending' === $order['status'] ? __( 'The payment has not been confirmed by PayPal yet. If you approved it, this page updates within a few minutes.', 'slashphoto' ) : '';
	}

	/**
	 * Receives PayPal events, verified through PayPal's API.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function webhook( WP_REST_Request $request ): WP_REST_Response {
		$webhook_id = (string) ( self::data()['webhook_id'] ?? '' );
		$event      = json_decode( $request->get_body(), true );
		if ( '' === $webhook_id || ! is_array( $event ) ) {
			return new WP_REST_Response( [ 'error' => 'not configured' ], 400 );
		}
		$check = self::request(
			'POST',
			'/v1/notifications/verify-webhook-signature',
			[
				'auth_algo'         => (string) $request->get_header( 'paypal_auth_algo' ),
				'cert_url'          => (string) $request->get_header( 'paypal_cert_url' ),
				'transmission_id'   => (string) $request->get_header( 'paypal_transmission_id' ),
				'transmission_sig'  => (string) $request->get_header( 'paypal_transmission_sig' ),
				'transmission_time' => (string) $request->get_header( 'paypal_transmission_time' ),
				'webhook_id'        => $webhook_id,
				'webhook_event'     => $event,
			]
		);
		if ( is_wp_error( $check ) || 'SUCCESS' !== ( $check['verification_status'] ?? '' ) ) {
			return new WP_REST_Response( [ 'error' => 'invalid signature' ], 400 );
		}

		$resource = (array) ( $event['resource'] ?? [] );
		$ref      = 'CHECKOUT.ORDER.APPROVED' === ( $event['event_type'] ?? '' )
			? (string) ( $resource['id'] ?? '' )
			: (string) ( $resource['supplementary_data']['related_ids']['order_id'] ?? '' );
		$order    = Orders::find_by_ref( 'paypal', $ref );
		if ( ! $order ) {
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}
		switch ( (string) ( $event['event_type'] ?? '' ) ) {
			case 'CHECKOUT.ORDER.APPROVED':
				if ( 'pending' === $order['status'] ) {
					self::capture( $order );
				}
				break;
			case 'PAYMENT.CAPTURE.COMPLETED':
				Orders::mark_paid( (int) $order['id'] );
				break;
			case 'PAYMENT.CAPTURE.DENIED':
				if ( 'pending' === $order['status'] ) {
					Orders::set_status( (int) $order['id'], 'failed' );
				}
				break;
			case 'PAYMENT.CAPTURE.REFUNDED':
				$money = $resource['seller_payable_breakdown']['total_refunded_amount'] ?? $resource['amount'] ?? [];
				$due   = GiftCards::due( $order );
				if ( $due > 0 && hash_equals( $order['currency'], (string) ( $money['currency_code'] ?? '' ) ) && Money::parse( (string) ( $money['value'] ?? '' ), $order['currency'] ) >= $due ) {
					Orders::set_status( (int) $order['id'], 'refunded' );
				}
				break;
		}//end switch
		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	/**
	 * Transient name for the access token.
	 *
	 * @param string $client_id Client ID.
	 * @param bool   $sandbox   Sandbox.
	 */
	private static function token_key( string $client_id, bool $sandbox ): string {
		return 'slashphoto_paypal_token_' . md5( $client_id . '|' . ( $sandbox ? 'sandbox' : 'live' ) );
	}

	/**
	 * An OAuth access token, cached until shortly before it expires.
	 *
	 * @param string $client_id Client ID.
	 * @param string $secret    Secret.
	 * @param bool   $sandbox   Sandbox.
	 * @return string|WP_Error
	 */
	private static function token( string $client_id, string $secret, bool $sandbox ) {
		$key    = self::token_key( $client_id, $sandbox );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_post(
			( $sandbox ? self::SANDBOX : self::LIVE ) . '/v1/oauth2/token',
			[
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => 'grant_type=client_credentials',
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'slashphoto_paypal_unreachable', __( 'PayPal could not be reached. Try again in a moment.', 'slashphoto' ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'slashphoto_paypal_auth', __( 'PayPal did not accept the client ID and secret. Check that they belong to the chosen environment (sandbox or live).', 'slashphoto' ) );
		}
		set_transient( $key, (string) $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
		return (string) $body['access_token'];
	}

	/**
	 * Calls the PayPal API.
	 *
	 * @param string     $method     HTTP method.
	 * @param string     $path       Path.
	 * @param array|null $body       JSON body.
	 * @param string     $request_id PayPal-Request-Id for idempotent POSTs.
	 * @return array|WP_Error
	 */
	public static function request( string $method, string $path, ?array $body = null, string $request_id = '' ) {
		$data  = self::data();
		$token = self::token( (string) ( $data['client_id'] ?? '' ), self::secret(), self::sandbox() );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = [
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		];
		if ( '' !== $request_id ) {
			$headers['PayPal-Request-Id'] = $request_id;
		}
		$response = wp_remote_request(
			( self::sandbox() ? self::SANDBOX : self::LIVE ) . $path,
			[
				'method'  => $method,
				'headers' => $headers,
				'body'    => null === $body ? ( 'POST' === $method ? '{}' : null ) : (string) wp_json_encode( $body ),
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'slashphoto_paypal_unreachable', __( 'PayPal could not be reached. Try again in a moment.', 'slashphoto' ) );
		}
		$code   = (int) wp_remote_retrieve_response_code( $response );
		$result = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $result ) ) {
			$message = is_array( $result ) ? (string) ( $result['details'][0]['description'] ?? $result['message'] ?? '' ) : '';
			return new WP_Error(
				'slashphoto_paypal_error',
				/* translators: %s: message from PayPal */
				sprintf( __( 'PayPal: %s', 'slashphoto' ), '' !== $message ? $message : __( 'the request was refused.', 'slashphoto' ) ),
				[ 'status' => $code ]
			);
		}
		return $result;
	}
}
