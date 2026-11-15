<?php
/**
 * Gallery terms the visitor must accept before seeing images.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Access;

use SlashPhoto\Settings;
use SlashPhoto\Visitors\VisitorLog;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Terms the visitor accepts before seeing a gallery.
 *
 * The visitor's browser remembers the acceptance in a cookie: an HMAC of
 * the gallery ID and the exact terms wording, signed with the site's auth
 * salt, so changing the wording asks everyone again. Each acceptance is
 * also logged (D-021): date, a fingerprint of the wording, and the account
 * or email address when known. Visitors who are not logged in are asked
 * for their email address when the setting says so. No IP address or user
 * agent is stored.
 *
 * Sub-galleries use the nearest gallery up the tree that has terms. The
 * email gate, when a gallery has one, comes before the terms.
 */
final class Terms {

	public const META_TERMS    = '_slashphoto_terms';
	public const ACTION        = 'slashphoto_accept_terms';
	public const COOKIE_PREFIX = 'slashphoto_terms_';

	/**
	 * Access rules.
	 *
	 * @var Access
	 */
	private Access $access;

	/**
	 * Email gate.
	 *
	 * @var EmailGate
	 */
	private EmailGate $gate;

	/**
	 * Constructor.
	 *
	 * @param Access         $access Access rules.
	 * @param EmailGate|null $gate   Email gate.
	 */
	public function __construct( Access $access, ?EmailGate $gate = null ) {
		$this->access = $access;
		$this->gate   = $gate ?? new EmailGate( $access );
	}

	/**
	 * The email gate.
	 */
	public function email_gate(): EmailGate {
		return $this->gate;
	}

	/**
	 * The step the visitor must take before seeing the gallery's photos:
	 * "email", "terms", or null when there is none.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function needs_step( WP_Post $gallery ): ?string {
		if ( $this->gate->needs_email( $gallery ) ) {
			return 'email';
		}
		return $this->needs_acceptance( $gallery ) ? 'terms' : null;
	}

	/**
	 * Whether the terms form should ask for an email address.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function asks_email( WP_Post $gallery ): bool {
		return Settings::get( 'terms_ask_email' ) && ! is_user_logged_in() && '' === $this->gate->email( $gallery );
	}

	/**
	 * Short fingerprint of a wording, stored with each acceptance.
	 *
	 * @param WP_Post $source Gallery that owns the terms.
	 */
	public static function fingerprint( WP_Post $source ): string {
		return substr( hash( 'sha256', self::text( $source ) ), 0, 16 );
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'handle_accept' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_accept' ] );
	}

	/**
	 * Returns the gallery whose terms apply to $gallery, or null.
	 *
	 * @param Access  $access  Access rules (for the ancestor chain).
	 * @param WP_Post $gallery Gallery.
	 */
	public static function source( Access $access, WP_Post $gallery ): ?WP_Post {
		foreach ( $access->chain( $gallery ) as $post ) {
			if ( '' !== trim( self::text( $post ) ) ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Returns the raw terms text of one gallery.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public static function text( WP_Post $gallery ): string {
		return (string) get_post_meta( $gallery->ID, self::META_TERMS, true );
	}

	/**
	 * Whether the current visitor still has to accept terms for $gallery.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function needs_acceptance( WP_Post $gallery ): bool {
		$source = self::source( $this->access, $gallery );
		if ( ! $source || $this->access->is_editor( $gallery ) ) {
			return false;
		}
		$cookie = self::COOKIE_PREFIX . $source->ID;
		$value  = isset( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '';
		return ! hash_equals( self::token( $source ), $value );
	}

	/**
	 * Signed token for the current wording of a gallery's terms.
	 *
	 * @param WP_Post $source Gallery that owns the terms.
	 */
	public static function token( WP_Post $source ): string {
		return hash_hmac( 'sha256', $source->ID . '|' . hash( 'sha256', self::text( $source ) ), wp_salt( 'auth' ) );
	}

	/**
	 * Handles the acceptance form (admin-post.php).
	 */
	public function handle_accept(): void {
		$gallery_id = isset( $_POST['gallery'] ) ? absint( $_POST['gallery'] ) : 0;
		$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$gallery    = $gallery_id ? get_post( $gallery_id ) : null;

		if ( ! $gallery || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $gallery_id ) ) {
			wp_die( esc_html__( 'The form has expired. Please go back, reload the page and try again.', 'slashphoto' ), '', [ 'response' => 400 ] );
		}

		// Terms never grant access on their own.
		if ( $this->access->can_view( $gallery ) && ! empty( $_POST['agree'] ) ) {
			$source = self::source( $this->access, $gallery );
			if ( $source ) {
				$user  = wp_get_current_user();
				$email = $user->exists() ? (string) $user->user_email : $this->gate->email( $gallery );
				if ( '' === $email && isset( $_POST['email'] ) ) {
					$email = sanitize_email( wp_unslash( $_POST['email'] ) );
				}
				if ( $this->asks_email( $gallery ) && ! is_email( $email ) ) {
					$this->finish( add_query_arg( 'slashphoto_terms', 'email', (string) get_permalink( $gallery ) ) );
				}
				VisitorLog::add( 'terms', $source->ID, is_email( $email ) ? $email : '', self::fingerprint( $source ) );
				$this->set_cookie( $source );
			}
		}

		$this->finish( (string) get_permalink( $gallery ) );
	}

	/**
	 * Sets the acceptance cookie.
	 *
	 * @param WP_Post $source Gallery that owns the terms.
	 */
	private function set_cookie( WP_Post $source ): void {
		/**
		 * Filters how long terms acceptance is remembered, in seconds.
		 *
		 * @param int $lifetime Default 30 days.
		 */
		$lifetime = (int) apply_filters( 'slashphoto_terms_cookie_lifetime', 30 * DAY_IN_SECONDS );
		$name     = self::COOKIE_PREFIX . $source->ID;
		$value    = self::token( $source );

		$_COOKIE[ $name ] = $value;
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			[
				'expires'  => time() + $lifetime,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/**
	 * Redirects back to the gallery and ends the request.
	 *
	 * @param string $url Gallery URL.
	 */
	private function finish( string $url ): void {
		/**
		 * Fires before the terms handler redirects and exits.
		 *
		 * @param string $url Redirect target.
		 */
		do_action( 'slashphoto_terms_handled', $url );
		wp_safe_redirect( $url );
		exit;
	}
}
