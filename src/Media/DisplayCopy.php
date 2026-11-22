<?php
/**
 * Display copies of gallery images.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Media;

use SlashPhoto\Gallery\Uploads;
use SlashPhoto\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every image uploaded to a gallery gets a separate display copy, and the
 * full-size original is never the file shown on the site.
 *
 * Photographers upload one full-resolution file per image. WordPress makes
 * a "-scaled" copy only above 2560 px; SlashPhoto makes one for every gallery
 * image, at the long edge set under SlashPhoto → Settings (360° panoramas
 * keep up to 8192 px), so Originals can always move the original out of
 * reach and sell or deliver it later.
 */
final class DisplayCopy {

	/**
	 * Whether the file being imported is already a display copy (made by
	 * SnapOtter), so WordPress must not make a second one.
	 *
	 * @var bool
	 */
	private static bool $prepared = false;

	/**
	 * Registers hooks. Runs after Panorama raises the limit for 360° images.
	 */
	public function register_hooks(): void {
		add_filter( 'big_image_size_threshold', [ $this, 'threshold' ], 20, 4 );
		add_filter( 'wp_editor_set_quality', [ $this, 'quality' ], 10, 2 );
		add_filter( 'image_editor_output_format', [ $this, 'output_format' ] );
	}

	/**
	 * Imports an already prepared display copy: no second copy is made.
	 *
	 * @param callable $callback Work that imports the file.
	 * @return mixed The callback's result.
	 */
	public static function with_prepared( callable $callback ) {
		$previous       = self::$prepared;
		self::$prepared = true;
		try {
			return $callback();
		} finally {
			self::$prepared = $previous;
		}
	}

	/**
	 * Long edge of the display copy for a gallery image.
	 *
	 * @param int|false $threshold     Longest side in pixels, or false.
	 * @param array     $imagesize     Width and height of the original.
	 * @param string    $file          Path of the original.
	 * @param int       $attachment_id Attachment ID.
	 * @return int|false
	 */
	public function threshold( $threshold, $imagesize, $file, $attachment_id = 0 ) {
		if ( ! is_string( $file ) || ! Originals::in_gallery( (int) $attachment_id ) ) {
			return $threshold;
		}
		if ( self::$prepared ) {
			return false;
		}

		$longest = max( (int) ( $imagesize[0] ?? 0 ), (int) ( $imagesize[1] ?? 0 ) );
		if ( $longest < 3 ) {
			return $threshold;
		}

		if ( null !== Panorama::detect_file( $file ) ) {
			// Panorama already chose the size (false keeps full resolution).
			$target = false === $threshold ? $longest : (int) $threshold;
		} else {
			$target = (int) Settings::get( 'display_size' );
		}

		// Two pixels smaller than the original at most: WordPress skips
		// resizes that differ by one pixel (wp_fuzzy_number_match), and then
		// no separate copy would be made.
		return max( 1, min( $target, $longest - 2 ) );
	}

	/**
	 * JPEG/WebP quality of display copies and sizes made during a gallery upload.
	 *
	 * @param int    $quality   Quality (1-100).
	 * @param string $mime_type Output type.
	 * @return int
	 */
	public function quality( $quality, $mime_type = '' ) {
		if ( ! Uploads::current_gallery() || ! in_array( $mime_type, [ 'image/jpeg', 'image/webp', 'image/avif' ], true ) ) {
			return $quality;
		}
		return (int) Settings::get( 'display_quality' );
	}

	/**
	 * Image types for display copies, with whether this server can write them.
	 *
	 * @return array<string, bool> Setting value => supported.
	 */
	public static function formats(): array {
		return [
			'jpeg' => true,
			'webp' => wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ),
			'avif' => wp_image_editor_supports( [ 'mime_type' => 'image/avif' ] ),
		];
	}

	/**
	 * Writes the display copy and sizes of JPEG gallery uploads as WebP or
	 * AVIF when chosen and supported. Originals are never converted; PNG keeps
	 * its format. Other images in the media library are not affected.
	 *
	 * @param array $formats Map of source type => output type.
	 * @return array
	 */
	public function output_format( $formats ) {
		$format = (string) Settings::get( 'display_format' );
		if ( 'jpeg' === $format || ! Uploads::current_gallery() || empty( self::formats()[ $format ] ) ) {
			return $formats;
		}
		$formats               = is_array( $formats ) ? $formats : [];
		$formats['image/jpeg'] = 'image/' . $format;
		return $formats;
	}
}
