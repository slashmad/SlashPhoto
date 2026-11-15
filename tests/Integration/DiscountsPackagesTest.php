<?php
/**
 * Shop: discount codes and packages.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Plugin;
use SlashPhoto\Shop\Coupons;
use SlashPhoto\Shop\Downloads;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PriceLists;

final class DiscountsPackagesTest extends ShopTestCase {

	public function set_up(): void {
		parent::set_up();
		Coupons::register();
		// Add packages to the standard list: 3 downloads, the whole gallery, 2 prints.
		PriceLists::save_products(
			$this->list,
			array_merge(
				PriceLists::products( $this->list ),
				[
					[
						'id'       => 'aaaa0003',
						'kind'     => 'package',
						'name'     => 'Three downloads',
						'price'    => 60000,
						'count'    => 3,
						'includes' => 'aaaa0001',
					],
					[
						'id'       => 'aaaa0004',
						'kind'     => 'package',
						'name'     => 'Whole gallery',
						'price'    => 90000,
						'count'    => 0,
						'includes' => 'aaaa0001',
					],
					[
						'id'       => 'aaaa0005',
						'kind'     => 'package',
						'name'     => 'Two prints',
						'price'    => 30000,
						'count'    => 2,
						'includes' => 'aaaa0002',
					],
					[
						'id'       => 'aaaa0006',
						'kind'     => 'package',
						'name'     => 'Broken',
						'price'    => 1,
						'count'    => 2,
						'includes' => 'ffffffff',
					],
				]
			)
		);
	}

	private function coupon( string $code, array $rules, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			[
				'post_type'   => Coupons::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $code,
			]
		);
		Coupons::save_rules( $id, $rules );
		return $id;
	}

	public function test_packages_need_a_valid_product_to_include(): void {
		$ids = array_column( PriceLists::products( $this->list ), 'id' );
		$this->assertContains( 'aaaa0003', $ids );
		$this->assertNotContains( 'aaaa0006', $ids, 'A package pointing at a missing product is not offered.' );
	}

	public function test_package_of_chosen_photos(): void {
		$gallery = $this->shop_gallery();
		$images  = [ $this->image( $gallery ), $this->image( $gallery ), $this->image( $gallery ), $this->image( $gallery ) ];
		$hidden  = $this->image( $this->shop_gallery( [ 'post_password' => 'secret' ] ) );
		$this->as_anonymous();

		$quote = $this->quote(
			[
				[
					'package' => 'abc',
					'product' => 'aaaa0003',
					'gallery' => $gallery,
					'images'  => [ $hidden, $images[0], $images[1], $images[1], $images[2], $images[3] ],
				],
			]
		);
		$line  = $quote['lines'][0];
		$this->assertSame( 'pkg:abc', $line['key'] );
		$this->assertSame( array_slice( $images, 0, 3 ), array_column( $line['images'], 'id' ), 'Hidden photos and duplicates are dropped; at most the package size.' );
		$this->assertSame( 60000, $quote['total'] );
		$this->assertFalse( $quote['needs_shipping'] );

		$prints = $this->quote(
			[
				[
					'package' => 'p2',
					'product' => 'aaaa0005',
					'images'  => [ $images[0] ],
				],
			]
		);
		$this->assertTrue( $prints['needs_shipping'], 'A package of prints is shipped.' );
		$this->assertSame( 30000 + 4900, $prints['total'] );
		$this->assertSame( 2, $prints['lines'][0]['count'] );

		$this->assertSame(
			[ 'pkg:x' ],
			$this->quote(
				[
					[
						'package' => 'x',
						'product' => 'aaaa0003',
						'images'  => [ $hidden ],
					],
				]
			)['unavailable']
		);
	}

	public function test_whole_gallery_package_and_its_downloads(): void {
		$gallery = $this->shop_gallery();
		$images  = [ $this->image( $gallery ), $this->image( $gallery ) ];
		$order   = $this->place(
			[
				[
					'package' => 'all1',
					'product' => 'aaaa0004',
					'gallery' => $gallery,
					'images'  => [],
				],
			]
		);
		$this->assertSame( 90000, $order['total'] );
		$items = Orders::items( (int) $order['id'] );
		$this->assertSame( 'package', $items[0]['kind'] );
		$this->assertSame( 90000, $items[0]['total'] );
		$this->assertSame( '2 photos', $items[0]['file_label'] );
		$children = array_slice( $items, 1 );
		$this->assertSame( $images, array_column( $children, 'image_id' ) );
		$this->assertSame( [ $items[0]['id'], $items[0]['id'] ], array_column( $children, 'parent_id' ) );
		$this->assertSame( [ 'download', 'download' ], array_column( $children, 'kind' ) );

		Orders::mark_paid( (int) $order['id'] );
		$this->assertIsArray( Downloads::resolve( $order['id'], $children[1]['id'], $order['key'] ) );
		$this->assertSame( 'slashphoto_download_not_found', Downloads::resolve( $order['id'], $items[0]['id'], $order['key'] )->get_error_code(), 'The package line itself is not a file.' );
		$this->assertStringContainsString( '2 photos', tests_retrieve_phpmailer_instance()->get_sent( 0 )->body );
	}

	public function test_percent_code_on_prints_with_limits(): void {
		$image = $this->image( $this->shop_gallery() );
		$this->coupon(
			'prints20',
			[
				'type'            => 'percent',
				'amount'          => 20,
				'applies'         => 'print',
				'limit_per_email' => 1,
			]
		);
		$this->assertNotNull( Coupons::find( ' Prints20 ' ), 'Codes are matched in upper case.' );

		$items = [
			[
				'image'   => $image,
				'product' => 'aaaa0001',
			],
			[
				'image'   => $image,
				'product' => 'aaaa0002',
				'qty'     => 2,
			],
		];
		$quote = Plugin::instance()->shop()->quote( $items, 'prints20' );
		$this->assertSame( 'PRINTS20', $quote['coupon'] );
		$this->assertSame( 7960, $quote['discount'], '20 % of the prints only (2 × 199 kr).' );
		$this->assertSame( 30000 + 39800 - 7960 + 4900, $quote['total'] );

		$order = $this->place(
			$items,
			[
				'coupon' => 'prints20',
				'total'  => $quote['total'],
			]
		);
		$this->assertSame( 'PRINTS20', $order['coupon'] );
		$this->assertSame( 7960, $order['discount'] );
		$this->assertStringContainsString( 'Discount (PRINTS20)', tests_retrieve_phpmailer_instance()->get_sent( 0 )->body );

		$again = $this->post( 'orders', $this->order_body( $items, [ 'coupon' => 'prints20' ] ) );
		$this->assertSame( 'coupon', $again->get_data()['data']['field'], 'Once per email address.' );
		$other = $this->post(
			'orders',
			$this->order_body(
				$items,
				[
					'coupon' => 'prints20',
					'email'  => 'other@example.org',
					'total'  => $quote['total'],
				]
			)
		);
		$this->assertSame( 200, $other->get_status() );
	}

	public function test_fixed_code_rules(): void {
		$image = $this->image( $this->shop_gallery() );
		$items = [
			[
				'image'   => $image,
				'product' => 'aaaa0001',
			],
		];
		$shop  = Plugin::instance()->shop();

		$this->coupon(
			'FIFTY',
			[
				'type'         => 'fixed',
				'amount'       => 5000,
				'min_subtotal' => 50000,
			]
		);
		$this->assertStringContainsString( 'at least', $shop->quote( $items, 'FIFTY' )['coupon_error'] );
		$this->assertSame( 0, $shop->quote( $items, 'FIFTY' )['discount'] );

		$this->coupon(
			'BIG',
			[
				'type'   => 'fixed',
				'amount' => 99999999,
			]
		);
		$this->assertSame( 0, $shop->quote( $items, 'BIG' )['total'], 'Never below zero.' );

		$this->coupon( 'OLD', [ 'ends' => '2000-01-01' ] );
		$this->assertStringContainsString( 'not valid right now', $shop->quote( $items, 'OLD' )['coupon_error'] );
		$this->coupon( 'DRAFT', [], 'draft' );
		$this->assertStringContainsString( 'not valid', $shop->quote( $items, 'DRAFT' )['coupon_error'] );
		$this->coupon(
			'SHIPFREE',
			[
				'amount'        => 0,
				'free_shipping' => true,
			]
		);
		$prints = [
			[
				'image'   => $image,
				'product' => 'aaaa0002',
			],
		];
		$this->assertSame( 0, $shop->quote( $prints, 'SHIPFREE' )['shipping'] );
		$this->assertSame( 19900, $shop->quote( $prints, 'SHIPFREE' )['total'] );

		$this->coupon(
			'ONCE',
			[
				'amount' => 10,
				'limit'  => 1,
			]
		);
		$this->place(
			$items,
			[
				'coupon' => 'ONCE',
				'total'  => $shop->quote( $items, 'ONCE' )['total'],
			]
		);
		$this->assertStringContainsString( 'used up', $shop->quote( $items, 'ONCE' )['coupon_error'] );
		$this->assertSame( 1, Coupons::uses( 'ONCE' ) );
	}

	public function test_free_order_after_discount_is_paid_at_once(): void {
		$image = $this->image( $this->shop_gallery() );
		$items = [
			[
				'image'   => $image,
				'product' => 'aaaa0001',
			],
		];
		$this->coupon( 'GIFT', [ 'amount' => 100 ] );
		$order = $this->place(
			$items,
			[
				'coupon'  => 'GIFT',
				'total'   => 0,
				'gateway' => '',
			]
		);
		$this->assertSame( 'paid', $order['status'] );
		$this->assertSame( 'free', $order['gateway'] );
	}

	public function test_invoice_then_receipt_with_discount_vat_and_package_photos(): void {
		$this->settings(
			[
				'business_name'    => 'Studio Ljus AB',
				'business_details' => "Storgatan 1\nOrg.nr 556000-0000",
			]
		);
		$gallery = $this->shop_gallery();
		$images  = [ $this->image( $gallery ), $this->image( $gallery ) ];
		$this->coupon( 'TEN', [ 'amount' => 10 ] );
		$items    = [
			[
				'package' => 'k1',
				'product' => 'aaaa0003',
				'images'  => $images,
			],
		];
		$order    = $this->place(
			$items,
			[
				'coupon' => 'TEN',
				'total'  => Plugin::instance()->shop()->quote( $items, 'TEN' )['total'],
			]
		);
		$receipts = new \SlashPhoto\Shop\Receipts( Plugin::instance()->templates() );

		$invoice = $receipts->render( $order );
		$this->assertStringContainsString( 'Invoice ' . $order['id'], $invoice );
		$this->assertStringContainsString( 'Swish 123 456 78 90', $invoice );
		$this->assertStringContainsString( 'Not paid', $invoice );
		$this->assertStringContainsString( 'Org.nr 556000-0000', $invoice );

		Orders::mark_paid( (int) $order['id'] );
		$html = $receipts->render( (array) Orders::get( (int) $order['id'] ) );
		$this->assertStringContainsString( 'Receipt ' . $order['id'], $html );
		$this->assertStringContainsString( 'Studio Ljus AB', $html );
		$this->assertStringContainsString( 'Discount (TEN)', $html );
		$this->assertStringContainsString( 'Three downloads', $html );
		$this->assertStringContainsString( \SlashPhoto\Proofing\Proofing::file_label( $images[1] ), $html );
		$this->assertStringContainsString( 'Of which VAT 25', $html );
		$this->assertStringNotContainsString( 'Swish', $html, 'No payment details once paid.' );
		$this->assertStringContainsString( 'receipt=1', tests_retrieve_phpmailer_instance()->get_sent( 2 )->body, 'The payment email links the receipt.' );
	}
}
