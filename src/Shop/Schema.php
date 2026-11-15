<?php
/**
 * Database tables for orders.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades SlashPhoto's tables: orders and their lines, the
 * visitor log and daily view counts. Tables are never dropped by the
 * plugin, not even on uninstall: orders are bookkeeping records.
 */
final class Schema {

	public const VERSION = 3;
	public const OPTION  = 'slashphoto_db_version';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ self::class, 'maybe_install' ], 1 );
	}

	/**
	 * Installs or upgrades when the stored version is older.
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Orders table name.
	 */
	public static function orders(): string {
		global $wpdb;
		return $wpdb->prefix . 'slashphoto_orders';
	}

	/**
	 * Order items table name.
	 */
	public static function items(): string {
		global $wpdb;
		return $wpdb->prefix . 'slashphoto_order_items';
	}

	/**
	 * Visitor log table (email gate, terms acceptance).
	 */
	public static function visitors(): string {
		global $wpdb;
		return $wpdb->prefix . 'slashphoto_visitors';
	}

	/**
	 * Daily view counts.
	 */
	public static function stats(): string {
		global $wpdb;
		return $wpdb->prefix . 'slashphoto_stats';
	}

	/**
	 * Creates or updates the tables.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$orders  = self::orders();
		$items   = self::items();
		$log     = self::visitors();
		$stats   = self::stats();

		// Version 2 added discount, coupon and parent_id (packages); version 3
		// the visitor log and anonymous daily view counts (D-021).
		// dbDelta needs two spaces after PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$orders} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	token char(32) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'pending',
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	email varchar(200) NOT NULL DEFAULT '',
	name varchar(200) NOT NULL DEFAULT '',
	phone varchar(50) NOT NULL DEFAULT '',
	address text NULL,
	customer_note text NULL,
	admin_note text NULL,
	currency char(3) NOT NULL,
	subtotal bigint(20) NOT NULL DEFAULT 0,
	discount bigint(20) NOT NULL DEFAULT 0,
	coupon varchar(50) NOT NULL DEFAULT '',
	shipping bigint(20) NOT NULL DEFAULT 0,
	tax bigint(20) NOT NULL DEFAULT 0,
	total bigint(20) NOT NULL DEFAULT 0,
	tax_rate decimal(6,3) NOT NULL DEFAULT 0,
	prices_include_tax tinyint(1) NOT NULL DEFAULT 1,
	gateway varchar(30) NOT NULL DEFAULT '',
	gateway_ref varchar(255) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	paid_at datetime NULL,
	downloads_until datetime NULL,
	PRIMARY KEY  (id),
	KEY status (status),
	KEY email (email(100)),
	KEY user_id (user_id),
	KEY gateway_ref (gateway_ref(100)),
	KEY coupon (coupon)
) {$charset};
CREATE TABLE {$items} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	order_id bigint(20) unsigned NOT NULL,
	parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
	kind varchar(20) NOT NULL,
	gallery_id bigint(20) unsigned NOT NULL DEFAULT 0,
	image_id bigint(20) unsigned NOT NULL DEFAULT 0,
	product_id varchar(20) NOT NULL DEFAULT '',
	name varchar(200) NOT NULL DEFAULT '',
	file_label varchar(200) NOT NULL DEFAULT '',
	quantity int(11) NOT NULL DEFAULT 1,
	unit_price bigint(20) NOT NULL DEFAULT 0,
	total bigint(20) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY order_id (order_id),
	KEY image_id (image_id)
) {$charset};
CREATE TABLE {$log} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	type varchar(10) NOT NULL,
	gallery_id bigint(20) unsigned NOT NULL DEFAULT 0,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	email varchar(200) NOT NULL DEFAULT '',
	detail varchar(64) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY gallery_id (gallery_id),
	KEY email (email(100)),
	KEY created_at (created_at)
) {$charset};
CREATE TABLE {$stats} (
	day date NOT NULL,
	gallery_id bigint(20) unsigned NOT NULL,
	image_id bigint(20) unsigned NOT NULL DEFAULT 0,
	views int(10) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (day,gallery_id,image_id),
	KEY gallery_id (gallery_id)
) {$charset};"
		);
		update_option( self::OPTION, self::VERSION, true );
	}
}
