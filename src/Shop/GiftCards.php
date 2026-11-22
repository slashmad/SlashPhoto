<?php
/**
 * Gift cards: sold in the shop, issued when paid, redeemed at checkout.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * - Customers buy a gift card of one of the amounts in the shop settings.
 *   It is a voucher, not a sale: no VAT, no discount codes, no shipping.
 *   VAT is charged on what it is spent on.
 * - When the order is paid, one card per gift card bought is issued. Its
 *   code is derived from the order with the site's salt (like the order
 *   key) and is never stored: the database only has an HMAC to look it up,
 *   so a copy of the database does not reveal usable codes. The buyer sees
 *   the codes on the order page and in the payment email.
 * - The photographer can also create a card under SlashPhoto → Gift cards;
 *   its code is shown once.
 * - At checkout a code pays for (part of) an order. Each use is recorded
 *   with the order; uses by cancelled, failed, refunded or deleted orders
 *   no longer count, so the balance comes back. A database lock keeps two
 *   orders from spending the same balance at once.
 * - Refunding or cancelling the order that bought a card voids it.
 */
final class GiftCards {

	public const KIND = 'giftcard';

	/** Order statuses whose gift card uses no longer count. */
	private const RELEASED = [ 'cancelled', 'failed', 'refunded' ];

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		// Before the status emails (10), so the payment email lists the codes.
		add_action( 'slashphoto_order_status', [ $this, 'on_status' ], 5, 3 );
	}

	/**
	 * Whether gift cards are sold.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'gift_cards' ) && (bool) self::amounts();
	}

	/**
	 * Whether checkout offers a gift card code field: when cards are sold or
	 * any usable card exists (for example one made by hand).
	 */
	public static function accepted(): bool {
		if ( self::enabled() ) {
			return true;
		}
		global $wpdb;
		$table = Schema::gift_cards();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE status = 'active' AND ( expires_at IS NULL OR expires_at > %s ) LIMIT 1", gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Amounts offered, in minor units.
	 *
	 * @return int[]
	 */
	public static function amounts(): array {
		return array_values( array_filter( array_map( 'intval', (array) Settings::get( 'gift_card_amounts' ) ), static fn( int $amount ): bool => $amount > 0 ) );
	}

	/**
	 * Name of a gift card product.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency.
	 */
	public static function name( int $amount, string $currency ): string {
		/* translators: %s: amount */
		return sprintf( __( 'Gift card %s', 'slashphoto' ), Money::format( $amount, $currency ) );
	}

	/**
	 * Normalises a typed code: letters and digits only, upper case.
	 *
	 * @param string $code Typed code.
	 */
	public static function normalize( string $code ): string {
		return (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $code ) );
	}

	/**
	 * Lookup hash of a code.
	 *
	 * @param string $code Code (any format).
	 */
	private static function hash( string $code ): string {
		return hash_hmac( 'sha256', 'giftcard|' . self::normalize( $code ), wp_salt( 'auth' ) );
	}

	/**
	 * Formats 80 bits as "XXXX-XXXX-XXXX-XXXX" in Crockford base32 (no I, L, O, U).
	 *
	 * @param string $bytes At least 10 bytes.
	 */
	private static function format( string $bytes ): string {
		$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
		$bits     = '';
		foreach ( str_split( substr( $bytes, 0, 10 ) ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		$code = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$code .= $alphabet[ bindec( $chunk ) ];
		}
		return implode( '-', str_split( $code, 4 ) );
	}

	/**
	 * The code of a card bought with an order (derived, never stored).
	 *
	 * @param int $order_id Order.
	 * @param int $item_id  Order line.
	 * @param int $number   Card number within the line (quantity).
	 */
	public static function code_for( int $order_id, int $item_id, int $number ): string {
		return self::format( hash_hmac( 'sha256', "giftcard-order|{$order_id}|{$item_id}|{$number}", wp_salt( 'auth' ), true ) );
	}

	/**
	 * Expiry for a card issued now, or null for none.
	 */
	private static function expiry(): ?string {
		$months = (int) Settings::get( 'gift_card_months' );
		return $months > 0 ? gmdate( 'Y-m-d 23:59:59', (int) strtotime( '+' . $months . ' months' ) ) : null;
	}

	/**
	 * Stores a card.
	 *
	 * @param string $code     Code.
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency.
	 * @param int    $order_id Order that bought it (0 for a card made by hand).
	 * @param int    $item_id  Order line.
	 * @param int    $number   Number within the line.
	 * @return int Card ID, 0 on failure.
	 */
	private static function insert( string $code, int $amount, string $currency, int $order_id, int $item_id, int $number ): int {
		global $wpdb;
		$plain = self::normalize( $code );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert(
			Schema::gift_cards(),
			[
				'code_hash'  => self::hash( $code ),
				'hint'       => substr( $plain, -4 ),
				'order_id'   => $order_id,
				'item_id'    => $item_id,
				'number'     => $number,
				'amount'     => $amount,
				'currency'   => strtoupper( $currency ),
				'status'     => 'active',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => self::expiry(),
			]
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Creates a card by hand. The code is returned once and not stored.
	 *
	 * @param int $amount Minor units.
	 * @return string Code, or '' on failure.
	 */
	public static function create( int $amount ): string {
		if ( $amount <= 0 ) {
			return '';
		}
		$code = self::format( random_bytes( 10 ) );
		return self::insert( $code, $amount, (string) Settings::get( 'currency' ), 0, 0, 0 ) ? $code : '';
	}

	/**
	 * Issues the cards of a paid order (once).
	 *
	 * @param int $order_id Order.
	 */
	public static function issue( int $order_id ): void {
		$order = Orders::get( $order_id );
		if ( ! $order || self::for_order( $order_id ) ) {
			return;
		}
		foreach ( Orders::items( $order_id ) as $item ) {
			if ( self::KIND !== $item['kind'] ) {
				continue;
			}
			for ( $number = 1; $number <= $item['quantity']; $number++ ) {
				self::insert( self::code_for( $order_id, $item['id'], $number ), $item['unit_price'], (string) $order['currency'], $order_id, $item['id'], $number );
			}
		}
	}

	/**
	 * Issues cards when an order is paid and voids them when it is cancelled
	 * or refunded.
	 *
	 * @param int    $id     Order.
	 * @param string $status New status.
	 * @param string $old    Previous status.
	 */
	public function on_status( $id, $status, $old ): void {
		unset( $old );
		if ( in_array( $status, Orders::PAID, true ) ) {
			self::issue( (int) $id );
		} elseif ( in_array( $status, [ 'cancelled', 'refunded' ], true ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->update( Schema::gift_cards(), [ 'status' => 'void' ], [ 'order_id' => (int) $id ] );
		}
	}

	/**
	 * Voids or reactivates a card.
	 *
	 * @param int  $card_id Card.
	 * @param bool $active  Whether it can be used.
	 */
	public static function set_active( int $card_id, bool $active ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return false !== $wpdb->update( Schema::gift_cards(), [ 'status' => $active ? 'active' : 'void' ], [ 'id' => $card_id ] );
	}

	/**
	 * Cards bought with an order.
	 *
	 * @param int $order_id Order.
	 * @return array[]
	 */
	public static function for_order( int $order_id ): array {
		global $wpdb;
		$table = Schema::gift_cards();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id", $order_id ), ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], $rows );
	}

	/**
	 * The codes of an order's cards, for its buyer.
	 *
	 * @param array $order Order.
	 * @return array<int, array{code: string, card: array}>
	 */
	public static function codes( array $order ): array {
		$codes = [];
		foreach ( self::for_order( (int) $order['id'] ) as $card ) {
			$codes[] = [
				'code' => self::code_for( (int) $order['id'], $card['item_id'], $card['number'] ),
				'card' => $card,
			];
		}
		return $codes;
	}

	/**
	 * One card by ID.
	 *
	 * @param int $card_id Card.
	 */
	public static function get( int $card_id ): ?array {
		global $wpdb;
		$table = Schema::gift_cards();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $card_id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Finds a card by its code.
	 *
	 * @param string $code Typed code.
	 */
	public static function find( string $code ): ?array {
		global $wpdb;
		if ( strlen( self::normalize( $code ) ) < 12 ) {
			return null;
		}
		$table = Schema::gift_cards();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code_hash = %s", self::hash( $code ) ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Cards for the admin list, newest first.
	 *
	 * @param int $per_page Page size.
	 * @param int $page     One-based page.
	 * @return array{rows: array[], total: int}
	 */
	public static function query( int $per_page, int $page ): array {
		global $wpdb;
		$table = Schema::gift_cards();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, $page - 1 ) * $per_page ), ARRAY_A );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable
		return [
			'rows'  => array_map( [ self::class, 'hydrate' ], $rows ),
			'total' => $total,
		];
	}

	/**
	 * Typed card row with its current balance.
	 *
	 * @param array $row Database row.
	 */
	private static function hydrate( array $row ): array {
		foreach ( [ 'id', 'order_id', 'item_id', 'number', 'amount' ] as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['balance'] = max( 0, $row['amount'] - self::spent( $row['id'] ) );
		return $row;
	}

	/**
	 * Amount spent from a card by orders that still count.
	 *
	 * @param int $card_id   Card.
	 * @param int $except_id Order to leave out.
	 */
	private static function spent( int $card_id, int $except_id = 0 ): int {
		global $wpdb;
		$uses     = Schema::gift_card_uses();
		$orders   = Schema::orders();
		$released = "'" . implode( "','", self::RELEASED ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, constant list.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(uses.amount), 0) FROM {$uses} uses INNER JOIN {$orders} orders ON orders.id = uses.order_id WHERE uses.card_id = %d AND uses.order_id <> %d AND orders.status NOT IN ({$released})", $card_id, $except_id ) );
	}

	/**
	 * Uses of a card, with their orders.
	 *
	 * @param int $card_id Card.
	 * @return array[]
	 */
	public static function uses( int $card_id ): array {
		global $wpdb;
		$uses   = Schema::gift_card_uses();
		$orders = Schema::orders();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT uses.order_id, uses.amount, uses.created_at, orders.status FROM {$uses} uses LEFT JOIN {$orders} orders ON orders.id = uses.order_id WHERE uses.card_id = %d ORDER BY uses.id", $card_id ), ARRAY_A );
	}

	/**
	 * Why a card cannot pay, or '' when it can.
	 *
	 * @param array|null $card     Card.
	 * @param string     $currency Shop currency.
	 */
	public static function problem( ?array $card, string $currency ): string {
		if ( ! $card ) {
			return __( 'This gift card code is not valid.', 'slashphoto' );
		}
		if ( 'active' !== $card['status'] ) {
			return __( 'This gift card can no longer be used.', 'slashphoto' );
		}
		if ( null !== $card['expires_at'] && strtotime( $card['expires_at'] . ' UTC' ) < time() ) {
			return __( 'This gift card has expired.', 'slashphoto' );
		}
		if ( strtoupper( $currency ) !== $card['currency'] ) {
			return __( 'This gift card is in another currency.', 'slashphoto' );
		}
		if ( $card['balance'] <= 0 ) {
			return __( 'This gift card has been used up.', 'slashphoto' );
		}
		return '';
	}

	/**
	 * Records that an order spends from a card, if the balance allows it.
	 * A named lock makes the check and the record atomic.
	 *
	 * @param int $card_id  Card.
	 * @param int $order_id Order.
	 * @param int $amount   Minor units.
	 */
	public static function reserve( int $card_id, int $order_id, int $amount ): bool {
		global $wpdb;
		$lock = 'slashphoto_giftcard_' . $card_id;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- locking and custom table.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return false;
		}
		try {
			$card = self::get( $card_id );
			if ( ! $card || '' !== self::problem( $card, $card['currency'] ) || $amount <= 0 || $amount > $card['amount'] - self::spent( $card_id, $order_id ) ) {
				return false;
			}
			return (bool) $wpdb->insert(
				Schema::gift_card_uses(),
				[
					'card_id'    => $card_id,
					'order_id'   => $order_id,
					'amount'     => $amount,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				]
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		// phpcs:enable
	}

	/**
	 * The amount still to pay for an order after its gift card.
	 *
	 * @param array $order Order.
	 */
	public static function due( array $order ): int {
		return max( 0, (int) $order['total'] - (int) ( $order['gift_card_amount'] ?? 0 ) );
	}
}
