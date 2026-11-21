<?php
/**
 * An accessible daily bar chart for admin reporting.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

defined( 'ABSPATH' ) || exit;

/** Shows all calendar days and exposes exact values in a table. */
final class ActivityChart {

	/**
	 * Renders a time series with an accessible data table.
	 *
	 * @param array  $values Day to view count.
	 * @param string $label  Chart title.
	 */
	public static function daily( array $values, string $label ): void {
		$max = $values ? max( $values ) : 0;
		if ( ! $max ) {
			echo '<p>' . esc_html__( 'No views counted in this period.', 'slashphoto' ) . '</p>';
			return;
		}
		?>
		<div class="slashphoto-chart" aria-hidden="true">
			<div class="slashphoto-chart__bars">
				<?php foreach ( $values as $day => $count ) : ?>
					<span title="<?php echo esc_attr( self::date( $day ) . ' · ' . number_format_i18n( $count ) ); ?>" style="--bar:<?php echo esc_attr( (string) ( $count > 0 ? max( 1, round( 100 * $count / $max ) ) : 0 ) ); ?>%"></span>
				<?php endforeach; ?>
			</div>
			<div class="slashphoto-chart__dates">
				<span><?php echo esc_html( self::date( array_key_first( $values ) ) ); ?></span>
				<span><?php echo esc_html( self::date( array_key_last( $values ) ) ); ?></span>
			</div>
		</div>
		<details class="slashphoto-chart__values">
			<summary><?php esc_html_e( 'Daily totals', 'slashphoto' ); ?></summary>
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php echo esc_html( $label ); ?></caption>
				<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'slashphoto' ); ?></th><th scope="col"><?php esc_html_e( 'Views', 'slashphoto' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $values as $day => $count ) : ?>
						<tr><th scope="row"><?php echo esc_html( self::date( $day ) ); ?></th><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	/**
	 * Formats a daily bucket in the site's time zone.
	 *
	 * @param string $day Date in Y-m-d format.
	 */
	private static function date( string $day ): string {
		return wp_date( get_option( 'date_format' ), ( new \DateTimeImmutable( $day, wp_timezone() ) )->getTimestamp() );
	}
}
