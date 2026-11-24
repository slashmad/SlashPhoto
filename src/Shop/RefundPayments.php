<?php
/**
 * Requests and retrieves refunds through the existing payment clients.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Shop\Gateways\MollieGateway;
use SlashPhoto\Shop\Gateways\PayPalGateway;
use SlashPhoto\Shop\Gateways\StripeGateway;

defined( 'ABSPATH' ) || exit;

/** Maps provider responses to the refund status kept with an order. */
final class RefundPayments {

	/**
	 * Whether the payment has a reference in a supported gateway.
	 *
	 * @param array $order Order.
	 */
	public static function supported( array $order ): bool {
		return ! empty( $order['gateway_ref'] ) && in_array( $order['gateway'], [ 'stripe', 'paypal', 'mollie' ], true );
	}

	/**
	 * Starts a refund or fetches the result of a previously accepted refund.
	 *
	 * @param array $order  Order.
	 * @param array $refund Refund record with its stable idempotency key.
	 * @return array{status: string, reference: string}
	 */
	public static function fetch( array $order, array $refund ): array {
		$payment   = rawurlencode( $order['gateway_ref'] );
		$reference = $refund['reference'];
		$retrieve  = '' !== $reference;
		$path      = rawurlencode( $reference );
		$currency  = (string) $order['currency'];
		$amount    = (int) $refund['amount'];
		$value     = Money::to_input( $amount, $currency );
		$key       = $refund['request_key'];

		$reply = match ( $order['gateway'] ) {
			'stripe' => $retrieve ? StripeGateway::request( 'GET', 'refunds/' . $path ) : self::stripe( $payment, $amount, $key ),
			'paypal' => $retrieve ? PayPalGateway::request( 'GET', '/v2/payments/refunds/' . $path ) : self::paypal( $payment, $value, $currency, $key ),
			'mollie' => $retrieve ? MollieGateway::request( 'GET', 'payments/' . $payment . '/refunds/' . $path ) : MollieGateway::request(
				'POST',
				'payments/' . $payment . '/refunds',
				[
					'amount'   => [
						'currency' => $currency,
						'value'    => $value,
					],
					'metadata' => [ 'slashphoto_refund' => $key ],
				],
				'',
				$key
			),
			default => null,
		};
		if ( null === $reply ) {
			// The original payment could not be resolved; no refund was requested.
			return [
				'status'    => 'failed',
				'reference' => $reference,
			];
		}
		if ( is_wp_error( $reply ) || ! self::matches( $reply, $reference, $amount, $currency ) ) {
			return [
				'status'    => 'review',
				'reference' => $reference,
			];
		}
		return [
			'reference' => $reply['id'],
			'status'    => match ( strtolower( (string) ( $reply['status'] ?? '' ) ) ) {
				'succeeded', 'completed', 'refunded' => 'completed',
				'pending', 'queued', 'processing', 'requires_action' => 'pending',
				'failed', 'canceled', 'cancelled' => 'failed',
				default => 'review',
			},
		];
	}

	/**
	 * Verifies the returned refund ID, currency and amount.
	 *
	 * @param array  $reply     Provider response.
	 * @param string $reference Known refund ID when retrieving.
	 * @param int    $amount    Expected in minor units.
	 * @param string $currency  Order currency.
	 */
	private static function matches( array $reply, string $reference, int $amount, string $currency ): bool {
		$id = $reply['id'] ?? '';
		if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $id ) || ( '' !== $reference && $reference !== $id ) ) {
			return false;
		}
		if ( is_array( $reply['amount'] ?? null ) ) {
			$money = $reply['amount'];
			return hash_equals( $currency, (string) ( $money['currency_code'] ?? $money['currency'] ?? '' ) ) && Money::parse( (string) ( $money['value'] ?? '' ), $currency ) === $amount;
		}
		return hash_equals( $currency, strtoupper( (string) ( $reply['currency'] ?? '' ) ) ) && ( $reply['amount'] ?? -1 ) === $amount;
	}

	/**
	 * Resolves the session's payment intent before requesting a refund.
	 *
	 * @param string $payment Checkout session ID.
	 * @param int    $amount  Amount in minor units.
	 * @param string $key     Idempotency key.
	 */
	private static function stripe( string $payment, int $amount, string $key ) {
		$session = StripeGateway::request( 'GET', 'checkout/sessions/' . $payment );
		$intent  = ! is_wp_error( $session ) && hash_equals( $payment, (string) ( $session['id'] ?? '' ) ) && 'paid' === ( $session['payment_status'] ?? '' ) ? (string) ( $session['payment_intent'] ?? '' ) : '';
		if ( ! preg_match( '/^pi_[a-zA-Z0-9]+$/D', $intent ) ) {
			return null;
		}
		return StripeGateway::request(
			'POST',
			'refunds',
			[
				'payment_intent' => $intent,
				'amount'         => $amount,
			],
			'',
			$key
		);
	}

	/**
	 * Resolves the PayPal order's single capture before requesting a refund.
	 *
	 * @param string $payment  PayPal order ID.
	 * @param string $value    Amount in major units.
	 * @param string $currency Currency.
	 * @param string $key      Idempotency key.
	 */
	private static function paypal( string $payment, string $value, string $currency, string $key ) {
		$order = PayPalGateway::request( 'GET', '/v2/checkout/orders/' . $payment );
		$units = ! is_wp_error( $order ) && hash_equals( $payment, (string) ( $order['id'] ?? '' ) ) ? (array) ( $order['purchase_units'] ?? [] ) : [];
		$items = 1 === count( $units ) ? (array) ( $units[0]['payments']['captures'] ?? [] ) : [];
		$id    = 1 === count( $items ) && in_array( $items[0]['status'] ?? '', [ 'COMPLETED', 'PARTIALLY_REFUNDED' ], true ) ? (string) ( $items[0]['id'] ?? '' ) : '';
		if ( ! preg_match( '/^[a-zA-Z0-9-]+$/D', $id ) ) {
			return null;
		}
		return PayPalGateway::request(
			'POST',
			'/v2/payments/captures/' . $id . '/refund',
			[
				'amount'    => [
					'value'         => $value,
					'currency_code' => $currency,
				],
				'custom_id' => $key,
			],
			$key
		);
	}
}
