<?php
/**
 * Unguessable storage paths for gallery images.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Gallery;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Stores images uploaded to a gallery under
 * uploads/slashphoto/<random gallery key>/<name>-<random suffix>.<ext>.
 *
 * WordPress normally stores files as uploads/YYYY/MM/<camera file name>, so
 * anyone can guess "DSC6752.jpg" after seeing "DSC6751.jpg". With a 96-bit
 * folder key per gallery and a 48-bit suffix per file, URLs of protected
 * galleries cannot be guessed or enumerated. Every generated size and the
 * original share the random file name.
 *
 * Folders get an index.php so servers with directory listing on do not list
 * them. This does not stop someone who has a URL from sharing it; serving
 * originals for sale through signed, expiring links is a separate feature.
 */
final class Uploads {

	public const META_KEY = '_slashphoto_key';
	public const BASE_DIR = 'slashphoto';

	/**
	 * Gallery receiving the current upload, if any.
	 *
	 * @var int
	 */
	private static int $gallery_id = 0;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'upload_dir', [ $this, 'upload_dir' ] );
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'randomize_name' ] );
		add_filter( 'wp_handle_sideload_prefilter', [ $this, 'randomize_name' ] );
		// Block editor and REST uploads: POST /wp/v2/media with a "post" parameter.
		add_filter( 'rest_request_before_callbacks', [ $this, 'rest_before' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'rest_after' ], 10, 3 );
	}

	/**
	 * Runs $callback with uploads routed to $gallery_id (for importers, seeding).
	 *
	 * @param int      $gallery_id Gallery ID.
	 * @param callable $callback   Callback.
	 * @return mixed Callback result.
	 */
	public static function for_gallery( int $gallery_id, callable $callback ) {
		$previous         = self::$gallery_id;
		self::$gallery_id = $gallery_id;
		try {
			return $callback();
		} finally {
			self::$gallery_id = $previous;
		}
	}

	/**
	 * Returns the gallery receiving the current upload, or 0.
	 */
	public static function current_gallery(): int {
		if ( self::$gallery_id ) {
			return self::is_gallery( self::$gallery_id ) ? self::$gallery_id : 0;
		}
		// Classic media uploader (wp-admin/async-upload.php, media-new.php).
		// Capability and nonce are checked by core before the file is handled;
		// the ID only chooses a folder.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		return $id && self::is_gallery( $id ) && current_user_can( 'edit_post', $id ) ? $id : 0;
	}

	/**
	 * Captures the target gallery of a REST media upload.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function rest_before( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && 'POST' === $request->get_method() && '/wp/v2/media' === $request->get_route() ) {
			$id = absint( $request->get_param( 'post' ) );
			if ( $id && self::is_gallery( $id ) && current_user_can( 'edit_post', $id ) ) {
				self::$gallery_id = $id;
			}
		}
		return $response;
	}

	/**
	 * Clears the REST upload target.
	 *
	 * @param mixed           $response Response.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function rest_after( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && '/wp/v2/media' === $request->get_route() ) {
			self::$gallery_id = 0;
		}
		return $response;
	}

	/**
	 * Routes gallery uploads into the gallery's random folder.
	 *
	 * @param array $dirs Upload directory data.
	 * @return array
	 */
	public function upload_dir( $dirs ) {
		$gallery_id = self::current_gallery();
		if ( ! $gallery_id || ! empty( $dirs['error'] ) ) {
			return $dirs;
		}

		$subdir         = '/' . self::BASE_DIR . '/' . self::key( $gallery_id );
		$dirs['subdir'] = $subdir;
		$dirs['path']   = $dirs['basedir'] . $subdir;
		$dirs['url']    = $dirs['baseurl'] . $subdir;

		self::protect_dir( $dirs['basedir'] . '/' . self::BASE_DIR );
		self::protect_dir( $dirs['path'] );
		return $dirs;
	}

	/**
	 * Appends a random suffix to the names of files uploaded to a gallery.
	 *
	 * @param array $file Upload data (name, tmp_name, ...).
	 * @return array
	 */
	public function randomize_name( $file ) {
		if ( ! self::current_gallery() || empty( $file['name'] ) ) {
			return $file;
		}
		$info         = pathinfo( (string) $file['name'] );
		$base         = sanitize_file_name( $info['filename'] ?? 'image' );
		$ext          = isset( $info['extension'] ) ? '.' . strtolower( $info['extension'] ) : '';
		$file['name'] = $base . '-' . bin2hex( random_bytes( 6 ) ) . $ext;
		return $file;
	}

	/**
	 * Returns the random folder key of a gallery, creating it on first use.
	 *
	 * @param int $gallery_id Gallery ID.
	 */
	public static function key( int $gallery_id ): string {
		$key = (string) get_post_meta( $gallery_id, self::META_KEY, true );
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $key ) ) {
			$key = bin2hex( random_bytes( 12 ) );
			update_post_meta( $gallery_id, self::META_KEY, $key );
		}
		return $key;
	}

	/**
	 * Whether $id is a gallery.
	 *
	 * @param int $id Post ID.
	 */
	private static function is_gallery( int $id ): bool {
		return PostType::POST_TYPE === get_post_type( $id );
	}

	/**
	 * Creates a directory with an empty index.php.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function protect_dir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny static file during upload.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}
}
