<?php
/**
 * Privacy policy text.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests privacy policy text (Settings > Privacy) describing exactly what
 * SlashPhoto stores: no visitor logging, no tracking, two functional cookies.
 */
final class Privacy {

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	/**
	 * Adds the suggested text.
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text from SlashPhoto. It describes the plugin as shipped; review it if you add other plugins.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Our photo galleries do not log visits, IP addresses or browser details, do not use analytics or tracking, and do not load anything from other websites.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you open a password-protected gallery, WordPress stores a cookie (wp-postpass) containing a scrambled form of the password, for up to 10 days.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you accept a gallery’s terms, a cookie (slashphoto_terms_…) remembers this in your browser for 30 days. It contains only a signature of the gallery and the terms wording; nothing about you is stored on our server.', 'slashphoto' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If we give you a client account, your name, email address and the galleries you may see are stored in that account until you ask us to delete it.', 'slashphoto' ) . '</p>';
		wp_add_privacy_policy_content( 'SlashPhoto', wp_kses_post( $text ) );
	}
}
