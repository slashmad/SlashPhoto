<?php
/**
 * Delivery of purchased originals and their scaled copies.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Shop;

use SlashPhoto\Proofing\Proofing;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Streams originals through PHP, so they are never reachable by a public
 * address and the web server needs no special rules.
 *
 * - Customers: /?slashphoto_download=<item>&order=<order>&key=<key>, for
 *   download lines of a paid order, until the download period ends.
 * - The photographer: admin-post.php?action=slashphoto_original&image=<id>
 *   with a nonce, for anyone who may edit the gallery (to print orders).
 */
final class Downloads {

	public const QUERY  = 'slashphoto_download';
	public const ACTION = 'slashphoto_original';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'handle_customer' ], 99 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_admin' ] );
	}

	/**
	 * Customer's download link for an order line.
	 *
	 * @param array $order Order.
	 * @param array $item  Order line.
	 */
	public static function url( array $order, array $item ): string {
		return add_query_arg(
			[
				self::QUERY => $item['id'],
				'order'     => $order['id'],
				'key'       => Orders::key( $order ),
			],
			home_url( '/' )
		);
	}

	/**
	 * Photographer's link to an image's original.
	 *
	 * @param int $image_id Image.
	 */
	public static function admin_url( int $image_id ): string {
		return wp_nonce_url( add_query_arg( 'image', $image_id, admin_url( 'admin-post.php?action=' . self::ACTION ) ), self::ACTION . '_' . $image_id );
	}

	/**
	 * Finds the file for a customer download.
	 *
	 * @param int    $order_id Order.
	 * @param int    $item_id  Order line.
	 * @param string $key      Key from the link.
	 * @return array{path: string, name: string, temporary?: bool}|WP_Error
	 */
	public static function resolve( int $order_id, int $item_id, string $key ) {
		$not_found = new WP_Error( 'slashphoto_download_not_found', __( 'This download link is not valid.', 'slashphoto' ), [ 'status' => 404 ] );
		$order     = Orders::get( $order_id );
		if ( ! $order || ! Orders::verify_key( $order, $key ) ) {
			return $not_found;
		}
		$item = null;
		foreach ( Orders::items( $order_id ) as $line ) {
			if ( $line['id'] === $item_id && 'download' === $line['kind'] ) {
				$item = $line;
			}
		}
		if ( ! $item ) {
			return $not_found;
		}
		if ( ! Orders::downloads_open( $order ) ) {
			return new WP_Error( 'slashphoto_download_closed', __( 'This download is not available. It opens when the payment has arrived and closes when the download period ends.', 'slashphoto' ), [ 'status' => 403 ] );
		}
		return DownloadSizes::file( $item['image_id'], (int) ( $item['download_size'] ?? 0 ), $item['file_label'] );
	}

	/**
	 * The original of an image.
	 *
	 * @param int    $image_id Image.
	 * @param string $label    Name to give the download (without extension).
	 * @return array{path: string, name: string}|WP_Error
	 */
	public static function file( int $image_id, string $label = '' ) {
		$path = (string) wp_get_original_image_path( $image_id );
		if ( '' === $path ) {
			$path = (string) get_attached_file( $image_id );
		}
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'slashphoto_download_missing', __( 'The file is missing. Please contact us.', 'slashphoto' ), [ 'status' => 410 ] );
		}
		$label = '' !== $label ? $label : Proofing::file_label( $image_id );
		$ext   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		return [
			'path' => $path,
			'name' => sanitize_file_name( $label . ( '' !== $ext ? '.' . $ext : '' ) ),
		];
	}

	/**
	 * Serves customer downloads.
	 */
	public function handle_customer(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key is the authorisation.
		if ( ! isset( $_GET[ self::QUERY ] ) ) {
			return;
		}
		$file = self::resolve(
			isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0,
			absint( $_GET[ self::QUERY ] ),
			isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : ''
		);
		// phpcs:enable
		if ( is_wp_error( $file ) ) {
			wp_die( esc_html( $file->get_error_message() ), esc_html__( 'Download', 'slashphoto' ), [ 'response' => (int) ( $file->get_error_data()['status'] ?? 404 ) ] );
		}
		self::send( $file['path'], $file['name'], ! empty( $file['temporary'] ) );
	}

	/**
	 * Serves originals to the photographer.
	 */
	public function handle_admin(): void {
		$image_id = isset( $_GET['image'] ) ? absint( $_GET['image'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $image_id );
		$image = get_post( $image_id );
		if ( ! $image || 'attachment' !== $image->post_type || ! current_user_can( 'edit_post', $image->post_parent ? $image->post_parent : $image_id ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		$file = self::file( $image_id );
		if ( is_wp_error( $file ) ) {
			wp_die( esc_html( $file->get_error_message() ), '', [ 'response' => 410 ] );
		}
		self::send( $file['path'], $file['name'] );
	}

	/**
	 * Streams a file as an attachment and ends the request.
	 *
	 * @param string $path      File.
	 * @param string $name      Download name.
	 * @param bool   $temporary Delete the file afterwards (a scaled copy that could not be cached).
	 */
	private static function send( string $path, string $name, bool $temporary = false ): void {
		$type = wp_check_filetype( $name );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );

		/**
		 * Fires before an original is streamed. A server-specific handler can
		 * send X-Sendfile or X-Accel-Redirect here and exit.
		 *
		 * @param string $path File.
		 * @param string $name Download name.
		 */
		do_action( 'slashphoto_send_original', $path, $name );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a large private file.
		$handle = fopen( $path, 'rb' );
		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file.
				flush();
			}
			fclose( $handle );
		}
		// phpcs:enable
		if ( $temporary ) {
			wp_delete_file( $path );
		}
		exit;
	}
}
