<?php
/**
 * Regression coverage for customer-facing delivery workflows.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Admin\TaxExport;
use SlashPhoto\Gallery\ExpiryReminders;
use SlashPhoto\Gallery\GalleryRenderer;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Media\Panorama;
use SlashPhoto\Plugin;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Proofing\Revisions;
use SlashPhoto\Proofing\Selections;
use SlashPhoto\Secrets;
use SlashPhoto\Shop\Downloads;
use SlashPhoto\Shop\Gateways\StripeGateway;
use SlashPhoto\Shop\Orders;
use SlashPhoto\Shop\PanoramaPrint;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\Refunds;
use ZipArchive;

final class CustomerWorkflowsTest extends ShopTestCase {

	public function test_archive_uses_the_paid_order_and_session_or_key(): void {
		$this->as_user();
		$gallery = $this->shop_gallery();
		$first   = $this->image( $gallery );
		$second  = $this->image( $gallery );
		$order   = $this->place(
			[
				[
					'image'   => $first,
					'product' => 'aaaa0001',
				],
				[
					'image'   => $second,
					'product' => 'aaaa0001',
				],
				[
					'image'   => $second,
					'product' => 'aaaa0002',
				],
			]
		);
		$this->assertSame( 'slashphoto_download_closed', Downloads::resolve_archive( $order['id'], null )->get_error_code() );
		Orders::mark_paid( $order['id'] );
		$archive = Downloads::resolve_archive( $order['id'], null );
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->assertSame( 'slashphoto_archive_unavailable', $archive->get_error_code() );
		} else {
			$this->assertIsArray( $archive );
			$zip = new ZipArchive();
			$this->assertTrue( $zip->open( $archive['path'] ) );
			$this->assertSame( 2, $zip->count(), 'Prints are excluded.' );
			$this->assertSame( hash_file( 'sha256', wp_get_original_image_path( $first ) ), hash( 'sha256', $zip->getFromIndex( 0 ) ) );
			$this->assertSame( hash_file( 'sha256', wp_get_original_image_path( $second ) ), hash( 'sha256', $zip->getFromIndex( 1 ) ) );
			$zip->close();
			wp_delete_file( $archive['path'] );
		}

		$this->as_user();
		$this->assertSame( 'slashphoto_download_not_found', Downloads::resolve_archive( $order['id'], null )->get_error_code() );
		$this->as_anonymous();
		$this->assertSame( 'slashphoto_download_not_found', Downloads::resolve_archive( $order['id'], '' )->get_error_code() );
		Orders::update( $order['id'], [ 'downloads_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
		$this->assertSame( 'slashphoto_download_closed', Downloads::resolve_archive( $order['id'], $order['key'] )->get_error_code() );
	}

	public function test_gallery_paging_keeps_manual_order_and_search_stays_in_gallery(): void {
		$repository = new GalleryRepository();
		$gallery    = $this->gallery();
		$photos     = [];
		for ( $i = 0; $i < 50; ++$i ) {
			$photos[] = self::factory()->post->create(
				[
					'post_parent'    => $gallery,
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image/jpeg',
					'post_title'     => $i >= 48 ? 'Aurora Photo' : 'Daylight',
				]
			);
		}
		$this->assertSame( 'moved', $repository->move_photo( $gallery, $photos[49], 1, GalleryRepository::order_token( $repository->images( $gallery ) ) ) );
		$new_photo = self::factory()->post->create(
			[
				'post_parent'    => $gallery,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'Late Aurora',
			]
		);
		$last      = array_column( $repository->image_page( $gallery, 5, 48, '' ), 'ID' );
		$this->assertSame( [ $photos[47], $photos[48], $new_photo ], $last );
		$this->assertSame( [ $photos[48], $new_photo ], array_column( $repository->image_page( $gallery, 5, 1, 'Aurora' ), 'ID' ) );

		update_post_meta( $gallery, GalleryRenderer::LAYOUT, 'square' );
		$html = $this->render_page( get_permalink( $gallery ) );
		$this->assertStringContainsString( 'slashphoto-grid--square', $html );
		$this->assertSame( 48, substr_count( $html, 'data-slashphoto-id=' ) );
		$this->assertStringContainsString( 'Next photos', $html );

		$private = $this->clients_gallery( [ $this->as_user() ] );
		$secret  = $this->image( $private );
		$this->as_anonymous();
		$html = $this->render_page( add_query_arg( 'sp_photo_search', 'Aurora', get_permalink( $private ) ) );
		$this->assertStringNotContainsString( 'data-slashphoto-id="' . $secret . '"', $html );
		$this->assertStringNotContainsString( 'Search photos', $html );
	}

	public function test_mixed_package_pickup_and_sales_export(): void {
		$this->settings(
			[
				'express_shipping'    => true,
				'express_price'       => 12900,
				'pickup_country'      => 'SE',
				'pickup_instructions' => 'Pickup at the studio.',
				'tax_rates'           => [ 'SE' => 25 ],
			]
		);
		$products   = PriceLists::products( $this->list );
		$products[] = [
			'id'       => 'aaaa0003',
			'kind'     => 'package',
			'name'     => 'Print and digital',
			'price'    => 19900,
			'count'    => 1,
			'includes' => [ 'aaaa0001', 'aaaa0002' ],
		];
		PriceLists::save_products( $this->list, $products );
		$gallery = $this->shop_gallery();
		$image   = $this->image( $gallery );
		$items   = [
			[
				'package' => 'qa',
				'product' => 'aaaa0003',
				'gallery' => $gallery,
				'images'  => [ $image ],
			],
		];
		$shop    = Plugin::instance()->shop();
		$express = $shop->quote( $items, '', '', 'SE', '', 'express' );
		$this->assertSame( 12900, $express['shipping'] );
		$pickup = $shop->quote( $items, '', '', '', '', 'pickup' );
		$this->assertSame( 'SE', $pickup['tax_country'] );
		$this->assertSame( 0, $pickup['shipping'] );
		$order = $this->place(
			$items,
			[
				'delivery' => 'pickup',
				'address'  => [],
				'total'    => $pickup['total'],
			]
		);
		$this->assertSame( 'pickup', $order['delivery_method'] );
		$this->assertSame( [], $order['address'] );
		$this->assertSame( 'Pickup at the studio.', $order['pickup_instructions'] );
		$this->assertSame( [ 'package', 'download', 'print' ], array_column( Orders::items( $order['id'] ), 'kind' ) );

		$output = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test stream.
		TaxExport::write_sales( $output, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test stream.
		$this->assertStringContainsString( ',manual,pickup,199.00,', $csv );
		$this->assertStringNotContainsString( $order['email'], $csv );
	}

	public function test_print_crop_keeps_cart_lines_and_fulfillment_distinct(): void {
		$image = $this->image( $this->shop_gallery() );
		wp_update_attachment_metadata(
			$image,
			[
				'width'  => 400,
				'height' => 300,
			]
		);
		$items = [
			[
				'image'   => $image,
				'product' => 'aaaa0002',
				'crop'    => 0,
			],
			[
				'image'   => $image,
				'product' => 'aaaa0002',
				'crop'    => 100,
			],
		];
		$order = $this->place( $items );
		$this->assertSame( [ '0:0:10000:8889', '0:1111:10000:8889' ], array_column( Orders::items( $order['id'] ), 'print_crop' ) );
	}

	public function test_expiry_mail_is_opt_in_and_checks_inherited_client_access(): void {
		$first   = $this->as_user();
		$second  = $this->as_user();
		$parent  = $this->clients_gallery( [ $first ] );
		$gallery = $this->clients_gallery( [ $first, $second ], [ 'post_parent' => $parent ] );
		update_post_meta( $parent, Access::META_EXPIRES, wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) );
		$reminders = new ExpiryReminders( Plugin::instance()->access() );
		$this->assertSame( 0, $reminders->notify( get_post( $gallery ) ) );
		update_post_meta( $gallery, ExpiryReminders::META, '1' );
		$this->assertSame( 1, $reminders->notify( get_post( $gallery ) ) );
		$this->assertSame( 0, $reminders->notify( get_post( $gallery ) ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertSame( get_userdata( $first )->user_email, $mail->to[0][0] );
		$this->assertStringNotContainsString( get_userdata( $second )->user_email, $mail->body );
	}

	public function test_revisions_are_visible_only_to_clients_who_selected_the_photo(): void {
		$client  = $this->as_user();
		$other   = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$gallery = $this->clients_gallery( [ $client, $other ] );
		update_post_meta( $gallery, Proofing::META, 'on' );
		$photo = $this->image( $gallery );
		add_post_meta( $photo, '_slashphoto_client_revision', 'o' . str_repeat( 'a', 24 ) );

		$selection = Selections::find_or_create( get_post( $gallery ), $client );
		Selections::save_items( $selection, [ $photo => 'Keep the notes.' ] );
		$service = new Revisions( Plugin::instance()->access(), Plugin::instance()->proofing() );
		$this->assertArrayHasKey( $photo, $service->urls( get_post( $gallery ), [ $photo ] ) );
		wp_set_current_user( $other );
		$this->assertSame( [], $service->urls( get_post( $gallery ), [ $photo ] ) );
		$this->as_anonymous();
		$this->assertSame( [], $service->urls( get_post( $gallery ), [ $photo ] ) );
		$this->assertSame( [ $photo => 'Keep the notes.' ], Selections::items( $selection ) );
	}

	public function test_panorama_print_keeps_the_requested_view_and_format(): void {
		$image = $this->image( $this->shop_gallery() );
		update_post_meta( $image, Panorama::META_OVERRIDE, '360' );
		wp_update_attachment_metadata(
			$image,
			[
				'width'  => 800,
				'height' => 400,
			]
		);
		$this->assertTrue( PanoramaPrint::supported( $image ) );
		$view   = '41000:-2300:55000';
		$order  = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0002',
					'view'    => $view,
				],
			]
		);
		$stored = json_decode( Orders::items( $order['id'] )[0]['print_view'], true );
		$this->assertSame( 41, $stored['yaw'] );
		$this->assertSame( -2.3, $stored['pitch'] );
		$this->assertSame( 55, $stored['fov'] );
		$this->assertEqualsWithDelta( 1.5, $stored['width'] / $stored['height'], 0.0001 );
		$this->assertSame( '', PanoramaPrint::snapshot( $image, '20 × 30 cm', '41000:99000:55000' ) );
	}

	public function test_partial_refund_reserves_the_balance_and_full_refund_closes_downloads(): void {
		$image = $this->image( $this->shop_gallery() );
		$order = $this->place(
			[
				[
					'image'   => $image,
					'product' => 'aaaa0001',
				],
			]
		);
		Orders::mark_paid( $order['id'] );
		Orders::update(
			$order['id'],
			[
				'gateway'     => 'stripe',
				'gateway_ref' => 'cs_test_refund',
			]
		);
		update_option( StripeGateway::OPTION, [ 'secret_key' => Secrets::encrypt( 'sk_test_localfixture', 'stripe' ) ], false );
		$key   = wp_generate_uuid4();
		$calls = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $request, $url ) use ( $key, &$calls ) {
				if ( ! str_starts_with( $url, StripeGateway::API ) ) {
					return $pre;
				}
				++$calls;
				if ( str_ends_with( $url, '/checkout/sessions/cs_test_refund' ) ) {
					$data = [
						'id'             => 'cs_test_refund',
						'payment_status' => 'paid',
						'payment_intent' => 'pi_local123',
					];
				} else {
					$this->assertSame( $key, $request['headers']['Idempotency-Key'] );
					parse_str( $request['body'], $body );
					$this->assertSame( '12000', $body['amount'] );
					$data = [
						'id'       => 're_local123',
						'amount'   => 12000,
						'currency' => 'sek',
						'status'   => 'succeeded',
					];
				}
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( $data ),
				];
			},
			10,
			3
		);
		$this->assertSame( 'completed', Refunds::create( $order['id'], 12000, $key, '' ) );
		$this->assertSame( 'completed', Refunds::create( $order['id'], 12000, $key, '' ) );
		$this->assertSame( 2, $calls );
		$this->assertTrue( Orders::downloads_open( Orders::get( $order['id'] ) ) );
		$this->assertWPError( Refunds::create( $order['id'], 18001, wp_generate_uuid4(), 'test-ref-too-high' ) );
		$this->assertSame( 'completed', Refunds::create( $order['id'], 18000, wp_generate_uuid4(), 'test-ref-external' ) );
		$this->assertSame( 'refunded', Orders::get( $order['id'] )['status'] );
		$this->assertFalse( Orders::downloads_open( Orders::get( $order['id'] ) ) );
	}
}
