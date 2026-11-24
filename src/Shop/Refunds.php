<?php
/**
 * Order refunds, with one record per partial or full refund.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Keeps refund amounts and provider attempts separate from the order total. */
final class Refunds {

	/**
	 * Reads ledger entries, most recent first.
	 *
	 * @param int $order_id Order.
	 * @return array[]
	 */
	public static function for_order( int $order_id ): array {
		global $wpdb;
		$table = Schema::refunds();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC", $order_id ), ARRAY_A );
	}

	/**
	 * Refundable balance after completed and unresolved entries.
	 *
	 * @param array   $order Order.
	 * @param array[] $rows  Its refund entries.
	 */
	public static function remaining( array $order, array $rows ): int {
		if ( ! $order['paid_at'] || ! in_array( $order['status'], [ ...Orders::PAID, 'refunded' ], true ) || $order['gift_card_amount'] > 0 ) {
			return 0;
		}
		$kinds = array_column( Orders::items( $order['id'] ), 'kind' );
		if ( in_array( GiftCards::KIND, $kinds, true ) ) {
			return 0;
		}
		$spent = array_sum( array_column( array_filter( $rows, static fn( array $row ): bool => 'failed' !== $row['status'] ), 'amount' ) );
		return max( 0, (int) $order['total'] - $spent );
	}

	/**
	 * Records a confirmed external refund or starts a provider refund.
	 *
	 * @param int    $order_id  Order.
	 * @param int    $amount    Minor units.
	 * @param string $key       A single form's UUID.
	 * @param string $reference External reference, or empty to send via the gateway.
	 * @return string|WP_Error Entry status.
	 */
	public static function create( int $order_id, int $amount, string $key, string $reference ) {
		return self::with_lock(
			$order_id,
			static function ( array $order ) use ( $amount, $key, $reference ) {
				global $wpdb;
				if ( ! wp_is_uuid( $key, 4 ) || $amount <= 0 || ( '' !== $reference && ! preg_match( '/^[a-zA-Z0-9._:-]{1,100}$/D', $reference ) ) ) {
					return self::error();
				}
				$rows = self::for_order( $order['id'] );
				foreach ( $rows as $row ) {
					if ( $key === $row['request_key'] ) {
						return $row['status'];
					}
					if ( '' !== $reference && 0 === strcasecmp( $reference, $row['reference'] ) ) {
						return self::error();
					}
				}
				$external = '' !== $reference;
				if ( $amount > self::remaining( $order, $rows ) || ( ! $external && ( 'refunded' === $order['status'] || ! RefundPayments::supported( $order ) ) ) ) {
					return self::error();
				}
				$entry = [
					'order_id'    => $order['id'],
					'amount'      => $amount,
					'status'      => $external ? 'completed' : 'review',
					'request_key' => $key,
					'reference'   => $reference,
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- persist before contacting the provider.
				if ( ! $wpdb->insert( Schema::refunds(), $entry ) ) {
					return self::error();
				}
				$id     = (int) $wpdb->insert_id;
				$result = $external ? $entry : RefundPayments::fetch( $order, $entry );
				return self::finish( $order, $id, $result );
			}
		);
	}

	/**
	 * Checks an accepted refund, or closes a case reconciled by an administrator.
	 *
	 * @param int    $order_id Order.
	 * @param int    $id       Refund.
	 * @param string $action   Refresh, confirm completion or release the reservation.
	 * @return string|WP_Error
	 */
	public static function refresh( int $order_id, int $id, string $action ) {
		return self::with_lock(
			$order_id,
			static function ( array $order ) use ( $id, $action ) {
				foreach ( self::for_order( $order['id'] ) as $row ) {
					if ( $id !== (int) $row['id'] || ! in_array( $row['status'], [ 'pending', 'review' ], true ) ) {
						continue;
					}
					if ( 'refresh' === $action && '' !== $row['reference'] && RefundPayments::supported( $order ) ) {
						return self::finish( $order, $id, RefundPayments::fetch( $order, $row ) );
					}
					if ( 'review' === $row['status'] && in_array( $action, [ 'completed', 'failed' ], true ) ) {
						return self::finish(
							$order,
							$id,
							[
								'status'    => $action,
								'reference' => $row['reference'],
							]
						);
					}
				}
				return self::error();
			}
		);
	}

	/**
	 * Persists the provider result and closes delivery after a full refund.
	 *
	 * @param array $order  Order.
	 * @param int   $id     Refund record.
	 * @param array $result Provider result or locally confirmed entry.
	 * @return string|WP_Error
	 */
	private static function finish( array $order, int $id, array $result ) {
		global $wpdb;
		$fields = [
			'status'    => $result['status'],
			'reference' => $result['reference'],
		];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		if ( false === $wpdb->update( Schema::refunds(), $fields, [ 'id' => $id ] ) ) {
			return self::error();
		}
		if ( 'completed' === $fields['status'] && self::completed( self::for_order( $order['id'] ) ) >= $order['total'] ) {
			Orders::set_status( $order['id'], 'refunded' );
		}
		return $fields['status'];
	}

	/**
	 * Sum of completed refunds, in minor units.
	 *
	 * @param array[] $rows Refund entries.
	 */
	public static function completed( array $rows ): int {
		return (int) array_sum( array_column( array_filter( $rows, static fn( array $row ): bool => 'completed' === $row['status'] ), 'amount' ) );
	}

	/**
	 * Serializes updates to the same order's ledger.
	 *
	 * @param int      $order_id Order.
	 * @param callable $work     Operation to run with fresh order data.
	 * @return string|WP_Error
	 */
	private static function with_lock( int $order_id, callable $work ) {
		global $wpdb;
		$lock = 'slashphoto_refund_' . md5( Schema::orders() . ':' . $order_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named DB lock.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return self::error();
		}
		try {
			$order = Orders::get( $order_id );
			return $order ? $work( $order ) : self::error();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		// phpcs:enable
	}

	/** Error for an invalid or unavailable refund operation. */
	private static function error(): WP_Error {
		return new WP_Error( 'slashphoto_refund_failed', __( 'The refund could not be recorded. Check the amount and the payment provider before trying again.', 'slashphoto' ) );
	}
}
