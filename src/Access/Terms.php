<?php
/**
 * Gallery terms the visitor must accept before seeing images.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Access;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Terms acceptance without server-side logging.
 *
 * Acceptance is stored only in a cookie on the visitor's own device: an HMAC
 * of the gallery ID and the exact terms wording, signed with the site's auth
 * salt. Nothing about the visitor (no IP address, user agent, email or time)
 * is stored on the server. Changing the wording invalidates earlier
 * acceptances automatically.
 *
 * Sub-galleries use the nearest gallery up the tree that has terms.
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
	 * Constructor.
	 *
	 * @param Access $access Access rules.
	 */
	public function __construct( Access $access ) {
		$this->access = $access;
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
