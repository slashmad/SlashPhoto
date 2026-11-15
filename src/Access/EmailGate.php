<?php
/**
 * Email gate: visitors give an email address before seeing a gallery.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Access;

use SlashPhoto\Visitors\VisitorLog;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A gallery with the email gate on asks visitors for their email address
 * (with consent) before showing photos; sub-galleries share it unless
 * they switch it off. The address is stored in the visitor log and the
 * visitor's browser gets a signed cookie pointing at that entry, for 30
 * days. Logged-in visitors pass without the form; their account address is
 * logged once per gallery.
 *
 * Like terms, the gate only adds a step: it never opens a gallery the
 * visitor could not otherwise see.
 */
final class EmailGate {

	public const META          = '_slashphoto_email_gate';
	public const ACTION        = 'slashphoto_email_gate';
	public const COOKIE_PREFIX = 'slashphoto_email_';

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
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * The gallery whose gate applies to $gallery, or null.
	 *
	 * @param Access  $access  Access rules.
	 * @param WP_Post $gallery Gallery.
	 */
	public static function source( Access $access, WP_Post $gallery ): ?WP_Post {
		foreach ( $access->chain( $gallery ) as $post ) {
			$value = (string) get_post_meta( $post->ID, self::META, true );
			if ( 'on' === $value ) {
				return $post;
			}
			if ( 'off' === $value ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Whether the current visitor still has to give an email address.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function needs_email( WP_Post $gallery ): bool {
		$source = self::source( $this->access, $gallery );
		if ( ! $source || $this->access->is_editor( $gallery ) ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( $user->exists() ) {
			if ( ! VisitorLog::has_user_entry( 'email', $source->ID, $user->ID ) ) {
				VisitorLog::add( 'email', $source->ID, (string) $user->user_email );
			}
			return false;
		}
		return null === $this->entry_id( $source );
	}

	/**
	 * The email address the visitor gave for a gallery's gate, or ''.
	 *
	 * @param WP_Post $gallery Gallery.
	 */
	public function email( WP_Post $gallery ): string {
		$source = self::source( $this->access, $gallery );
		$id     = $source ? $this->entry_id( $source ) : null;
		$row    = $id ? VisitorLog::get( $id ) : null;
		return $row ? (string) $row['email'] : '';
	}

	/**
	 * The visitor log entry in the visitor's cookie, if the signature holds.
	 *
	 * @param WP_Post $source Gallery with the gate.
	 */
	private function entry_id( WP_Post $source ): ?int {
		$name  = self::COOKIE_PREFIX . $source->ID;
		$value = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		if ( ! preg_match( '/^(\d+)\.([a-f0-9]{64})$/', $value, $m ) ) {
			return null;
		}
		return hash_equals( self::sign( $source->ID, (int) $m[1] ), $m[2] ) ? (int) $m[1] : null;
	}

	/**
	 * Signature for a cookie.
	 *
	 * @param int $gallery_id Gallery with the gate.
	 * @param int $entry_id   Log entry.
	 */
	public static function sign( int $gallery_id, int $entry_id ): string {
		return hash_hmac( 'sha256', 'email-gate|' . $gallery_id . '|' . $entry_id, wp_salt( 'auth' ) );
	}

	/**
	 * Handles the form (admin-post.php).
	 */
	public function handle(): void {
		$gallery_id = isset( $_POST['gallery'] ) ? absint( $_POST['gallery'] ) : 0;
		$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$gallery    = $gallery_id ? get_post( $gallery_id ) : null;
		if ( ! $gallery || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $gallery_id ) ) {
			wp_die( esc_html__( 'The form has expired. Please go back, reload the page and try again.', 'slashphoto' ), '', [ 'response' => 400 ] );
		}
		$url    = (string) get_permalink( $gallery );
		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$source = self::source( $this->access, $gallery );

		if ( ! $source || ! $this->access->can_view( $gallery ) ) {
			$this->finish( $url );
		}
		if ( ! is_email( $email ) || empty( $_POST['consent'] ) ) {
			$this->finish( add_query_arg( 'slashphoto_email', 'invalid', $url ) );
		}
		$entry = VisitorLog::add( 'email', $source->ID, $email );
		if ( $entry ) {
			$this->set_cookie( $source->ID, $entry );
		}
		$this->finish( $url );
	}

	/**
	 * Sets the cookie.
	 *
	 * @param int $gallery_id Gallery with the gate.
	 * @param int $entry_id   Log entry.
	 */
	private function set_cookie( int $gallery_id, int $entry_id ): void {
		/** This filter is documented in src/Access/Terms.php */
		$lifetime = (int) apply_filters( 'slashphoto_terms_cookie_lifetime', 30 * DAY_IN_SECONDS );
		$name     = self::COOKIE_PREFIX . $gallery_id;
		$value    = $entry_id . '.' . self::sign( $gallery_id, $entry_id );

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
	 * Redirects and ends the request.
	 *
	 * @param string $url Target.
	 */
	private function finish( string $url ): void {
		/** This action is documented in src/Access/Terms.php */
		do_action( 'slashphoto_terms_handled', $url );
		wp_safe_redirect( $url );
		exit;
	}
}
