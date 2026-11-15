<?php
/**
 * Import from Sunshine Photo Cart (data written the way Sunshine 3.8 stores it).
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

// phpcs:disable WordPress.DB.SlowDBQuery, WordPress.DB.DirectDatabaseQuery -- test fixtures read and written the way Sunshine stores data.

use SlashPhoto\Access\Access;
use SlashPhoto\Access\Terms;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Import\SunshineImporter;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\Schema;

final class SunshineImportTest extends AccessTestCase {

	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		PriceLists::register();
		register_post_type( 'sunshine-gallery', [ 'hierarchical' => true ] );
		register_taxonomy( 'sunshine-product-price-level', 'sunshine-product' );
		register_taxonomy( 'sunshine-product-category', 'sunshine-product' );
		register_taxonomy( 'sunshine-order-status', 'sunshine-order' );
		global $wpdb;
		// Sunshine tables, written directly.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sunshine_order_items ( order_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_id bigint(20) unsigned NOT NULL, type varchar(50) NOT NULL, image_id bigint(20) NOT NULL, qty int(10) unsigned NOT NULL, product_id bigint(20) unsigned NOT NULL, gallery_id bigint(20) unsigned NOT NULL, price_level bigint(20) unsigned NOT NULL, price decimal(19,4) unsigned NOT NULL, tax decimal(19,4) unsigned NOT NULL, discount decimal(19,4) unsigned NOT NULL, PRIMARY KEY (order_item_id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sunshine_order_itemmeta ( order_itemmeta_id bigint(20) NOT NULL AUTO_INCREMENT, order_item_id bigint(20) NOT NULL, meta_key varchar(190) DEFAULT NULL, meta_value longtext, PRIMARY KEY (order_itemmeta_id) )" );
		// End of direct Sunshine table writes.
		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'currency' => 'SEK' ] ) );
		update_option( 'sunshine_gallery_terms_text', 'No commercial use.' );
		$this->sunshine_site();
	}

	public function tear_down(): void {
		delete_option( SunshineImporter::STATE );
		delete_option( Settings::OPTION );
		delete_option( 'sunshine_gallery_terms_text' );
		parent::tear_down();
	}

	private function post( string $type, array $args, array $meta = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_type'   => $type,
					'post_status' => 'publish',
				],
				$args
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	private function sunshine_site(): void {
		$standard = wp_insert_term( 'Standard', 'sunshine-product-price-level' )['term_id'];
		$wedding  = wp_insert_term( 'Wedding', 'sunshine-product-price-level' )['term_id'];
		update_term_meta( $standard, 'default', 1 );
		$prints = wp_insert_term( 'Prints', 'sunshine-product-category' )['term_id'];

		$print    = $this->post(
			'sunshine-product',
			[ 'post_title' => '8x10' ],
			[
				'type'  => 'print',
				'price' => [
					$standard => 25,
					$wedding  => 30.5,
				],
			]
		);
		$download = $this->post(
			'sunshine-product',
			[ 'post_title' => 'Full resolution' ],
			[
				'type'  => 'download',
				'price' => [ $standard => 99 ],
			]
		);
		wp_set_object_terms( $print, [ $prints ], 'sunshine-product-category' );

		$top     = $this->post(
			'sunshine-gallery',
			[
				'post_title'   => 'Smith wedding',
				'post_name'    => 'smith-wedding',
				'post_content' => 'Hello!',
			],
			[
				'status'        => 'password',
				'password'      => 'love',
				'password_hint' => 'Our dog',
				'price_level'   => $wedding,
				'terms'         => 'required',
				'end_date'      => mktime( 12, 0, 0, 6, 30, 2031 ),
			]
		);
		$child   = $this->post(
			'sunshine-gallery',
			[
				'post_title'  => 'Ceremony',
				'post_parent' => $top,
			]
		);
		$clients = [ self::factory()->user->create(), self::factory()->user->create() ];
		$priv    = $this->post(
			'sunshine-gallery',
			[ 'post_title' => 'Private' ],
			[
				'status'        => 'private',
				'private_users' => array_map( 'strval', $clients ),
			]
		);
		$email   = $this->post(
			'sunshine-gallery',
			[ 'post_title' => 'Email gate' ],
			[
				'access_type'      => 'email',
				'disable_products' => 1,
				'emails'           => [ 'Guest@example.org', 'nope' ],
			]
		);
		$url     = $this->post( 'sunshine-gallery', [ 'post_title' => 'Link only' ], [ 'access_type' => 'url' ] );
		$members = $this->post( 'sunshine-gallery', [ 'post_title' => 'Members' ], [ 'access_type' => 'account' ] );
		global $wpdb;
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sunshine_terms_agreements ( agreement_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, gallery_id bigint(20) unsigned NOT NULL, user_id bigint(20) unsigned NOT NULL DEFAULT 0, email varchar(254) NOT NULL DEFAULT '', version varchar(32) NOT NULL, time bigint(20) unsigned NOT NULL, PRIMARY KEY (agreement_id) )" );
		$wpdb->insert(
			$wpdb->prefix . 'sunshine_terms_agreements',
			[
				'gallery_id' => $top,
				'email'      => 'agreed@example.org',
				'version'    => 'v2',
				'time'       => 1735689600,
			]
		);

		$a = $this->sunshine_image( $child, 'IMG_0001' );
		$b = $this->sunshine_image( $child, 'IMG_0002' );
		$c = $this->sunshine_image( $priv, 'IMG_0003' );
		// Sunshine keeps the gallery's image order in "images" (b before a).
		update_post_meta( $child, 'images', [ $b, $a ] );
		set_post_thumbnail( $top, $a );
		$missing = self::factory()->attachment->create(
			[
				'post_parent'    => $url,
				'post_mime_type' => 'image/jpeg',
				'file'           => '/nowhere/gone.jpg',
			]
		);
		update_post_meta( $url, 'images', [ $missing ] );

		$order  = $this->post(
			'sunshine-order',
			[
				'post_title'    => 'Order #12',
				'post_date'     => '2025-03-01 10:00:00',
				'post_date_gmt' => '2025-03-01 09:00:00',
			],
			[
				'order_number'      => '12',
				'email'             => 'kim@example.org',
				'first_name'        => 'Kim',
				'last_name'         => 'Client',
				'shipping_address1' => 'Storgatan 1',
				'shipping_postcode' => '111 22',
				'shipping_city'     => 'Stockholm',
				'shipping_country'  => 'se',
				'currency'          => 'SEK',
				'subtotal'          => 160,
				'discount'          => 10,
				'shipping'          => 49,
				'tax'               => 39.8,
				'total'             => 199,
				'price_has_tax'     => 'yes',
				'payment_method'    => 'stripe',
			]
		);
		$status = wp_insert_term( 'Shipped', 'sunshine-order-status', [ 'slug' => 'shipped' ] )['term_id'];
		wp_set_object_terms( $order, [ $status ], 'sunshine-order-status' );
		global $wpdb;
		// Sunshine tables, written directly.
		$wpdb->insert(
			$wpdb->prefix . 'sunshine_order_items',
			[
				'order_id'    => $order,
				'type'        => 'image',
				'image_id'    => $a,
				'qty'         => 2,
				'product_id'  => $print,
				'gallery_id'  => $child,
				'price_level' => $wedding,
				'price'       => 30.5,
				'tax'         => 0,
				'discount'    => 0,
			]
		);
		$item = $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'sunshine_order_itemmeta',
			[
				'order_item_id' => $item,
				'meta_key'      => 'product_name',
				'meta_value'    => '8x10',
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'sunshine_order_itemmeta',
			[
				'order_item_id' => $item,
				'meta_key'      => 'product_cat_name',
				'meta_value'    => 'Prints',
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'sunshine_order_itemmeta',
			[
				'order_item_id' => $item,
				'meta_key'      => 'filename',
				'meta_value'    => 'IMG_0001.jpg',
			]
		);
		$wpdb->insert(
			$wpdb->prefix . 'sunshine_order_items',
			[
				'order_id'    => $order,
				'type'        => 'image',
				'image_id'    => $b,
				'qty'         => 1,
				'product_id'  => $download,
				'gallery_id'  => $child,
				'price_level' => $wedding,
				'price'       => 99,
				'tax'         => 0,
				'discount'    => 0,
			]
		);
		// End of direct Sunshine table writes.

		$this->ids = compact( 'standard', 'wedding', 'top', 'child', 'priv', 'email', 'url', 'members', 'a', 'b', 'c', 'order', 'clients' );
	}

	private function sunshine_image( int $gallery, string $name ): int {
		$file = get_temp_dir() . $name . '.jpg';
		copy( TestImages::jpeg( 900, 600 ), $file );
		$id = self::factory()->attachment->create_upload_object( $file, $gallery );
		update_post_meta( $id, 'sunshine_file_name', $name . '.jpg' );
		wp_update_post(
			[
				'ID'           => $id,
				'post_title'   => $name . ' title',
				'post_excerpt' => 'Caption ' . $name,
			]
		);
		return $id;
	}

	private function ours( string $type, int $sunshine_id ): int {
		global $wpdb;
		// Direct query: SlashPhoto hides protected galleries from normal queries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = %s", SunshineImporter::META, (string) $sunshine_id, $type ) );
	}

	private function count_ours( string $type ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND p.post_type = %s", SunshineImporter::META, $type ) );
	}

	public function test_full_import(): void {
		$this->assertTrue( SunshineImporter::has_data() );
		$summary = SunshineImporter::summary();
		$this->assertSame( 6, $summary['galleries'] );
		$this->assertSame( 2, $summary['levels'] );
		$this->assertSame( 1, $summary['orders'] );

		$state = SunshineImporter::run( 60 );
		$this->assertSame( 'done', $state['step'] );
		$this->assertSame( 2, $state['done']['price_lists'] );
		$this->assertSame( 6, $state['done']['galleries'] );
		$this->assertSame( 2, $state['done']['visitors'] );
		$this->assertSame( 3, $state['done']['images'] );
		$this->assertSame( 1, $state['done']['orders'] );

		// Price lists.
		$wedding  = (int) get_posts(
			[
				'post_type'  => PriceLists::POST_TYPE,
				'meta_key'   => SunshineImporter::META,
				'meta_value' => 'level-' . $this->ids['wedding'],
				'fields'     => 'ids',
			]
		)[0];
		$products = PriceLists::products( $wedding );
		$this->assertSame( [ 'Prints – 8x10' ], array_column( $products, 'name' ), 'Only products priced at the level.' );
		$this->assertSame( 3050, $products[0]['price'] );
		$this->assertSame( 'print', $products[0]['kind'] );
		$standard = (int) get_posts(
			[
				'post_type'  => PriceLists::POST_TYPE,
				'meta_key'   => SunshineImporter::META,
				'meta_value' => 'level-' . $this->ids['standard'],
				'fields'     => 'ids',
			]
		)[0];
		$this->assertSame( [ 'print', 'download' ], array_column( PriceLists::products( $standard ), 'kind' ) );
		$this->assertSame( $standard, (int) Settings::get( 'shop_price_list' ), 'The default level becomes the default list.' );

		// Galleries and access.
		$top   = get_post( $this->ours( PostType::POST_TYPE, $this->ids['top'] ) );
		$child = get_post( $this->ours( PostType::POST_TYPE, $this->ids['child'] ) );
		$this->assertSame( 'Smith wedding', $top->post_title );
		$this->assertSame( 'smith-wedding', $top->post_name );
		$this->assertSame( 'love', $top->post_password );
		$this->assertSame( $top->ID, $child->post_parent );
		$this->assertSame( (string) $wedding, get_post_meta( $top->ID, PriceLists::GALLERY_META, true ) );
		$this->assertSame( '2031-06-30', get_post_meta( $top->ID, Access::META_EXPIRES, true ) );
		$this->assertSame( 'No commercial use.', get_post_meta( $top->ID, Terms::META_TERMS, true ) );
		$priv = $this->ours( PostType::POST_TYPE, $this->ids['priv'] );
		$this->assertSame( Access::MODE_CLIENTS, get_post_meta( $priv, Access::META_MODE, true ) );
		$this->assertSame( $this->ids['clients'], get_post_meta( $priv, Access::META_CLIENTS, true ) );
		$this->assertSame( Access::MODE_LINK, get_post_meta( $this->ours( PostType::POST_TYPE, $this->ids['url'] ), Access::META_MODE, true ) );
		$this->assertSame( PriceLists::NONE, get_post_meta( $this->ours( PostType::POST_TYPE, $this->ids['email'] ), PriceLists::GALLERY_META, true ) );
		$notes = implode( "\n", $state['notes'] );
		$this->assertStringNotContainsString( 'Email gate', $notes, 'Email gates carry over now.' );
		$this->assertSame( 'on', get_post_meta( $this->ours( PostType::POST_TYPE, $this->ids['email'] ), \SlashPhoto\Access\EmailGate::META, true ) );
		$this->assertSame( Access::MODE_MEMBERS, get_post_meta( $this->ours( PostType::POST_TYPE, $this->ids['members'] ), Access::META_MODE, true ) );
		$log = \SlashPhoto\Visitors\VisitorLog::query( [] )['rows'];
		$this->assertEqualsCanonicalizing( [ 'guest@example.org', 'agreed@example.org' ], array_column( $log, 'email' ) );
		$terms = array_values( array_filter( $log, static fn( array $row ): bool => 'terms' === $row['type'] ) )[0];
		$this->assertSame( '2025-01-01 00:00:00', $terms['created_at'] );
		$this->assertSame( $this->ours( PostType::POST_TYPE, $this->ids['top'] ), (int) $terms['gallery_id'] );
		$this->assertStringContainsString( 'Our dog', $notes );
		$this->assertStringContainsString( 'gone.jpg', $notes, 'A missing file is reported.' );

		// Images: copied in Sunshine's order, original kept privately, Sunshine untouched.
		$images = get_children(
			[
				'post_parent' => $child->ID,
				'post_type'   => 'attachment',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			]
		);
		$images = array_values( $images );
		$this->assertCount( 2, $images );
		$this->assertSame( 'IMG_0002 title', $images[0]->post_title );
		$this->assertSame( 'Caption IMG_0002', $images[0]->post_excerpt );
		$this->assertMatchesRegularExpression( '#^o[a-f0-9]{24}/IMG_0002-#', wp_get_attachment_metadata( $images[0]->ID )['original_image'] );
		$this->assertSame( $this->ours( 'attachment', $this->ids['a'] ), (int) get_post_thumbnail_id( $top->ID ) );
		$this->assertFileExists( get_attached_file( $this->ids['a'] ), 'Sunshine files are not moved.' );
		$this->assertSame( $this->ids['child'], get_post( $this->ids['a'] )->post_parent );

		// Order.
		$order = Orders::find_by_ref( SunshineImporter::GATEWAY, (string) $this->ids['order'] );
		$this->assertSame( 'completed', $order['status'] );
		$this->assertSame( 19900, $order['total'] );
		$this->assertSame( 1000, $order['discount'] );
		$this->assertSame( 'Kim Client', $order['name'] );
		$this->assertSame( 'SE', $order['address']['country'] );
		$this->assertSame( '2025-03-01 09:00:00', $order['created_at'] );
		$this->assertStringContainsString( 'Sunshine order 12', $order['admin_note'] );
		$items = Orders::items( (int) $order['id'] );
		$this->assertSame( 'Prints – 8x10', $items[0]['name'] );
		$this->assertSame( 'IMG_0001', $items[0]['file_label'] );
		$this->assertSame( 6100, $items[0]['total'] );
		$this->assertSame( $this->ours( 'attachment', $this->ids['a'] ), $items[0]['image_id'] );
		$this->assertSame( 'download', $items[1]['kind'] );
		$this->assertSame( $child->ID, $items[1]['gallery_id'] );
	}

	public function test_running_again_creates_no_duplicates(): void {
		SunshineImporter::run( 60 );
		SunshineImporter::restart();
		$state = SunshineImporter::run( 60 );
		$this->assertSame( 'done', $state['step'] );
		$this->assertSame( 2 + 6 + 3 + 1 + 2, array_sum( $state['done'] ), 'Counts are kept; nothing new was added.' );
		$this->assertSame( 6, $this->count_ours( PostType::POST_TYPE ) );
		$this->assertSame( 3, $this->count_ours( 'attachment' ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::orders() . " WHERE gateway = 'sunshine'" ) );
	}

	public function test_import_runs_in_short_steps(): void {
		$steps = 0;
		do {
			$state = SunshineImporter::run( 0 );
			++$steps;
		} while ( 'done' !== $state['step'] && $steps < 50 );
		$this->assertSame( 'done', $state['step'] );
		$this->assertGreaterThan( 2, $steps );
		$this->assertSame( 3, $state['done']['images'] );
	}

	public function test_only_admins_can_run_it_over_rest(): void {
		$this->as_user( 'editor' );
		$request = new \WP_REST_Request( 'POST', '/slashphoto/v1/import/sunshine' );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'prices', SunshineImporter::state()['step'] );
	}
}
