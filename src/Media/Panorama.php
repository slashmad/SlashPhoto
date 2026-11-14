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
	 * Returns viewer data for a 360° image: detected GPano values, or an
	 * empty array when the photographer marked it as 360° manually.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, string|float|int>
	 */
	public function viewer_data( int $attachment_id ): array {
		$detected = get_post_meta( $attachment_id, self::META_DETECTED, true );
		return is_array( $detected ) ? $detected : [];
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
