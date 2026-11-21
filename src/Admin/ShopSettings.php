<?php
/**
 * Shop settings screen.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Admin;

use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use SlashPhoto\Shop\Countries;
use SlashPhoto\Shop\Gateways\MollieGateway;
use SlashPhoto\Shop\Gateways\PayPalGateway;
use SlashPhoto\Shop\Gateways\StripeGateway;
use SlashPhoto\Shop\Money;
use SlashPhoto\Shop\PriceLists;
use SlashPhoto\Shop\PrintPresets;
use SlashPhoto\Shop\ShopPage;

defined( 'ABSPATH' ) || exit;

/**
 * SlashPhoto → Shop: cart page, prices and VAT, shipping of prints,
 * downloads, payments (manual and Stripe).
 */
final class ShopSettings {

	public const SLUG   = 'slashphoto-shop';
	public const STRIPE = 'slashphoto_stripe';
	public const PAYPAL = 'slashphoto_paypal';
	public const MOLLIE = 'slashphoto_mollie';

	/**
	 * Admin hook for loading the picker only on this page.
	 *
	 * @var string
	 */
	private string $screen_hook = '';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_post_' . self::STRIPE, [ $this, 'handle_stripe' ] );
		add_action( 'admin_post_' . self::PAYPAL, [ $this, 'handle_paypal' ] );
		add_action( 'admin_post_' . self::MOLLIE, [ $this, 'handle_mollie' ] );
	}

	/**
	 * Adds the submenu page.
	 */
	public function menu(): void {
		$this->screen_hook = (string) add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Shop settings', 'slashphoto' ),
			__( 'Shop settings', 'slashphoto' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Loads the country picker assets on the shop settings screen.
	 *
	 * @param string $hook Current screen hook.
	 */
	public function enqueue( string $hook ): void {
		if ( $this->screen_hook === $hook ) {
			AdminTabs::enqueue();
			wp_enqueue_style( 'slashphoto-country-picker', SLASHPHOTO_URL . 'assets/css/admin-countries.css', [], SLASHPHOTO_VERSION );
			wp_enqueue_script( 'slashphoto-country-picker', SLASHPHOTO_URL . 'assets/js/admin-countries.js', [], SLASHPHOTO_VERSION, true );
		}
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
		wp_safe_redirect( self::payment_url( 'slashphoto-stripe' ) );
		exit;
	}

	/**
	 * Saves or removes PayPal credentials.
	 */
	public function handle_paypal(): void {
		$this->check();
		check_admin_referer( self::PAYPAL );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$result    = true;
		if ( 'disconnect' === $operation ) {
			PayPalGateway::disconnect();
		} else {
			$result = PayPalGateway::save(
				isset( $_POST['paypal_client_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['paypal_client_id'] ) ) ) : '',
				isset( $_POST['paypal_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['paypal_secret'] ) ) ) : '',
				! empty( $_POST['paypal_sandbox'] ),
				isset( $_POST['paypal_webhook_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['paypal_webhook_id'] ) ) ) : ''
			);
		}
		$this->done( self::PAYPAL, $operation, $result );
	}

	/**
	 * Saves or removes the Mollie key.
	 */
	public function handle_mollie(): void {
		$this->check();
		check_admin_referer( self::MOLLIE );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$result    = true;
		if ( 'disconnect' === $operation ) {
			MollieGateway::disconnect();
		} else {
			$result = MollieGateway::save( isset( $_POST['mollie_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['mollie_api_key'] ) ) ) : '' );
		}
		$this->done( self::MOLLIE, $operation, $result );
	}

	/**
	 * Capability check for the payment forms.
	 */
	private function check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'slashphoto' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Remembers the result for the notice and returns to the section.
	 *
	 * @param string         $action    Form action (also the section anchor).
	 * @param string         $operation Operation.
	 * @param true|\WP_Error $result    Result.
	 */
	private function done( string $action, string $operation, $result ): void {
		set_transient(
			$action . '_notice_' . get_current_user_id(),
			[
				'operation' => $operation,
				'error'     => is_wp_error( $result ) ? $result->get_error_message() : '',
			],
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::payment_url( str_replace( '_', '-', $action ) ) );
		exit;
	}

	/**
	 * Returns to the relevant payment provider after saving its form.
	 *
	 * @param string $anchor Provider section ID.
	 */
	private static function payment_url( string $anchor ): string {
		return add_query_arg( 'tab', 'payments', Settings::admin_url( '', self::SLUG ) ) . '#' . $anchor;
	}

	/**
	 * Shows the result of the last save of a payment section.
	 *
	 * @param string $action Form action.
	 * @param string $saved  Message after saving.
	 * @param string $gone   Message after removing.
	 */
	private function notice( string $action, string $saved, string $gone ): void {
		$notice = get_transient( $action . '_notice_' . get_current_user_id() );
		delete_transient( $action . '_notice_' . get_current_user_id() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		$message = '' !== $notice['error'] ? $notice['error'] : ( 'disconnect' === $notice['operation'] ? $gone : $saved );
		echo '<div class="notice ' . esc_attr( '' === $notice['error'] ? 'notice-success' : 'notice-error' ) . ' inline"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Renders the PayPal section.
	 */
	private function render_paypal(): void {
		$data    = PayPalGateway::data();
		$ready   = ( new PayPalGateway() )->available();
		$sandbox = PayPalGateway::sandbox();
		?>
		<h2 id="slashphoto-paypal"><?php esc_html_e( 'PayPal', 'slashphoto' ); ?></h2>
		<p><?php esc_html_e( 'Customers approve the payment on paypal.com with their PayPal account or a card, then return to your site where the payment is completed. No PayPal script is loaded on your pages.', 'slashphoto' ); ?></p>
		<?php $this->notice( self::PAYPAL, __( 'Saved. PayPal accepted the credentials.', 'slashphoto' ), __( 'The PayPal credentials were removed.', 'slashphoto' ) ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::PAYPAL ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PAYPAL ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'slashphoto' ); ?></th>
					<td>
						<?php
						if ( ! $ready ) {
							esc_html_e( 'Not connected.', 'slashphoto' );
						} elseif ( $sandbox ) {
							esc_html_e( 'Connected to the PayPal sandbox: no real money is charged.', 'slashphoto' );
						} else {
							esc_html_e( 'Connected, live payments.', 'slashphoto' );
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="slashphoto-paypal-client"><?php esc_html_e( 'Client ID', 'slashphoto' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="slashphoto-paypal-client" name="paypal_client_id" autocomplete="off" value="<?php echo esc_attr( (string) ( $data['client_id'] ?? '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'developer.paypal.com → Apps & Credentials → your REST app.', 'slashphoto' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="slashphoto-paypal-secret"><?php esc_html_e( 'Secret', 'slashphoto' ); ?></label></th>
					<td><input type="password" class="regular-text" id="slashphoto-paypal-secret" name="paypal_secret" autocomplete="off" placeholder="<?php echo esc_attr( $ready ? __( 'Saved. Enter a new secret to replace it.', 'slashphoto' ) : '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Environment', 'slashphoto' ); ?></th>
					<td><label><input type="checkbox" name="paypal_sandbox" value="1" <?php checked( $sandbox || ! $ready ); ?>> <?php esc_html_e( 'Sandbox (test payments)', 'slashphoto' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="slashphoto-paypal-webhook"><?php esc_html_e( 'Webhook ID', 'slashphoto' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="slashphoto-paypal-webhook" name="paypal_webhook_id" autocomplete="off" value="<?php echo esc_attr( (string) ( $data['webhook_id'] ?? '' ) ); ?>">
						<p class="description">
							<?php esc_html_e( 'Recommended. In your PayPal app add a webhook with this address and the events Checkout order approved, Payment capture completed, denied and refunded, then paste its ID:', 'slashphoto' ); ?>
							<br><code><?php echo esc_html( PayPalGateway::webhook_url() ); ?></code>
						</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" name="operation" value="save" class="button button-primary"><?php esc_html_e( 'Save PayPal settings', 'slashphoto' ); ?></button>
				<?php if ( $ready ) : ?>
					<button type="submit" name="operation" value="disconnect" class="button button-link-delete"><?php esc_html_e( 'Remove credentials', 'slashphoto' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Renders the Mollie section.
	 */
	private function render_mollie(): void {
		$mode = MollieGateway::mode();
		?>
		<h2 id="slashphoto-mollie"><?php esc_html_e( 'Mollie', 'slashphoto' ); ?></h2>
		<p><?php esc_html_e( 'Customers pay on Mollie’s checkout page with the methods you switch on in the Mollie Dashboard: cards, iDEAL, Bancontact, Klarna, PayPal and more. No Mollie script is loaded on your pages.', 'slashphoto' ); ?></p>
		<?php $this->notice( self::MOLLIE, __( 'Saved. Mollie accepted the key.', 'slashphoto' ), __( 'The Mollie key was removed.', 'slashphoto' ) ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::MOLLIE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::MOLLIE ); ?>">
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
					<th scope="row"><label for="slashphoto-mollie-key"><?php esc_html_e( 'API key', 'slashphoto' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="slashphoto-mollie-key" name="mollie_api_key" autocomplete="off" placeholder="<?php echo esc_attr( $mode ? __( 'Saved. Enter a new key to replace it.', 'slashphoto' ) : 'live_… / test_…' ); ?>">
						<p class="description">
							<?php esc_html_e( 'Mollie Dashboard → Developers → API keys. Stored encrypted.', 'slashphoto' ); ?>
							<?php if ( ! MollieGateway::webhook_reachable() ) : ?>
								<br><?php esc_html_e( 'This site’s address is not reachable from the internet, so Mollie cannot send payment updates; payments are confirmed when customers return to the site.', 'slashphoto' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" name="operation" value="save" class="button button-primary"><?php esc_html_e( 'Save Mollie key', 'slashphoto' ); ?></button>
				<?php if ( $mode ) : ?>
					<button type="submit" name="operation" value="disconnect" class="button button-link-delete"><?php esc_html_e( 'Remove key', 'slashphoto' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php
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
		$tabs       = [
			'pages'     => __( 'Pages', 'slashphoto' ),
			'prices'    => __( 'Prices and VAT', 'slashphoto' ),
			'prints'    => __( 'Print templates', 'slashphoto' ),
			'shipping'  => __( 'Shipping', 'slashphoto' ),
			'downloads' => __( 'Downloads', 'slashphoto' ),
			'receipts'  => __( 'Receipts', 'slashphoto' ),
			'payments'  => __( 'Payments', 'slashphoto' ),
		];
		$tab        = AdminTabs::active( $tabs );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shop settings', 'slashphoto' ); ?></h1>
			<p><?php esc_html_e( 'Sell downloads of your originals and prints in different sizes. Assign a price list under the gallery’s settings. Sub-galleries use the same list unless you choose another.', 'slashphoto' ); ?></p>

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

			<?php AdminTabs::render( $tabs, $tab, Settings::admin_url( '', self::SLUG ), __( 'Settings sections', 'slashphoto' ) ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( SettingsPage::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( $field( '_section' ) ); ?>" value="<?php echo esc_attr( 'shop_' . $tab ); ?>">

				<?php if ( 'pages' === $tab ) : ?>
					<h2><?php esc_html_e( 'Pages', 'slashphoto' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="slashphoto-shop-page"><?php esc_html_e( 'Cart page', 'slashphoto' ); ?></label></th>
							<td>
								<?php
								wp_dropdown_pages(
									[
										'name'             => esc_attr( $field( 'shop_page' ) ),
										'id'               => 'slashphoto-shop-page',
										'selected'         => (int) $s['shop_page'],
										'show_option_none' => esc_html__( '— None —', 'slashphoto' ),
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
										'name'             => esc_attr( $field( 'shop_terms_page' ) ),
										'id'               => 'slashphoto-terms-page',
										'selected'         => (int) $s['shop_terms_page'],
										'show_option_none' => esc_html__( '— None —', 'slashphoto' ),
										'option_none_value' => '0',
									]
								);
								?>
								<p class="description"><?php esc_html_e( 'If you choose a page, customers must accept it before ordering.', 'slashphoto' ); ?></p>
							</td>
						</tr>
					</table>
				<?php elseif ( 'prices' === $tab ) : ?>
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
				<?php elseif ( 'prints' === $tab ) : ?>
					<h2><?php esc_html_e( 'Paper and framing templates', 'slashphoto' ); ?></h2>
					<p><?php esc_html_e( 'Choose which starter templates appear in the price-list editor. When you apply a template, keep the combinations your lab offers in that size and enter your own prices.', 'slashphoto' ); ?></p>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Available print templates', 'slashphoto' ); ?></legend>
						<input type="hidden" name="<?php echo esc_attr( $field( 'print_presets' ) ); ?>[]" value="">
						<?php foreach ( PrintPresets::all() as $id => $preset ) : ?>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $field( 'print_presets' ) ); ?>[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $s['print_presets'], true ) ); ?>>
									<strong><?php echo esc_html( $preset['name'] ); ?></strong>
								</label>
								<?php
								/* translators: %s: print lab name. */
								$reference = sprintf( __( 'View %s reference', 'slashphoto' ), $preset['name'] );
								?>
								<span aria-hidden="true"> · </span>
								<a href="<?php echo esc_url( $preset['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $reference ); ?></a>
							</p>
						<?php endforeach; ?>
					</fieldset>
				<?php elseif ( 'shipping' === $tab ) : ?>
					<h2><?php esc_html_e( 'Prints and shipping', 'slashphoto' ); ?></h2>
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
							<th scope="row" id="slashphoto-countries-label"><?php esc_html_e( 'Ship to countries', 'slashphoto' ); ?></th>
							<td>
								<?php $this->country_picker( $field( 'shipping_countries' ), Countries::codes( $s['shipping_countries'] ) ); ?>
							</td>
						</tr>
					</table>
				<?php elseif ( 'downloads' === $tab ) : ?>
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
				<?php elseif ( 'receipts' === $tab ) : ?>
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
				<?php elseif ( 'payments' === $tab ) : ?>
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
				<?php endif; ?>
				<?php submit_button(); ?>
			</form>
			<?php
			if ( 'payments' === $tab ) {
				$this->render_stripe();
				$this->render_paypal();
				$this->render_mollie();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Provides a searchable checklist. The first empty field records an empty
	 * selection when every checkbox is cleared.
	 *
	 * @param string   $field    Settings field name.
	 * @param string[] $selected Saved country codes.
	 */
	private function country_picker( string $field, array $selected ): void {
		$name  = $field . '[]';
		$empty = __( 'No countries selected. Choose a country to accept print orders.', 'slashphoto' );
		$shown = implode( ', ', array_map( [ Countries::class, 'name' ], array_slice( $selected, 0, 4 ) ) );
		if ( count( $selected ) > 4 ) {
			$shown .= ' +' . ( count( $selected ) - 4 );
		}
		?>
		<div class="slashphoto-country-picker" role="group" aria-labelledby="slashphoto-countries-label" data-slashphoto-country-picker>
			<details>
				<summary><?php esc_html_e( 'Choose countries', 'slashphoto' ); ?></summary>
				<label class="screen-reader-text" for="slashphoto-country-search"><?php esc_html_e( 'Search countries', 'slashphoto' ); ?></label>
				<input type="search" id="slashphoto-country-search" placeholder="<?php esc_attr_e( 'Search countries', 'slashphoto' ); ?>" autocomplete="off" hidden>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="">
				<div class="slashphoto-country-picker__list" data-countries>
					<?php foreach ( Countries::options( $selected ) as $code ) : ?>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $selected, true ) ); ?>>
							<span><?php echo esc_html( Countries::name( $code ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p data-no-results hidden><?php esc_html_e( 'No matching countries.', 'slashphoto' ); ?></p>
			</details>
			<p class="description" role="status" data-selection data-empty="<?php echo esc_attr( $empty ); ?>"><?php echo esc_html( $shown ? $shown : $empty ); ?></p>
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
