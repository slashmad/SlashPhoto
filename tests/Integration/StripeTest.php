<?php
/**
 * Stripe Checkout (simulated API).
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Plugin;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Gateways\StripeGateway;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PriceLists;

final class StripeTest extends AccessTestCase {

	private const KEY    = 'sk_test_abcdefghijklmnop1234';
	private const SECRET = 'whsec_abcdefghijklmnop1234';

	/** @var array<int, array{method: string, url: string, body: array, headers: array}> */
	private array $calls = [];

	private string $payment_status = 'unpaid';

	private int $image;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', [ $this, 'fake_stripe' ], 10, 3 );
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@example.org' );
		PriceLists::register();

		$list = self::factory()->post->create(
			[
				'post_type'   => PriceLists::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		PriceLists::save_products(
			$list,
			[
				[
					'id'    => 'cccc0001',
					'kind'  => 'download',
					'name'  => 'Download',
					'price' => 25000,
				],
			]
		);
		$page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::all(),
				[
					'currency'           => 'SEK',
					'prices_include_tax' => false,
					'tax_rate'           => 25,
					'shop_page'          => $page,
				]
			)
		);
		$this->image = $this->image( $this->gallery( [], [ PriceLists::GALLERY_META => (string) $list ] ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'fake_stripe' ], 10 );
		delete_option( StripeGateway::OPTION );
		delete_option( Settings::OPTION );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function fake_stripe( $pre, $args, $url ) {
		if ( ! str_starts_with( $url, StripeGateway::API ) ) {
			return $pre;
		}
		parse_str( is_string( $args['body'] ?? null ) ? $args['body'] : '', $body );
		$this->calls[] = [
			'method'  => $args['method'],
			'url'     => $url,
			'body'    => $body,
			'headers' => $args['headers'],
		];
		$ok            = 'Bearer ' . self::KEY === ( $args['headers']['Authorization'] ?? '' );
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
		if ( ! $ok ) {
			return $json( 401, [ 'error' => [ 'message' => 'Invalid API Key provided' ] ] );
		}
		if ( 'POST' === $args['method'] && '/v1/checkout/sessions' === $path ) {
			return $json(
				200,
				[
					'id'  => 'cs_test_123',
					'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
				]
			);
		}
		if ( '/v1/checkout/sessions' === $path ) {
			return $json( 200, [ 'data' => [] ] );
		}
		if ( '/v1/checkout/sessions/cs_test_123' === $path ) {
			return $json(
				200,
				[
					'id'             => 'cs_test_123',
					'object'         => 'checkout.session',
					'status'         => 'complete',
					'payment_status' => $this->payment_status,
				]
			);
		}
		return $json( 404, [ 'error' => [ 'message' => 'No such resource' ] ] );
	}

	private function connect(): void {
		$this->assertTrue( StripeGateway::save( self::KEY, self::SECRET ) );
	}

	private function checkout(): array {
		$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/shop/orders' );
		$request->set_header( 'Content-Type', 'application/json' );
		$items = [
			[
				'image'   => $this->image,
				'product' => 'cccc0001',
			],
		];
		$request->set_body(
			(string) wp_json_encode(
				[
					'items'   => $items,
					'total'   => Plugin::instance()->shop()->quote( $items )['total'],
					'email'   => 'kim@example.org',
					'name'    => 'Kim',
					'gateway' => 'stripe',
				]
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'https://checkout.stripe.com/c/pay/cs_test_123', $response->get_data()['redirect'] );
		return (array) Orders::find_by_ref( 'stripe', 'cs_test_123' );
	}

	private function webhook( array $event, ?string $signature = null ) {
		$payload = (string) wp_json_encode( $event );
		$time    = time();
		$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/stripe/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Stripe-Signature', $signature ?? 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, self::SECRET ) );
		$request->set_body( $payload );
		return rest_get_server()->dispatch( $request );
	}

	private function event( string $type, array $order, string $payment = 'paid', string $session = 'cs_test_123' ): array {
		return [
			'type' => $type,
			'data' => [
				'object' => [
					'id'             => $session,
					'object'         => 'checkout.session',
					'payment_status' => $payment,
					'status'         => 'checkout.session.expired' === $type ? 'expired' : 'complete',
					'metadata'       => [ 'order_id' => (string) $order['id'] ],
				],
			],
		];
	}

	public function test_keys_are_checked_and_stored_encrypted(): void {
		$this->assertSame( 'slashphoto_stripe_key', StripeGateway::save( 'pk_test_abcdefghijklmnop', '' )->get_error_code() );
		$this->assertSame( 'slashphoto_stripe_auth', StripeGateway::save( 'sk_test_wrongwrongwrong123', '' )->get_error_code() );
		$this->assertSame( 'slashphoto_stripe_webhook', StripeGateway::save( '', 'not-a-secret' )->get_error_code() );
		$this->assertFalse( ( new StripeGateway() )->available() );

		$this->connect();
		$this->assertSame( 'test', StripeGateway::mode() );
		$this->assertSame( self::KEY, StripeGateway::secret_key() );
		$stored = (string) wp_json_encode( get_option( StripeGateway::OPTION ) );
		$this->assertStringNotContainsString( 'abcdefghijklmnop', $stored );
		$this->assertFalse( wp_load_alloptions()[ StripeGateway::OPTION ] ?? false, 'Not autoloaded.' );
	}

	public function test_checkout_session_carries_server_prices(): void {
		$this->connect();
		$order = $this->checkout();
		$this->assertSame( 'pending', $order['status'] );
		$this->assertSame( 31250, $order['total'] );

		$create = end( $this->calls );
		$this->assertSame( 'POST', $create['method'] );
		$this->assertSame( (string) $order['id'], $create['body']['metadata']['order_id'] );
		$this->assertSame( 'kim@example.org', $create['body']['customer_email'] );
		$this->assertSame( '25000', $create['body']['line_items'][0]['price_data']['unit_amount'] );
		$this->assertSame( 'sek', $create['body']['line_items'][0]['price_data']['currency'] );
		$this->assertSame( '6250', $create['body']['line_items'][1]['price_data']['unit_amount'], 'VAT added on top as its own line.' );
		$this->assertStringContainsString( 'session_id={CHECKOUT_SESSION_ID}', $create['body']['success_url'] );
		$this->assertStringStartsWith( 'slashphoto-order-' . $order['id'] . '-', $create['headers']['Idempotency-Key'] );
	}

	public function test_signed_webhook_marks_the_order_paid(): void {
		$this->connect();
		$order = $this->checkout();

		$this->assertSame( 400, $this->webhook( $this->event( 'checkout.session.completed', $order ), 't=' . time() . ',v1=' . str_repeat( 'a', 64 ) )->get_status() );
		$old = time() - 3600;
		$this->assertSame( 400, $this->webhook( $this->event( 'checkout.session.completed', $order ), 't=' . $old . ',v1=' . hash_hmac( 'sha256', $old . '.x', self::SECRET ) )->get_status() );
		$this->assertSame( 'pending', Orders::get( (int) $order['id'] )['status'] );

		$this->assertSame( 200, $this->webhook( $this->event( 'checkout.session.completed', $order, 'paid', 'cs_test_other' ) )->get_status() );
		$this->assertSame( 'pending', Orders::get( (int) $order['id'] )['status'], 'A session that is not the order’s own is ignored.' );

		$this->assertSame( 200, $this->webhook( $this->event( 'checkout.session.completed', $order, 'unpaid' ) )->get_status() );
		$this->assertSame( 'pending', Orders::get( (int) $order['id'] )['status'], 'Delayed methods wait for async_payment_succeeded.' );

		$this->webhook( $this->event( 'checkout.session.async_payment_succeeded', $order ) );
		$paid = Orders::get( (int) $order['id'] );
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertTrue( Orders::downloads_open( $paid ) );
	}

	public function test_expired_sessions_cancel_pending_orders(): void {
		$this->connect();
		$order = $this->checkout();
		$this->webhook( $this->event( 'checkout.session.expired', $order, 'unpaid' ) );
		$this->assertSame( 'cancelled', Orders::get( (int) $order['id'] )['status'] );
	}

	public function test_return_from_stripe_confirms_the_payment_without_webhook(): void {
		$this->connect();
		$order   = $this->checkout();
		$gateway = new StripeGateway();

		$this->assertSame( 'pending', $gateway->on_return( $order, [ 'session_id' => 'cs_test_123' ] )['status'] );
		$this->payment_status = 'paid';
		$this->assertSame( 'pending', $gateway->on_return( $order, [ 'session_id' => 'cs_forged' ] )['status'] );
		$this->assertSame( 'paid', $gateway->on_return( $order, [ 'session_id' => 'cs_test_123' ] )['status'] );
	}

	public function test_signature_check(): void {
		$payload = '{"a":1}';
		$sig     = hash_hmac( 'sha256', '1000.' . $payload, 'whsec_x' );
		$this->assertTrue( StripeGateway::verify_signature( $payload, 't=1000,v1=' . $sig, 'whsec_x', 1100 ) );
		$this->assertTrue( StripeGateway::verify_signature( $payload, 't=1000,v1=bad,v1=' . $sig, 'whsec_x', 1100 ), 'Any v1 may match during secret rotation.' );
		$this->assertFalse( StripeGateway::verify_signature( $payload, 't=1000,v1=' . $sig, 'whsec_x', 2000 ) );
		$this->assertFalse( StripeGateway::verify_signature( $payload . ' ', 't=1000,v1=' . $sig, 'whsec_x', 1100 ) );
		$this->assertFalse( StripeGateway::verify_signature( $payload, 'v1=' . $sig, 'whsec_x', 1100 ) );
	}
}
