<?php
/**
 * Import from Sunshine Photo Cart.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Import;

use SlashPhoto\Access\Access;
use SlashPhoto\Access\EmailGate;
use SlashPhoto\Access\Terms;
use SlashPhoto\Gallery\Importer;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\Schema;
use SlashPhoto\Visitors\VisitorLog;

defined( 'ABSPATH' ) || exit;

/**
 * Copies a Sunshine Photo Cart site into SlashPhoto, in steps that can be
 * repeated: price levels and products, galleries with their access
 * settings, images, then orders.
 *
 * Sunshine's own data is only read, never changed, and Sunshine does not
 * need to be active (its posts and tables are read directly). Everything
 * created remembers the Sunshine ID it came from, so running the import
 * again continues where it stopped and skips what is already there.
 *
 * Images go through the normal gallery import: a display copy is made and
 * the original is stored privately, so the files are copied, not moved.
 */
final class SunshineImporter {

	public const STATE   = 'slashphoto_sunshine_import';
	public const META    = '_slashphoto_sunshine_id';
	public const STEPS   = [ 'prices', 'galleries', 'images', 'orders', 'done' ];
	public const GATEWAY = 'sunshine';

	/**
	 * Whether this site has Sunshine data.
	 */
	public static function has_data(): bool {
		return self::count_posts( 'sunshine-gallery' ) > 0 || self::count_posts( 'sunshine-order' ) > 0;
	}

	/**
	 * What would be imported, and how far the import has come.
	 *
	 * @return array{galleries: int, images: int, products: int, levels: int, orders: int, state: array}
	 */
	public static function summary(): array {
		$images = 0;
		foreach ( self::galleries() as $gallery ) {
			$images += count( self::gallery_images( $gallery->ID ) );
		}
		return [
			'galleries' => self::count_posts( 'sunshine-gallery' ),
			'images'    => $images,
			'products'  => self::count_posts( 'sunshine-product' ),
			'levels'    => count( self::price_levels() ),
			'orders'    => self::count_posts( 'sunshine-order' ),
			'state'     => self::state(),
		];
	}

	/**
	 * Progress so far.
	 *
	 * @return array{step: string, cursor: int, done: array<string, int>, notes: string[], failed: int[]}
	 */
	public static function state(): array {
		$state = get_option( self::STATE, [] );
		$state = is_array( $state ) ? $state : [];
		return [
			'step'   => in_array( $state['step'] ?? '', self::STEPS, true ) ? $state['step'] : 'prices',
			'cursor' => (int) ( $state['cursor'] ?? 0 ),
			'done'   => array_merge(
				[
					'price_lists' => 0,
					'galleries'   => 0,
					'images'      => 0,
					'orders'      => 0,
					'visitors'    => 0,
				],
				(array) ( $state['done'] ?? [] )
			),
			'notes'  => array_slice( array_values( (array) ( $state['notes'] ?? [] ) ), -200 ),
			// Images that could not be copied, so they are not tried on every run.
			'failed' => array_values( array_map( 'intval', (array) ( $state['failed'] ?? [] ) ) ),
		];
	}

	/**
	 * Starts over from the first step, to pick up Sunshine content added
	 * since. Things already imported are found again and skipped; images
	 * that failed are tried again. Counts and notes are kept.
	 */
	public static function restart(): void {
		$state           = self::state();
		$state['step']   = 'prices';
		$state['cursor'] = 0;
		$state['failed'] = [];
		update_option( self::STATE, $state, false );
	}

	/**
	 * Runs import steps for about $seconds and returns the progress.
	 *
	 * @param int $seconds Time budget.
	 */
	public static function run( int $seconds = 15 ): array {
		$state = self::state();
		$until = microtime( true ) + max( 0, $seconds );
		wp_raise_memory_limit( 'image' );

		// Every call does at least one unit of work, so a 0-second budget
		// still makes progress.
		while ( 'done' !== $state['step'] ) {
			$finished = match ( $state['step'] ) {
				'prices'    => self::step_prices( $state ),
				'galleries' => self::step_galleries( $state, $until ),
				'images'    => self::step_images( $state, $until ),
				'orders'    => self::step_orders( $state, $until ),
				default     => true,
			};
			if ( $finished ) {
				$next            = array_search( $state['step'], self::STEPS, true ) + 1;
				$state['step']   = self::STEPS[ $next ] ?? 'done';
				$state['cursor'] = 0;
			}
			update_option( self::STATE, $state, false );
			if ( microtime( true ) >= $until ) {
				break;
			}
		}
		return $state;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Price levels and products → price lists
	 * ---------------------------------------------------------------------
	 */

	/**
	 * One price list per Sunshine price level, with every product that has a
	 * price at that level.
	 *
	 * @param array $state Progress.
	 */
	private static function step_prices( array &$state ): bool {
		$products = get_posts(
			[
				'post_type'   => 'sunshine-product',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'menu_order title',
				'order'       => 'ASC',
			]
		);
		$currency = (string) Settings::get( 'currency' );

		foreach ( self::price_levels() as $level ) {
			if ( self::find_post( PriceLists::POST_TYPE, 'level-' . $level['id'] ) ) {
				continue;
			}
			$rows = [];
			foreach ( $products as $product ) {
				$prices = get_post_meta( $product->ID, 'price', true );
				if ( ! is_array( $prices ) || ! isset( $prices[ $level['id'] ] ) || '' === (string) $prices[ $level['id'] ] ) {
					continue;
				}
				$category = self::term_name( $product->ID, 'sunshine-product-category' );
				$rows[]   = [
					'id'    => self::product_id( $product->ID ),
					'kind'  => self::product_kind( $product->ID ),
					'name'  => ( '' !== $category ? $category . ' – ' : '' ) . $product->post_title,
					'price' => Money::parse( (string) (float) $prices[ $level['id'] ], $currency ),
				];
			}
			$list = wp_insert_post(
				[
					'post_type'   => PriceLists::POST_TYPE,
					'post_status' => 'publish',
					/* translators: %s: Sunshine price level name */
					'post_title'  => sprintf( __( '%s (from Sunshine)', 'slashphoto' ), $level['name'] ),
				]
			);
			if ( ! $list || is_wp_error( $list ) ) {
				continue;
			}
			update_post_meta( $list, self::META, 'level-' . $level['id'] );
			PriceLists::save_products( $list, $rows );
			++$state['done']['price_lists'];
			if ( $level['default'] && ! Settings::get( 'shop_price_list' ) ) {
				update_option( Settings::OPTION, array_merge( Settings::all(), [ 'shop_price_list' => $list ] ) );
			}
		}//end foreach
		return true;
	}

	/**
	 * Sunshine price levels.
	 *
	 * @return array<int, array{id: int, name: string, default: bool}>
	 */
	private static function price_levels(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the taxonomy may not be registered (Sunshine inactive).
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY t.term_id", 'sunshine-product-price-level' ), ARRAY_A );
		$levels = [];
		foreach ( $rows as $row ) {
			$levels[] = [
				'id'      => (int) $row['term_id'],
				'name'    => (string) $row['name'],
				'default' => (bool) get_term_meta( (int) $row['term_id'], 'default', true ),
			];
		}
		if ( $levels && ! array_filter( array_column( $levels, 'default' ) ) ) {
			$levels[0]['default'] = true;
			// Sunshine falls back to the first level.
		}
		return $levels;
	}

	/**
	 * Our product ID for a Sunshine product (stable, so orders map to it).
	 *
	 * @param int $product_id Sunshine product.
	 */
	private static function product_id( int $product_id ): string {
		return substr( md5( 'sunshine-product-' . $product_id ), 0, 8 );
	}

	/**
	 * Download or print.
	 *
	 * @param int $product_id Sunshine product.
	 */
	private static function product_kind( int $product_id ): string {
		return str_contains( (string) get_post_meta( $product_id, 'type', true ), 'download' ) ? 'download' : 'print';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Galleries
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Sunshine galleries, parents before children.
	 *
	 * @return \WP_Post[]
	 */
	private static function galleries(): array {
		$galleries = get_posts(
			[
				'post_type'   => 'sunshine-gallery',
				'post_status' => [ 'publish', 'private', 'draft', 'pending', 'future' ],
				'numberposts' => -1,
				'orderby'     => 'menu_order ID',
				'order'       => 'ASC',
			]
		);
		usort(
			$galleries,
			static fn( \WP_Post $a, \WP_Post $b ): int => [ count( get_post_ancestors( $a ) ), $a->menu_order, $a->ID ] <=> [ count( get_post_ancestors( $b ) ), $b->menu_order, $b->ID ]
		);
		return $galleries;
	}

	/**
	 * Creates galleries with their access settings.
	 *
	 * @param array $state Progress.
	 * @param float $until Deadline.
	 */
	private static function step_galleries( array &$state, float $until ): bool {
		$did = false;
		foreach ( self::galleries() as $source ) {
			if ( self::find_post( PostType::POST_TYPE, (string) $source->ID ) ) {
				continue;
			}
			if ( $did && microtime( true ) >= $until ) {
				return false;
			}
			$did    = true;
			$parent = $source->post_parent ? self::find_post( PostType::POST_TYPE, (string) $source->post_parent ) : 0;
			$meta   = static fn( string $key ) => get_post_meta( $source->ID, $key, true );
			$status = (string) $meta( 'status' );

			$id = wp_insert_post(
				[
					'post_type'     => PostType::POST_TYPE,
					'post_status'   => $source->post_status,
					'post_title'    => $source->post_title,
					'post_name'     => $source->post_name,
					'post_content'  => $source->post_content,
					'post_excerpt'  => $source->post_excerpt,
					'post_date'     => $source->post_date,
					'post_date_gmt' => $source->post_date_gmt,
					'post_author'   => $source->post_author,
					'post_parent'   => $parent,
					'menu_order'    => $source->menu_order,
					'post_password' => 'password' === $status ? (string) $meta( 'password' ) : '',
				],
				true
			);
			if ( is_wp_error( $id ) ) {
				/* translators: 1: gallery title, 2: error */
				$state['notes'][] = sprintf( __( 'Gallery “%1$s” could not be created: %2$s', 'slashphoto' ), $source->post_title, $id->get_error_message() );
				continue;
			}
			update_post_meta( $id, self::META, (string) $source->ID );
			++$state['done']['galleries'];

			// Access.
			$access = (string) $meta( 'access_type' );
			if ( 'private' === $status ) {
				update_post_meta( $id, Access::META_MODE, Access::MODE_CLIENTS );
				update_post_meta( $id, Access::META_CLIENTS, array_values( array_filter( array_map( 'intval', (array) $meta( 'private_users' ) ) ) ) );
			} elseif ( 'url' === $access ) {
				update_post_meta( $id, Access::META_MODE, Access::MODE_LINK );
			} elseif ( 'account' === $access ) {
				update_post_meta( $id, Access::META_MODE, Access::MODE_MEMBERS );
			} elseif ( 'email' === $access ) {
				update_post_meta( $id, EmailGate::META, 'on' );
			}
			self::copy_visitors( $source->ID, (int) $id, $state );
			if ( 'password' === $status && '' !== (string) $meta( 'password_hint' ) ) {
				/* translators: 1: gallery title, 2: password hint */
				$state['notes'][] = sprintf( __( 'Gallery “%1$s” had the password hint “%2$s”, which was not imported.', 'slashphoto' ), $source->post_title, $meta( 'password_hint' ) );
			}
			$end = $meta( 'end_date' );
			if ( is_numeric( $end ) && (int) $end > 0 ) {
				// Sunshine stores local time as a timestamp.
				update_post_meta( $id, Access::META_EXPIRES, gmdate( 'Y-m-d', (int) $end ) );
			}
			$terms = (string) $meta( 'terms' );
			$text  = (string) get_option( 'sunshine_gallery_terms_text', '' );
			if ( '' !== trim( $text ) && ( 'required' === $terms || ( '' === $terms && ! $source->post_parent && get_option( 'sunshine_gallery_terms' ) ) ) ) {
				update_post_meta( $id, Terms::META_TERMS, wp_kses_post( $text ) );
			}

			// Products.
			if ( $meta( 'disable_products' ) ) {
				update_post_meta( $id, PriceLists::GALLERY_META, PriceLists::NONE );
			} elseif ( $meta( 'price_level' ) ) {
				$list = self::find_post( PriceLists::POST_TYPE, 'level-' . (int) $meta( 'price_level' ) );
				if ( $list ) {
					update_post_meta( $id, PriceLists::GALLERY_META, (string) $list );
				}
			}
		}//end foreach
		return true;
	}

	/**
	 * Copies the email addresses visitors gave and the terms agreements
	 * Sunshine recorded for a gallery into the visitor log.
	 *
	 * @param int   $source  Sunshine gallery.
	 * @param int   $gallery New gallery.
	 * @param array $state   Progress.
	 */
	private static function copy_visitors( int $source, int $gallery, array &$state ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array_unique( array_filter( (array) get_post_meta( $source, 'emails', true ), 'is_email' ) ) as $email ) {
			VisitorLog::insert(
				[
					'type'       => 'email',
					'gallery_id' => $gallery,
					'email'      => strtolower( (string) $email ),
					'detail'     => 'sunshine',
					'created_at' => $now,
				]
			);
			++$state['done']['visitors'];
		}

		$table = $wpdb->prefix . 'sunshine_terms_agreements';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Sunshine's table, read only.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, email, version, time FROM {$table} WHERE gallery_id = %d ORDER BY agreement_id", $source ), ARRAY_A );
		// phpcs:enable
		foreach ( $rows as $row ) {
			VisitorLog::insert(
				[
					'type'       => 'terms',
					'gallery_id' => $gallery,
					'user_id'    => (int) $row['user_id'],
					'email'      => strtolower( sanitize_email( (string) $row['email'] ) ),
					'detail'     => mb_substr( 'sunshine-' . $row['version'], 0, 64 ),
					'created_at' => gmdate( 'Y-m-d H:i:s', (int) $row['time'] ),
				]
			);
			++$state['done']['visitors'];
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Images
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Image IDs of a Sunshine gallery, in its order.
	 *
	 * @param int $gallery_id Sunshine gallery.
	 * @return int[]
	 */
	private static function gallery_images( int $gallery_id ): array {
		$ids = get_post_meta( $gallery_id, 'images', true );
		if ( is_array( $ids ) && $ids ) {
			return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		}
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'      => 'attachment',
					'post_parent'    => $gallery_id,
					'post_status'    => 'inherit',
					'post_mime_type' => 'image',
					'numberposts'    => -1,
					'orderby'        => 'menu_order ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				]
			)
		);
	}

	/**
	 * Copies images into the new galleries, a few at a time.
	 *
	 * @param array $state Progress.
	 * @param float $until Deadline.
	 */
	private static function step_images( array &$state, float $until ): bool {
		$imported = self::imported_ids( 'attachment' ) + array_fill_keys( $state['failed'], -1 );
		$did      = false;
		foreach ( self::galleries() as $source ) {
			$gallery = self::find_post( PostType::POST_TYPE, (string) $source->ID );
			if ( ! $gallery ) {
				continue;
			}
			foreach ( self::gallery_images( $source->ID ) as $position => $image_id ) {
				if ( isset( $imported[ $image_id ] ) ) {
					continue;
				}
				if ( $did && microtime( true ) >= $until ) {
					return false;
				}
				$did                   = true;
				$new                   = self::copy_image( $image_id, $gallery, (int) $position, $state );
				$imported[ $image_id ] = $new;
				if ( $new < 0 ) {
					$state['failed'][] = $image_id;
				}
			}
		}//end foreach

		// Covers last: a gallery's cover can be a photo from a sub-gallery.
		foreach ( self::galleries() as $source ) {
			$gallery = self::find_post( PostType::POST_TYPE, (string) $source->ID );
			$thumb   = (int) get_post_thumbnail_id( $source->ID );
			if ( $gallery && $thumb && ( $imported[ $thumb ] ?? 0 ) > 0 && ! has_post_thumbnail( $gallery ) ) {
				set_post_thumbnail( $gallery, $imported[ $thumb ] );
			}
		}
		return true;
	}

	/**
	 * Copies one image.
	 *
	 * @param int   $image_id   Sunshine attachment.
	 * @param int   $gallery_id New gallery.
	 * @param int   $position   Order in the gallery.
	 * @param array $state      Progress.
	 * @return int New attachment ID, or -1 when it failed.
	 */
	private static function copy_image( int $image_id, int $gallery_id, int $position, array &$state ): int {
		$source = (string) wp_get_original_image_path( $image_id );
		if ( '' === $source || ! is_file( $source ) ) {
			$source = (string) get_attached_file( $image_id );
		}
		$old  = get_post( $image_id );
		$name = (string) get_post_meta( $image_id, 'sunshine_file_name', true );
		$name = '' !== $name ? $name : wp_basename( $source );
		$fail = static function ( string $reason ) use ( $name, &$state ): int {
			/* translators: 1: file name, 2: reason */
			$state['notes'][] = sprintf( __( 'Image %1$s was not imported: %2$s', 'slashphoto' ), $name, $reason );
			return -1;
		};

		if ( ! $old || '' === $source || ! is_readable( $source ) ) {
			return $fail( __( 'the file is missing.', 'slashphoto' ) );
		}
		$temp = wp_tempnam( $name );
		if ( ! $temp || ! copy( $source, $temp ) ) {
			return $fail( __( 'the file could not be copied.', 'slashphoto' ) );
		}
		$id = Importer::import( $temp, $name, $gallery_id );
		if ( is_wp_error( $id ) ) {
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}
			return $fail( $id->get_error_message() );
		}
		wp_update_post(
			[
				'ID'           => $id,
				'post_title'   => $old->post_title,
				'post_excerpt' => $old->post_excerpt,
				'post_content' => $old->post_content,
				'menu_order'   => $position,
			]
		);
		$alt = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		update_post_meta( $id, self::META, (string) $image_id );
		++$state['done']['images'];
		return (int) $id;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Orders
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Copies orders with their lines. Customers' old Sunshine order links do
	 * not carry over; orders get new private links.
	 *
	 * @param array $state Progress.
	 * @param float $until Deadline.
	 */
	private static function step_orders( array &$state, float $until ): bool {
		global $wpdb;
		$orders = get_posts(
			[
				'post_type'   => 'sunshine-order',
				'post_status' => 'any',
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'fields'      => 'ids',
			]
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom table name from Schema.
		$done   = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT gateway_ref FROM ' . Schema::orders() . ' WHERE gateway = %s', self::GATEWAY ) ) ) );
		$images = self::imported_ids( 'attachment' );
		$did    = false;

		foreach ( $orders as $order_id ) {
			if ( isset( $done[ $order_id ] ) ) {
				continue;
			}
			if ( $did && microtime( true ) >= $until ) {
				return false;
			}
			$did = true;
			self::copy_order( (int) $order_id, $images );
			++$state['done']['orders'];
		}
		return true;
	}

	/**
	 * Copies one order.
	 *
	 * @param int   $order_id Sunshine order.
	 * @param array $images   Sunshine attachment ID → new attachment ID.
	 */
	private static function copy_order( int $order_id, array $images ): void {
		global $wpdb;
		$post     = get_post( $order_id );
		$meta     = static fn( string $key ): string => is_scalar( get_post_meta( $order_id, $key, true ) ) ? (string) get_post_meta( $order_id, $key, true ) : '';
		$currency = strtoupper( '' !== $meta( 'currency' ) ? $meta( 'currency' ) : (string) Settings::get( 'currency' ) );
		$money    = static fn( $value ): int => (int) round( (float) $value * ( 10 ** Money::decimals( $currency ) ) );
		$statuses = [
			'new'        => 'paid',
			'processing' => 'paid',
			'pickup'     => 'paid',
			'shipped'    => 'completed',
			'cancelled'  => 'cancelled',
			'refunded'   => 'refunded',
			// Kept as records, so not "pending"/"failed" (those are deleted after a week).
			'pending'    => 'on-hold',
			'failed'     => 'cancelled',
		];
		$sunshine = self::term_slug( $order_id, 'sunshine-order-status' );
		$status   = $statuses[ $sunshine ] ?? 'on-hold';
		$created  = '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : get_gmt_from_date( $post->post_date );
		$paid     = in_array( $status, [ 'paid', 'completed', 'refunded' ], true );
		$days     = (int) Settings::get( 'download_days' );

		$prefix  = '' !== $meta( 'shipping_address1' ) ? 'shipping_' : 'billing_';
		$address = array_filter(
			[
				'line1'    => $meta( $prefix . 'address1' ),
				'line2'    => $meta( $prefix . 'address2' ),
				'postcode' => $meta( $prefix . 'postcode' ),
				'city'     => $meta( $prefix . 'city' ),
				'country'  => strtoupper( $meta( $prefix . 'country' ) ),
			]
		);
		$first   = '' !== $meta( 'first_name' ) ? $meta( 'first_name' ) : $meta( 'billing_first_name' );
		$last    = '' !== $meta( 'last_name' ) ? $meta( 'last_name' ) : $meta( 'billing_last_name' );
		$number  = '' !== $meta( 'order_number' ) ? $meta( 'order_number' ) : (string) $order_id;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
		$wpdb->insert(
			Schema::orders(),
			[
				'token'              => bin2hex( random_bytes( 16 ) ),
				'status'             => $status,
				'user_id'            => (int) $meta( 'customer_id' ),
				'email'              => sanitize_email( $meta( 'email' ) ),
				'name'               => trim( $first . ' ' . $last ),
				'phone'              => $meta( 'phone' ),
				'address'            => $address ? (string) wp_json_encode( $address ) : null,
				'customer_note'      => $meta( 'customer_notes' ),
				'admin_note'         => sprintf(
					/* translators: 1: Sunshine order number, 2: payment method */
					__( 'Imported from Sunshine order %1$s (payment: %2$s).', 'slashphoto' ),
					$number,
					'' !== $meta( 'payment_method_name' ) ? $meta( 'payment_method_name' ) : $meta( 'payment_method' )
				),
				'currency'           => substr( $currency, 0, 3 ),
				'subtotal'           => $money( $meta( 'subtotal' ) ),
				'discount'           => $money( $meta( 'discount' ) ),
				'shipping'           => $money( $meta( 'shipping' ) ),
				'tax'                => $money( $meta( 'tax' ) ),
				'total'              => $money( $meta( 'total' ) ),
				'tax_rate'           => 0,
				'prices_include_tax' => 'yes' === $meta( 'price_has_tax' ) ? 1 : 0,
				'gateway'            => self::GATEWAY,
				'gateway_ref'        => (string) $order_id,
				'created_at'         => $created,
				'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
				'paid_at'            => $paid ? $created : null,
				'downloads_until'    => $paid && $days > 0 ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $created . ' UTC' ) + $days * DAY_IN_SECONDS ) : null,
			]
		);
		$new = (int) $wpdb->insert_id;
		if ( ! $new ) {
			return;
		}

		$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sunshine_order_items WHERE order_id = %d ORDER BY order_item_id", $order_id ), ARRAY_A );
		foreach ( $items as $item ) {
			$item_meta = [];
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}sunshine_order_itemmeta WHERE order_item_id = %d", $item['order_item_id'] ), ARRAY_A ) as $row ) {
				$item_meta[ $row['meta_key'] ] = maybe_unserialize( $row['meta_value'] );
			}
			$product  = (int) $item['product_id'];
			$name     = is_string( $item_meta['product_name'] ?? null ) ? $item_meta['product_name'] : ( $product ? (string) get_post_field( 'post_title', $product, 'raw' ) : (string) $item['type'] );
			$category = is_string( $item_meta['product_cat_name'] ?? null ) ? $item_meta['product_cat_name'] : '';
			$file     = is_string( $item_meta['filename'] ?? null ) ? $item_meta['filename'] : ( is_string( $item_meta['image_name'] ?? null ) ? $item_meta['image_name'] : '' );
			$qty      = max( 1, (int) $item['qty'] );
			$unit     = $money( $item['price'] );
			$image    = (int) ( $images[ (int) $item['image_id'] ] ?? 0 );
			$gallery  = $item['gallery_id'] ? self::find_post( PostType::POST_TYPE, (string) (int) $item['gallery_id'] ) : 0;
			$wpdb->insert(
				Schema::items(),
				[
					'order_id'   => $new,
					'kind'       => $product && 'download' === self::product_kind( $product ) ? 'download' : 'print',
					'gallery_id' => $gallery,
					'image_id'   => max( 0, $image ),
					'product_id' => $product ? self::product_id( $product ) : '',
					'name'       => mb_substr( wp_strip_all_tags( ( '' !== $category ? $category . ' – ' : '' ) . $name ), 0, 200 ),
					'file_label' => mb_substr( (string) pathinfo( wp_strip_all_tags( $file ), PATHINFO_FILENAME ), 0, 200 ),
					'quantity'   => $qty,
					'unit_price' => $unit,
					'total'      => $unit * $qty,
				]
			);
		}//end foreach
		// phpcs:enable
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Number of posts of a type (any status except trash and auto drafts).
	 *
	 * @param string $type Post type.
	 */
	private static function count_posts( string $type ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the post type may not be registered.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')", $type ) );
	}

	/**
	 * Our post created from a Sunshine ID, or 0.
	 *
	 * @param string $post_type Our post type.
	 * @param string $source    Sunshine ID (or "level-<id>").
	 */
	private static function find_post( string $post_type, string $source ): int {
		global $wpdb;
		// Read directly: SlashPhoto's access filters hide protected galleries from normal queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- import bookkeeping.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.ID LIMIT 1", self::META, $source, $post_type ) );
	}

	/**
	 * Sunshine ID → our ID for everything of a post type imported so far.
	 *
	 * @param string $post_type Post type.
	 * @return array<int, int>
	 */
	private static function imported_ids( string $post_type ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- import bookkeeping.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value AS source, p.ID AS id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s", self::META, $post_type ), ARRAY_A );
		$map  = [];
		foreach ( $rows as $row ) {
			$map[ (int) $row['source'] ] = (int) $row['id'];
		}
		return $map;
	}

	/**
	 * First term name of a post in a taxonomy that may not be registered.
	 *
	 * @param int    $post_id  Post.
	 * @param string $taxonomy Taxonomy.
	 */
	private static function term_name( int $post_id, string $taxonomy ): string {
		return (string) ( self::term( $post_id, $taxonomy )['name'] ?? '' );
	}

	/**
	 * First term slug of a post in a taxonomy that may not be registered.
	 *
	 * @param int    $post_id  Post.
	 * @param string $taxonomy Taxonomy.
	 */
	private static function term_slug( int $post_id, string $taxonomy ): string {
		return (string) ( self::term( $post_id, $taxonomy )['slug'] ?? '' );
	}

	/**
	 * First term of a post, read directly from the database.
	 *
	 * @param int    $post_id  Post.
	 * @param string $taxonomy Taxonomy.
	 * @return array{name: string, slug: string}|null
	 */
	private static function term( int $post_id, string $taxonomy ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the taxonomy may not be registered.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.name, t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY t.term_id LIMIT 1", $post_id, $taxonomy ), ARRAY_A );
		return $row ? $row : null;
	}
}
