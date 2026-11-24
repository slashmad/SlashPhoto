<?php
/**
 * Customer account sections.
 *
 * Override: copy to yourtheme/slashphoto/account.php.
 *
 * @package SlashPhoto
 */

use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\Orders;

defined( 'ABSPATH' ) || exit;

if ( ! empty( $args['login_url'] ) ) :
	?>
	<section class="slashphoto-account slashphoto-account__section">
		<p><?php esc_html_e( 'Log in to see your galleries, selections and orders.', 'slashphoto' ); ?></p>
		<a class="wp-element-button" href="<?php echo esc_url( $args['login_url'] ); ?>"><?php esc_html_e( 'Log in', 'slashphoto' ); ?></a>
	</section>
	<?php
	return;
endif;

$slashphoto_statuses = Orders::labels();
?>
<div class="slashphoto-account">
	<div class="slashphoto-account__actions">
		<a href="<?php echo esc_url( $args['logout_url'] ); ?>"><?php esc_html_e( 'Log out', 'slashphoto' ); ?></a>
	</div>
	<div class="slashphoto-account__grid">
		<section class="slashphoto-account__section">
			<h2><?php esc_html_e( 'My galleries', 'slashphoto' ); ?></h2>
			<?php if ( $args['galleries'] ) : ?>
				<ul class="slashphoto-account__list">
					<?php foreach ( $args['galleries'] as $slashphoto_gallery ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $slashphoto_gallery ) ); ?>"><?php echo esc_html( get_the_title( $slashphoto_gallery ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No galleries are assigned to your account.', 'slashphoto' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="slashphoto-account__section">
			<h2><?php esc_html_e( 'My selections', 'slashphoto' ); ?></h2>
			<?php if ( $args['selections'] ) : ?>
				<ul class="slashphoto-account__list">
					<?php foreach ( $args['selections'] as $slashphoto_selection ) : ?>
						<li>
							<a href="<?php echo esc_url( get_permalink( $slashphoto_selection['gallery'] ) ); ?>"><?php echo esc_html( get_the_title( $slashphoto_selection['gallery'] ) ); ?></a>
							<span class="slashphoto-account__status"><?php echo $slashphoto_selection['submitted'] ? esc_html__( 'Submitted', 'slashphoto' ) : esc_html__( 'Still choosing', 'slashphoto' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'Your photo selections will appear here.', 'slashphoto' ); ?></p>
			<?php endif; ?>
		</section>
	</div>

	<section class="slashphoto-account__section">
		<h2><?php esc_html_e( 'Orders and downloads', 'slashphoto' ); ?></h2>
		<?php if ( $args['orders'] ) : ?>
			<ul class="slashphoto-account__list">
				<?php foreach ( $args['orders'] as $slashphoto_order ) : ?>
					<?php
					/* translators: %d: order number */
					$slashphoto_label = sprintf( __( 'Order %d', 'slashphoto' ), $slashphoto_order['id'] );
					?>
					<li>
						<div>
							<?php if ( $args['checkout'] ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'order', $slashphoto_order['id'], $args['checkout'] ) ); ?>"><?php echo esc_html( $slashphoto_label ); ?></a>
							<?php else : ?>
								<strong><?php echo esc_html( $slashphoto_label ); ?></strong>
							<?php endif; ?>
							<span class="slashphoto-account__date"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $slashphoto_order['created_at'] . ' UTC' ) ) ); ?></span>
						</div>
						<div class="slashphoto-account__order-status">
							<span><?php echo esc_html( Money::format( (int) $slashphoto_order['total'], $slashphoto_order['currency'] ) ); ?></span>
							<span class="slashphoto-account__status"><?php echo esc_html( $slashphoto_statuses[ $slashphoto_order['status'] ] ?? $slashphoto_order['status'] ); ?></span>
							<?php if ( $args['checkout'] && $slashphoto_order['has_downloads'] && Orders::downloads_open( $slashphoto_order ) ) : ?>
								<span class="slashphoto-account__status slashphoto-account__status--ready"><?php esc_html_e( 'Downloads available', 'slashphoto' ); ?></span>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'No orders to show.', 'slashphoto' ); ?></p>
		<?php endif; ?>
		<?php if ( $args['latest_url'] || $args['older_url'] ) : ?>
			<nav class="slashphoto-account__actions" aria-label="<?php esc_attr_e( 'Order history', 'slashphoto' ); ?>">
				<?php if ( $args['latest_url'] ) : ?>
					<a href="<?php echo esc_url( $args['latest_url'] ); ?>"><?php esc_html_e( 'Latest orders', 'slashphoto' ); ?></a>
				<?php endif; ?>
				<?php if ( $args['older_url'] ) : ?>
					<a href="<?php echo esc_url( $args['older_url'] ); ?>"><?php esc_html_e( 'Older orders', 'slashphoto' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	</section>
</div>
