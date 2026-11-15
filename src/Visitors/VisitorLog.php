<?php
/**
 * Visitor log.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Visitors;

use SlashPhoto\Settings;
use SlashPhoto\Shop\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * What visitors chose to tell the photographer, per gallery:
 *
 * - "email": an email address given to open a gallery with an email gate
 *   (or the account's address, for logged-in visitors);
 * - "terms": an acceptance of a gallery's terms, with a fingerprint of the
 *   exact wording and the account or email address when known.
 *
 * Each row holds the type, gallery, account, email address, detail and
 * time. Never an IP address, user agent or anything about the device.
 * Rows are deleted after the retention period in the settings and are
 * covered by WordPress' personal data export and erase tools.
 */
final class VisitorLog {

	public const TYPES = [ 'email', 'terms' ];
	public const CRON  = 'slashphoto_cleanup_visitors';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( self::CRON, [ self::class, 'cleanup' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
	}

	/**
	 * Schedules the daily clean-up.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Adds a row.
	 *
	 * @param string $type       "email" or "terms".
	 * @param int    $gallery_id Gallery the entry belongs to.
	 * @param string $email      Email address, or ''.
	 * @param string $detail     Extra detail (terms fingerprint).
	 * @param int    $user_id    Account, default the current user.
	 * @return int Row ID.
	 */
	public static function add( string $type, int $gallery_id, string $email, string $detail = '', ?int $user_id = null ): int {
		global $wpdb;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			Schema::visitors(),
			[
				'type'       => $type,
				'gallery_id' => $gallery_id,
				'user_id'    => null === $user_id ? get_current_user_id() : $user_id,
				'email'      => mb_substr( strtolower( sanitize_email( $email ) ), 0, 200 ),
				'detail'     => mb_substr( $detail, 0, 64 ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Inserts a row with given values (imports).
	 *
	 * @param array $row Columns.
	 */
	public static function insert( array $row ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert( Schema::visitors(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * One row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::visitors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Whether an account already has an entry of a type for a gallery.
	 *
	 * @param string $type       Type.
	 * @param int    $gallery_id Gallery.
	 * @param int    $user_id    Account.
	 */
	public static function has_user_entry( string $type, int $gallery_id, int $user_id ): bool {
		global $wpdb;
		$table = Schema::visitors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND gallery_id = %d AND user_id = %d LIMIT 1", $type, $gallery_id, $user_id ) );
	}

	/**
	 * Rows for the admin list.
	 *
	 * @param array $filters type, gallery, search.
	 * @param int   $per_page Page size (0 for all, CSV).
	 * @param int   $page     Page from 1.
	 * @return array{rows: array, total: int}
	 */
	public static function query( array $filters, int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$table  = Schema::visitors();
		$where  = [ '1=1' ];
		$params = [];
		if ( in_array( $filters['type'] ?? '', self::TYPES, true ) ) {
			$where[]  = 'type = %s';
			$params[] = $filters['type'];
		}
		if ( ! empty( $filters['gallery'] ) ) {
			$where[]  = 'gallery_id = %d';
			$params[] = (int) $filters['gallery'];
		}
		if ( '' !== (string) ( $filters['search'] ?? '' ) ) {
			$where[]  = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
		}
		$sql = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- custom table; $sql holds placeholders only.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$sql}" );
		$limit = $per_page > 0 ? $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, $page - 1 ) * $per_page ) : '';
		$query = "SELECT * FROM {$table} WHERE {$sql} ORDER BY id DESC" . $limit;
		$rows  = (array) $wpdb->get_results( $params ? $wpdb->prepare( $query, $params ) : $query, ARRAY_A );
		// phpcs:enable
		return [
			'rows'  => $rows,
			'total' => $total,
		];
	}

	/**
	 * Rows of one person, by email address or the account with it.
	 *
	 * @param string $email Email address.
	 * @return array<int, array>
	 */
	public static function for_person( string $email ): array {
		global $wpdb;
		$table = Schema::visitors();
		$user  = get_user_by( 'email', $email );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s OR ( user_id > 0 AND user_id = %d ) ORDER BY id LIMIT 1000", strtolower( $email ), $user ? $user->ID : 0 ), ARRAY_A );
	}

	/**
	 * Deletes the rows of one person.
	 *
	 * @param string $email Email address.
	 * @return int Rows deleted.
	 */
	public static function delete_person( string $email ): int {
		global $wpdb;
		$table = Schema::visitors();
		$user  = get_user_by( 'email', $email );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email = %s OR ( user_id > 0 AND user_id = %d )", strtolower( $email ), $user ? $user->ID : 0 ) );
	}

	/**
	 * Deletes rows older than the retention period.
	 */
	public static function cleanup(): void {
		global $wpdb;
		$days = (int) Settings::get( 'visitor_retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		$table = Schema::visitors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
