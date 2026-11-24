<?php
/**
 * VAT report for bookkeeping and OSS returns.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * One row per paid order in a date range, with the country and rate VAT was
 * charged for, so totals per country can be summed in a spreadsheet. No
 * names, addresses or email addresses are included.
 */
final class TaxExport {

	private const ACTION = 'slashphoto_tax_csv';

	/** Registers the authenticated export. */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'download' ] );
	}

	/**
	 * First and last day of the previous calendar quarter (OSS returns are quarterly).
	 *
	 * @param int|null $now Unix time.
	 * @return array{0: string, 1: string} Y-m-d dates.
	 */
	public static function previous_quarter( ?int $now = null ): array {
		$now     = $now ?? time();
		$year    = (int) gmdate( 'Y', $now );
		$quarter = intdiv( (int) gmdate( 'n', $now ) - 1, 3 );
		if ( 0 === $quarter ) {
			--$year;
			$quarter = 4;
		}
		$first = sprintf( '%04d-%02d-01', $year, ( $quarter - 1 ) * 3 + 1 );
		return [ $first, gmdate( 'Y-m-t', (int) strtotime( $first . ' +2 months' ) ) ];
	}

	/**
	 * Renders a report's date-range form.
	 *
	 * @param bool $sales Whether to offer the sales report.
	 */
	public static function form( bool $sales = false ): void {
		[ $from, $to ] = $sales ? [ gmdate( 'Y-m-01' ), gmdate( 'Y-m-d' ) ] : self::previous_quarter();
		$report        = $sales ? 'sales' : 'vat';
		?>
		<form class="slashphoto-tax-export" method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="report" value="<?php echo esc_attr( $report ); ?>">
			<?php wp_nonce_field( self::ACTION, '_wpnonce', false ); ?>
			<label for="slashphoto-<?php echo esc_attr( $report ); ?>-from"><?php echo esc_html( $sales ? __( 'Sales report from', 'slashphoto' ) : __( 'VAT report from', 'slashphoto' ) ); ?></label>
			<input type="date" id="slashphoto-<?php echo esc_attr( $report ); ?>-from" name="from" value="<?php echo esc_attr( $from ); ?>" required>
			<label for="slashphoto-<?php echo esc_attr( $report ); ?>-to"><?php esc_html_e( 'to', 'slashphoto' ); ?></label>
			<input type="date" id="slashphoto-<?php echo esc_attr( $report ); ?>-to" name="to" value="<?php echo esc_attr( $to ); ?>" required>
			<button type="submit" class="button"><?php echo esc_html( $sales ? __( 'Download sales report (CSV)', 'slashphoto' ) : __( 'Download VAT report (CSV)', 'slashphoto' ) ); ?></button>
		</form>
		<?php
	}

	/** Streams the authorized CSV download. */
	public function download(): void {
		if ( ! current_user_can( OrdersPage::capability() ) ) {
			wp_die( esc_html__( 'You cannot export orders.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );
		$sales = isset( $_GET['report'] ) && 'sales' === sanitize_key( wp_unslash( $_GET['report'] ) );
		$date  = static function ( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by check_admin_referer() above.
			$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $value : '';
		};
		$from  = $date( 'from' );
		$to    = $date( 'to' );
		if ( '' === $from || '' === $to || $from > $to ) {
			wp_die( esc_html__( 'Choose a start date on or before the end date.', 'slashphoto' ), '', [ 'response' => 400 ] );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- HTTP response stream.
		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			wp_die( esc_html__( 'The export could not be opened.', 'slashphoto' ), '', [ 'response' => 500 ] );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="slashphoto-' . ( $sales ? 'sales-' : 'vat-' ) . $from . '-' . $to . '.csv"' );
		if ( $sales ) {
			self::write_sales( $output, $from, $to );
		} else {
			self::write( $output, $from, $to );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- HTTP response stream.
		fclose( $output );
		exit;
	}

	/**
	 * Writes order amounts in major units, one row per order placed.
	 *
	 * @param resource $output Writable CSV stream.
	 * @param string   $from   First UTC date.
	 * @param string   $to     Last UTC date.
	 */
	public static function write_sales( $output, string $from, string $to ): void {
		fputcsv( $output, [ 'order_id', 'placed_at_utc', 'paid_at_utc', 'status', 'currency', 'vat_country', 'payment_method', 'delivery_method', 'subtotal', 'discount', 'shipping', 'vat', 'total', 'prices_include_vat', 'paid_with_gift_card', 'refunded' ], ',', '"', '' );
		foreach ( Orders::sales_rows( $from, $to ) as $row ) {
			$amount = static fn( string $key ): string => Money::to_input( (int) $row[ $key ], $row['currency'] );
			fputcsv(
				$output,
				[
					$row['id'],
					$row['created_at'],
					$row['paid_at'],
					$row['status'],
					$row['currency'],
					$row['tax_country'],
					sanitize_key( $row['gateway'] ),
					sanitize_key( $row['delivery_method'] ),
					$amount( 'subtotal' ),
					$amount( 'discount' ),
					$amount( 'shipping' ),
					$amount( 'tax' ),
					$amount( 'total' ),
					(int) $row['prices_include_tax'],
					$amount( 'gift_card_amount' ),
					$amount( 'refunded' ),
				],
				',',
				'"',
				''
			);
		}//end foreach
	}

	/**
	 * Writes the report. Amounts are in major units with a dot as decimal mark.
	 *
	 * @param resource $output Writable CSV stream.
	 * @param string   $from   First day (Y-m-d).
	 * @param string   $to     Last day (Y-m-d).
	 */
	public static function write( $output, string $from, string $to ): void {
		// Gift cards sold are vouchers, not sales: they are left out of net and
		// VAT and listed on their own, like the part of an order a card paid.
		fputcsv( $output, [ 'order_id', 'paid_date_utc', 'status', 'vat_country', 'vat_rate', 'currency', 'net', 'vat', 'total', 'contains', 'gift_cards_sold', 'paid_with_gift_card' ], ',', '"', '' );
		foreach ( Orders::tax_rows( $from, $to ) as $row ) {
			$amount = static fn( int $value ): string => Money::to_input( $value, $row['currency'] );
			fputcsv(
				$output,
				[
					$row['id'],
					substr( $row['paid_at'], 0, 10 ),
					$row['status'],
					$row['tax_country'],
					rtrim( rtrim( number_format( $row['tax_rate'], 3, '.', '' ), '0' ), '.' ),
					$row['currency'],
					$amount( $row['total'] - $row['vouchers'] - $row['tax'] ),
					$amount( $row['tax'] ),
					$amount( $row['total'] ),
					str_replace( ',', '+', $row['kinds'] ),
					$amount( $row['vouchers'] ),
					$amount( $row['gift_card'] ),
				],
				',',
				'"',
				''
			);
		}//end foreach
	}
}
