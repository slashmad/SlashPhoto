<?php
/**
 * Visitor log and statistics screens.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Frontend\Assets;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use SlashPhoto\Visitors\Stats;
use SlashPhoto\Visitors\VisitorLog;

defined( 'ABSPATH' ) || exit;

/**
 * SlashPhoto → Visitors: email addresses given at email gates and terms
 * acceptances, filterable by gallery and type, with CSV export.
 * SlashPhoto → Statistics: anonymous view counts per gallery and photo.
 */
final class VisitorsPage {

	public const SLUG       = 'slashphoto-visitors';
	public const STATS_SLUG = 'slashphoto-stats';
	public const EXPORT     = 'slashphoto_visitors_csv';
	public const PER_PAGE   = 50;

	/**
	 * Capability for both screens.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability for the Visitors and Statistics screens.
		 *
		 * @param string $capability Capability.
		 */
		return (string) apply_filters( 'slashphoto_visitors_capability', 'edit_others_posts' );
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_' . self::EXPORT, [ $this, 'export' ] );
	}

	/**
	 * Adds the pages.
	 */
	public function menu(): void {
		$parent = 'edit.php?post_type=' . PostType::POST_TYPE;
		add_submenu_page( $parent, __( 'Visitors', 'slashphoto' ), __( 'Visitors', 'slashphoto' ), self::capability(), self::SLUG, [ $this, 'render_log' ] );
		add_submenu_page( $parent, __( 'Statistics', 'slashphoto' ), __( 'Statistics', 'slashphoto' ), self::capability(), self::STATS_SLUG, [ $this, 'render_stats' ] );
	}

	/**
	 * Loads the card layout for both reports.
	 *
	 * @param string $hook Current admin screen.
	 */
	public function enqueue( string $hook ): void {
		if ( str_ends_with( $hook, '_page_' . self::SLUG ) || str_ends_with( $hook, '_page_' . self::STATS_SLUG ) ) {
			$file = 'assets/css/admin-galleries.css';
			wp_enqueue_style( 'slashphoto-gallery-overview', SLASHPHOTO_URL . $file, [ 'dashicons' ], Assets::version( $file ) );
		}
	}

	/**
	 * Filters from the request.
	 *
	 * @return array{type: string, gallery: int, search: string}
	 */
	private static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters only.
		return [
			'type'    => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '',
			'gallery' => isset( $_GET['gallery'] ) ? absint( $_GET['gallery'] ) : 0,
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		];
		// phpcs:enable
	}

	/**
	 * Type names.
	 *
	 * @return array<string, string>
	 */
	private static function types(): array {
		return [
			'email' => __( 'Gave email address', 'slashphoto' ),
			'terms' => __( 'Accepted terms', 'slashphoto' ),
		];
	}

	/**
	 * Renders the log.
	 */
	public function render_log(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$filters = self::filters();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination only.
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = VisitorLog::query( $filters, self::PER_PAGE, $page );
		$types  = self::types();
		$days   = (int) Settings::get( 'visitor_retention_days' );
		$base   = Settings::admin_url( '', self::SLUG );
		?>
		<div class="wrap slashphoto-gallery-overview">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Visitors', 'slashphoto' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_filter( $filters ), admin_url( 'admin-post.php?action=' . self::EXPORT ) ), self::EXPORT ) ); ?>"><?php esc_html_e( 'Download CSV', 'slashphoto' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->visitor_activity(); ?>
			<h2><?php esc_html_e( 'Visitor log', 'slashphoto' ); ?></h2>
			<p>
				<?php
				esc_html_e( 'Email addresses visitors gave to open galleries with an email request, and acceptances of gallery terms. No IP addresses or browser details are stored.', 'slashphoto' );
				echo ' ';
				echo esc_html(
					$days
						/* translators: %d: number of days */
						? sprintf( __( 'Entries are deleted after %d days (Settings → Visitors).', 'slashphoto' ), $days )
						: __( 'Entries are kept until you delete them (Settings → Visitors).', 'slashphoto' )
				);
				?>
			</p>
			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( PostType::POST_TYPE ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label class="screen-reader-text" for="slashphoto-visitor-type"><?php esc_html_e( 'Type', 'slashphoto' ); ?></label>
				<select id="slashphoto-visitor-type" name="type">
					<option value=""><?php esc_html_e( 'All entries', 'slashphoto' ); ?></option>
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $filters['gallery'] ) : ?>
					<input type="hidden" name="gallery" value="<?php echo esc_attr( (string) $filters['gallery'] ); ?>">
					<?php /* translators: %s: gallery title */ ?>
					<span><?php echo esc_html( sprintf( __( 'Gallery: %s', 'slashphoto' ), get_the_title( $filters['gallery'] ) ) ); ?> <a href="<?php echo esc_url( remove_query_arg( 'gallery', add_query_arg( array_filter( $filters ), $base ) ) ); ?>"><?php esc_html_e( 'All galleries', 'slashphoto' ); ?></a></span>
				<?php endif; ?>
				<label class="screen-reader-text" for="slashphoto-visitor-search"><?php esc_html_e( 'Search email addresses', 'slashphoto' ); ?></label>
				<input type="search" id="slashphoto-visitor-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Email address', 'slashphoto' ); ?>">
				<?php submit_button( __( 'Filter', 'slashphoto' ), '', '', false ); ?>
			</form>
			<table id="slashphoto-visitor-log" class="widefat striped" style="margin-top:12px">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'slashphoto' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Entry', 'slashphoto' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Gallery', 'slashphoto' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'slashphoto' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Account', 'slashphoto' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $result['rows'] ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No entries.', 'slashphoto' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<?php $user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null; ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
							<td>
								<?php echo esc_html( $types[ $row['type'] ] ?? $row['type'] ); ?>
								<?php if ( 'terms' === $row['type'] && '' !== $row['detail'] ) : ?>
									<br><span class="description">
										<?php
										/* translators: %s: short fingerprint of the terms wording */
										echo esc_html( sprintf( __( 'Wording %s', 'slashphoto' ), $row['detail'] ) );
										?>
									</span>
								<?php endif; ?>
							</td>
							<td><a href="<?php echo esc_url( add_query_arg( 'gallery', (int) $row['gallery_id'], $base ) ); ?>"><?php echo esc_html( get_the_title( (int) $row['gallery_id'] ) ); ?></a></td>
							<td><?php echo esc_html( (string) $row['email'] ); ?></td>
							<td><?php echo $user ? esc_html( $user->display_name . ' (' . $user->user_login . ')' ) : '–'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $result['total'] / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
					(string) paginate_links(
						[
							'base'    => add_query_arg( 'paged', '%#%', add_query_arg( array_filter( $filters ), $base ) ),
							'format'  => '',
							'current' => $page,
							'total'   => $pages,
						]
					)
				) . '</div></div>';
			}
			?>
			<p class="description"><?php esc_html_e( 'The wording fingerprint changes when you change a gallery’s terms, so you can tell which version someone accepted. Visitors can ask for their entries through Tools → Export Personal Data and Erase Personal Data.', 'slashphoto' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Sends the log as CSV.
	 */
	public function export(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to see visitors.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::EXPORT );
		$rows  = VisitorLog::query( self::filters(), 0 )['rows'];
		$types = self::types();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="slashphoto-visitors-' . gmdate( 'Y-m-d' ) . '.csv"' );
		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming CSV to the browser.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'date_utc', 'entry', 'gallery_id', 'gallery', 'email', 'account', 'detail' ] );
		foreach ( $rows as $row ) {
			$user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			fputcsv(
				$out,
				array_map(
					[ self::class, 'csv_safe' ],
					[ $row['created_at'], $types[ $row['type'] ] ?? $row['type'], $row['gallery_id'], get_the_title( (int) $row['gallery_id'] ), $row['email'], $user ? $user->user_login : '', $row['detail'] ]
				)
			);
		}
		fclose( $out );
		// phpcs:enable
		exit;
	}

	/**
	 * Stops spreadsheet programs from running values as formulas.
	 *
	 * @param mixed $value Cell.
	 */
	public static function csv_safe( $value ): string {
		return Csv::safe( $value );
	}

	/** Reports the existing visitor log and aggregate page-view data. */
	private function visitor_activity(): void {
		$start  = current_datetime()->setTime( 0, 0 )->modify( '-29 days' );
		$events = VisitorLog::activity( gmdate( 'Y-m-d H:i:s', $start->getTimestamp() ) );
		$guests = $events[0] ?? 0;
		unset( $events[0] );
		$top = array_slice( $events, 0, 10, true );
		$max = $top ? max( $top ) : 1;
		?>
		<h2><?php esc_html_e( 'Activity in the last 30 days', 'slashphoto' ); ?></h2>
		<div class="slashphoto-gallery-overview__groups slashphoto-report">
			<section class="slashphoto-gallery-section" data-visibility="restricted">
				<header class="slashphoto-gallery-section__header"><h2><?php esc_html_e( 'Activity by account', 'slashphoto' ); ?></h2></header>
				<div class="slashphoto-report__body">
					<p><?php esc_html_e( 'Email gate and terms events, up to 10 accounts with the most activity.', 'slashphoto' ); ?></p>
					<?php if ( ! $top ) : ?>
						<p><?php esc_html_e( 'No account activity in this period.', 'slashphoto' ); ?></p>
					<?php else : ?>
						<ul class="slashphoto-activity-accounts">
							<?php foreach ( $top as $id => $count ) : ?>
								<?php $account = get_userdata( $id ); ?>
								<li>
									<span><?php echo esc_html( $account ? $account->display_name : __( 'Deleted account', 'slashphoto' ) ); ?></span>
									<span><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
									<meter min="0" max="<?php echo esc_attr( (string) $max ); ?>" value="<?php echo esc_attr( (string) $count ); ?>" aria-hidden="true"></meter>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p>
						<?php
						/* translators: %s: event count */
						echo esc_html( sprintf( __( 'Events without a signed-in account: %s', 'slashphoto' ), number_format_i18n( $guests ) ) );
						?>
					</p>
				</div>
			</section>
			<section class="slashphoto-gallery-section">
				<header class="slashphoto-gallery-section__header"><h2><?php esc_html_e( 'Gallery views without visitor identifiers', 'slashphoto' ); ?></h2></header>
				<div class="slashphoto-report__body">
					<p><?php esc_html_e( 'All counted gallery views share one total. Individual viewers cannot be identified from these counts.', 'slashphoto' ); ?></p>
					<?php ActivityChart::daily( array_column( self::daily( $start ), 'gallery', 'day' ), __( 'Gallery views per day', 'slashphoto' ) ); ?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Returns a continuous date range, including days with zero views.
	 *
	 * @param \DateTimeImmutable $start First day at midnight in the site time zone.
	 */
	private static function daily( \DateTimeImmutable $start ): array {
		$recorded = Stats::daily_counts( $start->format( 'Y-m-d' ) );
		$today    = current_datetime()->setTime( 0, 0 );
		$days     = [];
		for ( $day = $start; $day <= $today; $day = $day->modify( '+1 day' ) ) {
			$key    = $day->format( 'Y-m-d' );
			$days[] = [ 'day' => $key ] + ( $recorded[ $key ] ?? [
				'gallery' => 0,
				'photo'   => 0,
			] );
		}
		return $days;
	}

	/**
	 * Renders the statistics.
	 */
	public function render_stats(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter only.
		$days   = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$days   = in_array( $days, [ 7, 30, 90, 365 ], true ) ? $days : 30;
		$start  = current_datetime()->setTime( 0, 0 )->modify( '-' . ( $days - 1 ) . ' days' );
		$since  = $start->format( 'Y-m-d' );
		$rows   = Stats::galleries( $since );
		$photos = Stats::photos( $since );
		$daily  = self::daily( $start );
		$base   = Settings::admin_url( '', self::STATS_SLUG );
		?>
		<div class="wrap slashphoto-gallery-overview">
			<h1><?php esc_html_e( 'Statistics', 'slashphoto' ); ?></h1>
			<?php if ( ! Stats::enabled() ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Counting is switched off under SlashPhoto → Settings → Visitors.', 'slashphoto' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Daily views of gallery pages and lightbox photos. Totals include views of content that has since been removed. Editors and robots are excluded.', 'slashphoto' ); ?></p>
			<nav class="slashphoto-report__periods" aria-label="<?php esc_attr_e( 'Statistics period', 'slashphoto' ); ?>">
				<?php
				foreach ( [ 7, 30, 90, 365 ] as $option ) {
					/* translators: %d: number of days */
					$label = sprintf( _n( 'Last %d day', 'Last %d days', $option, 'slashphoto' ), $option );
					echo $option === $days
						? '<strong>' . esc_html( $label ) . '</strong> '
						: '<a href="' . esc_url( add_query_arg( 'days', $option, $base ) ) . '">' . esc_html( $label ) . '</a> ';
				}
				?>
			</nav>
			<div class="slashphoto-gallery-overview__groups slashphoto-report">
				<?php
				$charts = [
					'gallery' => __( 'Gallery views per day', 'slashphoto' ),
					'photo'   => __( 'Photo views per day', 'slashphoto' ),
				];
				foreach ( $charts as $key => $label ) :
					$values = array_column( $daily, $key, 'day' );
					?>
					<section class="slashphoto-gallery-section" aria-label="<?php echo esc_attr( $label ); ?>" data-visibility="<?php echo 'photo' === $key ? 'restricted' : 'public'; ?>">
						<header class="slashphoto-gallery-section__header">
							<h2><?php echo esc_html( $label ); ?></h2>
							<span class="slashphoto-gallery-section__count"><?php echo esc_html( number_format_i18n( array_sum( $values ) ) ); ?></span>
						</header>
						<div class="slashphoto-report__body"><?php ActivityChart::daily( $values, $label ); ?></div>
					</section>
				<?php endforeach; ?>
				<section class="slashphoto-gallery-section" data-visibility="private">
					<header class="slashphoto-gallery-section__header"><h2><?php esc_html_e( 'Galleries', 'slashphoto' ); ?></h2></header>
					<div class="slashphoto-report__table">
						<table id="slashphoto-viewed-galleries" class="widefat striped">
							<thead><tr><th scope="col"><?php esc_html_e( 'Gallery', 'slashphoto' ); ?></th><th scope="col"><?php esc_html_e( 'Page views', 'slashphoto' ); ?></th><th scope="col"><?php esc_html_e( 'Photo views', 'slashphoto' ); ?></th></tr></thead>
							<tbody>
								<?php if ( ! $rows ) : ?>
									<tr><td colspan="3"><?php esc_html_e( 'No views counted in this period.', 'slashphoto' ); ?></td></tr>
								<?php endif; ?>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td><?php self::gallery_name( $row['gallery_id'] ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $row['page_views'] ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $row['photo_views'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
				<section class="slashphoto-gallery-section" data-visibility="private">
					<header class="slashphoto-gallery-section__header"><h2><?php esc_html_e( 'Most viewed photos', 'slashphoto' ); ?></h2></header>
					<div class="slashphoto-report__table">
						<table class="widefat striped">
							<thead><tr><th scope="col"><?php esc_html_e( 'Photo', 'slashphoto' ); ?></th><th scope="col"><?php esc_html_e( 'Gallery', 'slashphoto' ); ?></th><th scope="col"><?php esc_html_e( 'Views', 'slashphoto' ); ?></th></tr></thead>
							<tbody>
								<?php if ( ! $photos ) : ?>
									<tr><td colspan="3"><?php esc_html_e( 'No views counted in this period.', 'slashphoto' ); ?></td></tr>
								<?php endif; ?>
								<?php foreach ( $photos as $photo ) : ?>
									<tr>
										<td><?php echo wp_get_attachment_image( $photo['image_id'], [ 60, 60 ], false, [ 'style' => 'width:48px;height:auto;vertical-align:middle;margin-right:8px' ] ); ?><?php echo esc_html( \SlashPhoto\Proofing\Proofing::file_label( $photo['image_id'] ) ); ?></td>
										<td><?php self::gallery_name( $photo['gallery_id'] ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $photo['views'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Shows an existing gallery by name or labels its historical counts.
	 *
	 * @param int $id Gallery ID.
	 */
	private static function gallery_name( int $id ): void {
		if ( PostType::POST_TYPE !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
			esc_html_e( 'Removed gallery', 'slashphoto' );
			return;
		}
		$title = get_the_title( $id );
		$title = '' !== $title ? $title : __( '(Untitled gallery)', 'slashphoto' );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			echo esc_html( $title );
			return;
		}
		echo '<a href="' . esc_url( GalleryWorkspace::url( $id ) ) . '">' . esc_html( $title ) . '</a>';
	}
}
