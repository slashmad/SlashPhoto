<?php
/**
 * Panorama and 360° image support.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Detects 360° (equirectangular) and wide panoramas.
 *
 * Detection reads Google Photo Sphere XMP (the GPano namespace) that 360°
 * cameras and stitchers write into the file. Images without that metadata are
 * never treated as 360° automatically, because viewing a flat panorama in a
 * sphere viewer distorts it badly; photographers can set the type manually in
 * the media library instead.
 *
 * Kinds returned by kind():
 *  - "360"   equirectangular sphere or partial sphere, needs a 360° viewer
 *  - "wide"  flat panorama, aspect ratio at least WIDE_RATIO
 *  - "photo" everything else
 */
final class Panorama {

	/** Post meta holding detected GPano data (array). */
	public const META_DETECTED = '_slashphoto_panorama';

	/** Post meta holding the photographer's override: "", "360" or "flat". */
	public const META_OVERRIDE = '_slashphoto_projection';

	/** Minimum width/height ratio for a flat image to count as a wide panorama. */
	public const WIDE_RATIO = 2.0;

	/** Default longest side for 360° images instead of WordPress' 2560 px. */
	public const DEFAULT_MAX_SIZE = 8192;

	/** Bytes read from the start of a file when looking for XMP. */
	private const XMP_SCAN_BYTES = 524288;

	/**
	 * GPano properties kept for the viewer, mapped to our keys.
	 */
	private const GPANO_FIELDS = [
		'ProjectionType'               => 'projection',
		'FullPanoWidthPixels'          => 'full_width',
		'FullPanoHeightPixels'         => 'full_height',
		'CroppedAreaImageWidthPixels'  => 'cropped_width',
		'CroppedAreaImageHeightPixels' => 'cropped_height',
		'CroppedAreaLeftPixels'        => 'cropped_x',
		'CroppedAreaTopPixels'         => 'cropped_y',
		'PoseHeadingDegrees'           => 'pose_heading',
		'PosePitchDegrees'             => 'pose_pitch',
		'PoseRollDegrees'              => 'pose_roll',
		'InitialViewHeadingDegrees'    => 'initial_heading',
		'InitialViewPitchDegrees'      => 'initial_pitch',
		'InitialHorizontalFOVDegrees'  => 'initial_fov',
	];

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'big_image_size_threshold', [ $this, 'big_image_threshold' ], 10, 3 );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'store_detection' ], 10, 2 );
		add_filter( 'attachment_fields_to_edit', [ $this, 'edit_fields' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ $this, 'save_fields' ], 10, 2 );
	}

	/**
	 * Raises WordPress' big-image downscale limit for 360° images, which need
	 * far more pixels than a flat photo to look sharp in a viewer.
	 *
	 * @param int|false $threshold Longest side in pixels, or false.
	 * @param array     $imagesize Width and height of the original.
	 * @param string    $file      Path of the original.
	 * @return int|false
	 */
	public function big_image_threshold( $threshold, $imagesize, $file ) {
		if ( false === $threshold || ! is_string( $file ) || null === self::detect_file( $file ) ) {
			return $threshold;
		}

		/**
		 * Filters the longest side, in pixels, of the web copy of 360° images.
		 *
		 * The original is always kept. 8192 matches the texture size limit of
		 * most GPUs. Return false to keep the full resolution.
		 *
		 * @param int|false $max_size Longest side in pixels.
		 */
		$max_size = apply_filters( 'slashphoto_panorama_max_size', self::DEFAULT_MAX_SIZE );
		return false === $max_size ? false : max( (int) $threshold, (int) $max_size );
	}

	/**
	 * Stores GPano data when an image is uploaded or regenerated.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function store_detection( $metadata, $attachment_id ) {
		$file = wp_get_original_image_path( (int) $attachment_id );
		$data = $file ? self::detect_file( $file ) : null;

		if ( null !== $data ) {
			// GPano values refer to the original file; keep its size so they
			// can be mapped onto the downscaled web copy later.
			$size = wp_getimagesize( $file );
			if ( $size ) {
				$data['image_width']  = (int) $size[0];
				$data['image_height'] = (int) $size[1];
			}
		}

		if ( null === $data ) {
			delete_post_meta( (int) $attachment_id, self::META_DETECTED );
		} else {
			update_post_meta( (int) $attachment_id, self::META_DETECTED, $data );
		}
		return $metadata;
	}

	/**
	 * Returns the display kind of an image: "360", "wide" or "photo".
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function kind( int $attachment_id ): string {
		$override = (string) get_post_meta( $attachment_id, self::META_OVERRIDE, true );
		$detected = get_post_meta( $attachment_id, self::META_DETECTED, true );

		if ( '360' === $override || ( '' === $override && is_array( $detected ) && $detected ) ) {
			$kind = '360';
		} else {
			$meta   = wp_get_attachment_metadata( $attachment_id );
			$width  = (int) ( $meta['width'] ?? 0 );
			$height = (int) ( $meta['height'] ?? 0 );
			$kind   = ( $height > 0 && $width / $height >= self::WIDE_RATIO ) ? 'wide' : 'photo';
		}

		/**
		 * Filters the display kind of a gallery image.
		 *
		 * @param string $kind          "360", "wide" or "photo".
		 * @param int    $attachment_id Attachment ID.
		 */
		return (string) apply_filters( 'slashphoto_image_kind', $kind, $attachment_id );
	}

	/**
	 * Returns the raw GPano data detected on upload, or an empty array.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, string|float|int>
	 */
	public function detected( int $attachment_id ): array {
		$detected = get_post_meta( $attachment_id, self::META_DETECTED, true );
		return is_array( $detected ) ? $detected : [];
	}

	/**
	 * Returns viewer geometry for the web copy ("full" size) of a 360° image,
	 * in Photo Sphere Viewer's panoData format.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, int|float>
	 */
	public function pano_data( int $attachment_id ): array {
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$web_w = (int) ( $meta['width'] ?? 0 );
		$web_h = (int) ( $meta['height'] ?? 0 );
		if ( $web_w <= 0 || $web_h <= 0 ) {
			return [];
		}

		/**
		 * Filters the viewer geometry of a 360° image.
		 *
		 * @param array $pano_data     panoData for Photo Sphere Viewer.
		 * @param int   $attachment_id Attachment ID.
		 */
		return (array) apply_filters(
			'slashphoto_panorama_pano_data',
			self::normalize( $this->detected( $attachment_id ), $web_w, $web_h ),
			$attachment_id
		);
	}

	/**
	 * Maps GPano values onto the image that is actually displayed.
	 *
	 * GPano describes the original file, but the displayed image can differ:
	 *  - WordPress may have made a smaller "-scaled" web copy.
	 *  - Photographers often crop a stitched panorama again (for example in
	 *    Lightroom) after the stitcher wrote GPano, so the cropped area in the
	 *    metadata no longer matches the pixels. The horizontal extent is
	 *    trusted (it defines the angle of view); a height mismatch is treated
	 *    as an even trim of top and bottom.
	 * Without GPano (marked as 360° by hand) the image is assumed to cover a
	 * full 360° horizontally, centred vertically.
	 *
	 * @param array $gpano Detected data (snake_case keys from parse_xmp(), plus image_width/image_height).
	 * @param int   $web_w Width of the displayed image.
	 * @param int   $web_h Height of the displayed image.
	 * @return array<string, int|float> panoData: fullWidth, fullHeight, croppedWidth, croppedHeight, croppedX, croppedY, pose*.
	 */
	public static function normalize( array $gpano, int $web_w, int $web_h ): array {
		$orig_w = (int) ( $gpano['image_width'] ?? $gpano['cropped_width'] ?? 0 );
		$orig_h = (int) ( $gpano['image_height'] ?? $gpano['cropped_height'] ?? 0 );

		if ( empty( $gpano['full_width'] ) || $orig_w <= 0 || $orig_h <= 0 ) {
			$full_w = max( $web_w, 2 * $web_h );
			$full_h = (int) round( $full_w / 2 );
			return [
				'fullWidth'     => $full_w,
				'fullHeight'    => $full_h,
				'croppedWidth'  => $web_w,
				'croppedHeight' => $web_h,
				'croppedX'      => (int) round( ( $full_w - $web_w ) / 2 ),
				'croppedY'      => (int) round( ( $full_h - $web_h ) / 2 ),
			];
		}

		// 1. Express GPano in original pixels, trusting the horizontal extent.
		$crop_w = (float) ( $gpano['cropped_width'] ?? $orig_w );
		$s0     = $crop_w > 0 ? $orig_w / $crop_w : 1.0;
		$full_w = (float) $gpano['full_width'] * $s0;
		$crop_x = (float) ( $gpano['cropped_x'] ?? ( $full_w - $orig_w ) / 2 / $s0 ) * $s0;
		$crop_h = (float) ( $gpano['cropped_height'] ?? $orig_h ) * $s0;
		$crop_y = isset( $gpano['cropped_y'] ) ? (float) $gpano['cropped_y'] * $s0 : null;

		// 2. Re-crop after stitching: trim the difference evenly.
		$full_h = $full_w / 2;
		if ( null === $crop_y ) {
			$crop_y = ( $full_h - $orig_h ) / 2;
		} elseif ( abs( $crop_h - $orig_h ) > 1 ) {
			$crop_y += ( $crop_h - $orig_h ) / 2;
		}
		$crop_y = min( max( 0.0, $crop_y ), max( 0.0, $full_h - $orig_h ) );

		// 3. Scale from the original to the displayed copy.
		$s1     = $web_w / $orig_w;
		$full_w = (int) round( $full_w * $s1 );
		$full_h = (int) round( $full_w / 2 );

		$data = [
			'fullWidth'     => $full_w,
			'fullHeight'    => $full_h,
			'croppedWidth'  => $web_w,
			'croppedHeight' => $web_h,
			'croppedX'      => (int) min( max( 0, round( $crop_x * $s1 ) ), $full_w - $web_w ),
			'croppedY'      => (int) min( max( 0, round( $crop_y * $s1 ) ), max( 0, $full_h - $web_h ) ),
		];

		foreach ( [
			'pose_heading' => 'poseHeading',
			'pose_pitch'   => 'posePitch',
			'pose_roll'    => 'poseRoll',
		] as $from => $to ) {
			if ( isset( $gpano[ $from ] ) ) {
				$data[ $to ] = (float) $gpano[ $from ];
			}
		}
		return $data;
	}

	/**
	 * Looks for GPano XMP in the first part of an image file.
	 *
	 * @param string $file Absolute path.
	 * @return array<string, string|float|int>|null GPano data, or null when not a 360° image.
	 */
	public static function detect_file( string $file ): ?array {
		if ( ! is_readable( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, partial read.
		$head = file_get_contents( $file, false, null, 0, self::XMP_SCAN_BYTES );
		return false === $head ? null : self::parse_xmp( $head );
	}

	/**
	 * Parses GPano properties from XMP, in both attribute and element syntax.
	 *
	 * @param string $xmp Raw bytes containing an XMP packet.
	 * @return array<string, string|float|int>|null GPano data, or null when not equirectangular.
	 */
	public static function parse_xmp( string $xmp ): ?array {
		if ( ! str_contains( $xmp, 'GPano' ) ) {
			return null;
		}

		$raw = [];
		if ( preg_match_all( '/GPano:(\w+)\s*=\s*["\']([^"\']*)["\']/', $xmp, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$raw[ $match[1] ] = $match[2];
			}
		}
		if ( preg_match_all( '/<GPano:(\w+)>([^<]*)<\/GPano:\1>/', $xmp, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$raw[ $match[1] ] = $match[2];
			}
		}

		if ( 'equirectangular' !== strtolower( trim( $raw['ProjectionType'] ?? '' ) ) ) {
			return null;
		}

		$data = [];
		foreach ( self::GPANO_FIELDS as $gpano => $key ) {
			if ( ! isset( $raw[ $gpano ] ) || '' === trim( $raw[ $gpano ] ) ) {
				continue;
			}
			$value        = trim( $raw[ $gpano ] );
			$data[ $key ] = 'projection' === $key ? 'equirectangular' : ( str_contains( $key, 'width' ) || str_contains( $key, 'height' ) || str_starts_with( $key, 'cropped_' ) ? (int) $value : (float) $value );
		}
		return $data;
	}

	/**
	 * Adds a "Panorama" select to the media library edit screen and modal.
	 *
	 * @param array    $fields     Form fields.
	 * @param \WP_Post $attachment Attachment.
	 * @return array
	 */
	public function edit_fields( $fields, $attachment ) {
		if ( ! wp_attachment_is_image( $attachment ) ) {
			return $fields;
		}

		$current  = (string) get_post_meta( $attachment->ID, self::META_OVERRIDE, true );
		$detected = get_post_meta( $attachment->ID, self::META_DETECTED, true ) ? __( 'detected: 360°', 'slashphoto' ) : __( 'detected: not 360°', 'slashphoto' );
		$options  = [
			''     => sprintf( /* translators: %s: detection result */ __( 'Automatic (%s)', 'slashphoto' ), $detected ),
			'360'  => __( '360° panorama', 'slashphoto' ),
			'flat' => __( 'Not 360°', 'slashphoto' ),
		];

		$name = 'attachments[' . $attachment->ID . '][slashphoto_projection]';
		$html = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( 'attachments-' . $attachment->ID . '-slashphoto_projection' ) . '">';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select>';

		$fields['slashphoto_projection'] = [
			'label' => __( 'Panorama', 'slashphoto' ),
			'input' => 'html',
			'html'  => $html,
		];
		return $fields;
	}

	/**
	 * Saves the "Panorama" select.
	 *
	 * @param array $post       Attachment post data.
	 * @param array $attachment Submitted attachment fields.
	 * @return array
	 */
	public function save_fields( $post, $attachment ) {
		if ( ! isset( $attachment['slashphoto_projection'] ) || empty( $post['ID'] ) ) {
			return $post;
		}

		$value = sanitize_key( (string) $attachment['slashphoto_projection'] );
		if ( in_array( $value, [ '360', 'flat' ], true ) ) {
			update_post_meta( (int) $post['ID'], self::META_OVERRIDE, $value );
		} else {
			delete_post_meta( (int) $post['ID'], self::META_OVERRIDE );
		}
		return $post;
	}
}
