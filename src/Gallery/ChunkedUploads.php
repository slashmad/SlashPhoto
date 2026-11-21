<?php
/**
 * Chunked uploads of full-size originals.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use SlashPhoto\Media\Originals;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Receives large files in pieces, so the size of an original is not limited
 * by PHP's upload_max_filesize or post_max_size.
 *
 * 1. POST /slashphoto/v1/galleries/<id>/uploads {name, size}
 *    → {upload, chunkSize}
 * 2. POST /slashphoto/v1/uploads/<upload>?offset=<bytes> with the raw chunk
 *    as the body, in order
 * 3. POST /slashphoto/v1/uploads/<upload>/complete → the new attachment
 *
 * Parts are kept in uploads/slashphoto/tmp/ (no web access where .htaccess
 * is read, random names elsewhere) and removed after a day if an upload is
 * abandoned. Only the user who started an upload can continue it, and only
 * while they may still edit the gallery.
 */
final class ChunkedUploads {

	public const NAMESPACE  = 'slashphoto/v1';
	public const CRON       = 'slashphoto_cleanup_uploads';
	public const MAX_CHUNK  = 8388608;
	public const MIN_CHUNK  = 262144;
	public const STALE_SECS = DAY_IN_SECONDS;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( self::CRON, [ self::class, 'cleanup' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
	}

	/**
	 * Schedules the daily cleanup of abandoned parts.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$upload = '/uploads/(?P<upload>[a-f0-9]{32})';

		register_rest_route(
			self::NAMESPACE,
			'/galleries/(?P<id>\d+)/uploads',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'start' ],
				'permission_callback' => [ $this, 'can_upload_to_gallery' ],
				'args'                => [
					'name' => [
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => 255,
					],
					'size' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			$upload,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'chunk' ],
					'permission_callback' => [ $this, 'owns_upload' ],
					'args'                => [
						'offset' => [
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 0,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'abort' ],
					'permission_callback' => [ $this, 'owns_upload' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			$upload . '/complete',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'complete' ],
				'permission_callback' => [ $this, 'owns_upload' ],
			]
		);
	}

	/**
	 * Users who can upload files and edit the gallery.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function can_upload_to_gallery( WP_REST_Request $request ): bool {
		$id = (int) $request['id'];
		return PostType::POST_TYPE === get_post_type( $id ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Only the user who started the upload, while they may edit the gallery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function owns_upload( WP_REST_Request $request ) {
		$meta = self::meta( (string) $request['upload'] );
		if ( ! $meta || get_current_user_id() !== (int) $meta['user'] || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', (int) $meta['gallery'] ) ) {
			return new WP_Error( 'slashphoto_upload_not_found', __( 'Upload not found.', 'slashphoto' ), [ 'status' => 404 ] );
		}
		return true;
	}

	/**
	 * Starts an upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		$name = sanitize_file_name( wp_basename( (string) $request['name'] ) );
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$size = (int) $request['size'];

		if ( ! in_array( $ext, Importer::allowed_extensions(), true ) ) {
			return in_array( $ext, Importer::SNAPOTTER_TYPES, true )
				? new WP_Error( 'slashphoto_needs_snapotter', __( 'RAW, HEIC, TIFF and PSD files need a SnapOtter server. Connect one under SlashPhoto → Settings, or upload JPEG files.', 'slashphoto' ), [ 'status' => 415 ] )
				: new WP_Error( 'slashphoto_file_type', __( 'This file type cannot be added to a gallery.', 'slashphoto' ), [ 'status' => 415 ] );
		}

		/**
		 * Filters the largest original accepted, in bytes. 0 means no limit
		 * other than free disk space.
		 *
		 * @param int $max_bytes Maximum size.
		 */
		$max = (int) apply_filters( 'slashphoto_max_original_size', 0 );
		if ( $max && $size > $max ) {
			return new WP_Error( 'slashphoto_upload_too_large', __( 'The file is larger than this site accepts.', 'slashphoto' ), [ 'status' => 413 ] );
		}

		$dir = self::dir();
		if ( ! $dir ) {
			return new WP_Error( 'slashphoto_upload_dir', __( 'The upload folder could not be created.', 'slashphoto' ), [ 'status' => 500 ] );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled on shared hosting.
		// Room for the original, its display copy and sizes.
		if ( false !== $free && $free < $size * 2 + 50 * MB_IN_BYTES ) {
			return new WP_Error( 'slashphoto_upload_space', __( 'There is not enough free disk space for this file.', 'slashphoto' ), [ 'status' => 507 ] );
		}

		$id = bin2hex( random_bytes( 16 ) );
		self::write_meta(
			$id,
			[
				'user'    => get_current_user_id(),
				'gallery' => (int) $request['id'],
				'name'    => $name,
				'size'    => $size,
				'created' => time(),
			]
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- private temporary file.
		touch( $dir . '/' . $id . '.part' );

		return new WP_REST_Response(
			[
				'upload'    => $id,
				'chunkSize' => self::chunk_size(),
			],
			201
		);
	}

	/**
	 * Appends one chunk.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chunk( WP_REST_Request $request ) {
		$id     = (string) $request['upload'];
		$meta   = (array) self::meta( $id );
		$part   = self::dir() . '/' . $id . '.part';
		$offset = (int) $request['offset'];
		$body   = $request->get_body();
		clearstatcache( true, $part );
		$current = is_file( $part ) ? (int) filesize( $part ) : 0;

		if ( $offset !== $current ) {
			return new WP_Error(
				'slashphoto_upload_offset',
				__( 'The upload is out of step. Resume from the size given.', 'slashphoto' ),
				[
					'status'   => 409,
					'received' => $current,
				]
			);
		}
		$length = strlen( $body );
		if ( 0 === $length || $length > self::MAX_CHUNK || $current + $length > (int) $meta['size'] ) {
			return new WP_Error( 'slashphoto_upload_chunk', __( 'Invalid upload chunk.', 'slashphoto' ), [ 'status' => 400 ] );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- append to a private temporary file.
		$handle = fopen( $part, 'ab' );
		$ok     = $handle && fwrite( $handle, $body ) === $length;
		if ( $handle ) {
			fclose( $handle );
		}
		// phpcs:enable
		if ( ! $ok ) {
			return new WP_Error( 'slashphoto_upload_write', __( 'The chunk could not be saved.', 'slashphoto' ), [ 'status' => 500 ] );
		}
		return new WP_REST_Response( [ 'received' => $current + $length ] );
	}

	/**
	 * Finishes an upload and imports the file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete( WP_REST_Request $request ) {
		$id   = (string) $request['upload'];
		$meta = (array) self::meta( $id );
		$part = self::dir() . '/' . $id . '.part';
		clearstatcache( true, $part );
		if ( ! is_file( $part ) || (int) filesize( $part ) !== (int) $meta['size'] ) {
			return new WP_Error( 'slashphoto_upload_incomplete', __( 'The upload is not complete.', 'slashphoto' ), [ 'status' => 409 ] );
		}

		// The meta file goes first, so a second "complete" cannot import twice.
		wp_delete_file( self::dir() . '/' . $id . '.json' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not allowed on every host.
		}
		$attachment = Importer::import( $part, (string) $meta['name'], (int) $meta['gallery'] );
		if ( is_file( $part ) ) {
			wp_delete_file( $part );
		}
		if ( is_wp_error( $attachment ) ) {
			$attachment->add_data( [ 'status' => 422 ] );
			return $attachment;
		}

		$thumb = wp_get_attachment_image_src( $attachment, 'thumbnail' );
		return new WP_REST_Response(
			[
				'id'        => $attachment,
				'title'     => get_the_title( $attachment ),
				'thumbnail' => $thumb ? $thumb[0] : '',
				'preview'   => (string) wp_get_attachment_image_url( $attachment, 'medium' ),
			],
			201
		);
	}

	/**
	 * Cancels an upload.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function abort( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request['upload'];
		wp_delete_file( self::dir() . '/' . $id . '.part' );
		wp_delete_file( self::dir() . '/' . $id . '.json' );
		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	/**
	 * Deletes parts of uploads older than a day.
	 */
	public static function cleanup(): void {
		$dir = self::dir();
		if ( ! $dir ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( preg_match( '/\/[a-f0-9]{32}(\.part|\.json|\.part-display\.jpg)$/', (string) $file ) && filemtime( (string) $file ) < time() - self::STALE_SECS ) {
				wp_delete_file( (string) $file );
			}
		}
	}

	/**
	 * Chunk size that fits under the server's request size limit.
	 */
	public static function chunk_size(): int {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$size  = $limit > 0 ? $limit - 65536 : self::MAX_CHUNK;
		return max( self::MIN_CHUNK, min( self::MAX_CHUNK, $size ) );
	}

	/**
	 * The private folder for parts, or '' when it cannot be created.
	 */
	public static function dir(): string {
		$uploads = wp_get_upload_dir();
		$dir     = $uploads['basedir'] . '/' . Uploads::BASE_DIR . '/tmp';
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
			Originals::protect( $dir );
		}
		return $dir;
	}

	/**
	 * Reads an upload's details.
	 *
	 * @param string $id Upload ID.
	 * @return array{user: int, gallery: int, name: string, size: int, created: int}|null
	 */
	private static function meta( string $id ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}
		$file = self::dir() . '/' . $id . '.json';
		$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return is_array( $data ) && isset( $data['user'], $data['gallery'], $data['name'], $data['size'] ) ? $data : null;
	}

	/**
	 * Writes an upload's details.
	 *
	 * @param string $id   Upload ID.
	 * @param array  $data Details.
	 */
	private static function write_meta( string $id, array $data ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private temporary file.
		file_put_contents( self::dir() . '/' . $id . '.json', (string) wp_json_encode( $data ) );
	}
}
