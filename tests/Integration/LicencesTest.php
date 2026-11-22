<?php
/**
 * Usage licences for downloads.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Plugin;
use SlashPhoto\Shop\Licences;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PriceLists;

final class LicencesTest extends ShopTestCase {

	private int $licence;

	public function set_up(): void {
		parent::set_up();
		Licences::register();
		$this->licence = self::factory()->post->create(
			[
				'post_type'    => Licences::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Personal use',
				'post_content' => '<p>For your own prints and screens.</p><p>No resale.</p>',
			]
		);
		PriceLists::save_products(
			$this->list,
			[
				[
					'id'      => 'aaaa0001',
					'kind'    => 'download',
					'name'    => 'Download',
					'price'   => 30000,
					'licence' => $this->licence,
				],
				[
					'id'      => 'aaaa0002',
					'kind'    => 'print',
					'name'    => 'Print 20 × 30 cm',
					'price'   => 19900,
					'licence' => $this->licence,
				],
				[
					'id'       => 'aaaa0005',
					'kind'     => 'package',
					'name'     => 'Two downloads',
					'price'    => 50000,
					'count'    => 2,
					'includes' => 'aaaa0001',
				],
			]
		);
	}

	public function test_licence_is_kept_only_on_downloads_and_shown_in_the_offer(): void {
		$products = PriceLists::products( $this->list );
		$this->assertSame( $this->licence, $products[0]['licence'] );
		$this->assertArrayNotHasKey( 'licence', $products[1] );

		$offer = Plugin::instance()->shop()->offer( get_post( $this->shop_gallery() ) );
		$this->assertSame( 'Personal use', $offer[0]['licence'] );
		$this->assertArrayNotHasKey( 'licence', $offer[1] );
		$this->assertSame(
			[
				'id'   => $this->licence,
				'name' => 'Personal use',
				'text' => "For your own prints and screens.\n\nNo resale.",
			],
			Licences::get( $this->licence )
		);
	}

	public function test_customers_accept_the_licence_and_the_order_keeps_its_text(): void {
		$gallery = $this->shop_gallery();
		$image   = $this->image( $gallery );
		$other   = $this->image( $gallery );
		$items   = [
			[
				'image'   => $image,
				'product' => 'aaaa0001',
			],
			[
				'package' => 'p1',
				'product' => 'aaaa0005',
				'gallery' => $gallery,
				'images'  => [ $image, $other ],
			],
		];
		$this->assertCount( 1, $this->quote( $items )['licences'], 'Each licence once.' );

		$refused = $this->post( 'orders', $this->order_body( $items ) );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'licences', $refused->get_data()['data']['field'] );

		$order = $this->place( $items, [ 'licences' => true ] );
		wp_update_post(
			[
				'ID'           => $this->licence,
				'post_content' => 'Changed later.',
			]
		);
		$lines = Orders::items( (int) $order['id'] );
		$this->assertSame( 'Personal use', $lines[0]['licence_name'] );
		$this->assertStringContainsString( 'No resale.', $lines[0]['licence_text'] );
		$children = array_filter( $lines, static fn( array $line ): bool => $line['parent_id'] > 0 );
		$this->assertCount( 2, $children );
		foreach ( $children as $child ) {
			$this->assertSame( 'Personal use', $child['licence_name'] );
		}
	}

	public function test_prints_and_unpublished_licences_need_no_acceptance(): void {
		$image = $this->image( $this->shop_gallery() );
		$this->assertSame(
			[],
			$this->quote(
				[
					[
						'image'   => $image,
						'product' => 'aaaa0002',
					],
				]
			)['licences']
		);

		wp_update_post(
			[
				'ID'          => $this->licence,
				'post_status' => 'draft',
			]
		);
		$this->assertSame(
			[],
			$this->quote(
				[
					[
						'image'   => $image,
						'product' => 'aaaa0001',
					],
				]
			)['licences']
		);
		$this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
	}
}
