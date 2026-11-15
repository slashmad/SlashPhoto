<?php
/**
 * The slashphoto/gallery block.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\GalleryMeta;

final class GalleryBlockTest extends AccessTestCase {

	private function block( int $gallery_id, int $context_id = 0 ): string {
		$markup = '<!-- wp:slashphoto/gallery {"galleryId":' . $gallery_id . '} /-->';
		$post   = $context_id ? get_post( $context_id ) : null;
		if ( $post ) {
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- block context for the test.
			setup_postdata( $post );
		}
		return do_blocks( $markup );
	}

	public function test_block_is_registered_with_server_rendering(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'slashphoto/gallery' );
		$this->assertNotNull( $type );
		$this->assertTrue( $type->is_dynamic() );
		$this->assertContains( 'wide', $type->supports['align'] );
	}

	public function test_block_renders_a_public_gallery(): void {
		$gallery = $this->gallery();
		$image   = $this->image( $gallery );
		$this->as_anonymous();

		$html = $this->block( $gallery );

		$this->assertStringContainsString( 'wp-block-slashphoto-gallery', $html );
		$this->assertStringContainsString( 'data-slashphoto-id="' . $image . '"', $html );
	}

	public function test_block_uses_the_current_gallery_when_no_id_is_set(): void {
		$gallery = $this->gallery();
		$image   = $this->image( $gallery );

		$this->assertStringContainsString( 'data-slashphoto-id="' . $image . '"', $this->block( 0, $gallery ) );
	}

	public function test_block_never_shows_more_than_the_gallery_page(): void {
		$client   = self::factory()->user->create( [ 'role' => GalleryMeta::CLIENT_ROLE ] );
		$clients  = $this->clients_gallery( [ $client ] );
		$password = $this->gallery( [ 'post_password' => 'secret' ] );
		$draft    = $this->gallery( [ 'post_status' => 'draft' ] );
		foreach ( [ $clients, $password, $draft ] as $id ) {
			$this->image( $id );
		}
		$this->as_anonymous();

		$this->assertSame( '', $this->block( $clients ) );
		$this->assertSame( '', $this->block( $draft ) );

		$html = $this->block( $password );
		$this->assertStringContainsString( 'slashphoto-notice--password', $html );
		$this->assertStringContainsString( get_permalink( $password ), $html );
		$this->assertStringNotContainsString( 'slashphoto-item', $html );

		wp_set_current_user( $client );
		$this->assertStringContainsString( 'slashphoto-item', $this->block( $clients ) );
	}

	public function test_block_ignores_non_gallery_ids(): void {
		$this->assertSame( '', $this->block( self::factory()->post->create() ) );
	}

	public function test_client_role_can_only_read(): void {
		$role = get_role( GalleryMeta::CLIENT_ROLE );
		$this->assertSame( [ 'read' => true ], $role->capabilities );
	}

	public function test_meta_sanitising(): void {
		$user = self::factory()->user->create();
		$this->assertSame( [ $user ], GalleryMeta::sanitize_clients( [ $user, $user, 0, 999999, 'x' ] ) );
		$this->assertSame( '2026-12-31', GalleryMeta::sanitize_date( '2026-12-31' ) );
		$this->assertSame( '', GalleryMeta::sanitize_date( '2026-02-30' ) );
		$this->assertSame( '', GalleryMeta::sanitize_date( 'tomorrow' ) );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		parent::tear_down();
	}
}
