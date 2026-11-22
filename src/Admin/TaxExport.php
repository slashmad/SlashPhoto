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

	/** Renders the date-range form. */
	public static function form(): void {
		[ $from, $to ] = self::previous_quarter();
		?>
		<form class="slashphoto-tax-export" method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION, '_wpnonce', false ); ?>
			<label for="slashphoto-tax-from"><?php esc_html_e( 'VAT report from', 'slashphoto' ); ?></label>
			<input type="date" id="slashphoto-tax-from" name="from" value="<?php echo esc_attr( $from ); ?>" required>
			<label for="slashphoto-tax-to"><?php esc_html_e( 'to', 'slashphoto' ); ?></label>
			<input type="date" id="slashphoto-tax-to" name="to" value="<?php echo esc_attr( $to ); ?>" required>
			<button type="submit" class="button"><?php esc_html_e( 'Download VAT report (CSV)', 'slashphoto' ); ?></button>
		</form>
		<?php
	}

	/** Streams the authorized CSV download. */
	public function download(): void {
		if ( ! current_user_can( OrdersPage::capability() ) ) {
			wp_die( esc_html__( 'You cannot export orders.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );
		$date = static function ( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by check_admin_referer() above.
			$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ? $value : '';
		};
		$from = $date( 'from' );
		$to   = $date( 'to' );
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
		header( 'Content-Disposition: attachment; filename="slashphoto-vat-' . $from . '-' . $to . '.csv"' );
		self::write( $output, $from, $to );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- HTTP response stream.
		fclose( $output );
		exit;
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
