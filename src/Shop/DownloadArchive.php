<?php
/**
 * Temporary ZIP delivery for purchased photos.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/** Builds an archive from the order's download lines, in line order. */
final class DownloadArchive {

	private const MAX_FILES = 500;
	private const MAX_BYTES = 1073741824;

	/**
	 * Whether this PHP install can build an archive for the given order size.
	 *
	 * @param int $count Number of download lines.
	 */
	public static function available( int $count ): bool {
		return $count > 0 && $count <= self::MAX_FILES && class_exists( ZipArchive::class );
	}

	/**
	 * Packages each purchased file and its snapshotted licence, if present.
	 * The caller authorizes the order and serves the ZIP.
	 *
	 * @param int     $order_id Order.
	 * @param array[] $items    Authorized download lines in their original order.
	 * @return array{path: string, name: string, temporary: bool}|WP_Error
	 */
	public static function build( int $order_id, array $items ) {
		$unavailable = new WP_Error( 'slashphoto_archive_unavailable', __( 'This order cannot be downloaded as one ZIP. You can download each file separately from the order page.', 'slashphoto' ), [ 'status' => 503 ] );
		if ( ! self::available( count( $items ) ) ) {
			return $unavailable;
		}
		$directory = realpath( sys_get_temp_dir() );
		$uploads   = wp_get_upload_dir();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- PHP's system temporary directory.
		if ( ! $directory || ! is_writable( $directory ) ) {
			return $unavailable;
		}
		foreach ( [ ABSPATH, WP_CONTENT_DIR, $uploads['basedir'] ] as $public ) {
			$root = realpath( $public );
			if ( $root && str_starts_with( trailingslashit( $directory ), trailingslashit( $root ) ) ) {
				return $unavailable;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- create an exclusive 0600 file outside the site.
		$path = tempnam( $directory, 'slashphoto-order-' );
		if ( ! $path ) {
			return $unavailable;
		}
		register_shutdown_function( 'wp_delete_file', $path );
		$archive = new ZipArchive();
		if ( true !== $archive->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $path );
			return $unavailable;
		}

		$names     = [ 'licences.txt' => true ];
		$licences  = [];
		$temporary = [];
		$bytes     = 0;
		$complete  = false;
		try {
			foreach ( $items as $item ) {
				$file = Downloads::item_file( $item );
				if ( is_wp_error( $file ) ) {
					return $file;
				}
				if ( ! empty( $file['temporary'] ) ) {
					$temporary[] = $file['path'];
					register_shutdown_function( 'wp_delete_file', $file['path'] );
				}
				$size = filesize( $file['path'] );
				if ( false === $size || $size > self::MAX_BYTES - $bytes ) {
					return $unavailable;
				}
				$bytes += $size;
				$name   = self::unique_name( $file['name'], $names );
				if ( ! $archive->addFile( $file['path'], $name ) || ! $archive->setCompressionName( $name, ZipArchive::CM_STORE ) ) {
					return $unavailable;
				}
				if ( '' !== (string) ( $item['licence_name'] ?? '' ) ) {
					$licences[] = $name . "\n" . $item['licence_name'] . "\n" . $item['licence_text'];
				}
			}//end foreach
			if ( $licences && ! $archive->addFromString( 'licences.txt', implode( "\n\n", $licences ) . "\n" ) ) {
				return $unavailable;
			}
			$complete = $archive->close();
			return $complete ? [
				'path'      => $path,
				'name'      => 'order-' . $order_id . '.zip',
				'temporary' => true,
			] : $unavailable;
		} finally {
			if ( ! $complete ) {
				$archive->unchangeAll();
				unset( $archive );
				wp_delete_file( $path );
			}
			foreach ( $temporary as $file ) {
				wp_delete_file( $file );
			}
		}//end try
	}

	/**
	 * Gives duplicate filenames distinct names even on case-insensitive systems.
	 *
	 * @param string $name  File's safe download name.
	 * @param array  $names Used names.
	 */
	private static function unique_name( string $name, array &$names ): string {
		$name      = sanitize_file_name( $name );
		$name      = '' !== $name ? $name : 'photo';
		$extension = pathinfo( $name, PATHINFO_EXTENSION );
		$base      = pathinfo( $name, PATHINFO_FILENAME );
		$candidate = $name;
		$suffix    = 2;
		while ( isset( $names[ strtolower( $candidate ) ] ) ) {
			$candidate = $base . '-' . $suffix . ( '' !== $extension ? '.' . $extension : '' );
			++$suffix;
		}
		$names[ strtolower( $candidate ) ] = true;
		return $candidate;
	}
}
