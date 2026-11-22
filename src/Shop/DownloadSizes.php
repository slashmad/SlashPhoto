<?php
/**
 * Smaller copies of originals for downloads sold at a fixed size.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Proofing\Proofing;
use SlashPhoto\SnapOtter\Connection;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A download product may set the longest side in pixels (for example 2048
 * for web and social media). The copy is made from the private original the
 * first time a customer downloads it and is kept next to the original, in
 * its protected random folder, so it is never reachable by a public address.
 * Originals that are already small enough are delivered unchanged; nothing
 * is ever enlarged. Watermarks are not applied: they belong to the display
 * copies only.
 */
final class DownloadSizes {

	public const MIN = 320;
	public const MAX = 16384;

	/**
	 * Attachment meta listing the copies made, by size, as file names in
	 * the original's folder. Used to delete them with the image.
	 */
	public const META = '_slashphoto_download_sizes';

	private const QUALITY = 92;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'delete_attachment', [ self::class, 'delete_copies' ] );
	}

	/**
	 * A valid longest side, or 0 for the full-size original.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function clean( $value ): int {
		$size = is_numeric( $value ) ? (int) $value : 0;
		return $size > 0 ? min( self::MAX, max( self::MIN, $size ) ) : 0;
	}

	/**
	 * The file to deliver for an image at a size.
	 *
	 * @param int    $image_id Image.
	 * @param int    $size     Longest side in pixels.
	 * @param string $label    Download name without extension.
	 * @return array{path: string, name: string, temporary?: bool}|WP_Error
	 */
	public static function file( int $image_id, int $size, string $label = '' ) {
		$original = Downloads::file( $image_id, $label );
		if ( is_wp_error( $original ) || $size <= 0 ) {
			return $original;
		}
		$source = $original['path'];
		$dims   = wp_getimagesize( $source );
		if ( $dims && max( (int) $dims[0], (int) $dims[1] ) <= $size ) {
			return $original;
		}

		$label  = '' !== $label ? $label : Proofing::file_label( $image_id );
		$folder = dirname( $source );
		// Copies are only kept inside a protected originals folder.
		$cache = (bool) preg_match( '/^o[a-f0-9]{24}$/', wp_basename( $folder ) );
		$type  = wp_check_filetype( $source );
		$mime  = in_array( $type['type'], [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ], true ) ? (string) $type['type'] : 'image/jpeg';
		$ext   = 'image/jpeg' === $mime ? 'jpg' : (string) $type['ext'];
		$stem  = sanitize_file_name( pathinfo( $source, PATHINFO_FILENAME ) . '-' . $size . 'px' );
		$dir   = $cache ? $folder : untrailingslashit( get_temp_dir() );
		$file  = ( $cache ? '' : 'slashphoto-' . bin2hex( random_bytes( 8 ) ) . '-' ) . $stem . '.' . $ext;
		$path  = $dir . '/' . $file;
		$name  = sanitize_file_name( $label . '-' . $size . 'px.' . $ext );

		if ( $cache && is_file( $path ) && filemtime( $path ) >= filemtime( $source ) ) {
			return [
				'path' => $path,
				'name' => $name,
			];
		}

		$made = self::resize( $source, $size, $mime, $dir, $file );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		if ( '' === $made ) {
			// The original is no larger than the size: never enlarge.
			return $original;
		}
		if ( $cache ) {
			$copies = get_post_meta( $image_id, self::META, true );
			$copies = is_array( $copies ) ? array_filter( $copies, 'is_string' ) : [];

			$copies[ $size ] = $file;
			update_post_meta( $image_id, self::META, $copies );
		}
		return [
			'path'      => $path,
			'name'      => $name,
			'temporary' => ! $cache,
		];
	}

	/**
	 * Writes the scaled copy atomically: into a temporary file in the same
	 * folder, then renamed, so a parallel download never sees half a file.
	 *
	 * @param string $source Original.
	 * @param int    $size   Longest side.
	 * @param string $mime   Output type.
	 * @param string $dir    Target folder.
	 * @param string $file   Target file name, with the extension for $mime.
	 * @return string|WP_Error The final path, '' when the original is small enough.
	 */
	private static function resize( string $source, int $size, string $mime, string $dir, string $file ) {
		$failed = new WP_Error( 'slashphoto_download_size', __( 'This size cannot be made right now. Please try again later or contact us.', 'slashphoto' ), [ 'status' => 503 ] );
		$ext    = (string) pathinfo( $file, PATHINFO_EXTENSION );
		$part   = $dir . '/' . pathinfo( $file, PATHINFO_FILENAME ) . '.part' . bin2hex( random_bytes( 4 ) ) . '.' . $ext;
		$done   = self::with_wordpress( $source, $size, $mime, $part );
		if ( '' === $done ) {
			return '';
		}
		if ( is_wp_error( $done ) ) {
			wp_delete_file( $part );
			// RAW, HEIC, PSD and other formats WordPress cannot open; SnapOtter writes JPEG.
			$client = 'image/jpeg' === $mime ? Connection::client() : null;
			if ( ! $client ) {
				return new WP_Error( 'slashphoto_download_size', __( 'This size cannot be made from the original file. Please contact us.', 'slashphoto' ), [ 'status' => 500 ] );
			}
			if ( is_wp_error( $client->display_copy( $source, $size, self::QUALITY, $part ) ) || ! is_file( $part ) ) {
				wp_delete_file( $part );
				return $failed;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same folder, atomic replace.
		if ( ! rename( $part, $dir . '/' . $file ) ) {
			wp_delete_file( $part );
			return $failed;
		}
		return $dir . '/' . $file;
	}

	/**
	 * Scales with the WordPress image editor, keeping the colour profile and
	 * photo metadata (copyright, camera, capture time) where the editor can.
	 *
	 * @param string $source Original.
	 * @param int    $size   Longest side.
	 * @param string $mime   Output type.
	 * @param string $target Where to write, with the extension for $mime.
	 * @return true|string|WP_Error True when written, '' when no smaller copy is needed.
	 */
	private static function with_wordpress( string $source, int $size, string $mime, string $target ) {
		$keep = static fn(): bool => false;
		add_filter( 'image_strip_meta', $keep );
		try {
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			$current = $editor->get_size();
			if ( max( (int) $current['width'], (int) $current['height'] ) <= $size ) {
				return '';
			}
			if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
				$editor->maybe_exif_rotate();
			}
			$editor->set_quality( self::QUALITY );
			$resized = $editor->resize( $size, $size, false );
			$saved   = is_wp_error( $resized ) ? $resized : $editor->save( $target, $mime );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			return $saved['path'] === $target && is_file( $target ) ? true : new WP_Error( 'slashphoto_download_size', 'unexpected output' );
		} finally {
			remove_filter( 'image_strip_meta', $keep );
		}//end try
	}

	/**
	 * Deletes the copies made of an image when the image is deleted.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function delete_copies( $attachment_id ): void {
		$copies = get_post_meta( (int) $attachment_id, self::META, true );
		if ( ! is_array( $copies ) || ! $copies ) {
			return;
		}
		$original = (string) wp_get_original_image_path( (int) $attachment_id );
		$folder   = '' !== $original ? dirname( $original ) : '';
		if ( '' === $folder || ! preg_match( '/^o[a-f0-9]{24}$/', wp_basename( $folder ) ) ) {
			return;
		}
		foreach ( $copies as $name ) {
			if ( is_string( $name ) && wp_basename( $name ) === $name ) {
				wp_delete_file( $folder . '/' . $name );
			}
		}
	}
}
