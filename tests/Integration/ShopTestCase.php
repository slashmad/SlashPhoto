<?php
/**
 * Shared fixtures for shop tests: a price list, a cart page and settings.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Plugin;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PriceLists;

abstract class ShopTestCase extends AccessTestCase {

	protected int $list;

	protected int $page;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		// The test site is "localhost", which PHPMailer rejects as a sender domain.
		add_filter( 'wp_mail_from', static fn(): string => 'wordpress@example.org' );
		PriceLists::register();

		$this->list = $this->price_list(
			'Standard',
			[
				[
					'id'    => 'aaaa0001',
					'kind'  => 'download',
					'name'  => 'Download',
					'price' => 30000,
				],
				[
					'id'    => 'aaaa0002',
					'kind'  => 'print',
					'name'  => 'Print 20 × 30 cm',
					'price' => 19900,
				],
			]
		);
		$this->page = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Cart',
				'post_content' => '[slashphoto_checkout]',
			]
		);
		$this->settings(
			[
				'currency'            => 'SEK',
				'tax_rate'            => 25,
				'prices_include_tax'  => true,
				'shipping_flat'       => 4900,
				'free_shipping_over'  => 0,
				'shipping_countries'  => 'SE, NO',
				'download_days'       => 30,
				'shop_page'           => $this->page,
				'shop_email'          => 'studio@example.org',
				'manual_enabled'      => true,
				'manual_instructions' => 'Swish 123 456 78 90',
			]
		);
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		delete_option( \SlashPhoto\Shop\Gateways\StripeGateway::OPTION );
		wp_dequeue_script_module( '@slashphoto/shop' );
		reset_phpmailer_instance();
		unset( $_GET['order'], $_GET['key'], $_GET['session_id'] );
		parent::tear_down();
	}

	protected function settings( array $values ): void {
		update_option( Settings::OPTION, array_merge( Settings::all(), $values ) );
	}

	protected function price_list( string $title, array $products ): int {
		$id = self::factory()->post->create(
			[
				'post_type'   => PriceLists::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		PriceLists::save_products( $id, $products );
		return $id;
	}

	protected function shop_gallery( array $args = [], array $meta = [] ): int {
		return $this->gallery( $args, array_merge( [ PriceLists::GALLERY_META => (string) $this->list ], $meta ) );
	}

	protected function quote( array $items ): array {
		return Plugin::instance()->shop()->quote( $items );
	}

	protected function post( string $route, array $body ) {
		$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/shop/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	protected function order_body( array $items, array $extra = [] ): array {
		return array_merge(
			[
				'items'   => $items,
				'total'   => $this->quote( $items )['total'],
				'email'   => 'kim@example.org',
				'name'    => 'Kim Client',
				'gateway' => 'manual',
				'address' => [
					'line1'    => 'Storgatan 1',
					'postcode' => '111 22',
					'city'     => 'Stockholm',
					'country'  => 'SE',
				],
			],
			$extra
		);
	}

	protected function place( array $items, array $extra = [] ): array {
		$response = $this->post( 'orders', $this->order_body( $items, $extra ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		parse_str( (string) wp_parse_url( $response->get_data()['redirect'], PHP_URL_QUERY ), $query );
		return (array) Orders::get( (int) $query['order'] ) + [ 'key' => $query['key'] ];
	}
}
