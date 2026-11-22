<?php
/**
 * VAT by the customer's country, and the VAT report.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Admin\TaxExport;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;

final class TaxByCountryTest extends ShopTestCase {

	private function with_rates(): void {
		$this->settings(
			[
				'tax_rates' => [
					'DE' => 19,
					'NO' => 0,
				],
			]
		);
	}

	private function order( array $items, string $country = '', array $address = [] ) {
		$body = $this->order_body( $items, [ 'country' => $country ] );
		if ( $address ) {
			$body['address'] = $address + $body['address'];
		}
		$body['total'] = \SlashPhoto\Plugin::instance()->shop()->quote( $items, '', '', $address ? $address['country'] : $country )['total'];
		return $this->post( 'orders', $body );
	}

	private static function order_id( $response ): int {
		parse_str( (string) wp_parse_url( $response->get_data()['redirect'], PHP_URL_QUERY ), $query );
		return (int) $query['order'];
	}

	public function test_rates_are_parsed_from_text_and_shown_again(): void {
		$rates = Settings::tax_rates( "de 19\nFI: 25,5 %\nXX 10\nnot a rate\nSE=25, NO 0" );
		$this->assertSame(
			[
				'DE' => 19.0,
				'FI' => 25.5,
				'NO' => 0.0,
				'SE' => 25.0,
			],
			$rates
		);
		$this->assertSame( "DE 19\nFI 25.5\nNO 0\nSE 25", Settings::tax_rates_text( $rates ) );
		$this->assertSame( $rates, Settings::tax_rates( $rates ), 'Saved maps stay as they are.' );
		$this->assertSame( [ 'DE' => 100.0 ], Settings::tax_rates( [ 'de' => 150 ] ) );
		$this->assertSame( [], Settings::sanitize( [] )['tax_rates'] );
	}

	public function test_quote_uses_the_rate_of_the_country(): void {
		$this->with_rates();
		$items = [
			[
				'image'   => $this->image( $this->shop_gallery() ),
				'product' => 'aaaa0001',
			],
		];
		$shop  = \SlashPhoto\Plugin::instance()->shop();

		$german = $shop->quote( $items, '', '', 'de' );
		$this->assertSame( 19.0, $german['tax_rate'] );
		$this->assertSame( 'DE', $german['tax_country'] );
		$this->assertTrue( $german['tax_by_country'] );
		$this->assertSame( 30000, $german['total'], 'Prices that include VAT stay the same.' );
		$this->assertSame( Money::tax( 30000, 19.0, true ), $german['tax'] );

		$this->assertSame( 25.0, $shop->quote( $items, '', '', 'US' )['tax_rate'], 'Other countries use the general rate.' );
		$this->assertSame( '', $shop->quote( $items, '', '', 'ZZ' )['tax_country'] );
		$this->assertSame( 25.0, $shop->quote( $items )['tax_rate'] );

		$this->settings( [ 'prices_include_tax' => false ] );
		$net = $shop->quote( $items, '', '', 'DE' );
		$this->assertSame( 30000 + 5700, $net['total'] );
	}

	public function test_download_orders_need_the_customer_country(): void {
		$this->with_rates();
		$items = [
			[
				'image'   => $this->image( $this->shop_gallery() ),
				'product' => 'aaaa0001',
			],
		];

		$missing = $this->order( $items );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'billing_country', $missing->get_data()['data']['field'] );

		$response = $this->order( $items, 'DE' );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$order = Orders::get( self::order_id( $response ) );
		$this->assertSame( 'DE', $order['tax_country'] );
		$this->assertSame( 19.0, $order['tax_rate'] );
	}

	public function test_prints_use_the_delivery_country(): void {
		$this->with_rates();
		$items = [
			[
				'image'   => $this->image( $this->shop_gallery() ),
				'product' => 'aaaa0002',
			],
		];

		$response = $this->order(
			$items,
			'DE',
			[
				'line1'    => 'Karl Johans gate 1',
				'postcode' => '0154',
				'city'     => 'Oslo',
				'country'  => 'NO',
			]
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$order = Orders::get( self::order_id( $response ) );
		$this->assertSame( 'NO', $order['tax_country'], 'The delivery country wins over the stated country.' );
		$this->assertSame( 0, $order['tax'] );

		// A total quoted for another country is refused.
		$body          = $this->order_body( $items, [ 'country' => 'DE' ] );
		$body['total'] = $this->quote( $items )['total'] + 1;
		$this->assertSame( 409, $this->post( 'orders', $body )->get_status() );
	}

	public function test_without_rates_nothing_changes(): void {
		$items = [
			[
				'image'   => $this->image( $this->shop_gallery() ),
				'product' => 'aaaa0001',
			],
		];
		$this->assertFalse( $this->quote( $items )['tax_by_country'] );
		$this->assertSame( '', \SlashPhoto\Plugin::instance()->shop()->quote( $items, '', '', 'DE' )['tax_country'], 'No country is recorded.' );
		$order = $this->place( $items );
		$this->assertSame( 25.0, $order['tax_rate'] );
		$this->assertSame( '', $order['tax_country'] );
	}

	public function test_vat_report_lists_paid_orders_in_the_period(): void {
		$this->with_rates();
		$image = $this->image( $this->shop_gallery() );
		$items = [
			[
				'image'   => $image,
				'product' => 'aaaa0001',
			],
		];
		$paid  = self::order_id( $this->order( $items, 'DE' ) );
		$this->order( $items, 'DE' );
		Orders::mark_paid( $paid );
		$old = self::order_id( $this->order( $items, 'NO' ) );
		Orders::mark_paid( $old );
		Orders::update( $old, [ 'paid_at' => '2020-01-15 10:00:00' ] );

		$stream = fopen( 'php://memory', 'w+' );
		TaxExport::write( $stream, gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) );
		rewind( $stream );
		$rows = array_map( 'str_getcsv', array_filter( explode( "\n", (string) stream_get_contents( $stream ) ) ) );
		fclose( $stream );

		$this->assertSame( [ 'order_id', 'paid_date_utc', 'status', 'vat_country', 'vat_rate', 'currency', 'net', 'vat', 'total', 'contains', 'gift_cards_sold', 'paid_with_gift_card' ], $rows[0] );
		$this->assertCount( 2, $rows, 'Only the paid order in the period.' );
		$tax = Money::tax( 30000, 19.0, true );
		$this->assertSame( [ (string) $paid, gmdate( 'Y-m-d' ), 'paid', 'DE', '19', 'SEK', Money::to_input( 30000 - $tax, 'SEK' ), Money::to_input( $tax, 'SEK' ), '300.00', 'download', '0.00', '0.00' ], $rows[1] );

		$this->assertSame( [ '2026-07-01', '2026-09-30' ], TaxExport::previous_quarter( strtotime( '2026-11-22' ) ) );
		$this->assertSame( [ '2025-10-01', '2025-12-31' ], TaxExport::previous_quarter( strtotime( '2026-02-10' ) ) );
	}
}
