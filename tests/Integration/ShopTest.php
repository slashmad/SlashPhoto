<?php
/**
 * Shop: price lists, cart quotes, orders, downloads.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Plugin;
use SlashPhoto\Admin\PrintExport;
use SlashPhoto\Privacy;
use SlashPhoto\Settings;
use SlashPhoto\Shop\CheckoutRest;
use SlashPhoto\Shop\Downloads;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\PrintPresets;
use SlashPhoto\Shop\ShopPage;

final class ShopTest extends ShopTestCase {

	public function test_money_parsing_formatting_and_tax(): void {
		$this->assertSame( 9950, Money::parse( '99,50', 'SEK' ) );
		$this->assertSame( 120000, Money::parse( '1 200', 'SEK' ) );
		$this->assertSame( 120050, Money::parse( '1.200,50', 'SEK' ) );
		$this->assertSame( 1200, Money::parse( '1200', 'JPY' ) );
		$this->assertSame( 0, Money::parse( 'abc', 'SEK' ) );
		$this->assertSame( '99.50', Money::to_input( 9950, 'SEK' ) );
		$this->assertSame( 2500, Money::tax( 12500, 25, true ) );
		$this->assertSame( 2500, Money::tax( 10000, 25, false ) );
		$this->assertSame( 0, Money::tax( 10000, 0, true ) );
		$this->assertStringContainsString( '300', Money::format( 30000, 'SEK' ) );
	}

	public function test_price_list_is_inherited_and_can_be_switched_off(): void {
		$access = Plugin::instance()->access();
		$top    = $this->shop_gallery();
		$child  = $this->gallery( [ 'post_parent' => $top ] );
		$off    = $this->gallery( [ 'post_parent' => $top ], [ PriceLists::GALLERY_META => PriceLists::NONE ] );
		$plain  = $this->gallery();

		$this->assertSame( $this->list, PriceLists::for_gallery( get_post( $child ), $access ) );
		$this->assertSame( 0, PriceLists::for_gallery( get_post( $off ), $access ) );
		$this->assertSame( 0, PriceLists::for_gallery( get_post( $plain ), $access ) );

		$this->settings( [ 'shop_price_list' => $this->list ] );
		$this->assertSame( $this->list, PriceLists::for_gallery( get_post( $plain ), $access ) );

		wp_update_post(
			[
				'ID'          => $this->list,
				'post_status' => 'draft',
			]
		);
		$this->assertSame( 0, PriceLists::for_gallery( get_post( $child ), $access ), 'Draft price lists sell nothing.' );
	}

	public function test_unpriced_suggestions_are_not_saved(): void {
		$clean = PriceLists::clean(
			[
				[
					'kind'  => 'print',
					'name'  => '<b>Print</b> 10 × 15',
					'price' => 4900,
				],
				[
					'kind'  => 'poster',
					'name'  => 'Unknown kind',
					'price' => 1,
				],
				[
					'kind'  => 'download',
					'name'  => '',
					'price' => 1,
				],
			]
		);
		$this->assertCount( 1, $clean );
		$this->assertSame( 'Print 10 × 15', $clean[0]['name'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}$/', $clean[0]['id'] );
	}

	public function test_print_options_are_priced_separately_and_saved_on_the_order(): void {
		$list                = PriceLists::products( $this->list );
		$list[1]['variants'] = [
			[
				'paper' => 'Matte photo paper',
				'frame' => '',
				'price' => 21900,
			],
			[
				'paper' => 'Matte photo paper',
				'frame' => 'Black wood',
				'price' => 36900,
			],
		];
		PriceLists::save_products( $this->list, $list );
		$gallery = $this->shop_gallery();
		$image   = $this->image( $gallery );
		$offer   = Plugin::instance()->shop()->offer( get_post( $gallery ) );
		$ids     = array_column( $offer[1]['variants'], 'id' );
		$print   = [
			'image'   => $image,
			'product' => 'aaaa0002',
		];
		$matte   = $print + [ 'variant' => $ids[0] ];
		$framed  = $print + [
			'variant' => $ids[1],
			'price'   => 1,
			'name'    => 'Browser value',
		];
		$items   = [ $matte, $framed, $framed ];
		$quote   = $this->quote( $items );
		$this->assertCount( 2, $quote['lines'] );
		$this->assertSame( [ 1, 2 ], array_column( $quote['lines'], 'qty' ) );
		$this->assertSame( [ 21900, 36900 ], array_column( $quote['lines'], 'unit' ) );
		$this->assertSame( 21900 + 2 * 36900 + 4900, $quote['total'] );
		$this->assertSame(
			[ $image . ':aaaa0002', $image . ':aaaa0002:0000000000000000', $image . ':aaaa0001:' . $ids[1] ],
			$this->quote( [ $print, $print + [ 'variant' => '0000000000000000' ], array_replace( $framed, [ 'product' => 'aaaa0001' ] ) ] )['unavailable']
		);

		$order = $this->place( $items );

		$this->settings( [ 'print_presets' => [] ] );
		$this->assertSame( $quote['lines'], $this->quote( $items )['lines'] );

		$list[1]['variants'] = [ $list[1]['variants'][0] ];
		PriceLists::save_products( $this->list, $list );
		$this->assertSame( [ $image . ':aaaa0002:' . $ids[1] ], $this->quote( $items )['unavailable'] );
		$stored = Orders::items( $order['id'] );
		$this->assertSame( [ 'Print 20 × 30 cm — Matte photo paper / Unframed', 'Print 20 × 30 cm — Matte photo paper / Black wood' ], array_column( $stored, 'name' ) );
		$this->assertSame( [ 21900, 36900 ], array_column( $stored, 'unit_price' ) );
		$this->assertSame( [ 'Print 20 × 30 cm', 'Print 20 × 30 cm' ], array_column( $stored, 'print_product' ) );
		$this->assertSame( [ 'Matte photo paper', 'Matte photo paper' ], array_column( $stored, 'print_paper' ) );
		$this->assertSame( [ '', 'Black wood' ], array_column( $stored, 'print_frame' ) );
	}

	public function test_print_csv_uses_stored_labels_and_filters_orders_without_exporting_contacts(): void {
		$gallery = $this->shop_gallery();
		$image   = $this->image( $gallery );
		$quote   = $this->quote(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
				[
					'image'   => $image,
					'product' => 'aaaa0002',
					'qty'     => 2,
				],
			]
		);
		// An older print line has only a description and a file label.
		unset( $quote['lines'][1]['print_product'] );
		$quote['lines'][1]['name'] = ' =FORMULA()';
		$id                        = Orders::create(
			[
				'email' => 'customer@example.org',
				'name'  => 'Private contact',
			],
			$quote
		);
		$this->assertIsInt( $id );
		$this->assertSame( [], iterator_to_array( Orders::print_rows( 'paid' ) ) );
		$this->assertTrue( Orders::set_status( $id, 'paid' ) );
		$this->assertSame( [], iterator_to_array( Orders::print_rows( 'paid', 'different@example.org' ) ) );

		$csv = fopen( 'php://memory', 'w+' );
		PrintExport::write( $csv, 'paid', '#' . $id );
		rewind( $csv );
		$headers = fgetcsv( $csv, 0, ',', '"', '' );
		$row     = array_combine( $headers, fgetcsv( $csv, 0, ',', '"', '' ) );
		$this->assertSame( (string) $id, $row['order_id'] );
		$this->assertSame( 'paid', $row['status'] );
		$this->assertSame( "' =FORMULA()", $row['description'] );
		$this->assertSame( '', $row['product'] );
		$this->assertSame( '2', $row['quantity'] );
		$this->assertArrayNotHasKey( 'email', $row );
		$this->assertArrayNotHasKey( 'address', $row );
		$this->assertFalse( fgetcsv( $csv, 0, ',', '"', '' ) );
		fclose( $csv );
	}

	public function test_print_options_in_price_list_form_are_validated_before_saving(): void {
		$this->as_user( 'administrator' );
		$lists = new PriceLists();
		$post  = get_post( $this->list );

		$_POST[ PriceLists::NONCE . '_nonce' ] = wp_create_nonce( PriceLists::NONCE );
		$_POST['slashphoto_products']          = [
			[
				'id'            => 'aaaa0002',
				'kind'          => 'print',
				'name'          => 'Poster 30 × 40 cm',
				'price'         => '',
				'variant_input' => "Matte photo paper | | 219,50\nFine art | Natural oak | 429",
			],
		];
		try {
			$lists->save( $this->list, $post );
			$saved = PriceLists::product( $this->list, 'aaaa0002' );
			$this->assertSame( [ 21950, 42900 ], array_column( $saved['variants'], 'price' ) );
			$_POST['slashphoto_products'][0]['variant_input'] = "Matte photo paper | | 219,50\nFine art | Natural oak | invalid";
			$lists->save( $this->list, $post );
			$this->assertSame( $saved, PriceLists::product( $this->list, 'aaaa0002' ) );
		} finally {
			unset( $_POST[ PriceLists::NONCE . '_nonce' ], $_POST['slashphoto_products'] );
		}
	}

	public function test_quote_uses_server_prices_and_shipping_only_for_prints(): void {
		$image = $this->image( $this->shop_gallery() );

		$downloads = $this->quote(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
					'qty'     => 5,
					'price'   => 1,
				],
			]
		);
		$this->assertSame( 1, $downloads['lines'][0]['qty'], 'A download is bought once.' );
		$this->assertSame( 30000, $downloads['total'] );
		$this->assertSame( 0, $downloads['shipping'] );
		$this->assertFalse( $downloads['needs_shipping'] );
		$this->assertSame( 6000, $downloads['tax'], 'VAT included in 300 kr at 25 % is 60 kr.' );

		$prints = $this->quote(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0002',
					'qty'     => 2,
				],
				[
					'image'   => $image,
					'product' => 'aaaa0002',
					'qty'     => 1,
				],
			]
		);
		$this->assertCount( 1, $prints['lines'], 'Same image and product are merged.' );
		$this->assertSame( 3, $prints['lines'][0]['qty'] );
		$this->assertSame( 3 * 19900 + 4900, $prints['total'] );

		$this->settings( [ 'free_shipping_over' => 50000 ] );
		$this->assertSame(
			0,
			$this->quote(
				[
					[
						'image'   => $image,
						'product' => 'aaaa0002',
						'qty'     => 3,
					],
				]
			)['shipping']
		);

		$this->settings( [ 'prices_include_tax' => false ] );
		$excluded = $this->quote(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		$this->assertSame( 7500, $excluded['tax'] );
		$this->assertSame( 37500, $excluded['total'] );
	}

	public function test_quote_refuses_images_the_visitor_cannot_see_or_buy(): void {
		$public    = $this->image( $this->shop_gallery() );
		$protected = $this->image( $this->shop_gallery( [ 'post_password' => 'secret' ] ) );
		$clients   = $this->image( $this->clients_gallery( [ 999 ], [] ) );
		update_post_meta( (int) get_post( $clients )->post_parent, PriceLists::GALLERY_META, (string) $this->list );
		$unlisted = $this->image( $this->gallery() );
		$other    = $this->price_list(
			'Other',
			[
				[
					'id'    => 'bbbb0001',
					'kind'  => 'download',
					'name'  => 'Cheap',
					'price' => 100,
				],
			]
		);
		$this->as_anonymous();

		$quote = $this->quote(
			[
				[
					'image'   => $public,
					'product' => 'aaaa0001',
				],
				[
					'image'   => $public,
					'product' => 'bbbb0001',
				],
				[
					'image'   => $protected,
					'product' => 'aaaa0001',
				],
				[
					'image'   => $clients,
					'product' => 'aaaa0001',
				],
				[
					'image'   => $unlisted,
					'product' => 'aaaa0001',
				],
				[
					'image'   => 999999,
					'product' => 'aaaa0001',
				],
			]
		);
		$this->assertSame( [ $public ], array_column( $quote['lines'], 'image' ) );
		$this->assertCount( 5, $quote['unavailable'] );
		$this->assertGreaterThan( 0, $other );

		$this->enter_password( 'secret' );
		Plugin::instance()->access()->flush();
		$this->assertCount(
			1,
			$this->quote(
				[
					[
						'image'   => $protected,
						'product' => 'aaaa0001',
					],
				]
			)['lines']
		);
	}

	public function test_manual_order_waits_for_payment_and_emails_both_sides(): void {
		$image = $this->image( $this->shop_gallery() );
		$this->as_anonymous();
		$order = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
				[
					'image'   => $image,
					'product' => 'aaaa0002',
					'qty'     => 2,
				],
			],
			[ 'note' => 'Matte paper please' ]
		);

		$this->assertSame( 'on-hold', $order['status'] );
		$this->assertSame( 30000 + 2 * 19900 + 4900, $order['total'] );
		$this->assertSame( 'Stockholm', $order['address']['city'] );
		$this->assertSame( 'manual', $order['gateway'] );
		$this->assertCount( 2, Orders::items( (int) $order['id'] ) );
		$this->assertFalse( Orders::downloads_open( $order ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'kim@example.org', $mailer->get_sent( 0 )->to[0][0] );
		$this->assertStringContainsString( 'Swish 123 456 78 90', $mailer->get_sent( 0 )->body );
		$this->assertStringContainsString( $order['key'], $mailer->get_sent( 0 )->body );
		$this->assertSame( 'studio@example.org', $mailer->get_sent( 1 )->to[0][0] );
		$this->assertStringContainsString( 'Matte paper please', $mailer->get_sent( 1 )->body );
		$this->assertStringContainsString( 'Storgatan 1', $mailer->get_sent( 1 )->body );
		$this->assertStringNotContainsString( $order['key'], $mailer->get_sent( 1 )->body, 'The shop owner gets the admin link, not the customer key.' );
	}

	public function test_order_validation(): void {
		$image  = $this->image( $this->shop_gallery() );
		$prints = [
			[
				'image'   => $image,
				'product' => 'aaaa0002',
			],
		];

		$changed = $this->post( 'orders', $this->order_body( $prints, [ 'total' => 100 ] ) );
		$this->assertSame( 409, $changed->get_status() );
		$this->assertSame( 'slashphoto_cart_changed', $changed->get_data()['code'] );

		$no_address = $this->post( 'orders', $this->order_body( $prints, [ 'address' => [ 'line1' => '' ] ] ) );
		$this->assertSame( 400, $no_address->get_status() );
		$this->assertSame( 'line1', $no_address->get_data()['data']['field'] );

		$country = $this->post(
			'orders',
			$this->order_body(
				$prints,
				[
					'address' => [
						'line1'    => 'x',
						'postcode' => '1',
						'city'     => 'y',
						'country'  => 'US',
					],
				]
			)
		);
		$this->assertSame( 'country', $country->get_data()['data']['field'] );

		$this->assertSame( 'email', $this->post( 'orders', $this->order_body( $prints, [ 'email' => 'nope' ] ) )->get_data()['data']['field'] );
		$this->assertSame( 400, $this->post( 'orders', $this->order_body( $prints, [ 'website' => 'http://spam' ] ) )->get_status() );
		$this->assertSame( 'gateway', $this->post( 'orders', $this->order_body( $prints, [ 'gateway' => 'bitcoin' ] ) )->get_data()['data']['field'] );
		$this->assertSame( 400, $this->post( 'orders', $this->order_body( [] ) )->get_status() );

		$terms = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		$this->settings( [ 'shop_terms_page' => $terms ] );
		$this->assertSame( 'terms', $this->post( 'orders', $this->order_body( $prints ) )->get_data()['data']['field'] );
		$this->assertSame( 200, $this->post( 'orders', $this->order_body( $prints, [ 'terms' => true ] ) )->get_status() );
	}

	public function test_downloads_open_only_for_paid_orders_with_the_right_key(): void {
		$image    = $this->image( $this->shop_gallery() );
		$order    = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
				[
					'image'   => $image,
					'product' => 'aaaa0002',
				],
			]
		);
		$items    = Orders::items( (int) $order['id'] );
		$download = $items[0];
		$print    = $items[1];

		$this->assertSame( 'slashphoto_download_closed', Downloads::resolve( $order['id'], $download['id'], $order['key'] )->get_error_code() );

		reset_phpmailer_instance();
		$this->assertTrue( Orders::mark_paid( (int) $order['id'] ) );
		$this->assertFalse( Orders::mark_paid( (int) $order['id'] ), 'Only once.' );
		$this->assertStringContainsString( 'Download your photos here', tests_retrieve_phpmailer_instance()->get_sent( 0 )->body );

		$file = Downloads::resolve( $order['id'], $download['id'], $order['key'] );
		$this->assertIsArray( $file );
		$this->assertSame( wp_get_original_image_path( $image ), $file['path'] );
		$this->assertStringContainsString( '/o', $file['path'], 'The original, not the display copy.' );
		$this->assertStringEndsWith( '.jpg', $file['name'] );

		$this->assertSame( 'slashphoto_download_not_found', Downloads::resolve( $order['id'], $download['id'], str_repeat( '0', 32 ) )->get_error_code() );
		$this->assertSame( 'slashphoto_download_not_found', Downloads::resolve( $order['id'], $print['id'], $order['key'] )->get_error_code() );
		$this->assertSame( 'slashphoto_download_not_found', Downloads::resolve( $order['id'] + 1, $download['id'], $order['key'] )->get_error_code() );

		Orders::update( (int) $order['id'], [ 'downloads_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
		$this->assertSame( 'slashphoto_download_closed', Downloads::resolve( $order['id'], $download['id'], $order['key'] )->get_error_code() );
	}

	public function test_order_page_needs_the_key(): void {
		$image = $this->image( $this->shop_gallery() );
		$order = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		$page  = new ShopPage( Plugin::instance()->templates() );

		$_GET['order'] = (string) $order['id'];
		$_GET['key']   = str_repeat( 'a', 32 );
		$this->assertStringContainsString( 'not valid', $page->render() );

		$_GET['key'] = $order['key'];
		$html        = $page->render();
		$this->assertStringContainsString( 'Order ' . $order['id'], $html );
		$this->assertStringContainsString( 'Swish 123 456 78 90', $html );
		$this->assertStringNotContainsString( Downloads::QUERY, $html );

		Orders::mark_paid( (int) $order['id'] );
		$this->assertStringContainsString( Downloads::QUERY, $page->render() );

		unset( $_GET['order'], $_GET['key'] );
		$this->assertStringContainsString( 'data-slashphoto-checkout', $page->render() );
		$this->assertStringContainsString( 'value="manual"', $page->render() );
	}

	public function test_gallery_markup_offers_products_only_where_for_sale(): void {
		$gallery = $this->shop_gallery();
		$this->image( $gallery );
		$html = Plugin::instance()->renderer()->render( get_post( $gallery ) );
		$this->assertStringContainsString( 'data-slashphoto-shop=', $html );
		$this->assertStringContainsString( 'aaaa0002', $html );
		$this->assertStringNotContainsString( '19900', $html, 'Formatted prices only.' );

		$plain = $this->gallery();
		$this->image( $plain );
		$this->assertStringNotContainsString( 'data-slashphoto-shop', Plugin::instance()->renderer()->render( get_post( $plain ) ) );
	}

	public function test_abandoned_checkouts_are_deleted_after_a_week(): void {
		$image   = $this->image( $this->shop_gallery() );
		$pending = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		Orders::update( (int) $pending['id'], [ 'status' => 'pending' ] );
		$paid = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		Orders::mark_paid( (int) $paid['id'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test table name from Schema.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \SlashPhoto\Shop\Schema::orders() . ' SET updated_at = %s', gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ) );
		Orders::delete_abandoned();

		$this->assertNull( Orders::get( (int) $pending['id'] ) );
		$this->assertSame( [], Orders::items( (int) $pending['id'] ) );
		$this->assertNotNull( Orders::get( (int) $paid['id'] ) );
	}

	public function test_privacy_export_and_erasure_keep_paid_orders(): void {
		$image  = $this->image( $this->shop_gallery() );
		$unpaid = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		$paid   = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		Orders::mark_paid( (int) $paid['id'] );

		$export = Privacy::export_orders( 'kim@example.org' );
		$this->assertCount( 2, $export['data'] );
		$this->assertSame( 'Kim Client', $export['data'][0]['data'][3]['value'] );

		$erase = Privacy::erase_orders( 'kim@example.org' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertTrue( $erase['items_retained'] );
		$this->assertNull( Orders::get( (int) $unpaid['id'] ) );
		$this->assertNotNull( Orders::get( (int) $paid['id'] ) );
	}

	public function test_settings_sections_do_not_overwrite_each_other(): void {
		$this->settings(
			[
				'watermark_scale' => 44,
				'shipping_flat'   => 4900,
			]
		);
		$clean = Settings::sanitize(
			[
				'_section'            => 'shop',
				'currency'            => 'EUR',
				'shipping_flat_input' => '12,50',
				'tax_rate'            => '24,5',
			]
		);
		$this->assertSame( 44, $clean['watermark_scale'] );
		$this->assertSame( 1250, $clean['shipping_flat'] );
		$this->assertSame( 24.5, $clean['tax_rate'] );
		$this->assertFalse( $clean['prices_include_tax'], 'Unchecked boxes in the submitted section are off.' );
		$this->assertFalse( $clean['manual_enabled'] );
		$this->assertSame( 'EUR', $clean['currency'] );
	}

	public function test_settings_tab_save_only_applies_its_own_fields(): void {
		$this->settings(
			[
				'watermark_default'  => true,
				'stats_enabled'      => true,
				'terms_ask_email'    => true,
				'prices_include_tax' => true,
				'manual_enabled'     => true,
				'currency'           => 'EUR',
			]
		);
		$clean = Settings::sanitize(
			[
				'_section'            => 'shop_shipping',
				'shipping_flat_input' => '12,50',
				'shipping_countries'  => [ '', 'SE', 'NO' ],
				'currency'            => 'USD',
			]
		);
		$this->assertSame( 1250, $clean['shipping_flat'] );
		$this->assertSame( 'SE, NO', $clean['shipping_countries'] );
		$this->assertSame( 'EUR', $clean['currency'] );
		$this->assertTrue( $clean['prices_include_tax'] );
		$this->assertTrue( $clean['manual_enabled'] );

		$clean = Settings::sanitize( [ '_section' => 'general_visitors' ] );
		$this->assertFalse( $clean['stats_enabled'] );
		$this->assertFalse( $clean['terms_ask_email'] );
		$this->assertTrue( $clean['watermark_default'] );
	}

	public function test_print_template_settings_allow_each_template_or_none(): void {
		$this->assertSame( PrintPresets::IDS, Settings::get( 'print_presets' ) );
		$chosen = Settings::sanitize(
			[
				'_section'      => 'shop_prints',
				'print_presets' => [ '', 'theprintspace', 'unknown', 'crimson', 'crimson', [ 'invalid' ] ],
				'currency'      => 'USD',
			]
		);
		$this->assertSame( [ 'crimson', 'theprintspace' ], $chosen['print_presets'] );
		$this->assertSame( 'SEK', $chosen['currency'] );
		$this->assertTrue( $chosen['manual_enabled'] );
		$this->settings( $chosen );
		$this->assertSame(
			[ 'crimson', 'theprintspace' ],
			Settings::sanitize(
				[
					'_section' => 'shop_shipping',
				]
			)['print_presets']
		);

		$disabled = Settings::sanitize(
			[
				'_section'      => 'shop_prints',
				'print_presets' => [ '' ],
			]
		);
		$this->assertSame( [], $disabled['print_presets'] );
		$this->settings( $disabled );
		$this->assertSame( [], Settings::get( 'print_presets' ) );
	}

	public function test_shipping_checkboxes_and_legacy_settings_preserve_country_order(): void {
		$this->settings( [ 'shipping_countries' => 'se, GB; xk, SE' ] );
		$this->assertSame( [ 'SE', 'GB', 'XK' ], CheckoutRest::countries() );

		$form = Settings::sanitize(
			[
				'_section'           => 'shop',
				'shipping_countries' => [ '', 'SE', 'GB', 'XK', 'US', 'gb', 'invalid' ],
			]
		);
		$this->assertSame( 'SE, GB, XK, US', $form['shipping_countries'] );

		$empty = Settings::sanitize(
			[
				'_section'           => 'shop',
				'shipping_countries' => [ '' ],
			]
		);
		$this->assertSame( '', $empty['shipping_countries'] );
	}
}
