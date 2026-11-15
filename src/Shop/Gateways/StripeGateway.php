<?php
/**
 * Stripe Checkout.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop\Gateways;

use SlashPhoto\Secrets;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\ShopPage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Payment on Stripe's hosted Checkout page. Customers enter card (or Swish,
 * Klarna and other methods switched on in the Stripe Dashboard) details on
 * stripe.com; this site never sees them and loads no Stripe scripts.
 *
 * The order is marked paid from a signed webhook, or when the customer
 * returns and the session is confirmed with Stripe's API, whichever comes
 * first. No platform fee is added.
 */
final class StripeGateway implements Gateway {

	public const OPTION    = 'slashphoto_stripe';
	public const API       = 'https://api.stripe.com/v1/';
	public const TOLERANCE = 300;
	public const KEY_REGEX = '/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,250}$/';

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
			'/stripe/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'webhook' ],
				// Requests are authenticated by their Stripe signature.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Address to enter in the Stripe Dashboard.
	 */
	public static function webhook_url(): string {
		return rest_url( 'slashphoto/v1/stripe/webhook' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'stripe';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Pay online', 'slashphoto' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Card and other methods. You pay on Stripe’s secure page.', 'slashphoto' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return '' !== self::secret_key();
	}

	/**
	 * Stored settings: secret_key and webhook_secret (encrypted).
	 *
	 * @return array{secret_key?: string, webhook_secret?: string}
	 */
	public static function data(): array {
		$data = get_option( self::OPTION, [] );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * The secret API key, or ''.
	 */
	public static function secret_key(): string {
		$stored = (string) ( self::data()['secret_key'] ?? '' );
		return '' === $stored ? '' : Secrets::decrypt( $stored, 'stripe' );
	}

	/**
	 * The webhook signing secret, or ''.
	 */
	public static function webhook_secret(): string {
		$stored = (string) ( self::data()['webhook_secret'] ?? '' );
		return '' === $stored ? '' : Secrets::decrypt( $stored, 'stripe' );
	}

	/**
	 * "test", "live" or '' when not configured.
	 */
	public static function mode(): string {
		$key = self::secret_key();
		return preg_match( self::KEY_REGEX, $key, $match ) ? $match[2] : '';
	}

	/**
	 * Checks and stores keys. An empty value keeps the stored one.
	 *
	 * @param string $secret_key     Secret or restricted API key.
	 * @param string $webhook_secret Webhook signing secret.
	 * @return true|WP_Error
	 */
	public static function save( string $secret_key, string $webhook_secret ) {
		$data = self::data();
		if ( '' !== $secret_key ) {
			if ( ! preg_match( self::KEY_REGEX, $secret_key ) ) {
				return new WP_Error( 'slashphoto_stripe_key', __( 'That does not look like a Stripe secret key (sk_live_…, sk_test_… or a restricted rk_… key). Publishable keys (pk_…) do not work here.', 'slashphoto' ) );
			}
			$check = self::request( 'GET', 'checkout/sessions', [ 'limit' => 1 ], $secret_key );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$data['secret_key'] = Secrets::encrypt( $secret_key, 'stripe' );
		}
		if ( '' !== $webhook_secret ) {
			if ( ! preg_match( '/^whsec_[A-Za-z0-9]{10,250}$/', $webhook_secret ) ) {
				return new WP_Error( 'slashphoto_stripe_webhook', __( 'The webhook signing secret starts with whsec_.', 'slashphoto' ) );
			}
			$data['webhook_secret'] = Secrets::encrypt( $webhook_secret, 'stripe' );
		}
		update_option( self::OPTION, $data, false );
		return true;
	}

	/**
	 * Removes the keys.
	 */
	public static function disconnect(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Starts payment for a new order.
	 *
	 * @param array $order Order.
	 * @param array $items Order lines.
	 * @return string|\WP_Error Address to send the customer to.
	 */
	public function start( array $order, array $items ) {
		$currency = strtolower( (string) $order['currency'] );
		$params   = [
			'mode'                => 'payment',
			'client_reference_id' => (string) $order['id'],
			'customer_email'      => (string) $order['email'],
			'success_url'         => Orders::url( $order ) . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'          => ShopPage::url(),
			'locale'              => 'auto',
			'metadata'            => [ 'order_id' => (string) $order['id'] ],
			'payment_intent_data' => [
				/* translators: %d: order number */
				'description' => sprintf( __( 'Order %d', 'slashphoto' ), $order['id'] ),
				'metadata'    => [ 'order_id' => (string) $order['id'] ],
			],
			'line_items'          => [],
		];

		$line = static fn( string $name, int $amount, int $qty ): array => [
			'quantity'   => $qty,
			'price_data' => [
				'currency'     => $currency,
				'unit_amount'  => $amount,
				'product_data' => [ 'name' => mb_substr( $name, 0, 250 ) ],
			],
		];
		foreach ( $items as $item ) {
			if ( $item['parent_id'] ) {
				continue;
				// Photos of a package; the package line carries the price.
			}
			$params['line_items'][] = $line( $item['name'] . ' – ' . $item['file_label'], (int) $item['unit_price'], (int) $item['quantity'] );
		}
		if ( $order['shipping'] > 0 ) {
			$params['line_items'][] = $line( __( 'Shipping', 'slashphoto' ), (int) $order['shipping'], 1 );
		}
		if ( ! $order['prices_include_tax'] && $order['tax'] > 0 ) {
			/* translators: %s: tax rate in percent */
			$params['line_items'][] = $line( sprintf( __( 'VAT %s %%', 'slashphoto' ), number_format_i18n( $order['tax_rate'], floor( $order['tax_rate'] ) === $order['tax_rate'] ? 0 : 1 ) ), (int) $order['tax'], 1 );
		}

		if ( $order['discount'] > 0 ) {
			// Line items cannot be negative: the discount becomes a one-time Stripe coupon.
			$coupon = self::request(
				'POST',
				'coupons',
				[
					'amount_off'      => (int) $order['discount'],
					'currency'        => $currency,
					'duration'        => 'once',
					'max_redemptions' => 1,
					'name'            => mb_substr( '' !== (string) $order['coupon'] ? (string) $order['coupon'] : __( 'Discount', 'slashphoto' ), 0, 40 ),
				],
				'',
				'slashphoto-coupon-' . $order['id'] . '-' . $order['token']
			);
			if ( is_wp_error( $coupon ) ) {
				return $coupon;
			}
			$params['discounts'] = [ [ 'coupon' => (string) $coupon['id'] ] ];
		}

		$session = self::request( 'POST', 'checkout/sessions', $params, '', 'slashphoto-order-' . $order['id'] . '-' . $order['token'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( empty( $session['id'] ) || empty( $session['url'] ) || ! str_starts_with( (string) $session['url'], 'https://' ) ) {
			return new WP_Error( 'slashphoto_stripe_response', __( 'Stripe sent an unexpected answer.', 'slashphoto' ) );
		}
		Orders::update( (int) $order['id'], [ 'gateway_ref' => (string) $session['id'] ] );
		return (string) $session['url'];
	}

	/**
	 * Called when the customer opens their order page.
	 *
	 * @param array $order Order.
	 * @param array $query Query arguments.
	 */
	public function on_return( array $order, array $query ): array {
		$session_id = (string) ( $query['session_id'] ?? '' );
		if ( 'pending' !== $order['status'] || '' === $session_id || $session_id !== $order['gateway_ref'] ) {
			return $order;
		}
		$session = self::request( 'GET', 'checkout/sessions/' . rawurlencode( $session_id ) );
		if ( ! is_wp_error( $session ) ) {
			self::apply( $order, $session );
		}
		return (array) Orders::get( (int) $order['id'] );
	}

	/**
	 * Payment text for the customer while the order waits for payment.
	 *
	 * @param array $order Order.
	 */
	public function instructions( array $order ): string {
		return 'pending' === $order['status'] ? __( 'The payment has not been confirmed yet. If you paid, this page updates within a few minutes.', 'slashphoto' ) : '';
	}

	/**
	 * Updates an order from a Checkout Session.
	 *
	 * @param array $order   Order.
	 * @param array $session Checkout Session.
	 */
	private static function apply( array $order, array $session ): void {
		if ( ( $session['id'] ?? '' ) !== $order['gateway_ref'] ) {
			return;
		}
		$payment = (string) ( $session['payment_status'] ?? '' );
		if ( 'paid' === $payment || 'no_payment_required' === $payment ) {
			Orders::mark_paid( (int) $order['id'] );
		} elseif ( 'expired' === ( $session['status'] ?? '' ) && 'pending' === $order['status'] ) {
			Orders::set_status( (int) $order['id'], 'cancelled' );
		}
	}

	/**
	 * Receives Stripe events.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function webhook( WP_REST_Request $request ): WP_REST_Response {
		$secret  = self::webhook_secret();
		$payload = $request->get_body();
		if ( '' === $secret || ! self::verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ), $secret ) ) {
			return new WP_REST_Response( [ 'error' => 'invalid signature' ], 400 );
		}
		$event   = json_decode( $payload, true );
		$session = is_array( $event ) ? ( $event['data']['object'] ?? null ) : null;
		if ( ! is_array( $session ) || 'checkout.session' !== ( $session['object'] ?? '' ) ) {
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}
		$order = Orders::get( (int) ( $session['metadata']['order_id'] ?? 0 ) );
		if ( ! $order || 'stripe' !== $order['gateway'] ) {
			return new WP_REST_Response( [ 'received' => true ], 200 );
		}

		switch ( (string) ( $event['type'] ?? '' ) ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
			case 'checkout.session.expired':
				self::apply( $order, $session );
				break;
			case 'checkout.session.async_payment_failed':
				if ( ( $session['id'] ?? '' ) === $order['gateway_ref'] && 'pending' === $order['status'] ) {
					Orders::set_status( (int) $order['id'], 'failed' );
				}
				break;
		}
		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	/**
	 * Checks a Stripe-Signature header ("t=…,v1=…").
	 *
	 * @param string   $payload Raw request body.
	 * @param string   $header  Header value.
	 * @param string   $secret  Signing secret.
	 * @param int|null $now     Current time (tests).
	 */
	public static function verify_signature( string $payload, string $header, string $secret, ?int $now = null ): bool {
		$time       = 0;
		$signatures = [];
		foreach ( explode( ',', $header ) as $part ) {
			[ $name, $value ] = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );
			if ( 't' === $name ) {
				$time = (int) $value;
			} elseif ( 'v1' === $name ) {
				$signatures[] = $value;
			}
		}
		if ( ! $time || ! $signatures || abs( ( $now ?? time() ) - $time ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $time . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Calls the Stripe API.
	 *
	 * @param string $method      GET or POST.
	 * @param string $path        Path under /v1/.
	 * @param array  $params      Parameters.
	 * @param string $key         API key (default: the stored one).
	 * @param string $idempotency Idempotency key for POST.
	 * @return array|WP_Error Decoded response.
	 */
	public static function request( string $method, string $path, array $params = [], string $key = '', string $idempotency = '' ) {
		$key = '' !== $key ? $key : self::secret_key();
		if ( '' === $key ) {
			return new WP_Error( 'slashphoto_stripe_off', __( 'Stripe is not set up.', 'slashphoto' ) );
		}
		$headers = [ 'Authorization' => 'Bearer ' . $key ];
		if ( '' !== $idempotency ) {
			$headers['Idempotency-Key'] = $idempotency;
		}
		$url  = self::API . $path;
		$args = [
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		];
		if ( 'GET' === $method ) {
			$url = $params ? $url . '?' . http_build_query( $params, '', '&' ) : $url;
		} else {
			$args['body'] = http_build_query( $params, '', '&' );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'slashphoto_stripe_unreachable', __( 'Stripe could not be reached. Try again in a moment.', 'slashphoto' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = is_array( $body ) && ! empty( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'Stripe refused the request.', 'slashphoto' );
			return new WP_Error(
				401 === $code ? 'slashphoto_stripe_auth' : 'slashphoto_stripe_error',
				/* translators: %s: message from Stripe */
				sprintf( __( 'Stripe: %s', 'slashphoto' ), $message ),
				[ 'status' => $code ]
			);
		}
		return $body;
	}
}
