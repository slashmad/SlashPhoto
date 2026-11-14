<?php
/**
 * Panorama and 360° tests.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Tests\Integration;

use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Frontend\ThemeCompat;
use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Media\Panorama;
use WP_UnitTestCase;

final class PanoramaTest extends WP_UnitTestCase {

	public function test_parses_attribute_syntax(): void {
		$data = Panorama::parse_xmp( TestImages::gpano_xmp( 8000, 4000 ) );

		$this->assertSame( 'equirectangular', $data['projection'] );
		$this->assertSame( 8000, $data['full_width'] );
		$this->assertSame( 4000, $data['full_height'] );
		$this->assertSame( 0, $data['cropped_x'] );
	}

	public function test_parses_element_syntax(): void {
		$data = Panorama::parse_xmp( TestImages::gpano_xmp( 6000, 3000, true ) );

		$this->assertSame( 6000, $data['full_width'] );
		$this->assertSame( 90.5, $data['pose_heading'] );
	}

	public function test_ignores_images_without_equirectangular_projection(): void {
		$this->assertNull( Panorama::parse_xmp( 'no xmp here' ) );
		$this->assertNull( Panorama::parse_xmp( '<x GPano:ProjectionType="cylindrical"/>' ) );
	}

	public function test_upload_detects_360_and_keeps_more_pixels(): void {
		$gallery_id = self::factory()->post->create( [ 'post_type' => PostType::POST_TYPE ] );
		$file       = TestImages::jpeg( 3000, 1500, TestImages::gpano_xmp( 3000, 1500 ) );

		$image_id = self::factory()->attachment->create_upload_object( $file, $gallery_id );
		$meta     = wp_get_attachment_metadata( $image_id );

		$panorama = new Panorama();
		$this->assertSame( '360', $panorama->kind( $image_id ) );
		$this->assertSame( 3000, $panorama->viewer_data( $image_id )['full_width'] );
		$this->assertSame( 3000, $meta['width'], '360° images must not be downscaled to 2560 px.' );
		$this->assertArrayNotHasKey( 'original_image', $meta );
	}

	public function test_flat_images_are_still_downscaled(): void {
		$image_id = self::factory()->attachment->create_upload_object( TestImages::jpeg( 3000, 1000 ) );
		$meta     = wp_get_attachment_metadata( $image_id );

		$this->assertSame( 2560, $meta['width'] );
		$this->assertSame( 'wide', ( new Panorama() )->kind( $image_id ), 'A 3:1 image without GPano is a flat panorama.' );
	}

	public function test_manual_override_wins(): void {
		$image_id = self::factory()->attachment->create_upload_object( TestImages::jpeg( 2000, 1000 ) );
		$panorama = new Panorama();
		$this->assertSame( 'wide', $panorama->kind( $image_id ) );

		$panorama->save_fields( [ 'ID' => $image_id ], [ 'slashphoto_projection' => '360' ] );
		$this->assertSame( '360', $panorama->kind( $image_id ) );
		$this->assertSame( [], $panorama->viewer_data( $image_id ) );

		$panorama->save_fields( [ 'ID' => $image_id ], [ 'slashphoto_projection' => '' ] );
		$this->assertSame( 'wide', $panorama->kind( $image_id ) );
	}

	public function test_override_can_mark_detected_image_as_flat(): void {
		$image_id = self::factory()->attachment->create_upload_object( TestImages::jpeg( 3000, 1500, TestImages::gpano_xmp( 3000, 1500 ) ) );
		$panorama = new Panorama();

		$panorama->save_fields( [ 'ID' => $image_id ], [ 'slashphoto_projection' => 'flat' ] );

		$this->assertSame( 'wide', $panorama->kind( $image_id ) );
	}

	public function test_gallery_markup_marks_360_images(): void {
		$gallery_id = self::factory()->post->create( [ 'post_type' => PostType::POST_TYPE ] );
		$image_id   = self::factory()->attachment->create_upload_object( TestImages::jpeg( 3000, 1500, TestImages::gpano_xmp( 3000, 1500 ) ), $gallery_id );

		$compat = new ThemeCompat( new TemplateLoader(), new GalleryRepository(), new Panorama() );
		$html   = ( new TemplateLoader() )->render(
			'gallery.php',
			[
				'children' => [],
				'items'    => $compat->items( $gallery_id ),
			]
		);

		$this->assertStringContainsString( 'slashphoto-item--360', $html );
		$this->assertStringContainsString( 'data-slashphoto-id="' . $image_id . '"', $html );
		$this->assertStringContainsString( 'data-slashphoto-panorama="{&quot;projection&quot;:&quot;equirectangular&quot;', $html );
		$this->assertStringContainsString( 'slashphoto-badge', $html );
	}

	public function test_media_library_field_is_offered_for_images(): void {
		$image_id = self::factory()->attachment->create_upload_object( TestImages::jpeg( 800, 600 ) );

		$fields = ( new Panorama() )->edit_fields( [], get_post( $image_id ) );

		$this->assertArrayHasKey( 'slashphoto_projection', $fields );
		$this->assertStringContainsString( 'attachments[' . $image_id . '][slashphoto_projection]', $fields['slashphoto_projection']['html'] );
	}
}
