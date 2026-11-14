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
		$this->assertSame( 3000, $panorama->detected( $image_id )['full_width'] );
		$this->assertSame( 3000, $panorama->detected( $image_id )['image_width'] );
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
		$this->assertSame( [], $panorama->detected( $image_id ) );
		$this->assertSame(
			[
				'fullWidth'     => 2000,
				'fullHeight'    => 1000,
				'croppedWidth'  => 2000,
				'croppedHeight' => 1000,
				'croppedX'      => 0,
				'croppedY'      => 0,
			],
			$panorama->pano_data( $image_id ),
			'A manually marked 2:1 image is a full sphere.'
		);

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
		$this->assertStringContainsString( 'data-slashphoto-panorama="{&quot;fullWidth&quot;:3000,&quot;fullHeight&quot;:1500', $html );
		$this->assertStringContainsString( 'slashphoto-badge', $html );
	}

	/**
	 * Real PTGui panorama, re-cropped vertically in Lightroom after stitching
	 * and downscaled by WordPress to an 8192 px web copy.
	 */
	public function test_normalize_recropped_and_scaled_partial_sphere(): void {
		$gpano                 = Panorama::parse_xmp( '<x GPano:ProjectionType="equirectangular" GPano:FullPanoWidthPixels="10723" GPano:FullPanoHeightPixels="5362" GPano:CroppedAreaImageWidthPixels="10723" GPano:CroppedAreaImageHeightPixels="4370" GPano:CroppedAreaLeftPixels="0" GPano:CroppedAreaTopPixels="496"/>' );
		$gpano['image_width']  = 10723;
		$gpano['image_height'] = 4118;

		$this->assertSame(
			[
				'fullWidth'     => 8192,
				'fullHeight'    => 4096,
				'croppedWidth'  => 8192,
				'croppedHeight' => 3146,
				'croppedX'      => 0,
				'croppedY'      => 475,
			],
			Panorama::normalize( $gpano, 8192, 3146 )
		);
	}

	/**
	 * Real PTGui panorama covering about 224 degrees, re-cropped vertically, not scaled.
	 */
	public function test_normalize_recropped_horizontal_partial(): void {
		$gpano                 = Panorama::parse_xmp( '<x GPano:ProjectionType="equirectangular" GPano:FullPanoWidthPixels="10894" GPano:FullPanoHeightPixels="5448" GPano:CroppedAreaImageWidthPixels="6788" GPano:CroppedAreaImageHeightPixels="4086" GPano:CroppedAreaLeftPixels="2053" GPano:CroppedAreaTopPixels="681"/>' );
		$gpano['image_width']  = 6788;
		$gpano['image_height'] = 3633;

		$data = Panorama::normalize( $gpano, 6788, 3633 );

		$this->assertSame( 10894, $data['fullWidth'] );
		$this->assertSame( 5447, $data['fullHeight'], 'fullHeight must be exactly half of fullWidth.' );
		$this->assertSame( 2053, $data['croppedX'] );
		$this->assertSame( 908, $data['croppedY'], 'Half of the 453 px height difference is trimmed from the top.' );
		$this->assertLessThanOrEqual( $data['fullHeight'], $data['croppedY'] + $data['croppedHeight'] );
	}

	public function test_normalize_without_original_size_trusts_gpano(): void {
		$gpano = Panorama::parse_xmp( TestImages::gpano_xmp( 8000, 4000 ) );

		$data = Panorama::normalize( $gpano, 4000, 2000 );

		$this->assertSame( 4000, $data['fullWidth'] );
		$this->assertSame( 0, $data['croppedY'] );
	}

	public function test_media_library_field_is_offered_for_images(): void {
		$image_id = self::factory()->attachment->create_upload_object( TestImages::jpeg( 800, 600 ) );

		$fields = ( new Panorama() )->edit_fields( [], get_post( $image_id ) );

		$this->assertArrayHasKey( 'slashphoto_projection', $fields );
		$this->assertStringContainsString( 'attachments[' . $image_id . '][slashphoto_projection]', $fields['slashphoto_projection']['html'] );
	}
}
