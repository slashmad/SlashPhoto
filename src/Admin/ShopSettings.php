<?php
/**
 * Shop settings screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Gateways\StripeGateway;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\ShopPage;

defined( 'ABSPATH' ) || exit;

/**
 * SlashPhoto → Shop: cart page, prices and VAT, shipping of prints,
 * downloads, payments (manual and Stripe).
 */
final class ShopSettings {

	public const SLUG   = 'slashphoto-shop';
	public const STRIPE = 'slashphoto_stripe';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_' . self::STRIPE, [ $this, 'handle_stripe' ] );
	}

	/**
	 * Adds the submenu page.
	 */
	public function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Shop settings', 'slashphoto' ),
			__( 'Shop settings', 'slashphoto' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Saves or removes Stripe keys.
	 */
	public function handle_stripe(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::STRIPE );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$result    = true;
		if ( 'disconnect' === $operation ) {
			StripeGateway::disconnect();
		} else {
			$result = StripeGateway::save(
				isset( $_POST['stripe_secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['stripe_secret_key'] ) ) ) : '',
				isset( $_POST['stripe_webhook_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['stripe_webhook_secret'] ) ) ) : ''
			);
		}
		set_transient(
			'slashphoto_stripe_notice_' . get_current_user_id(),
			[
				'operation' => $operation,
				'error'     => is_wp_error( $result ) ? $result->get_error_message() : '',
			],
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( Settings::admin_url( 'slashphoto-stripe', self::SLUG ) );
		exit;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = Settings::all();
		$name     = Settings::OPTION;
		$field    = static fn( string $key ): string => $name . '[' . $key . ']';
		$currency = (string) $s['currency'];
		/**
		 * Filters the currencies offered in the shop settings.
		 *
		 * @param string[] $currencies ISO 4217 codes.
		 */
		$currencies = (array) apply_filters( 'slashphoto_currencies', Money::CURRENCIES );
		$page       = ShopPage::page_id();
		$lists      = PriceLists::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shop settings', 'slashphoto' ); ?></h1>
			<p><?php esc_html_e( 'Sell downloads of your originals and prints in different sizes. Give a gallery a price list in the gallery editor (“Shop” panel); sub-galleries use the same list unless you choose another.', 'slashphoto' ); ?></p>

			<?php if ( ! $page ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'Customers cannot check out until there is a cart page.', 'slashphoto' ); ?>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . ShopPage::CREATE ), ShopPage::CREATE ) ); ?>"><?php esc_html_e( 'Create the cart page', 'slashphoto' ); ?></a>
				</p></div>
			<?php endif; ?>
			<?php if ( ! $lists ) : ?>
				<div class="notice notice-info inline"><p>
					<?php esc_html_e( 'There are no price lists yet.', 'slashphoto' ); ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PriceLists::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add a price list', 'slashphoto' ); ?></a>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( SettingsPage::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( $field( '_section' ) ); ?>" value="shop">

				<h2><?php esc_html_e( 'Pages', 'slashphoto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slashphoto-shop-page"><?php esc_html_e( 'Cart page', 'slashphoto' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								[
									'name'              => esc_attr( $field( 'shop_page' ) ),
									'id'                => 'slashphoto-shop-page',
									'selected'          => (int) $s['shop_page'],
									'show_option_none'  => esc_html__( '— None —', 'slashphoto' ),
									'option_none_value' => '0',
								]
							);
							?>
							<p class="description">
								<?php
								/* translators: %s: shortcode */
								echo esc_html( sprintf( __( 'A page containing %s. It shows the cart, the checkout form and, through the link customers get by email, their orders and downloads.', 'slashphoto' ), '[' . ShopPage::SHORTCODE . ']' ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-terms-page"><?php esc_html_e( 'Terms of purchase', 'slashphoto' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								[
									'name'              => esc_attr( $field( 'shop_terms_page' ) ),
									'id'                => 'slashphoto-terms-page',
									'selected'          => (int) $s['shop_terms_page'],
									'show_option_none'  => esc_html__( '— None —', 'slashphoto' ),
									'option_none_value' => '0',
								]
							);
							?>
							<p class="description"><?php esc_html_e( 'If you choose a page, customers must accept it before ordering.', 'slashphoto' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Prices and VAT', 'slashphoto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slashphoto-currency"><?php esc_html_e( 'Currency', 'slashphoto' ); ?></label></th>
						<td>
							<select id="slashphoto-currency" name="<?php echo esc_attr( $field( 'currency' ) ); ?>">
								<?php foreach ( $currencies as $code ) : ?>
									<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $currency, $code ); ?>><?php echo esc_html( (string) $code ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Prices are not converted if you change this later.', 'slashphoto' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Prices', 'slashphoto' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $field( 'prices_include_tax' ) ); ?>" value="1" <?php checked( $s['prices_include_tax'] ); ?>> <?php esc_html_e( 'Prices include VAT', 'slashphoto' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-tax-rate"><?php esc_html_e( 'VAT rate (%)', 'slashphoto' ); ?></label></th>
						<td>
							<input type="number" id="slashphoto-tax-rate" min="0" max="100" step="0.01" name="<?php echo esc_attr( $field( 'tax_rate' ) ); ?>" value="<?php echo esc_attr( (string) $s['tax_rate'] ); ?>">
							<p class="description"><?php esc_html_e( 'One rate for everything; 0 if you do not charge VAT. Shown on receipts. Check the rules for your country.', 'slashphoto' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-price-list"><?php esc_html_e( 'Default price list', 'slashphoto' ); ?></label></th>
						<td>
							<select id="slashphoto-price-list" name="<?php echo esc_attr( $field( 'shop_price_list' ) ); ?>">
								<option value="0"><?php esc_html_e( 'None: only galleries with their own price list sell images', 'slashphoto' ); ?></option>
								<?php foreach ( $lists as $id => $title ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( (int) $s['shop_price_list'], $id ); ?>><?php echo esc_html( $title ); ?></option>
								<?php endforeach; ?>
							</select>
							<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PriceLists::POST_TYPE ) ); ?>"><?php esc_html_e( 'Manage price lists', 'slashphoto' ); ?></a></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Prints', 'slashphoto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slashphoto-shipping"><?php esc_html_e( 'Shipping per order', 'slashphoto' ); ?></label></th>
						<td><input type="text" inputmode="decimal" size="8" id="slashphoto-shipping" name="<?php echo esc_attr( $field( 'shipping_flat_input' ) ); ?>" value="<?php echo esc_attr( Money::to_input( (int) $s['shipping_flat'], $currency ) ); ?>"> <?php echo esc_html( $currency ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-free-shipping"><?php esc_html_e( 'Free shipping from', 'slashphoto' ); ?></label></th>
						<td>
							<input type="text" inputmode="decimal" size="8" id="slashphoto-free-shipping" name="<?php echo esc_attr( $field( 'free_shipping_over_input' ) ); ?>" value="<?php echo esc_attr( Money::to_input( (int) $s['free_shipping_over'], $currency ) ); ?>"> <?php echo esc_html( $currency ); ?>
							<p class="description"><?php esc_html_e( 'Order value; 0 means never free.', 'slashphoto' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-countries"><?php esc_html_e( 'Ship to countries', 'slashphoto' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="slashphoto-countries" name="<?php echo esc_attr( $field( 'shipping_countries' ) ); ?>" value="<?php echo esc_attr( (string) $s['shipping_countries'] ); ?>" placeholder="SE, NO, DK, FI">
							<p class="description"><?php esc_html_e( 'Two-letter country codes, separated by commas. Prints cannot be ordered while this is empty.', 'slashphoto' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Downloads', 'slashphoto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slashphoto-download-days"><?php esc_html_e( 'Available for (days)', 'slashphoto' ); ?></label></th>
						<td>
							<input type="number" id="slashphoto-download-days" min="0" max="3650" name="<?php echo esc_attr( $field( 'download_days' ) ); ?>" value="<?php echo esc_attr( (string) $s['download_days'] ); ?>">
							<p class="description"><?php esc_html_e( 'Counted from payment; 0 means no end date. Customers download the original file you uploaded, through a private link that only works for paid orders.', 'slashphoto' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Receipts and invoices', 'slashphoto' ); ?></h2>
				<p><?php esc_html_e( 'Customers can open and print a receipt for paid orders, and an invoice while a manual payment is outstanding. Check what your country requires on receipts.', 'slashphoto' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="slashphoto-business-name"><?php esc_html_e( 'Business name', 'slashphoto' ); ?></label></th>
						<td><input type="text" class="regular-text" id="slashphoto-business-name" maxlength="200" name="<?php echo esc_attr( $field( 'business_name' ) ); ?>" value="<?php echo esc_attr( (string) $s['business_name'] ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-business-details"><?php esc_html_e( 'Address and registration', 'slashphoto' ); ?></label></th>
						<td>
							<textarea id="slashphoto-business-details" class="large-text" rows="4" maxlength="1000" name="<?php echo esc_attr( $field( 'business_details' ) ); ?>"><?php echo esc_textarea( (string) $s['business_details'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Address, company registration number, VAT number, contact details. Shown as written.', 'slashphoto' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-receipt-note"><?php esc_html_e( 'Note at the bottom', 'slashphoto' ); ?></label></th>
						<td><textarea id="slashphoto-receipt-note" class="large-text" rows="2" maxlength="1000" name="<?php echo esc_attr( $field( 'receipt_note' ) ); ?>"><?php echo esc_textarea( (string) $s['receipt_note'] ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Payment and notifications', 'slashphoto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Manual payment', 'slashphoto' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $field( 'manual_enabled' ) ); ?>" value="1" <?php checked( $s['manual_enabled'] ); ?>> <?php esc_html_e( 'Customers may order and pay later (invoice, bank transfer, Swish, cash)', 'slashphoto' ); ?></label>
							<p class="description"><?php esc_html_e( 'The order waits until you mark it paid; downloads open then.', 'slashphoto' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-manual-title"><?php esc_html_e( 'Name at checkout', 'slashphoto' ); ?></label></th>
						<td><input type="text" class="regular-text" id="slashphoto-manual-title" maxlength="100" name="<?php echo esc_attr( $field( 'manual_title' ) ); ?>" value="<?php echo esc_attr( (string) $s['manual_title'] ); ?>" placeholder="<?php esc_attr_e( 'Pay later (invoice or transfer)', 'slashphoto' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-manual-instructions"><?php esc_html_e( 'Payment details', 'slashphoto' ); ?></label></th>
						<td>
							<textarea id="slashphoto-manual-instructions" class="large-text" rows="4" maxlength="2000" name="<?php echo esc_attr( $field( 'manual_instructions' ) ); ?>"><?php echo esc_textarea( (string) $s['manual_instructions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown on the order page and in the order email, e.g. “Swish 123 456 78 90, mark the payment with your order number.”', 'slashphoto' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slashphoto-shop-email"><?php esc_html_e( 'Order notifications to', 'slashphoto' ); ?></label></th>
						<td><input type="email" class="regular-text" id="slashphoto-shop-email" name="<?php echo esc_attr( $field( 'shop_email' ) ); ?>" value="<?php echo esc_attr( (string) $s['shop_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php $this->render_stripe(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the Stripe section (its own form, keys are never shown again).
	 */
	private function render_stripe(): void {
		$notice = get_transient( 'slashphoto_stripe_notice_' . get_current_user_id() );
		delete_transient( 'slashphoto_stripe_notice_' . get_current_user_id() );
		$mode    = StripeGateway::mode();
		$webhook = '' !== StripeGateway::webhook_secret();
		$action  = admin_url( 'admin-post.php' );
		?>
		<h2 id="slashphoto-stripe"><?php esc_html_e( 'Card payments with Stripe', 'slashphoto' ); ?></h2>
		<p><?php esc_html_e( 'Customers pay on Stripe’s own checkout page with card, or with Swish, Klarna and other methods you switch on in the Stripe Dashboard. Card details never reach this site, no Stripe scripts are loaded on your pages and SlashPhoto adds no fees.', 'slashphoto' ); ?></p>
		<?php if ( is_array( $notice ) ) : ?>
			<div class="notice <?php echo esc_attr( '' === $notice['error'] ? 'notice-success' : 'notice-error' ); ?> inline"><p>
				<?php
				if ( '' !== $notice['error'] ) {
					echo esc_html( $notice['error'] );
				} elseif ( 'disconnect' === $notice['operation'] ) {
					esc_html_e( 'The Stripe keys were removed.', 'slashphoto' );
				} else {
					esc_html_e( 'Saved. Stripe accepted the key.', 'slashphoto' );
				}
				?>
			</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<?php wp_nonce_field( self::STRIPE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::STRIPE ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'slashphoto' ); ?></th>
					<td>
						<?php
						if ( 'live' === $mode ) {
							esc_html_e( 'Connected, live payments.', 'slashphoto' );
						} elseif ( 'test' === $mode ) {
							esc_html_e( 'Connected in test mode: no real money is charged.', 'slashphoto' );
						} else {
							esc_html_e( 'Not connected.', 'slashphoto' );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="slashphoto-stripe-key"><?php esc_html_e( 'Secret key', 'slashphoto' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="slashphoto-stripe-key" name="stripe_secret_key" autocomplete="off" placeholder="<?php echo esc_attr( $mode ? __( 'Saved. Enter a new key to replace it.', 'slashphoto' ) : 'sk_live_… / rk_live_…' ); ?>">
						<p class="description"><?php esc_html_e( 'Stripe Dashboard → Developers → API keys. A restricted key needs write access to Checkout Sessions. Stored encrypted.', 'slashphoto' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="slashphoto-stripe-webhook"><?php esc_html_e( 'Webhook signing secret', 'slashphoto' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="slashphoto-stripe-webhook" name="stripe_webhook_secret" autocomplete="off" placeholder="<?php echo esc_attr( $webhook ? __( 'Saved. Enter a new secret to replace it.', 'slashphoto' ) : 'whsec_…' ); ?>">
						<p class="description">
							<?php esc_html_e( 'Recommended. In the Stripe Dashboard add a webhook endpoint with this address and the events checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed and checkout.session.expired:', 'slashphoto' ); ?>
							<br><code><?php echo esc_html( StripeGateway::webhook_url() ); ?></code><br>
							<?php esc_html_e( 'Without it, orders are marked paid when the customer returns to your site after paying.', 'slashphoto' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" name="operation" value="save" class="button button-primary"><?php esc_html_e( 'Save Stripe keys', 'slashphoto' ); ?></button>
				<?php if ( $mode ) : ?>
					<button type="submit" name="operation" value="disconnect" class="button button-link-delete"><?php esc_html_e( 'Remove keys', 'slashphoto' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}
}
