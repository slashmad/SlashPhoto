<?php
/**
 * Makes new display copies of existing gallery images from their originals.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Media;

use SlashPhoto\Gallery\GalleryRepository;
use SlashPhoto\Gallery\Importer;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Gallery\Uploads;
use SlashPhoto\Proofing\Proofing;
use SlashPhoto\Shop\DownloadSizes;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Settings such as the watermark, the display size and quality, and the
 * SnapOtter mode apply when an image is uploaded. Reprocessing applies the
 * current settings to images uploaded earlier:
 *
 * - the display copy and every size are made again from the original, with
 *   the current watermark, size, quality and SnapOtter settings;
 * - the files move into the gallery's random folder with new random names,
 *   so older uploads get unguessable addresses and caches see new files;
 * - the original moves into a new protected folder; originals that were
 *   still next to their display copy (uploaded before SlashPhoto protected
 *   them) become private.
 *
 * The attachment keeps its ID, title, caption, order and every reference
 * (selections, orders, covers). The old files are removed only after the
 * original in its new place has been checked byte for byte; if anything
 * fails, the image is left exactly as it was.
 */
final class Reprocessor {

	public const NAMESPACE = 'slashphoto/v1';

	/** Seconds of work per REST request. */
	private const BUDGET = 20.0;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'slashphoto reprocess', [ self::class, 'cli' ] );
		}
	}

	/**
	 * REST route used by the gallery workspace.
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/galleries/(?P<id>\d+)/reprocess',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest' ],
				'permission_callback' => static fn( WP_REST_Request $request ): bool => self::can( (int) $request['id'] ),
				'args'                => [
					'after' => [
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					],
				],
			]
		);
	}

	/**
	 * Whether the current user may reprocess a gallery's images.
	 *
	 * @param int $gallery_id Gallery.
	 */
	public static function can( int $gallery_id ): bool {
		return PostType::POST_TYPE === get_post_type( $gallery_id ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $gallery_id );
	}

	/**
	 * Processes the next images of a gallery.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rest( WP_REST_Request $request ) {
		return rest_ensure_response( self::batch( (int) $request['id'], (int) $request['after'], self::BUDGET ) );
	}

	/**
	 * Image IDs of a gallery (not its sub-galleries), in ID order.
	 *
	 * @param int $gallery_id Gallery.
	 * @return int[]
	 */
	public static function image_ids( int $gallery_id ): array {
		$ids = array_map( static fn( $image ): int => (int) $image->ID, ( new GalleryRepository() )->images( $gallery_id ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Reprocesses images with IDs above $after until the time budget is
	 * used (always at least one).
	 *
	 * @param int   $gallery_id Gallery.
	 * @param int   $after      Last ID already handled.
	 * @param float $budget     Seconds.
	 * @return array{done: int, failed: array<int, array{id: int, title: string, message: string}>, next: int, remaining: int, total: int}
	 */
	public static function batch( int $gallery_id, int $after, float $budget ): array {
		$ids    = self::image_ids( $gallery_id );
		$todo   = array_values( array_filter( $ids, static fn( int $id ): bool => $id > $after ) );
		$start  = microtime( true );
		$done   = 0;
		$failed = [];
		$next   = $after;
		foreach ( $todo as $id ) {
			if ( ( $done || $failed ) && microtime( true ) - $start > $budget ) {
				break;
			}
			$result = self::image( $id );
			if ( is_wp_error( $result ) ) {
				$failed[] = [
					'id'      => $id,
					'title'   => get_the_title( $id ),
					'message' => $result->get_error_message(),
				];
			} else {
				++$done;
			}
			$next = $id;
		}
		return [
			'done'      => $done,
			'failed'    => $failed,
			'next'      => $next,
			'remaining' => count( array_filter( $ids, static fn( int $id ): bool => $id > $next ) ),
			'total'     => count( $ids ),
		];
	}

	/**
	 * Makes new copies of one image from its original.
	 *
	 * @param int $id Attachment.
	 * @return true|WP_Error
	 */
	public static function image( int $id ) {
		$image = get_post( $id );
		if ( ! $image || 'attachment' !== $image->post_type || ! wp_attachment_is_image( $image ) || ! Originals::in_gallery( $id ) ) {
			return new WP_Error( 'slashphoto_reprocess_type', __( 'Only gallery photos can be processed again.', 'slashphoto' ) );
		}
		$gallery_id = (int) $image->post_parent;
		$old_main   = (string) get_attached_file( $id );
		$old_meta   = wp_get_attachment_metadata( $id );
		$old_meta   = is_array( $old_meta ) ? $old_meta : [];
		$original   = (string) wp_get_original_image_path( $id );
		$original   = '' !== $original && is_file( $original ) ? $original : $old_main;
		if ( '' === $original || ! is_file( $original ) || ! is_readable( $original ) ) {
			return new WP_Error( 'slashphoto_reprocess_missing', __( 'The original file is missing.', 'slashphoto' ) );
		}
		$hash = (string) sha1_file( $original );
		$ext  = strtolower( (string) pathinfo( $original, PATHINFO_EXTENSION ) );
		$web  = in_array( $ext, Importer::WEB_TYPES, true );
		if ( ! $web && ! Importer::snapotter_active() ) {
			return new WP_Error( 'slashphoto_needs_snapotter', __( 'RAW, HEIC, TIFF and PSD originals need a connected SnapOtter server.', 'slashphoto' ) );
		}

		$dirs = Uploads::for_gallery( $gallery_id, static fn() => wp_upload_dir() );
		if ( ! empty( $dirs['error'] ) || ! wp_mkdir_p( $dirs['path'] ) ) {
			return new WP_Error( 'slashphoto_reprocess_folder', __( 'The gallery folder cannot be written.', 'slashphoto' ) );
		}
		$old_files = self::files( $id, $old_main, $old_meta, $original );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		wp_raise_memory_limit( 'image' );

		$base = sanitize_file_name( Proofing::file_label( $id ) );
		$base = '' !== $base ? $base : 'image';
		// Every new file starts with this random stem, which also lets a
		// failed attempt be cleaned up completely.
		$stem = $dirs['path'] . '/' . $base . '-' . bin2hex( random_bytes( 6 ) );
		$made = self::generate( $id, $gallery_id, $original, $ext, $web, $stem );

		$new_meta     = wp_get_attachment_metadata( $id );
		$new_main     = (string) get_attached_file( $id );
		$new_original = (string) wp_get_original_image_path( $id );
		$new_original = '' !== $new_original && is_file( $new_original ) ? $new_original : $new_main;
		$ok           = ! is_wp_error( $made ) && is_array( $new_meta ) && ! empty( $new_meta['file'] ) && is_file( $new_main )
			&& $new_original !== $original && is_file( $new_original ) && hash_equals( $hash, (string) sha1_file( $new_original ) );
		$new_files    = is_array( $new_meta ) ? self::files( $id, $new_main, $new_meta, $new_original ) : [ $new_main ];

		if ( ! $ok ) {
			// Put the image back exactly as it was and remove what was made.
			update_attached_file( $id, $old_main );
			wp_update_attachment_metadata( $id, $old_meta );
			self::delete_stem( $stem, $old_files );
			return is_wp_error( $made ) ? $made : new WP_Error( 'slashphoto_reprocess_failed', __( 'New copies could not be made; the photo was left unchanged.', 'slashphoto' ) );
		}

		// Sized download copies belong to the old original.
		delete_post_meta( $id, DownloadSizes::META );
		self::delete( array_diff( $old_files, $new_files ) );
		self::remove_empty_folder( dirname( $original ) );
		clean_attachment_cache( $id );

		/**
		 * Fires after an image got new copies from its original.
		 *
		 * @param int $id Attachment ID.
		 */
		do_action( 'slashphoto_image_reprocessed', $id );
		return true;
	}

	/**
	 * Generates the new files: SnapOtter for formats WordPress cannot read
	 * and as configured, otherwise WordPress itself.
	 *
	 * @param int    $id         Attachment.
	 * @param int    $gallery_id Gallery.
	 * @param string $original   Original file (left in place).
	 * @param string $ext        Its lower-case extension.
	 * @param bool   $web        Whether WordPress can read it.
	 * @param string $stem       New path without extension.
	 * @return true|WP_Error
	 */
	private static function generate( int $id, int $gallery_id, string $original, string $ext, bool $web, string $stem ) {
		// SnapOtter writes next to its input, so it works on a temporary copy.
		$work = untrailingslashit( get_temp_dir() ) . '/slashphoto-reprocess-' . bin2hex( random_bytes( 8 ) ) . '.' . $ext;
		if ( ! copy( $original, $work ) ) {
			return new WP_Error( 'slashphoto_reprocess_copy', __( 'The original could not be read.', 'slashphoto' ) );
		}
		try {
			if ( Importer::use_snapotter( $work, $ext ) ) {
				$display = Importer::snapotter_copy( $work );
				if ( ! is_wp_error( $display ) ) {
					$main = $stem . '.jpg';
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- move the finished copy into place.
					if ( ! rename( $display, $main ) ) {
						wp_delete_file( $display );
						return new WP_Error( 'slashphoto_reprocess_folder', __( 'The gallery folder cannot be written.', 'slashphoto' ) );
					}
					update_attached_file( $id, $main );
					$meta = DisplayCopy::with_prepared(
						static fn() => Importer::with_original(
							$work,
							$ext,
							static fn() => Uploads::for_gallery( $gallery_id, static fn() => wp_generate_attachment_metadata( $id, $main ) )
						)
					);
					wp_update_attachment_metadata( $id, $meta );
					update_post_meta( $id, Importer::META_MADE_BY, 'snapotter' );
					return true;
				}
				if ( ! $web ) {
					return $display;
				}
			}//end if

			$main = $stem . '.' . $ext;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- the temporary copy becomes the new upload.
			if ( ! rename( $work, $main ) ) {
				return new WP_Error( 'slashphoto_reprocess_folder', __( 'The gallery folder cannot be written.', 'slashphoto' ) );
			}
			update_attached_file( $id, $main );
			$meta = Uploads::for_gallery( $gallery_id, static fn() => wp_generate_attachment_metadata( $id, $main ) );
			wp_update_attachment_metadata( $id, $meta );
			delete_post_meta( $id, Importer::META_MADE_BY );
			return true;
		} finally {
			if ( is_file( $work ) ) {
				wp_delete_file( $work );
			}
		}//end try
	}

	/**
	 * Every file that belongs to an image version.
	 *
	 * @param int    $id       Attachment.
	 * @param string $main     Attached file.
	 * @param array  $meta     Attachment metadata.
	 * @param string $original Original file.
	 * @return string[] Absolute paths.
	 */
	private static function files( int $id, string $main, array $meta, string $original ): array {
		$dir   = dirname( $main );
		$files = [ $main, $original ];
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . '/' . wp_basename( (string) $size['file'] );
			}
		}
		$copies = get_post_meta( $id, DownloadSizes::META, true );
		foreach ( is_array( $copies ) ? $copies : [] as $name ) {
			if ( is_string( $name ) && wp_basename( $name ) === $name ) {
				$files[] = dirname( $original ) . '/' . $name;
			}
		}
		return array_values( array_unique( array_filter( $files ) ) );
	}

	/**
	 * Deletes files inside the uploads folder.
	 *
	 * @param string[] $files Absolute paths.
	 */
	private static function delete( array $files ): void {
		$base = wp_normalize_path( trailingslashit( (string) wp_get_upload_dir()['basedir'] ) );
		foreach ( $files as $file ) {
			if ( str_starts_with( wp_normalize_path( $file ), $base ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Deletes every file a failed attempt made: they all start with the
	 * attempt's random stem, in the gallery folder or a new originals folder.
	 *
	 * @param string   $stem Path prefix of the attempt.
	 * @param string[] $keep Files of the image as it was.
	 */
	private static function delete_stem( string $stem, array $keep ): void {
		$dir   = dirname( $stem );
		$name  = wp_basename( $stem );
		$files = array_merge( (array) glob( $dir . '/' . $name . '*' ), (array) glob( $dir . '/o*/' . $name . '*' ) );
		self::delete( array_diff( array_filter( $files, 'is_string' ), $keep ) );
		foreach ( (array) glob( $dir . '/o*', GLOB_ONLYDIR ) as $folder ) {
			if ( is_string( $folder ) ) {
				self::remove_empty_folder( $folder );
			}
		}
	}

	/**
	 * Removes an originals folder that only holds its protection files.
	 *
	 * @param string $folder Folder of the old original.
	 */
	private static function remove_empty_folder( string $folder ): void {
		if ( ! preg_match( '/^o[a-f0-9]{24}$/', wp_basename( $folder ) ) || ! is_dir( $folder ) ) {
			return;
		}
		$left = array_diff( (array) scandir( $folder ), [ '.', '..', 'index.php', '.htaccess' ] );
		if ( $left ) {
			return;
		}
		wp_delete_file( $folder . '/index.php' );
		wp_delete_file( $folder . '/.htaccess' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty folder we created.
		rmdir( $folder );
	}

	/**
	 * Makes new display copies of gallery photos from their originals, with
	 * the current watermark, display size, quality and SnapOtter settings.
	 *
	 * ## OPTIONS
	 *
	 * [<gallery>...]
	 * : Gallery IDs.
	 *
	 * [--all]
	 * : Every gallery.
	 *
	 * ## EXAMPLES
	 *
	 *     wp slashphoto reprocess 42
	 *     wp slashphoto reprocess --all
	 *
	 * @param array $args       Gallery IDs.
	 * @param array $assoc_args Options.
	 */
	public static function cli( array $args, array $assoc_args ): void {
		$ids = array_map( 'absint', $args );
		if ( ! empty( $assoc_args['all'] ) ) {
			$ids = get_posts(
				[
					'post_type'        => PostType::POST_TYPE,
					'post_status'      => 'any',
					'numberposts'      => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				]
			);
		}
		if ( ! $ids ) {
			\WP_CLI::error( 'Give gallery IDs or --all.' );
		}
		$failures = 0;
		foreach ( $ids as $gallery_id ) {
			if ( PostType::POST_TYPE !== get_post_type( $gallery_id ) ) {
				\WP_CLI::warning( "{$gallery_id} is not a gallery." );
				continue;
			}
			$after = 0;
			do {
				$result = self::batch( (int) $gallery_id, $after, 60.0 );
				foreach ( $result['failed'] as $failure ) {
					++$failures;
					\WP_CLI::warning( "Gallery {$gallery_id}, image {$failure['id']}: {$failure['message']}" );
				}
				$after = $result['next'];
			} while ( $result['remaining'] > 0 && ( $result['done'] || $result['failed'] ) );
			\WP_CLI::log( sprintf( 'Gallery %d: %d photos.', $gallery_id, $result['total'] ) );
		}
		if ( $failures ) {
			\WP_CLI::error( "{$failures} photos could not be processed; they were left unchanged." );
		}
		\WP_CLI::success( 'Done.' );
	}
}
