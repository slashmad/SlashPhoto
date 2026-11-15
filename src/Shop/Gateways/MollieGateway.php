<?php
/**
 * Mollie.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop\Gateways;

use SlashPhoto\Secrets;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\ShopPage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Payment on Mollie's hosted checkout (cards, iDEAL, Bancontact, Klarna,
 * PayPal and other methods switched on in the Mollie Dashboard). The site
 * loads no Mollie scripts.
 *
 * Mollie's webhook only sends a payment ID; the status is always read back
 * from Mollie's API with the site's key, so the call itself proves nothing
 * and needs no signature. The payment is also checked when the customer
 * returns, which is what works on sites Mollie cannot reach (localhost).
 */
final class MollieGateway implements Gateway {

	public const OPTION = 'slashphoto_mollie';
	public const API    = 'https://api.mollie.com/v2/';

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
			'/mollie/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'webhook' ],
				// The payment is read back from Mollie with our key.
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'type'    => 'string',
						'pattern' => '^tr_[A-Za-z0-9]{4,64}$',
					],
				],
			]
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'mollie';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Pay online (Mollie)', 'slashphoto' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Card, iDEAL, Bancontact and other methods on Mollie’s secure page.', 'slashphoto' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return '' !== self::api_key();
	}

	/**
	 * The API key, or ''.
	 */
	public static function api_key(): string {
		$data   = get_option( self::OPTION, [] );
		$stored = is_array( $data ) ? (string) ( $data['api_key'] ?? '' ) : '';
		return '' === $stored ? '' : Secrets::decrypt( $stored, 'mollie' );
	}

	/**
	 * "test", "live" or ''.
	 */
	public static function mode(): string {
		return preg_match( '/^(live|test)_/', self::api_key(), $m ) ? $m[1] : '';
	}

	/**
	 * Checks and stores an API key.
	 *
	 * @param string $key API key.
	 * @return true|WP_Error
	 */
	public static function save( string $key ) {
		if ( ! preg_match( '/^(live|test)_[A-Za-z0-9]{20,64}$/', $key ) ) {
			return new WP_Error( 'slashphoto_mollie_key', __( 'A Mollie API key starts with live_ or test_ (Mollie Dashboard → Developers → API keys).', 'slashphoto' ) );
		}
		$check = self::request( 'GET', 'methods', null, $key );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		update_option( self::OPTION, [ 'api_key' => Secrets::encrypt( $key, 'mollie' ) ], false );
		return true;
	}

	/**
	 * Removes the key.
	 */
	public static function disconnect(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether Mollie can reach this site's webhook.
	 */
	public static function webhook_reachable(): bool {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 'localhost' === $host || preg_match( '/\.(local|test|localhost|internal)$/', $host ) ) {
			return false;
		}
		return ! filter_var( $host, FILTER_VALIDATE_IP ) || false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Starts payment.
	 *
	 * @param array $order Order.
	 * @param array $items Order lines.
	 * @return string|WP_Error Address to send the customer to.
	 */
	public function start( array $order, array $items ) {
		$body = [
			'amount'      => [
				'currency' => strtoupper( (string) $order['currency'] ),
				'value'    => Money::to_input( (int) $order['total'], (string) $order['currency'] ),
			],
			/* translators: 1: order number, 2: site name */
			'description' => mb_substr( sprintf( __( 'Order %1$d, %2$s', 'slashphoto' ), $order['id'], wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ), 0, 255 ),
			'redirectUrl' => Orders::url( $order ),
			'cancelUrl'   => ShopPage::url(),
			'metadata'    => [ 'order_id' => (string) $order['id'] ],
		];
		if ( self::webhook_reachable() ) {
			$body['webhookUrl'] = rest_url( 'slashphoto/v1/mollie/webhook' );
		}
		unset( $items );
		$payment = self::request( 'POST', 'payments', $body, '', 'slashphoto-order-' . $order['id'] . '-' . $order['token'] );
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}
		$checkout = (string) ( $payment['_links']['checkout']['href'] ?? '' );
		if ( empty( $payment['id'] ) || ! str_starts_with( $checkout, 'https://' ) ) {
			return new WP_Error( 'slashphoto_mollie_response', __( 'Mollie sent an unexpected answer.', 'slashphoto' ) );
		}
		Orders::update( (int) $order['id'], [ 'gateway_ref' => (string) $payment['id'] ] );
		return $checkout;
	}

	/**
	 * Checks the payment when the customer comes back.
	 *
	 * @param array $order Order.
	 * @param array $query Query arguments.
	 */
	public function on_return( array $order, array $query ): array {
		unset( $query );
		if ( 'pending' === $order['status'] && '' !== (string) $order['gateway_ref'] ) {
			self::sync( $order );
		}
		return (array) Orders::get( (int) $order['id'] );
	}

	/**
	 * Updates an order from its Mollie payment.
	 *
	 * @param array $order Order.
	 */
	private static function sync( array $order ): void {
		$payment = self::request( 'GET', 'payments/' . rawurlencode( (string) $order['gateway_ref'] ) );
		if ( is_wp_error( $payment ) || ( $payment['id'] ?? '' ) !== $order['gateway_ref'] ) {
			return;
		}
		$status = (string) ( $payment['status'] ?? '' );
		if ( 'paid' === $status ) {
			Orders::mark_paid( (int) $order['id'] );
		} elseif ( in_array( $status, [ 'canceled', 'expired' ], true ) && 'pending' === $order['status'] ) {
			Orders::set_status( (int) $order['id'], 'cancelled' );
		} elseif ( 'failed' === $status && 'pending' === $order['status'] ) {
			Orders::set_status( (int) $order['id'], 'failed' );
		}
		$refunded = (int) Money::parse( (string) ( $payment['amountRefunded']['value'] ?? '0' ), (string) $order['currency'] );
		if ( $refunded > 0 && $refunded >= (int) $order['total'] && in_array( $order['status'], Orders::PAID, true ) ) {
			Orders::set_status( (int) $order['id'], 'refunded' );
		}
	}

	/**
	 * Payment text for the customer while the order waits for payment.
	 *
	 * @param array $order Order.
	 */
	public function instructions( array $order ): string {
		return 'pending' === $order['status'] ? __( 'The payment has not been confirmed by Mollie yet. If you paid, this page updates within a few minutes.', 'slashphoto' ) : '';
	}

	/**
	 * Receives Mollie's webhook: a payment ID whose status is read back.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function webhook( WP_REST_Request $request ): WP_REST_Response {
		$order = Orders::find_by_ref( 'mollie', (string) $request['id'] );
		if ( $order ) {
			self::sync( $order );
		}
		// Always 200: the answer must not reveal which payments exist.
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Calls the Mollie API.
	 *
	 * @param string     $method      HTTP method.
	 * @param string     $path        Path under /v2/.
	 * @param array|null $body        JSON body.
	 * @param string     $key         API key (default: the stored one).
	 * @param string     $idempotency Idempotency key.
	 * @return array|WP_Error
	 */
	public static function request( string $method, string $path, ?array $body = null, string $key = '', string $idempotency = '' ) {
		$key = '' !== $key ? $key : self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'slashphoto_mollie_off', __( 'Mollie is not set up.', 'slashphoto' ) );
		}
		$headers = [
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		];
		if ( '' !== $idempotency ) {
			$headers['Idempotency-Key'] = $idempotency;
		}
		$response = wp_remote_request(
			self::API . $path,
			[
				'method'  => $method,
				'headers' => $headers,
				'body'    => null === $body ? null : (string) wp_json_encode( $body ),
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'slashphoto_mollie_unreachable', __( 'Mollie could not be reached. Try again in a moment.', 'slashphoto' ) );
		}
		$code   = (int) wp_remote_retrieve_response_code( $response );
		$result = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $result ) ) {
			return new WP_Error(
				401 === $code ? 'slashphoto_mollie_auth' : 'slashphoto_mollie_error',
				/* translators: %s: message from Mollie */
				sprintf( __( 'Mollie: %s', 'slashphoto' ), is_array( $result ) && ! empty( $result['detail'] ) ? (string) $result['detail'] : __( 'the request was refused.', 'slashphoto' ) ),
				[ 'status' => $code ]
			);
		}
		return $result;
	}
}
