<?php
/**
 * Imports full-size originals into a gallery.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use SlashPhoto\Media\DisplayCopy;
use SlashPhoto\Media\Originals;
use SlashPhoto\Media\Panorama;
use SlashPhoto\Settings;
use SlashPhoto\SnapOtter\Connection;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one uploaded original into a gallery image.
 *
 * - Web formats (JPEG, PNG, WebP, AVIF) are handled by WordPress: DisplayCopy
 *   makes the display copy and Originals moves the original away.
 * - With SnapOtter connected, RAW, HEIC, TIFF and PSD files, and (depending
 *   on the settings) very large or all images, are sent to SnapOtter once.
 *   Its display copy becomes the attachment; the original is stored next to
 *   it in a random folder, exactly as for WordPress-made copies.
 */
final class Importer {

	public const META_MADE_BY = '_slashphoto_display_copy';

	public const WEB_TYPES       = [ 'jpg', 'jpeg', 'jpe', 'png', 'webp', 'avif' ];
	public const SNAPOTTER_TYPES = [ 'tif', 'tiff', 'heic', 'heif', 'psd', 'dng', 'arw', 'cr2', 'cr3', 'nef', 'nrw', 'orf', 'raf', 'rw2', 'pef', 'srw', 'x3f' ];

	/**
	 * Original waiting to be attached to the display copy being imported.
	 *
	 * @var array{path: string, ext: string}|null
	 */
	private static ?array $pending = null;

	/**
	 * Registers hooks. Runs before panorama detection (10) and Originals (15),
	 * which both read original_image.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'attach_original' ], 5, 2 );
	}

	/**
	 * File extensions accepted for galleries right now.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions(): array {
		return self::snapotter_active() ? array_merge( self::WEB_TYPES, self::SNAPOTTER_TYPES ) : self::WEB_TYPES;
	}

	/**
	 * Whether SnapOtter is connected and switched on.
	 */
	public static function snapotter_active(): bool {
		return 'off' !== Settings::get( 'snapotter_mode' ) && Connection::is_configured();
	}

	/**
	 * Whether SnapOtter should make the display copy of this file.
	 *
	 * @param string $path File.
	 * @param string $ext  Lower-case extension of the uploaded name.
	 */
	public static function use_snapotter( string $path, string $ext ): bool {
		if ( ! self::snapotter_active() ) {
			return false;
		}
		if ( ! in_array( $ext, self::WEB_TYPES, true ) || 'all' === Settings::get( 'snapotter_mode' ) ) {
			return true;
		}
		$size = wp_getimagesize( $path );
		return $size && ( (int) $size[0] * (int) $size[1] ) >= (int) Settings::get( 'snapotter_min_mp' ) * 1000000;
	}

	/**
	 * Imports a file. The file is moved; the caller must not reuse $path.
	 *
	 * @param string $path       Local file.
	 * @param string $name       Name as uploaded, e.g. "DSC_1234.ARW".
	 * @param int    $gallery_id Gallery.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function import( string $path, string $name, int $gallery_id ) {
		$name = sanitize_file_name( wp_basename( $name ) );
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$base = (string) pathinfo( $name, PATHINFO_FILENAME );

		if ( ! in_array( $ext, self::allowed_extensions(), true ) ) {
			return in_array( $ext, self::SNAPOTTER_TYPES, true )
				? new WP_Error( 'slashphoto_needs_snapotter', __( 'RAW, HEIC, TIFF and PSD files need a SnapOtter server. Connect one under SlashPhoto → Settings, or upload JPEG files.', 'slashphoto' ) )
				: new WP_Error( 'slashphoto_file_type', __( 'This file type cannot be added to a gallery.', 'slashphoto' ) );
		}

		$post_data = [ 'post_title' => sanitize_text_field( $base ) ];

		if ( self::use_snapotter( $path, $ext ) ) {
			$display = self::snapotter_copy( $path );
			if ( ! is_wp_error( $display ) ) {
				self::$pending = [
					'path' => $path,
					'ext'  => $ext,
				];
				try {
					$id = DisplayCopy::with_prepared(
						static fn() => self::sideload( $display, $base . '.jpg', $gallery_id, $post_data )
					);
				} finally {
					self::$pending = null;
					wp_delete_file( $display );
				}
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
				if ( ! is_wp_error( $id ) ) {
					update_post_meta( $id, self::META_MADE_BY, 'snapotter' );
				}
				return $id;
			}//end if
			// WordPress can still handle web formats itself.
			if ( ! in_array( $ext, self::WEB_TYPES, true ) ) {
				wp_delete_file( $path );
				return $display;
			}
		}//end if

		$check = wp_check_filetype_and_ext( $path, $name );
		if ( empty( $check['type'] ) || ! str_starts_with( (string) $check['type'], 'image/' ) ) {
			wp_delete_file( $path );
			return new WP_Error( 'slashphoto_file_type', __( 'The file is not an image WordPress can read.', 'slashphoto' ) );
		}
		return self::sideload( $path, $name, $gallery_id, $post_data );
	}

	/**
	 * Asks SnapOtter for a display copy, written next to $path.
	 *
	 * @param string $path Original.
	 * @return string|WP_Error Path of the JPEG.
	 */
	public static function snapotter_copy( string $path ) {
		$client = Connection::client();
		if ( ! $client ) {
			return new WP_Error( 'slashphoto_snapotter_off', __( 'SnapOtter is not connected.', 'slashphoto' ) );
		}
		/** This filter is documented in src/Media/Panorama.php */
		$panorama  = (int) apply_filters( 'slashphoto_panorama_max_size', Panorama::DEFAULT_MAX_SIZE );
		$long_edge = null !== Panorama::detect_file( $path ) && $panorama > 0 ? $panorama : (int) Settings::get( 'display_size' );
		$target    = $path . '-display.jpg';
		$result    = $client->display_copy( $path, $long_edge, (int) Settings::get( 'display_quality' ), $target );
		return is_wp_error( $result ) ? $result : $target;
	}

	/**
	 * Runs $callback (which generates metadata for a prepared display copy)
	 * so that $path is stored as the attachment's private original.
	 *
	 * @param string   $path     Original file; it is moved.
	 * @param string   $ext      Lower-case extension to give it.
	 * @param callable $callback Work to run.
	 * @return mixed The callback's result.
	 */
	public static function with_original( string $path, string $ext, callable $callback ) {
		self::$pending = [
			'path' => $path,
			'ext'  => $ext,
		];
		try {
			return $callback();
		} finally {
			self::$pending = null;
		}
	}

	/**
	 * Adds a file to the gallery through WordPress' media functions.
	 *
	 * @param string $path       File (moved by WordPress).
	 * @param string $name       File name to use.
	 * @param int    $gallery_id Gallery.
	 * @param array  $post_data  Attachment fields.
	 * @return int|WP_Error
	 */
	private static function sideload( string $path, string $name, int $gallery_id, array $post_data ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		wp_raise_memory_limit( 'image' );

		$file = [
			'name'     => $name,
			'tmp_name' => $path,
		];
		return Uploads::for_gallery( $gallery_id, static fn() => media_handle_sideload( $file, $gallery_id, null, $post_data ) );
	}

	/**
	 * Stores the original next to a SnapOtter display copy and records it
	 * as the attachment's original_image.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function attach_original( $metadata, $attachment_id ) {
		if ( null === self::$pending || ! is_array( $metadata ) || ! empty( $metadata['original_image'] ) || ! Originals::in_gallery( (int) $attachment_id ) ) {
			return $metadata;
		}
		$main     = (string) get_attached_file( (int) $attachment_id );
		$relative = Originals::store( self::$pending['path'], dirname( $main ), pathinfo( $main, PATHINFO_FILENAME ) . '.' . self::$pending['ext'] );
		if ( null !== $relative ) {
			$metadata['original_image'] = $relative;
			self::$pending              = null;
			// Panorama and Watermark read the original from saved metadata.
			wp_update_attachment_metadata( (int) $attachment_id, $metadata );
		}
		return $metadata;
	}
}
