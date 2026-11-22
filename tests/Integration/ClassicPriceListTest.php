<?php
/**
 * Price list field in the classic-editor gallery box.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Admin\AccessSettings;
use SlashPhoto\Shop\PriceLists;

final class ClassicPriceListTest extends ShopTestCase {

	public function tear_down(): void {
		$_POST = [];
		parent::tear_down();
	}

	private function render( int $gallery, array $box = [] ): string {
		ob_start();
		( new AccessSettings() )->render_meta_box( get_post( $gallery ), $box );
		return (string) ob_get_clean();
	}

	private function save( int $gallery, array $fields ): void {
		$_POST = array_merge(
			[
				AccessSettings::NONCE => wp_create_nonce( 'slashphoto_access_' . $gallery ),
				'slashphoto_access'   => 'public',
			],
			$fields
		);
		( new AccessSettings() )->save_meta_box( $gallery );
	}

	public function test_classic_box_chooses_the_price_list(): void {
		$this->as_user( 'editor' );
		$gallery = $this->gallery();
		$html    = $this->render( $gallery );
		$this->assertStringContainsString( 'name="slashphoto_price_list"', $html );
		$this->assertStringContainsString( 'value="' . $this->list . '"', $html );
		$this->assertStringNotContainsString( 'slashphoto_price_list', $this->render( $gallery, [ 'workspace' => true ] ), 'The workspace has its own field.' );

		$this->save( $gallery, [ 'slashphoto_price_list' => (string) $this->list ] );
		$this->assertSame( (string) $this->list, get_post_meta( $gallery, PriceLists::GALLERY_META, true ) );
		$this->assertMatchesRegularExpression( '/value="' . $this->list . '"\s+selected/', $this->render( $gallery ) );

		$this->save( $gallery, [ 'slashphoto_price_list' => '999999' ] );
		$this->assertSame( (string) $this->list, get_post_meta( $gallery, PriceLists::GALLERY_META, true ), 'Unknown lists are ignored.' );

		$this->save( $gallery, [] );
		$this->assertSame( (string) $this->list, get_post_meta( $gallery, PriceLists::GALLERY_META, true ), 'Saves without the field keep it.' );

		$this->save( $gallery, [ 'slashphoto_price_list' => PriceLists::NONE ] );
		$this->assertSame( PriceLists::NONE, get_post_meta( $gallery, PriceLists::GALLERY_META, true ) );
	}
}
