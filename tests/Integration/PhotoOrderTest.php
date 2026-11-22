<?php
/**
 * Gallery image ordering keeps gallery membership and query limits.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\GalleryRepository;

final class PhotoOrderTest extends AccessTestCase {

	public function test_custom_order_respects_existing_sorting_and_new_uploads(): void {
		$gallery = $this->gallery();
		$first   = $this->image( $gallery );
		$second  = $this->image( $gallery );
		$third   = $this->image( $gallery );
		wp_update_post(
			[
				'ID'         => $first,
				'menu_order' => 20,
			]
		);

		$repository = new GalleryRepository();
		$initial    = $repository->images( $gallery );
		$token      = GalleryRepository::order_token( $initial );
		$this->assertSame( [ $second, $third, $first ], array_column( $initial, 'ID' ) );
		$this->assertSame( 'moved', $repository->move_photo( $gallery, $first, 1, $token ) );
		$this->assertSame( [ $first ], array_column( $repository->images( $gallery, 1 ), 'ID' ), 'The first photo can be used for a directory cover.' );
		$this->assertSame( [ $first, $second, $third ], array_column( $repository->images( $gallery ), 'ID' ) );
		$this->assertSame( 'stale', $repository->move_photo( $gallery, $second, 1, $token ) );

		$new = $this->image( $gallery );
		$this->assertSame( [ $first, $second, $third, $new ], array_column( $repository->images( $gallery, 4 ), 'ID' ) );
		$token = GalleryRepository::order_token( $repository->images( $gallery ) );
		$this->assertSame( 'moved', $repository->move_photo( $gallery, $first, 4, $token ) );
		$this->assertSame( [ $second, $third, $new, $first ], array_column( $repository->images( $gallery ), 'ID' ) );
		$this->assertSame( 20, get_post( $first )->menu_order );
	}

	public function test_order_cannot_move_or_expose_an_image_from_another_gallery(): void {
		$gallery    = $this->gallery();
		$first      = $this->image( $gallery );
		$second     = $this->image( $gallery );
		$restricted = $this->gallery( [ 'post_password' => 'fixture-only' ] );
		$foreign    = $this->image( $restricted );

		$repository = new GalleryRepository();
		$token      = GalleryRepository::order_token( $repository->images( $gallery ) );
		$this->assertSame( 'invalid', $repository->move_photo( $gallery, $foreign, 1, $token ) );
		$this->assertSame( 'invalid', $repository->move_photo( $gallery, $first, 3, $token ) );
		$this->assertSame( 'moved', $repository->move_photo( $gallery, $second, 1, $token ) );
		wp_update_post(
			[
				'ID'          => $second,
				'post_parent' => $restricted,
			]
		);

		$this->as_anonymous();
		$this->assertSame( [ $first ], array_column( $repository->images( $gallery, 2 ), 'ID' ) );
		$this->assertSame( [ $first ], array_column( $repository->images( $gallery ), 'ID' ) );
	}
}
