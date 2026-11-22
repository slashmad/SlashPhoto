<?php
/**
 * Finding gallery photos by text and camera metadata.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Media\PhotoSearch;

final class PhotoSearchTest extends AccessTestCase {

	private function photo( int $gallery, string $title, array $image_meta ): int {
		$id                 = $this->image( $gallery );
		$meta               = wp_get_attachment_metadata( $id );
		$meta['image_meta'] = array_merge( $meta['image_meta'], $image_meta );
		wp_update_attachment_metadata( $id, $meta );
		wp_update_post(
			[
				'ID'           => $id,
				'post_title'   => $title,
				'post_excerpt' => 'Caption for ' . $title,
			]
		);
		return $id;
	}

	public function test_photos_are_found_by_text_keywords_camera_and_date(): void {
		$gallery = $this->gallery();
		$beach   = $this->photo(
			$gallery,
			'Sunset',
			[
				'keywords'          => [ 'Beach', 'Gotland' ],
				'camera'            => 'ILCE-7RM3',
				'make'              => 'SONY',
				'lens'              => '15mm F1.4 DG DN',
				'copyright'         => 'Robin',
				'created_timestamp' => (string) gmmktime( 21, 10, 0, 7, 31, 2025 ),
			]
		);
		$city    = $this->photo(
			$gallery,
			'Old town',
			[
				'keywords'          => [ 'Stockholm' ],
				'camera'            => 'X100VI',
				'created_timestamp' => (string) gmmktime( 9, 0, 0, 1, 2, 2026 ),
			]
		);
		$images  = ( new GalleryRepository() )->images( $gallery );
		$ids     = static fn( array $found ): array => array_map( static fn( $post ): int => $post->ID, $found );

		$this->assertSame( [ $beach ], $ids( PhotoSearch::filter( $images, 'gotland' ) ) );
		$this->assertSame( [ $beach ], $ids( PhotoSearch::filter( $images, 'sony 15mm' ) ), 'All words must match.' );
		$this->assertSame( [], $ids( PhotoSearch::filter( $images, 'sony stockholm' ) ) );
		$this->assertSame( [ $city ], $ids( PhotoSearch::filter( $images, '2026-01' ) ) );
		$this->assertSame( [ $beach ], $ids( PhotoSearch::filter( $images, 'CAPTION FOR SUNSET' ) ) );
		$this->assertSame( [ $city ], $ids( PhotoSearch::filter( $images, mb_strtolower( \SlashPhoto\Proofing\Proofing::file_label( $city ) ) ) ), 'Lightroom file names.' );
		$this->assertCount( 2, PhotoSearch::filter( $images, '  ' ) );
		$this->assertStringContainsString( 'ILCE-7RM3', PhotoSearch::summary( $beach ) );
	}

	public function test_older_photos_are_indexed_on_first_search_and_titles_reindex(): void {
		$gallery = $this->gallery();
		$id      = $this->image( $gallery );
		delete_post_meta( $id, PhotoSearch::META );
		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Wedding kiss',
			]
		);
		$this->assertStringContainsString( 'wedding kiss', (string) get_post_meta( $id, PhotoSearch::META, true ), 'Edits reindex.' );

		delete_post_meta( $id, PhotoSearch::META );
		$this->assertCount( 1, PhotoSearch::filter( ( new GalleryRepository() )->images( $gallery ), 'kiss' ) );
		$this->assertNotSame( '', get_post_meta( $id, PhotoSearch::META, true ) );

		$outside = self::factory()->attachment->create_upload_object( TestImages::jpeg( 400, 300 ) );
		$this->assertSame( '', get_post_meta( $outside, PhotoSearch::META, true ), 'Only gallery photos are indexed.' );
	}

	public function test_lens_and_make_are_read_from_exif(): void {
		$meta = PhotoSearch::camera_details(
			[ 'camera' => 'ILCE-7RM3' ],
			'',
			0,
			[],
			[
				'UndefinedTag:0xA434' => '15mm F1.4 DG DN DIAGONAL FISHEYE | Art 024',
				'Make'                => 'SONY',
			]
		);
		$this->assertSame( '15mm F1.4 DG DN DIAGONAL FISHEYE | Art 024', $meta['lens'] );
		$this->assertSame( 'SONY', $meta['make'] );
	}
}
