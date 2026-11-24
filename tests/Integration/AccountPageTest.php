<?php
/**
 * Customer account ownership and gallery access.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Access\Access;
use SlashPhoto\Frontend\AccountPage;
use SlashPhoto\Plugin;
use SlashPhoto\Shop\Orders;

final class AccountPageTest extends ShopTestCase {

	public function test_account_lists_explicitly_assigned_and_accessible_galleries(): void {
		$client  = $this->as_user();
		$other   = self::factory()->user->create( [ 'role' => 'slashphoto_client' ] );
		$visible = $this->clients_gallery( [ $client ] );
		$foreign = $this->clients_gallery( [ $other ] );
		$child   = $this->clients_gallery( [ $client ], [ 'post_parent' => $visible ] );
		$this->clients_gallery( [ $client ], [ 'post_parent' => $foreign ] );
		$this->clients_gallery( [ $client ], [ 'post_password' => 'test-password' ] );
		$this->clients_gallery( [ $client ], [ 'post_status' => 'draft' ] );
		$this->gallery( [], [ Access::META_MODE => Access::MODE_MEMBERS ] );
		$expired = $this->clients_gallery( [ $client ] );
		update_post_meta( $expired, Access::META_EXPIRES, '2020-01-01' );

		$plugin = Plugin::instance();
		$page   = new AccountPage( $plugin->access(), $plugin->proofing(), $plugin->templates() );
		$this->assertSame( [ $child, $visible ], array_column( $page->assigned_galleries(), 'ID' ) );
		$this->as_anonymous();
		$this->assertSame( [], $page->assigned_galleries() );
	}

	public function test_order_history_uses_account_id_and_never_returns_keys_or_emails(): void {
		$image = $this->image( $this->shop_gallery() );
		$items = [
			[
				'image'   => $image,
				'product' => 'aaaa0001',
			],
		];
		$this->as_anonymous();
		$this->place( $items );
		$client  = $this->as_user();
		$older   = $this->place( $items );
		$newer   = $this->place( $items );
		$other   = $this->as_user();
		$foreign = $this->place( $items );

		$history = Orders::for_account( $client, 0, 12 );
		$this->assertSame( [ $newer['id'], $older['id'] ], array_map( 'intval', array_column( $history, 'id' ) ) );
		$this->assertSame( [ $older['id'] ], array_map( 'intval', array_column( Orders::for_account( $client, $newer['id'], 12 ), 'id' ) ) );
		$this->assertSame( [ $foreign['id'] ], array_map( 'intval', array_column( Orders::for_account( $other, 0, 12 ), 'id' ) ) );
		$this->assertSame( [], Orders::for_account( 0, 0, 12 ) );
		$this->assertArrayNotHasKey( 'token', $history[0] );
		$this->assertArrayNotHasKey( 'email', $history[0] );
		$this->assertSame( 1, (int) $history[0]['has_downloads'] );
	}

	public function test_shortcode_omits_private_content_outside_prepared_page(): void {
		$this->as_user();
		$id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[slashphoto_account]',
			]
		);
		$this->settings( [ 'account_page' => $id ] );
		$this->assertSame( $id, AccountPage::page_id() );
		$this->assertSame( '', do_shortcode( '[slashphoto_account]' ) );
		$this->assertSame( '', trim( $this->rest( '/wp/v2/pages/' . $id )->get_data()['content']['rendered'] ) );
	}
}
