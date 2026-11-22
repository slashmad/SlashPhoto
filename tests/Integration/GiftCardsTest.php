<?php
/**
 * Gift cards: selling, issuing, redeeming and returning balances.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Plugin;
use SlashPhoto\Settings;
use SlashPhoto\Shop\GiftCards;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\Schema;

final class GiftCardsTest extends ShopTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings(
			[
				'gift_cards'        => true,
				'gift_card_amounts' => [ 50000, 100000 ],
				'gift_card_months'  => 12,
			]
		);
	}

	private function download(): array {
		return [
			'image'   => $this->image( $this->shop_gallery() ),
			'product' => 'aaaa0001',
		];
	}

	private function shop_quote( array $items, string $gift = '' ): array {
		return Plugin::instance()->shop()->quote( $items, '', '', '', $gift );
	}

	private function order_with( array $items, string $gift ) {
		$body = $this->order_body( $items, [ 'gift_card' => $gift ] );
		return $this->post( 'orders', $body );
	}

	private static function id_of( $response ): int {
		parse_str( (string) wp_parse_url( $response->get_data()['redirect'], PHP_URL_QUERY ), $query );
		return (int) $query['order'];
	}

	/** Buys two cards of 500 and returns their codes. */
	private function buy_cards(): array {
		$order = $this->place(
			[
				[
					'giftcard' => 50000,
					'qty'      => 2,
				],
			]
		);
		$this->assertSame( [], GiftCards::for_order( (int) $order['id'] ), 'Nothing is issued before payment.' );
		reset_phpmailer_instance();
		$this->assertTrue( Orders::mark_paid( (int) $order['id'] ) );
		return [ $order, array_column( GiftCards::codes( Orders::get( (int) $order['id'] ) ), 'code' ) ];
	}

	public function test_amounts_setting_is_parsed(): void {
		$this->assertSame( [ 25050, 50000, 100000 ], Settings::amounts( '500, 1000 250.50 x 500', 'SEK' ) );
		$this->assertSame( [ 1000 ], Settings::amounts( [ 1000, -5, 0 ], 'SEK' ) );
		$this->assertSame( [ 500 ], Settings::amounts( '500', 'JPY' ) );
	}

	public function test_gift_cards_are_vouchers_without_vat_or_shipping(): void {
		$quote = $this->shop_quote(
			[
				[
					'giftcard' => 50000,
					'qty'      => 2,
				],
				$this->download(),
			]
		);
		$card  = array_values( array_filter( $quote['lines'], static fn( array $line ): bool => GiftCards::KIND === $line['kind'] ) )[0];
		$this->assertSame( 100000, $card['total'] );
		$this->assertSame( 130000, $quote['total'] );
		$this->assertSame( Money::tax( 30000, 25.0, true ), $quote['tax'], 'VAT only on the photo.' );
		$this->assertFalse( $quote['needs_shipping'] );

		$this->assertSame( [ 'gc:12300' ], $this->shop_quote( [ [ 'giftcard' => 12300 ] ] )['unavailable'] );
		$this->settings( [ 'gift_cards' => false ] );
		$this->assertSame( [ 'gc:50000' ], $this->shop_quote( [ [ 'giftcard' => 50000 ] ] )['unavailable'] );
	}

	public function test_paid_orders_issue_codes_that_are_not_stored(): void {
		[ $order, $codes ] = $this->buy_cards();
		$this->assertCount( 2, $codes );
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/', $codes[0] );
		$this->assertNotSame( $codes[0], $codes[1] );

		$mailer   = tests_retrieve_phpmailer_instance();
		$customer = $mailer->get_sent( 0 );
		$owner    = $mailer->get_sent( 1 );
		$this->assertSame( 'kim@example.org', $customer->to[0][0] );
		$this->assertStringContainsString( $codes[0], $customer->body );
		$this->assertStringContainsString( $codes[1], $customer->body );
		$this->assertStringNotContainsString( $codes[0], $owner->body, 'Only the buyer gets the codes.' );

		global $wpdb;
		$dump = (string) wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Schema::gift_cards(), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( $codes as $code ) {
			$this->assertStringNotContainsString( GiftCards::normalize( $code ), $dump );
		}

		Orders::set_status( (int) $order['id'], 'completed' );
		$this->assertCount( 2, GiftCards::for_order( (int) $order['id'] ), 'Issued once.' );
		$this->assertSame( 50000, GiftCards::find( strtolower( str_replace( '-', ' ', $codes[0] ) ) )['balance'] );
	}

	public function test_codes_pay_for_orders_and_balances_come_back(): void {
		[ , $codes ] = $this->buy_cards();
		$code        = $codes[0];

		// 300 paid fully: no payment needed.
		$quote = $this->shop_quote( [ $this->download() ], $code );
		$this->assertSame( 30000, $quote['gift_card']['amount'] );
		$this->assertSame( 0, $quote['due'] );
		$first = $this->order_with( [ $this->download() ], $code );
		$this->assertSame( 200, $first->get_status(), (string) wp_json_encode( $first->get_data() ) );
		$first = Orders::get( self::id_of( $first ) );
		$this->assertSame( 'paid', $first['status'] );
		$this->assertSame( 30000, $first['gift_card_amount'] );
		$this->assertSame( substr( GiftCards::normalize( $code ), -4 ), $first['gift_card_hint'] );
		$this->assertSame( 20000, GiftCards::find( $code )['balance'] );

		// The remaining 200 pays part of the next order; the rest is invoiced.
		$second = Orders::get( self::id_of( $this->order_with( [ $this->download() ], $code ) ) );
		$this->assertSame( 'on-hold', $second['status'] );
		$this->assertSame( 20000, $second['gift_card_amount'] );
		$this->assertSame( 10000, GiftCards::due( $second ) );
		$this->assertSame( 0, GiftCards::find( $code )['balance'] );
		$this->assertSame( 'This gift card has been used up.', $this->shop_quote( [ $this->download() ], $code )['gift_card']['error'] );
		$refused = $this->order_with( [ $this->download() ], $code );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'gift_card', $refused->get_data()['data']['field'] );

		// Cancelling an order gives its part back.
		Orders::set_status( (int) $second['id'], 'cancelled' );
		$this->assertSame( 20000, GiftCards::find( $code )['balance'] );
		$this->assertFalse( GiftCards::reserve( GiftCards::find( $code )['id'], 999999, 20001 ), 'Never more than the balance.' );
	}

	public function test_limits(): void {
		[ $order, $codes ] = $this->buy_cards();

		$this->assertSame( 'Gift cards cannot be paid for with a gift card.', $this->shop_quote( [ [ 'giftcard' => 50000 ] ], $codes[0] )['gift_card']['error'] );
		$this->assertSame( 'This gift card code is not valid.', $this->shop_quote( [ $this->download() ], 'AAAA-BBBB-CCCC-DDDD' )['gift_card']['error'] );

		// Refunding the order that bought the cards voids them.
		Orders::set_status( (int) $order['id'], 'refunded' );
		$this->assertSame( 'This gift card can no longer be used.', $this->shop_quote( [ $this->download() ], $codes[1] )['gift_card']['error'] );

		// Cards made by hand; expiry.
		$code = GiftCards::create( 10000 );
		$this->assertMatchesRegularExpression( '/^\w{4}-\w{4}-\w{4}-\w{4}$/', $code );
		$card = GiftCards::find( $code );
		$this->assertSame( 0, $card['order_id'] );
		$this->assertNotNull( $card['expires_at'] );
		global $wpdb;
		$wpdb->update( Schema::gift_cards(), [ 'expires_at' => '2020-01-01 00:00:00' ], [ 'id' => $card['id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'This gift card has expired.', $this->shop_quote( [ $this->download() ], $code )['gift_card']['error'] );
		$this->assertSame( '', GiftCards::create( 0 ) );
	}
}
