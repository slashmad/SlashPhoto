<?php
/**
 * Cart and checkout.
 *
 * The cart lives in the visitor's browser; the shop script fills in the
 * lines and prices from the site and sends the order. Override: copy this
 * file to yourtheme/slashphoto/checkout.php and keep the data-slashphoto-*
 * attributes and field names.
 *
 * @package SlashPhoto
 *
 * @var array $args {
 *     @type array[]  $gateways   Payment methods: { id, title, description }.
 *     @type string[] $countries  Country codes prints can be sent to.
 *     @type string[] $residence  Countries a customer can say they live in, when VAT
 *                                depends on the country (empty otherwise).
 *     @type int      $terms_page Page with the terms of purchase, or 0.
 *     @type string   $login_url  Log-in address that returns here.
 * }
 */

defined( 'ABSPATH' ) || exit;

$slashphoto_gateways  = $args['gateways'] ?? [];
$slashphoto_countries = $args['countries'] ?? [];
$slashphoto_residence = $args['residence'] ?? [];
$slashphoto_terms     = (int) ( $args['terms_page'] ?? 0 );
$slashphoto_names     = function_exists( 'locale_get_display_region' );
?>
<div class="slashphoto slashphoto-checkout" data-slashphoto-checkout>
	<noscript><p><?php esc_html_e( 'The cart needs JavaScript. Please switch it on to order.', 'slashphoto' ); ?></p></noscript>

	<div class="slashphoto-cart" data-slashphoto-cart aria-live="polite">
		<p class="slashphoto-cart__loading"><?php esc_html_e( 'Loading your cart…', 'slashphoto' ); ?></p>
	</div>

	<div class="slashphoto-coupon" data-slashphoto-coupon hidden>
		<p class="slashphoto-field">
			<label for="slashphoto-coupon"><?php esc_html_e( 'Discount code', 'slashphoto' ); ?></label>
			<input type="text" id="slashphoto-coupon" name="coupon" maxlength="50" autocomplete="off" autocapitalize="characters" spellcheck="false">
		</p>
		<button type="button" class="slashphoto-coupon__apply wp-element-button"><?php esc_html_e( 'Apply', 'slashphoto' ); ?></button>
		<p class="slashphoto-coupon__message" role="status" hidden></p>
	</div>

	<div class="slashphoto-coupon slashphoto-gift-code" data-slashphoto-gift-card-code hidden>
		<p class="slashphoto-field">
			<label for="slashphoto-gift-card"><?php esc_html_e( 'Gift card code', 'slashphoto' ); ?></label>
			<input type="text" id="slashphoto-gift-card" name="gift_card" maxlength="30" autocomplete="off" autocapitalize="characters" spellcheck="false">
		</p>
		<button type="button" class="slashphoto-coupon__apply wp-element-button"><?php esc_html_e( 'Use', 'slashphoto' ); ?></button>
		<p class="slashphoto-coupon__message" role="status" hidden></p>
	</div>

	<form class="slashphoto-checkout__form" data-slashphoto-checkout-form hidden novalidate>
		<h2 class="slashphoto-checkout__title"><?php esc_html_e( 'Your details', 'slashphoto' ); ?></h2>
		<?php if ( ! is_user_logged_in() ) : ?>
			<p class="slashphoto-checkout__login">
				<?php esc_html_e( 'If the photographer gave you an account, log in first so images from your private galleries can be ordered.', 'slashphoto' ); ?>
				<a href="<?php echo esc_url( $args['login_url'] ?? wp_login_url() ); ?>"><?php esc_html_e( 'Log in', 'slashphoto' ); ?></a>
			</p>
		<?php endif; ?>
		<p class="slashphoto-field">
			<label for="slashphoto-email"><?php esc_html_e( 'Email', 'slashphoto' ); ?></label>
			<input type="email" id="slashphoto-email" name="email" autocomplete="email" maxlength="200" required>
		</p>
		<p class="slashphoto-field">
			<label for="slashphoto-name"><?php esc_html_e( 'Name', 'slashphoto' ); ?></label>
			<input type="text" id="slashphoto-name" name="name" autocomplete="name" maxlength="200" required>
		</p>
		<p class="slashphoto-field">
			<label for="slashphoto-phone"><?php esc_html_e( 'Phone (optional)', 'slashphoto' ); ?></label>
			<input type="tel" id="slashphoto-phone" name="phone" autocomplete="tel" maxlength="50">
		</p>

		<?php if ( $slashphoto_residence ) : ?>
			<p class="slashphoto-field" data-slashphoto-residence hidden>
				<label for="slashphoto-residence"><?php esc_html_e( 'Country you live in', 'slashphoto' ); ?></label>
				<select id="slashphoto-residence" name="residence" autocomplete="country" aria-describedby="slashphoto-residence-hint">
					<option value=""><?php esc_html_e( 'Choose…', 'slashphoto' ); ?></option>
					<?php foreach ( $slashphoto_residence as $slashphoto_code ) : ?>
						<option value="<?php echo esc_attr( $slashphoto_code ); ?>"><?php echo esc_html( $slashphoto_names ? (string) locale_get_display_region( '-' . $slashphoto_code, determine_locale() ) : $slashphoto_code ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="slashphoto-checkout__hint" id="slashphoto-residence-hint"><?php esc_html_e( 'Digital downloads are charged the VAT of the country you live in.', 'slashphoto' ); ?></span>
			</p>
		<?php endif; ?>

		<fieldset class="slashphoto-checkout__address" data-slashphoto-address hidden>
			<legend><?php esc_html_e( 'Delivery address for prints', 'slashphoto' ); ?></legend>
			<?php if ( ! $slashphoto_countries ) : ?>
				<p class="slashphoto-checkout__notice"><?php esc_html_e( 'Prints cannot be sent at the moment. Remove them from the cart to order the rest.', 'slashphoto' ); ?></p>
			<?php endif; ?>
			<p class="slashphoto-field">
				<label for="slashphoto-line1"><?php esc_html_e( 'Street address', 'slashphoto' ); ?></label>
				<input type="text" id="slashphoto-line1" name="line1" autocomplete="address-line1" maxlength="200">
			</p>
			<p class="slashphoto-field">
				<label for="slashphoto-line2"><?php esc_html_e( 'Apartment, c/o (optional)', 'slashphoto' ); ?></label>
				<input type="text" id="slashphoto-line2" name="line2" autocomplete="address-line2" maxlength="200">
			</p>
			<p class="slashphoto-field slashphoto-field--short">
				<label for="slashphoto-postcode"><?php esc_html_e( 'Postcode', 'slashphoto' ); ?></label>
				<input type="text" id="slashphoto-postcode" name="postcode" autocomplete="postal-code" maxlength="20">
			</p>
			<p class="slashphoto-field">
				<label for="slashphoto-city"><?php esc_html_e( 'City', 'slashphoto' ); ?></label>
				<input type="text" id="slashphoto-city" name="city" autocomplete="address-level2" maxlength="100">
			</p>
			<p class="slashphoto-field">
				<label for="slashphoto-country"><?php esc_html_e( 'Country', 'slashphoto' ); ?></label>
				<select id="slashphoto-country" name="country" autocomplete="country">
					<?php foreach ( $slashphoto_countries as $slashphoto_code ) : ?>
						<option value="<?php echo esc_attr( $slashphoto_code ); ?>"><?php echo esc_html( $slashphoto_names ? (string) locale_get_display_region( '-' . $slashphoto_code, determine_locale() ) : $slashphoto_code ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		</fieldset>

		<p class="slashphoto-field">
			<label for="slashphoto-note"><?php esc_html_e( 'Message to the photographer (optional)', 'slashphoto' ); ?></label>
			<textarea id="slashphoto-note" name="note" rows="3" maxlength="2000"></textarea>
		</p>

		<p class="slashphoto-checkout__website" aria-hidden="true">
			<label for="slashphoto-website">Website</label>
			<input type="text" id="slashphoto-website" name="website" tabindex="-1" autocomplete="off">
		</p>

		<fieldset class="slashphoto-checkout__gateways" data-slashphoto-gateways>
			<legend><?php esc_html_e( 'Payment', 'slashphoto' ); ?></legend>
			<?php if ( ! $slashphoto_gateways ) : ?>
				<p class="slashphoto-checkout__notice"><?php esc_html_e( 'Ordering is not open at the moment.', 'slashphoto' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $slashphoto_gateways as $slashphoto_index => $slashphoto_gateway ) : ?>
				<p class="slashphoto-checkout__gateway">
					<input type="radio" id="slashphoto-gateway-<?php echo esc_attr( $slashphoto_gateway['id'] ); ?>" name="gateway" value="<?php echo esc_attr( $slashphoto_gateway['id'] ); ?>" <?php checked( 0, $slashphoto_index ); ?>>
					<label for="slashphoto-gateway-<?php echo esc_attr( $slashphoto_gateway['id'] ); ?>">
						<strong><?php echo esc_html( $slashphoto_gateway['title'] ); ?></strong>
						<span class="slashphoto-checkout__hint"><?php echo esc_html( $slashphoto_gateway['description'] ); ?></span>
					</label>
				</p>
			<?php endforeach; ?>
		</fieldset>

		<?php if ( $slashphoto_terms ) : ?>
			<p class="slashphoto-checkout__terms">
				<input type="checkbox" id="slashphoto-terms" name="terms" value="1" required>
				<label for="slashphoto-terms">
					<?php
					printf(
						/* translators: %s: link to the terms of purchase */
						esc_html__( 'I accept the %s', 'slashphoto' ),
						'<a href="' . esc_url( (string) get_permalink( $slashphoto_terms ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'terms of purchase', 'slashphoto' ) . '</a>'
					);
					?>
				</label>
			</p>
		<?php endif; ?>

		<p class="slashphoto-checkout__error" data-slashphoto-checkout-error role="alert" hidden></p>
		<p><button type="submit" class="slashphoto-checkout__submit wp-element-button" <?php disabled( ! $slashphoto_gateways ); ?>><?php esc_html_e( 'Place order', 'slashphoto' ); ?></button></p>
		<p class="slashphoto-checkout__privacy"><?php esc_html_e( 'We store your details with the order only to deliver it and for our bookkeeping. Orders that are never paid are deleted after a week.', 'slashphoto' ); ?></p>
	</form>
</div>
