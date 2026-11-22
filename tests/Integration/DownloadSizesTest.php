<?php
/**
 * Downloads sold at a smaller size than the original.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Shop\Downloads;
use SlashPhoto\Shop\DownloadSizes;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\Schema;

final class DownloadSizesTest extends ShopTestCase {

	public function set_up(): void {
		parent::set_up();
		PriceLists::save_products(
			$this->list,
			[
				[
					'id'    => 'aaaa0001',
					'kind'  => 'download',
					'name'  => 'Download',
					'price' => 30000,
				],
				[
					'id'    => 'aaaa0003',
					'kind'  => 'download',
					'name'  => 'Web size',
					'price' => 9900,
					'size'  => 320,
				],
				[
					'id'    => 'aaaa0004',
					'kind'  => 'download',
					'name'  => 'Large',
					'price' => 19900,
					'size'  => 1024,
				],
				[
					'id'       => 'aaaa0005',
					'kind'     => 'package',
					'name'     => 'Two web downloads',
					'price'    => 14900,
					'count'    => 2,
					'includes' => 'aaaa0003',
				],
			]
		);
	}

	private function paid( array $items ): array {
		$order = $this->place( $items );
		$this->assertTrue( Orders::mark_paid( (int) $order['id'] ) );
		return $order;
	}

	public function test_size_is_cleaned_and_kept_only_on_downloads(): void {
		$this->assertSame( 0, DownloadSizes::clean( '' ) );
		$this->assertSame( 0, DownloadSizes::clean( 'abc' ) );
		$this->assertSame( 0, DownloadSizes::clean( -5 ) );
		$this->assertSame( DownloadSizes::MIN, DownloadSizes::clean( 100 ) );
		$this->assertSame( DownloadSizes::MAX, DownloadSizes::clean( 99999 ) );
		$this->assertSame( 2048, DownloadSizes::clean( '2048' ) );

		$clean = PriceLists::clean(
			[
				[
					'kind'  => 'download',
					'name'  => 'Full',
					'price' => 1,
				],
				[
					'kind'  => 'download',
					'name'  => 'Web',
					'price' => 1,
					'size'  => '2048',
				],
				[
					'kind'  => 'print',
					'name'  => 'Print',
					'price' => 1,
					'size'  => 2048,
				],
			]
		);
		$this->assertArrayNotHasKey( 'size', $clean[0] );
		$this->assertSame( 2048, $clean[1]['size'] );
		$this->assertArrayNotHasKey( 'size', $clean[2] );
	}

	public function test_order_lines_keep_the_size_and_deliver_a_private_scaled_copy(): void {
		global $wpdb;
		$this->assertContains( 'download_size', $wpdb->get_col( 'DESCRIBE ' . Schema::items() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$image          = $this->image( $this->shop_gallery() );
		$order          = $this->paid(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0003',
				],
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		[ $web, $full ] = Orders::items( (int) $order['id'] );
		$this->assertSame( 320, $web['download_size'] );
		$this->assertSame( 0, $full['download_size'] );

		// Later price-list changes do not change what was bought.
		PriceLists::save_products( $this->list, [] );

		$file     = Downloads::resolve( $order['id'], $web['id'], $order['key'] );
		$original = wp_get_original_image_path( $image );
		$this->assertIsArray( $file );
		$this->assertNotSame( $original, $file['path'] );
		$this->assertSame( dirname( $original ), dirname( $file['path'] ), 'Kept in the protected originals folder.' );
		$this->assertStringEndsWith( '-320px.jpg', $file['name'] );
		$this->assertEmpty( $file['temporary'] ?? false );
		[ $width, $height ] = wp_getimagesize( $file['path'] );
		$this->assertSame( [ 320, 240 ], [ $width, $height ] );
		$this->assertSame( [ 320 => wp_basename( $file['path'] ) ], get_post_meta( $image, DownloadSizes::META, true ) );

		// The copy is reused.
		$mtime = filemtime( $file['path'] );
		$again = Downloads::resolve( $order['id'], $web['id'], $order['key'] );
		$this->assertSame( $file['path'], $again['path'] );
		$this->assertSame( $mtime, filemtime( $again['path'] ) );

		$this->assertSame( $original, Downloads::resolve( $order['id'], $full['id'], $order['key'] )['path'] );

		// Still closed for a wrong key, like full-size downloads.
		$this->assertSame( 'slashphoto_download_not_found', Downloads::resolve( $order['id'], $web['id'], str_repeat( '0', 32 ) )->get_error_code() );

		// Deleting the image deletes the copy.
		wp_delete_attachment( $image, true );
		$this->assertFileDoesNotExist( $file['path'] );
	}

	public function test_small_originals_are_never_enlarged(): void {
		$image = $this->image( $this->shop_gallery() );
		$order = $this->paid(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0004',
				],
			]
		);
		$line  = Orders::items( (int) $order['id'] )[0];
		$this->assertSame( 1024, $line['download_size'] );

		$file = Downloads::resolve( $order['id'], $line['id'], $order['key'] );
		$this->assertSame( wp_get_original_image_path( $image ), $file['path'] );
		$this->assertStringEndsWith( '.jpg', $file['name'] );
		$this->assertStringNotContainsString( 'px', $file['name'] );
		$this->assertEmpty( get_post_meta( $image, DownloadSizes::META, true ) );
	}

	public function test_package_photos_get_the_included_size(): void {
		$gallery  = $this->shop_gallery();
		$images   = [ $this->image( $gallery ), $this->image( $gallery ) ];
		$order    = $this->paid(
			[
				[
					'package' => 'p1',
					'product' => 'aaaa0005',
					'gallery' => $gallery,
					'images'  => $images,
				],
			]
		);
		$children = array_values( array_filter( Orders::items( (int) $order['id'] ), static fn( array $item ): bool => $item['parent_id'] > 0 ) );
		$this->assertCount( 2, $children );
		foreach ( $children as $child ) {
			$this->assertSame( 320, $child['download_size'] );
			$file = Downloads::resolve( $order['id'], $child['id'], $order['key'] );
			$this->assertStringEndsWith( '-320px.jpg', $file['name'] );
		}
	}
}
