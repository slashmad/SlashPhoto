<?php
/**
 * Order storage.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes orders in the custom tables.
 *
 * Customers reach their order through a link with a key. The key is not
 * stored: it is an HMAC of the order's random token with the site's salt,
 * so the database alone does not give access to orders or downloads.
 *
 * Statuses: pending (waiting for an online payment), on-hold (waiting for a
 * manual payment), paid (to be fulfilled; downloads open), completed
 * (delivered), cancelled, refunded, failed.
 */
final class Orders {

	public const STATUSES     = [ 'pending', 'on-hold', 'paid', 'completed', 'cancelled', 'refunded', 'failed' ];
	public const PAID         = [ 'paid', 'completed' ];
	public const CRON         = 'slashphoto_cleanup_orders';
	public const ABANDONED_IN = 7 * DAY_IN_SECONDS;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( self::CRON, [ self::class, 'delete_abandoned' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
	}

	/**
	 * Schedules the daily clean-up of abandoned checkouts.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Status names for people.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			'pending'   => __( 'Awaiting payment', 'slashphoto' ),
			'on-hold'   => __( 'Awaiting manual payment', 'slashphoto' ),
			'paid'      => __( 'Paid, being prepared', 'slashphoto' ),
			'completed' => __( 'Completed', 'slashphoto' ),
			'cancelled' => __( 'Cancelled', 'slashphoto' ),
			'refunded'  => __( 'Refunded', 'slashphoto' ),
			'failed'    => __( 'Payment failed', 'slashphoto' ),
		];
	}

	/**
	 * Creates an order with its lines.
	 *
	 * @param array $order Fields: email, name, phone, address (array), customer_note, gateway, user_id.
	 * @param array $quote Quote from Cart::quote().
	 * @return int|WP_Error Order ID.
	 */
	public static function create( array $order, array $quote ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert(
			Schema::orders(),
			[
				'token'              => bin2hex( random_bytes( 16 ) ),
				'status'             => 'pending',
				'user_id'            => (int) ( $order['user_id'] ?? 0 ),
				'email'              => (string) $order['email'],
				'name'               => (string) $order['name'],
				'phone'              => (string) ( $order['phone'] ?? '' ),
				'address'            => ! empty( $order['address'] ) ? (string) wp_json_encode( $order['address'] ) : null,
				'customer_note'      => (string) ( $order['customer_note'] ?? '' ),
				'currency'           => (string) $quote['currency'],
				'subtotal'           => (int) $quote['subtotal'],
				'discount'           => (int) ( $quote['discount'] ?? 0 ),
				'coupon'             => (string) ( $quote['coupon'] ?? '' ),
				'gift_card_id'       => (int) ( $quote['gift_card']['id'] ?? 0 ),
				'gift_card_hint'     => (string) ( $quote['gift_card']['hint'] ?? '' ),
				'gift_card_amount'   => (int) ( $quote['gift_card']['amount'] ?? 0 ),
				'shipping'           => (int) $quote['shipping'],
				'tax'                => (int) $quote['tax'],
				'total'              => (int) $quote['total'],
				'tax_rate'           => (float) $quote['tax_rate'],
				'tax_country'        => (string) ( $quote['tax_country'] ?? '' ),
				'prices_include_tax' => $quote['prices_include_tax'] ? 1 : 0,
				'gateway'            => (string) ( $order['gateway'] ?? '' ),
				'created_at'         => $now,
				'updated_at'         => $now,
			]
		);
		if ( ! $ok ) {
			return new WP_Error( 'slashphoto_order_save', __( 'The order could not be saved.', 'slashphoto' ), [ 'status' => 500 ] );
		}
		$id = (int) $wpdb->insert_id;

		foreach ( $quote['lines'] as $line ) {
			$parent = self::insert_item(
				[
					'order_id'      => $id,
					'kind'          => $line['kind'],
					'gallery_id'    => $line['gallery'],
					'image_id'      => $line['image'],
					'product_id'    => $line['product'],
					'name'          => $line['name'],
					/* translators: %d: number of photos */
					'file_label'    => 'package' === $line['kind'] ? sprintf( _n( '%d photo', '%d photos', count( $line['images'] ), 'slashphoto' ), count( $line['images'] ) ) : $line['file'],
					'print_product' => $line['print_product'] ?? '',
					'print_paper'   => $line['print_paper'] ?? '',
					'print_frame'   => $line['print_frame'] ?? '',
					'download_size' => $line['download_size'] ?? 0,
					'quantity'      => $line['qty'],
					'unit_price'    => $line['unit'],
					'total'         => $line['total'],
				]
			);
			// Each photo of a package becomes its own line (price 0), so
			// downloads and prints work exactly as for single products.
			foreach ( $line['images'] ?? [] as $image ) {
				self::insert_item(
					[
						'order_id'      => $id,
						'parent_id'     => $parent,
						'kind'          => $line['includes']['kind'],
						'gallery_id'    => $image['gallery'],
						'image_id'      => $image['id'],
						'product_id'    => $line['includes']['id'],
						'name'          => $line['includes']['name'],
						'file_label'    => $image['file'],
						'print_product' => 'print' === $line['includes']['kind'] ? $line['includes']['name'] : '',
						'download_size' => $line['includes']['size'] ?? 0,
						'quantity'      => 1,
						'unit_price'    => 0,
						'total'         => 0,
					]
				);
			}
		}//end foreach

		// The gift card is spent last, under a lock; if its balance went
		// elsewhere meanwhile, the order is not placed.
		$card = (int) ( $quote['gift_card']['id'] ?? 0 );
		if ( $card && ! GiftCards::reserve( $card, $id, (int) $quote['gift_card']['amount'] ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
			$wpdb->delete( Schema::items(), [ 'order_id' => $id ] );
			$wpdb->delete( Schema::orders(), [ 'id' => $id ] );
			// phpcs:enable
			return new WP_Error( 'slashphoto_gift_card_changed', __( 'The gift card balance has changed. Check your cart and try again.', 'slashphoto' ), [ 'status' => 409 ] );
		}
		return $id;
	}

	/**
	 * Inserts an order line.
	 *
	 * @param array $row Columns.
	 * @return int Line ID.
	 */
	private static function insert_item( array $row ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert( Schema::items(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Reads an order.
	 *
	 * @param int $id Order ID.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::orders();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, fresh data needed.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Lines of an order.
	 *
	 * @param int $id Order ID.
	 * @return array<int, array>
	 */
	public static function items( int $id ): array {
		global $wpdb;
		$table = Schema::items();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id", $id ), ARRAY_A );
		return array_map(
			static function ( array $row ): array {
				foreach ( [ 'id', 'order_id', 'parent_id', 'gallery_id', 'image_id', 'download_size', 'quantity', 'unit_price', 'total' ] as $key ) {
					$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
				}
				return $row;
			},
			$rows
		);
	}

	/**
	 * Finds an order by its payment reference.
	 *
	 * @param string $gateway Gateway ID.
	 * @param string $ref     Reference.
	 * @return array|null
	 */
	public static function find_by_ref( string $gateway, string $ref ): ?array {
		global $wpdb;
		if ( '' === $ref ) {
			return null;
		}
		$table = Schema::orders();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE gateway = %s AND gateway_ref = %s", $gateway, $ref ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Orders for the admin list.
	 *
	 * @param string $status   Status or '' for all.
	 * @param string $search   Order number, name or email.
	 * @param int    $per_page Page size.
	 * @param int    $page     Page, from 1.
	 * @return array{rows: array, total: int}
	 */
	public static function query( string $status, string $search, int $per_page, int $page ): array {
		global $wpdb;
		$table            = Schema::orders();
		[ $sql, $params ] = self::filters( $status, $search );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- custom table; $sql holds placeholders only, filled from $params.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} orders WHERE {$sql}", $params ) : "SELECT COUNT(*) FROM {$table} orders WHERE {$sql}" );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT orders.* FROM {$table} orders WHERE {$sql} ORDER BY orders.id DESC LIMIT %d OFFSET %d", array_merge( $params, [ $per_page, max( 0, $page - 1 ) * $per_page ] ) ), ARRAY_A );
		// phpcs:enable
		return [
			'rows'  => array_map( [ self::class, 'hydrate' ], $rows ),
			'total' => $total,
		];
	}

	/**
	 * Streams print lines in descending item order, including prints in packages.
	 *
	 * @param string $status Order status filter.
	 * @param string $search Order number, email or name filter.
	 * @return \Generator<int, array>
	 */
	public static function print_rows( string $status = '', string $search = '' ): \Generator {
		global $wpdb;
		$orders           = Schema::orders();
		$items            = Schema::items();
		[ $sql, $params ] = self::filters( $status, $search );
		$before           = PHP_INT_MAX;
		$batch            = 250;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- custom tables; filters hold placeholders only.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT items.id, orders.id AS order_id, orders.status, items.gallery_id, items.image_id,
						items.file_label, items.name, items.print_product, items.print_paper, items.print_frame, items.quantity
					FROM {$items} items INNER JOIN {$orders} orders ON orders.id = items.order_id
					WHERE items.kind = 'print' AND items.id < %d AND {$sql}
					ORDER BY items.id DESC LIMIT %d",
					array_merge( [ $before ], $params, [ $batch ] )
				),
				ARRAY_A
			);
			// phpcs:enable
			$more = count( $rows ) === $batch;
			foreach ( $rows as $row ) {
				$before = (int) $row['id'];
				yield $row;
			}
		} while ( $more );
	}

	/**
	 * Paid orders (paid, completed or refunded) whose payment arrived between
	 * two dates, oldest first, with what they contained. For VAT reports.
	 *
	 * @param string $from First day, Y-m-d (UTC).
	 * @param string $to   Last day, Y-m-d (UTC), inclusive.
	 * @return \Generator<array{id: int, paid_at: string, status: string, tax_country: string, tax_rate: float, currency: string, tax: int, total: int, kinds: string, vouchers: int, gift_card: int}>
	 */
	public static function tax_rows( string $from, string $to ): \Generator {
		global $wpdb;
		$orders = Schema::orders();
		$items  = Schema::items();
		$after  = 0;
		$batch  = 250;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT orders.id, orders.paid_at, orders.status, orders.tax_country, orders.tax_rate, orders.currency, orders.tax, orders.total, orders.gift_card_amount,
						(SELECT GROUP_CONCAT(DISTINCT kind ORDER BY kind) FROM {$items} WHERE order_id = orders.id AND parent_id = 0 AND kind IN ('download', 'print', 'package', 'giftcard')) AS kinds,
						(SELECT COALESCE(SUM(total), 0) FROM {$items} WHERE order_id = orders.id AND parent_id = 0 AND kind = 'giftcard') AS vouchers
					FROM {$orders} orders
					WHERE orders.status IN ('paid', 'completed', 'refunded') AND orders.paid_at >= %s AND orders.paid_at < %s AND orders.id > %d
					ORDER BY orders.id ASC LIMIT %d",
					$from . ' 00:00:00',
					gmdate( 'Y-m-d', (int) strtotime( $to . ' +1 day' ) ) . ' 00:00:00',
					$after,
					$batch
				),
				ARRAY_A
			);
			// phpcs:enable
			$more = count( $rows ) === $batch;
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				yield [
					'id'          => $after,
					'paid_at'     => (string) $row['paid_at'],
					'status'      => (string) $row['status'],
					'tax_country' => (string) $row['tax_country'],
					'tax_rate'    => (float) $row['tax_rate'],
					'currency'    => (string) $row['currency'],
					'tax'         => (int) $row['tax'],
					'total'       => (int) $row['total'],
					'kinds'       => (string) $row['kinds'],
					'vouchers'    => (int) $row['vouchers'],
					'gift_card'   => (int) $row['gift_card_amount'],
				];
			}
		} while ( $more );
	}

	/**
	 * Builds the shared order filters for the admin list and production export.
	 *
	 * @param string $status Order status filter.
	 * @param string $search Order number, email or name filter.
	 * @return array{string, array}
	 */
	private static function filters( string $status, string $search ): array {
		global $wpdb;
		$where  = [ '1=1' ];
		$params = [];
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'orders.status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(orders.id = %d OR orders.email LIKE %s OR orders.name LIKE %s)';
			$params[] = (int) ltrim( $search, '#' );
			$params[] = $like;
			$params[] = $like;
		}
		return [ implode( ' AND ', $where ), $params ];
	}

	/**
	 * Orders placed with an email address (privacy export).
	 *
	 * @param string $email Email address.
	 * @return array<int, array>
	 */
	public static function for_email( string $email ): array {
		global $wpdb;
		$table = Schema::orders();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id LIMIT 500", $email ), ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], $rows );
	}

	/**
	 * Number of orders per status.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;
		$table = Schema::orders();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows   = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Updates fields of an order.
	 *
	 * @param int   $id     Order ID.
	 * @param array $fields Columns.
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return false !== $wpdb->update( Schema::orders(), $fields, [ 'id' => $id ] );
	}

	/**
	 * Changes the status and tells listeners (emails).
	 *
	 * Moving to "paid" sets the payment time and opens downloads for the
	 * number of days in the settings.
	 *
	 * @param int    $id     Order ID.
	 * @param string $status New status.
	 * @return bool Whether the status changed.
	 */
	public static function set_status( int $id, string $status ): bool {
		$order = self::get( $id );
		if ( ! $order || ! in_array( $status, self::STATUSES, true ) || $order['status'] === $status ) {
			return false;
		}
		$fields = [ 'status' => $status ];
		if ( in_array( $status, self::PAID, true ) && null === $order['paid_at'] ) {
			$days                      = (int) Settings::get( 'download_days' );
			$fields['paid_at']         = gmdate( 'Y-m-d H:i:s' );
			$fields['downloads_until'] = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null;
		}
		if ( ! self::update( $id, $fields ) ) {
			return false;
		}

		/**
		 * Fires when an order's status changes.
		 *
		 * @param int    $id     Order ID.
		 * @param string $status New status.
		 * @param string $old    Previous status.
		 */
		do_action( 'slashphoto_order_status', $id, $status, $order['status'] );
		return true;
	}

	/**
	 * Marks an order paid if it was waiting for payment.
	 *
	 * @param int $id Order ID.
	 */
	public static function mark_paid( int $id ): bool {
		$order = self::get( $id );
		return $order && in_array( $order['status'], [ 'pending', 'on-hold', 'failed' ], true ) && self::set_status( $id, 'paid' );
	}

	/**
	 * The secret key in the customer's order link.
	 *
	 * @param array $order Order.
	 */
	public static function key( array $order ): string {
		return substr( hash_hmac( 'sha256', 'order|' . $order['id'] . '|' . $order['token'], wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Whether a key opens an order.
	 *
	 * @param array  $order Order.
	 * @param string $key   Key from the link.
	 */
	public static function verify_key( array $order, string $key ): bool {
		return '' !== $key && hash_equals( self::key( $order ), $key );
	}

	/**
	 * The customer's link to their order.
	 *
	 * @param array $order Order.
	 */
	public static function url( array $order ): string {
		return add_query_arg(
			[
				'order' => $order['id'],
				'key'   => self::key( $order ),
			],
			ShopPage::url()
		);
	}

	/**
	 * Whether the customer may download the order's files now.
	 *
	 * @param array $order Order.
	 */
	public static function downloads_open( array $order ): bool {
		if ( ! in_array( $order['status'], self::PAID, true ) ) {
			return false;
		}
		return null === $order['downloads_until'] || strtotime( $order['downloads_until'] . ' UTC' ) > time();
	}

	/**
	 * Deletes checkouts that were never paid, a week after they started, so
	 * names and addresses of people who did not buy are not kept.
	 */
	public static function delete_abandoned(): void {
		global $wpdb;
		$orders = Schema::orders();
		$items  = Schema::items();
		$before = gmdate( 'Y-m-d H:i:s', time() - self::ABANDONED_IN );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$orders} WHERE status IN ('pending','failed') AND updated_at < %s LIMIT 500", $before ) ) );
		foreach ( $ids as $id ) {
			$wpdb->delete( $items, [ 'order_id' => $id ] );
			$wpdb->delete( $orders, [ 'id' => $id ] );
		}
		// phpcs:enable
	}

	/**
	 * Converts types of a database row.
	 *
	 * @param array $row Row.
	 */
	private static function hydrate( array $row ): array {
		foreach ( [ 'id', 'user_id', 'subtotal', 'discount', 'shipping', 'tax', 'total', 'gift_card_id', 'gift_card_amount' ] as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}
		$row['tax_rate']           = (float) $row['tax_rate'];
		$row['prices_include_tax'] = (bool) $row['prices_include_tax'];
		$address                   = json_decode( (string) $row['address'], true );
		$row['address']            = is_array( $address ) ? $address : [];
		return $row;
	}
}
