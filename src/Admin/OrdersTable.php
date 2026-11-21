<?php
/**
 * Orders list table.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Settings;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The list on SlashPhoto → Orders.
 */
final class OrdersTable extends \WP_List_Table {

	public const PER_PAGE = 30;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return [
			'order'    => __( 'Order', 'slashphoto' ),
			'date'     => __( 'Date', 'slashphoto' ),
			'customer' => __( 'Customer', 'slashphoto' ),
			'status'   => __( 'Status', 'slashphoto' ),
			'total'    => __( 'Total', 'slashphoto' ),
		];
	}

	/**
	 * Loads the rows.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters only.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$result                = Orders::query( $status, $search, self::PER_PAGE, $this->get_pagenum() );
		$this->items           = $result['rows'];
		$this->_column_headers = [ $this->get_columns(), [], [] ];
		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			]
		);
	}

	/**
	 * Status filter links.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$counts  = Orders::counts();
		$base    = Settings::admin_url( '', OrdersPage::SLUG );
		$views   = [
			'all' => sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'view', 'list', $base ) ), '' === $current ? ' class="current" aria-current="page"' : '', esc_html__( 'All', 'slashphoto' ), array_sum( $counts ) ),
		];
		foreach ( Orders::labels() as $status => $label ) {
			if ( ! $counts[ $status ] ) {
				continue;
			}
			$views[ $status ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( add_query_arg( 'status', $status, $base ) ), $current === $status ? ' class="current" aria-current="page"' : '', esc_html( $label ), $counts[ $status ] );
		}
		return $views;
	}

	/**
	 * Message for an empty list.
	 */
	public function no_items() {
		esc_html_e( 'No orders yet.', 'slashphoto' );
	}

	/**
	 * Default column output.
	 *
	 * @param array  $item        Order.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'order':
				/* translators: %d: order number */
				return sprintf( '<strong><a class="row-title" href="%s">%s</a></strong>', esc_url( add_query_arg( 'order', $item['id'], Settings::admin_url( '', OrdersPage::SLUG ) ) ), esc_html( sprintf( __( 'Order %d', 'slashphoto' ), $item['id'] ) ) );
			case 'date':
				return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $item['created_at'] . ' UTC' ) ) );
			case 'customer':
				return esc_html( (string) $item['name'] ) . '<br><span class="description">' . esc_html( (string) $item['email'] ) . '</span>';
			case 'status':
				return esc_html( Orders::labels()[ $item['status'] ] ?? $item['status'] );
			case 'total':
				return esc_html( Money::format( (int) $item['total'], (string) $item['currency'] ) );
		}
		return '';
	}
}
