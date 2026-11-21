<?php
/**
 * Print order export for production.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Shop\Orders;

defined( 'ABSPATH' ) || exit;

/** Provides the production CSV to users who can manage orders. */
final class PrintExport {

	private const ACTION = 'slashphoto_print_csv';

	/** Registers the authenticated export. */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'download' ] );
	}

	/**
	 * Builds a download URL for the current order filters.
	 *
	 * @param string $status Order status.
	 * @param string $search Order search.
	 */
	public static function url( string $status = '', string $search = '' ): string {
		$args = [ 'action' => self::ACTION ];
		if ( '' !== $status ) {
			$args['status'] = $status;
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/** Streams the authorized CSV download. */
	public function download(): void {
		if ( ! current_user_can( OrdersPage::capability() ) ) {
			wp_die( esc_html__( 'You cannot export print orders.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- HTTP response stream.
		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			wp_die( esc_html__( 'The export could not be opened.', 'slashphoto' ), '', [ 'response' => 500 ] );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="slashphoto-prints-' . gmdate( 'Y-m-d' ) . '.csv"' );
		self::write( $output, $status, $search );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- HTTP response stream.
		fclose( $output );
		exit;
	}

	/**
	 * Writes print details only. Order contacts, addresses and links are excluded.
	 *
	 * @param resource $output Writable CSV stream.
	 * @param string   $status Order status filter.
	 * @param string   $search Order search.
	 */
	public static function write( $output, string $status = '', string $search = '' ): void {
		fputcsv( $output, [ 'order_id', 'status', 'gallery_id', 'image_id', 'file_label', 'description', 'product', 'paper', 'frame', 'quantity' ], ',', '"', '' );
		foreach ( Orders::print_rows( $status, $search ) as $row ) {
			unset( $row['id'] );
			fputcsv( $output, array_map( [ Csv::class, 'safe' ], $row ), ',', '"', '' );
		}
	}
}
