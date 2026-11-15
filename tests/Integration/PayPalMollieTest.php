<?php
/**
 * PayPal and Mollie gateways (simulated APIs).
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Plugin;
use SlashPhoto\Shop\Gateways\MollieGateway;
use SlashPhoto\Shop\Gateways\PayPalGateway;
use SlashPhoto\Shop\Orders;

final class PayPalMollieTest extends ShopTestCase {

	private const CLIENT = 'AbCdEfGhIjKlMnOpQrStUvWx123';
	private const SECRET = 'EfGhIjKlMnOpQrStUvWxYz987654';
	private const MOLLIE = 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM';

	/** @var array<int, array{method: string, url: string, body: mixed, headers: array}> */
	private array $calls = [];

	private string $capture = 'COMPLETED';

	private string $mollie_status = 'open';

	private string $verified = 'SUCCESS';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', [ $this, 'fake' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'fake' ], 10 );
		PayPalGateway::disconnect();
		MollieGateway::disconnect();
		parent::tear_down();
	}

	public function fake( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, [ 'api-m.sandbox.paypal.com', 'api-m.paypal.com', 'api.mollie.com' ], true ) ) {
			return $pre;
		}
		$body          = is_string( $args['body'] ?? null ) ? ( json_decode( $args['body'], true ) ?? $args['body'] ) : null;
		$this->calls[] = [
			'method'  => $args['method'] ?? 'GET',
			'url'     => $url,
			'body'    => $body,
			'headers' => $args['headers'] ?? [],
		];
		$path          = (string) wp_parse_url( $url, PHP_URL_PATH );
		$json          = static fn( int $code, array $data ): array => [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $data ),
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];
		$auth          = (string) ( $args['headers']['Authorization'] ?? '' );

		if ( 'api.mollie.com' === $host ) {
			if ( 'Bearer ' . self::MOLLIE !== $auth ) {
				return $json( 401, [ 'detail' => 'Missing authentication, or failed to authenticate' ] );
			}
			if ( '/v2/methods' === $path ) {
				return $json( 200, [ 'count' => 1 ] );
			}
			if ( '/v2/payments' === $path ) {
				return $json(
					201,
					[
						'id'     => 'tr_WDqYK6vllg',
						'_links' => [ 'checkout' => [ 'href' => 'https://www.mollie.com/checkout/select-method/WDqYK6vllg' ] ],
					]
				);
			}
			if ( '/v2/payments/tr_WDqYK6vllg' === $path ) {
				return $json(
					200,
					[
						'id'     => 'tr_WDqYK6vllg',
						'status' => $this->mollie_status,
					]
				);
			}
			return $json( 404, [ 'detail' => 'Not found' ] );
		}

		if ( '/v1/oauth2/token' === $path ) {
			$ok = 'Basic ' . base64_encode( self::CLIENT . ':' . self::SECRET ) === $auth; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return $ok ? $json(
				200,
				[
					'access_token' => 'A21AAtoken',
					'expires_in'   => 32400,
				]
			) : $json( 401, [ 'error' => 'invalid_client' ] );
		}
		if ( 'Bearer A21AAtoken' !== $auth ) {
			return $json( 401, [ 'message' => 'unauthorized' ] );
		}
		if ( '/v2/checkout/orders' === $path ) {
			return $json(
				200,
				[
					'id'     => '5O190127TN364715T',
					'status' => 'PAYER_ACTION_REQUIRED',
					'links'  => [
						[
							'rel'  => 'payer-action',
							'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T',
						],
					],
				]
			);
		}
		if ( '/v2/checkout/orders/5O190127TN364715T/capture' === $path ) {
			return $json(
				201,
				[
					'id'             => '5O190127TN364715T',
					'status'         => 'COMPLETED',
					'purchase_units' => [ [ 'payments' => [ 'captures' => [ [ 'status' => $this->capture ] ] ] ] ],
				]
			);
		}
		if ( '/v1/notifications/verify-webhook-signature' === $path ) {
			return $json( 200, [ 'verification_status' => $this->verified ] );
		}
		return $json( 404, [ 'message' => 'not found' ] );
	}

	private function checkout( string $gateway, array $items ): array {
		$response = $this->post( 'orders', $this->order_body( $items, [ 'gateway' => $gateway ] ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$order = Orders::query( '', '', 1, 1 )['rows'][0];
		$this->assertSame( $gateway, $order['gateway'] );
		$this->assertSame( 'paypal' === $gateway ? '5O190127TN364715T' : 'tr_WDqYK6vllg', $order['gateway_ref'] );
		return [ $response->get_data()['redirect'], $order ];
	}

	public function test_paypal_credentials_are_checked_and_stored_encrypted(): void {
		$this->assertSame( 'slashphoto_paypal_credentials', PayPalGateway::save( 'short', 'x', true, '' )->get_error_code() );
		$this->assertSame( 'slashphoto_paypal_auth', PayPalGateway::save( self::CLIENT, 'WrongSecretWrongSecret12', true, '' )->get_error_code() );
		$this->assertTrue( PayPalGateway::save( self::CLIENT, self::SECRET, true, '' ) );
		$this->assertTrue( ( new PayPalGateway() )->available() );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( get_option( PayPalGateway::OPTION ) ) );
		$this->assertTrue( PayPalGateway::save( self::CLIENT, '', true, '' ), 'An empty secret keeps the stored one.' );
	}

	public function test_paypal_order_breakdown_and_capture_on_return(): void {
		PayPalGateway::save( self::CLIENT, self::SECRET, true, '' );
		$image                = $this->image( $this->shop_gallery() );
		[ $redirect, $order ] = $this->checkout(
			'paypal',
			[
				[
					'image'   => $image,
					'product' => 'aaaa0002',
					'qty'     => 2,
				],
			]
		);
		$this->assertStringStartsWith( 'https://www.sandbox.paypal.com/checkoutnow', $redirect );

		$create = array_values( array_filter( $this->calls, static fn( array $call ): bool => str_ends_with( $call['url'], '/v2/checkout/orders' ) ) )[0];
		$unit   = $create['body']['purchase_units'][0];
		$this->assertSame( '447.00', $unit['amount']['value'], '2 × 199 + 49 shipping.' );
		$this->assertSame( '398.00', $unit['amount']['breakdown']['item_total']['value'] );
		$this->assertSame( '49.00', $unit['amount']['breakdown']['shipping']['value'] );
		$this->assertSame( 'SEK', $unit['amount']['currency_code'] );
		$this->assertSame( 'PHYSICAL_GOODS', $unit['items'][0]['category'] );
		$this->assertSame( 'NO_SHIPPING', $create['body']['payment_source']['paypal']['experience_context']['shipping_preference'] );
		$this->assertStringStartsWith( 'slashphoto-order-' . $order['id'], $create['headers']['PayPal-Request-Id'] );

		$gateway = new PayPalGateway();
		$this->assertSame( 'pending', $gateway->on_return( $order, [ 'token' => 'FORGED' ] )['status'] );
		$this->assertSame( 'paid', $gateway->on_return( $order, [ 'token' => '5O190127TN364715T' ] )['status'] );
	}

	public function test_paypal_declined_capture_and_verified_webhook(): void {
		PayPalGateway::save( self::CLIENT, self::SECRET, true, 'WH1234567890ABCDEF' );
		$image          = $this->image( $this->shop_gallery() );
		[ , $order ]    = $this->checkout(
			'paypal',
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		$event          = [
			'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
			'resource'   => [ 'supplementary_data' => [ 'related_ids' => [ 'order_id' => '5O190127TN364715T' ] ] ],
		];
		$send           = function ( array $event ) {
			$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/paypal/webhook' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'PayPal-Transmission-Id', 'abc' );
			$request->set_body( (string) wp_json_encode( $event ) );
			return rest_get_server()->dispatch( $request );
		};
		$this->verified = 'FAILURE';
		$this->assertSame( 400, $send( $event )->get_status() );
		$this->assertSame( 'pending', Orders::get( (int) $order['id'] )['status'] );

		$this->verified = 'SUCCESS';
		$this->assertSame( 200, $send( $event )->get_status() );
		$this->assertSame( 'paid', Orders::get( (int) $order['id'] )['status'] );
		$verify = array_values( array_filter( $this->calls, static fn( array $call ): bool => str_contains( $call['url'], 'verify-webhook-signature' ) ) )[0];
		$this->assertSame( 'WH1234567890ABCDEF', $verify['body']['webhook_id'] );
		$this->assertSame( 'abc', $verify['body']['transmission_id'] );

		[ , $declined ] = $this->checkout(
			'paypal',
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		$this->capture  = 'DECLINED';
		$this->assertSame( 'failed', ( new PayPalGateway() )->on_return( $declined, [ 'token' => '5O190127TN364715T' ] )['status'] );
	}

	public function test_mollie_payment_return_and_webhook(): void {
		$this->assertSame( 'slashphoto_mollie_key', MollieGateway::save( 'sk_live_nope' )->get_error_code() );
		$this->assertSame( 'slashphoto_mollie_auth', MollieGateway::save( 'test_wrongwrongwrongwrongwrong12' )->get_error_code() );
		$this->assertTrue( MollieGateway::save( self::MOLLIE ) );
		$this->assertSame( 'test', MollieGateway::mode() );
		$this->assertStringNotContainsString( self::MOLLIE, (string) wp_json_encode( get_option( MollieGateway::OPTION ) ) );

		$image                = $this->image( $this->shop_gallery() );
		[ $redirect, $order ] = $this->checkout(
			'mollie',
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		$this->assertStringStartsWith( 'https://www.mollie.com/checkout/', $redirect );
		$create = array_values( array_filter( $this->calls, static fn( array $call ): bool => str_ends_with( $call['url'], '/v2/payments' ) ) )[0];
		$this->assertSame( '300.00', $create['body']['amount']['value'] );
		$this->assertSame( (string) $order['id'], $create['body']['metadata']['order_id'] );
		$this->assertArrayNotHasKey( 'webhookUrl', $create['body'], 'The test site (localhost) is not reachable for Mollie.' );

		$gateway = new MollieGateway();
		$this->assertSame( 'pending', $gateway->on_return( $order, [] )['status'] );

		$this->mollie_status = 'paid';
		$request             = new \WP_REST_Request( 'POST', '/slashphoto/v1/mollie/webhook' );
		$request->set_param( 'id', 'tr_WDqYK6vllg' );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'paid', Orders::get( (int) $order['id'] )['status'] );

		$unknown = new \WP_REST_Request( 'POST', '/slashphoto/v1/mollie/webhook' );
		$unknown->set_param( 'id', 'tr_unknown123' );
		$this->assertSame( 200, rest_get_server()->dispatch( $unknown )->get_status(), 'Unknown payments look the same.' );
	}

	public function test_all_gateways_are_offered_at_checkout(): void {
		PayPalGateway::save( self::CLIENT, self::SECRET, true, '' );
		MollieGateway::save( self::MOLLIE );
		$html = ( new \SlashPhoto\Shop\ShopPage( Plugin::instance()->templates() ) )->render();
		foreach ( [ 'paypal', 'mollie', 'manual' ] as $id ) {
			$this->assertStringContainsString( 'value="' . $id . '"', $html );
		}
	}
}
