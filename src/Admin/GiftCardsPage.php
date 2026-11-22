<?php
/**
 * Admin screen: SlashPhoto → Gift cards.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use SlashPhoto\Shop\GiftCards;
use SlashPhoto\Shop\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Lists gift cards with their balance, creates cards by hand (the code is
 * shown once, to the person who made it) and voids or reactivates cards.
 */
final class GiftCardsPage {

	public const SLUG = 'slashphoto-gift-cards';

	private const ACTION   = 'slashphoto_gift_card';
	private const PER_PAGE = 50;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Screen address.
	 */
	public static function url(): string {
		return Settings::admin_url( '', self::SLUG );
	}

	/**
	 * Adds the submenu page.
	 */
	public function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . PostType::POST_TYPE, __( 'Gift cards', 'slashphoto' ), __( 'Gift cards', 'slashphoto' ), OrdersPage::capability(), self::SLUG, [ $this, 'render' ] );
	}

	/**
	 * Where a new code waits for its maker, for one page view.
	 */
	private static function transient(): string {
		return 'slashphoto_new_gift_card_' . get_current_user_id();
	}

	/**
	 * Creates, voids or reactivates a card.
	 */
	public function handle(): void {
		if ( ! current_user_can( OrdersPage::capability() ) ) {
			wp_die( esc_html__( 'You cannot manage gift cards.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$result    = 'error';
		if ( 'create' === $operation ) {
			$amount = Money::parse( isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '', (string) Settings::get( 'currency' ) );
			$code   = GiftCards::create( $amount );
			if ( '' !== $code ) {
				// Not in the URL: it would end up in the browser history and server logs.
				set_transient( self::transient(), $code, 5 * MINUTE_IN_SECONDS );
				$result = 'created';
			} else {
				$result = 'amount';
			}
		} elseif ( in_array( $operation, [ 'void', 'activate' ], true ) ) {
			$card   = isset( $_POST['card'] ) ? absint( $_POST['card'] ) : 0;
			$result = $card && GiftCards::get( $card ) && GiftCards::set_active( $card, 'activate' === $operation ) ? $operation : 'error';
		}
		wp_safe_redirect( add_query_arg( 'done', $result, self::url() ), 303 );
		exit;
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( OrdersPage::capability() ) ) {
			return;
		}
		$currency = (string) Settings::get( 'currency' );
		$money    = static fn( int $amount, string $in = '' ): string => Money::format( $amount, '' !== $in ? $in : $currency );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen parameters.
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$code = 'created' === $done ? (string) get_transient( self::transient() ) : '';
		delete_transient( self::transient() );
		$list     = GiftCards::query( self::PER_PAGE, $page );
		$messages = [
			'void'     => __( 'The gift card can no longer be used.', 'slashphoto' ),
			'activate' => __( 'The gift card can be used again.', 'slashphoto' ),
			'amount'   => __( 'Enter an amount above zero.', 'slashphoto' ),
			'error'    => __( 'That did not work. Reload the page and try again.', 'slashphoto' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Gift cards', 'slashphoto' ); ?></h1>
			<?php if ( '' !== $code ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Gift card created. Copy the code now: it is not stored and cannot be shown again.', 'slashphoto' ); ?></p>
					<p><input type="text" class="regular-text code" readonly value="<?php echo esc_attr( $code ); ?>" aria-label="<?php esc_attr_e( 'New gift card code', 'slashphoto' ); ?>" onfocus="this.select()"></p>
				</div>
			<?php elseif ( isset( $messages[ $done ] ) ) : ?>
				<div class="notice <?php echo in_array( $done, [ 'amount', 'error' ], true ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( $messages[ $done ] ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				echo esc_html(
					GiftCards::enabled()
						? __( 'Gift cards are for sale on pages with the [slashphoto_gift_cards] shortcode.', 'slashphoto' )
						: __( 'Gift cards are not for sale. Switch them on under Shop settings → Gift cards; cards made here can be used either way.', 'slashphoto' )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Create a gift card', 'slashphoto' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="operation" value="create">
				<?php wp_nonce_field( self::ACTION ); ?>
				<label for="slashphoto-gift-card-amount">
					<?php
					/* translators: %s: currency code */
					echo esc_html( sprintf( __( 'Amount (%s)', 'slashphoto' ), $currency ) );
					?>
				</label>
				<input type="text" inputmode="decimal" id="slashphoto-gift-card-amount" name="amount" size="8" required>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create', 'slashphoto' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'For prizes, apologies or cards you sell yourself. The validity in the shop settings applies.', 'slashphoto' ); ?></p>

			<h2><?php esc_html_e( 'All gift cards', 'slashphoto' ); ?></h2>
			<?php if ( ! $list['rows'] ) : ?>
				<p><?php esc_html_e( 'No gift cards yet.', 'slashphoto' ); ?></p>
			<?php else : ?>
				<div class="slashphoto-gift-cards-table" style="overflow-x:auto">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Code ends with', 'slashphoto' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Amount', 'slashphoto' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Left', 'slashphoto' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Source', 'slashphoto' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Valid until', 'slashphoto' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'slashphoto' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $list['rows'] as $card ) : ?>
							<?php
							$expired = null !== $card['expires_at'] && strtotime( $card['expires_at'] . ' UTC' ) < time();
							$active  = 'active' === $card['status'];
							?>
							<tr>
								<td><code style="white-space:nowrap">…<?php echo esc_html( $card['hint'] ); ?></code></td>
								<td><?php echo esc_html( $money( $card['amount'], $card['currency'] ) ); ?></td>
								<td>
									<?php echo esc_html( $money( $card['balance'], $card['currency'] ) ); ?>
									<?php foreach ( GiftCards::uses( $card['id'] ) as $use ) : ?>
										<br><small>
											<a href="<?php echo esc_url( add_query_arg( 'order', $use['order_id'], Settings::admin_url( '', OrdersPage::SLUG ) ) ); ?>">#<?php echo esc_html( (string) $use['order_id'] ); ?></a>
											<?php echo esc_html( '−' . $money( (int) $use['amount'], $card['currency'] ) . ( in_array( (string) $use['status'], [ 'cancelled', 'failed', 'refunded', '' ], true ) ? ' (' . __( 'returned', 'slashphoto' ) . ')' : '' ) ); ?>
										</small>
									<?php endforeach; ?>
								</td>
								<td>
									<?php if ( $card['order_id'] ) : ?>
										<a href="<?php echo esc_url( add_query_arg( 'order', $card['order_id'], Settings::admin_url( '', OrdersPage::SLUG ) ) ); ?>">
											<?php
											/* translators: %d: order number */
											echo esc_html( sprintf( __( 'Order %d', 'slashphoto' ), $card['order_id'] ) );
											?>
										</a>
									<?php else : ?>
										<?php esc_html_e( 'Made by hand', 'slashphoto' ); ?>
									<?php endif; ?>
									<br><small><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( $card['created_at'] . ' UTC' ) ) ); ?></small>
								</td>
								<td><?php echo esc_html( null === $card['expires_at'] ? __( 'No end date', 'slashphoto' ) : wp_date( (string) get_option( 'date_format' ), (int) strtotime( $card['expires_at'] . ' UTC' ) ) ); ?></td>
								<td>
									<?php echo esc_html( ! $active ? __( 'Void', 'slashphoto' ) : ( $expired ? __( 'Expired', 'slashphoto' ) : ( $card['balance'] > 0 ? __( 'Active', 'slashphoto' ) : __( 'Used up', 'slashphoto' ) ) ) ); ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
										<input type="hidden" name="operation" value="<?php echo esc_attr( $active ? 'void' : 'activate' ); ?>">
										<input type="hidden" name="card" value="<?php echo esc_attr( (string) $card['id'] ); ?>">
										<?php wp_nonce_field( self::ACTION ); ?>
										<button type="submit" class="button-link<?php echo $active ? ' button-link-delete' : ''; ?>"><?php echo esc_html( $active ? __( 'Void', 'slashphoto' ) : __( 'Reactivate', 'slashphoto' ) ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php
				$pages = (int) ceil( $list['total'] / self::PER_PAGE );
				if ( $pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
						(string) paginate_links(
							[
								'base'    => add_query_arg( 'paged', '%#%', self::url() ),
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							]
						)
					) . '</div></div>';
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}
}
