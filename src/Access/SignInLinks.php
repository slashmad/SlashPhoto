<?php
/**
 * One-time sign-in links for gallery clients.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\Access;

use SlashPhoto\Frontend\TemplateLoader;
use SlashPhoto\Gallery\PostType;
use SlashPhoto\Settings;
use WP_Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Lets clients sign in from an emailed link instead of a password.
 *
 * - Only accounts that cannot edit content get links: photographers and
 *   administrators keep their password (and any two-factor plugin).
 * - A link works once and expires. Only an HMAC of its random token is
 *   stored, in the client's user meta, and it is removed when used.
 * - Opening the link shows a confirmation page; signing in needs a click
 *   (POST), so mail scanners that open links do not use them up.
 * - A client can ask for a link on a client-only gallery. The answer is the
 *   same whether or not an account exists, and each account gets at most
 *   one link a minute. Nothing about the request is stored.
 */
final class SignInLinks {

	public const QUERY  = 'slashphoto_signin';
	public const SENT   = 'slashphoto_signin_sent';
	public const ACTION = 'slashphoto_signin_request';
	public const META   = '_slashphoto_signin_tokens';

	public const INVITE_TTL  = 7 * DAY_IN_SECONDS;
	public const REQUEST_TTL = HOUR_IN_SECONDS;

	private const MAX_TOKENS = 5;
	private const THROTTLE   = MINUTE_IN_SECONDS;

	/**
	 * Uses the access rules and the plugin's templates.
	 *
	 * @param Access         $access    Gallery access rules.
	 * @param TemplateLoader $templates Templates.
	 */
	public function __construct( private Access $access, private TemplateLoader $templates ) {}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'handle_request' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_request' ] );
		add_action( 'template_redirect', [ $this, 'handle_link' ], 0 );
	}

	/**
	 * Whether sign-in links are turned on.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'signin_links' );
	}

	/**
	 * Whether this account may sign in by link.
	 *
	 * @param WP_User $user Account.
	 */
	public static function eligible( WP_User $user ): bool {
		$allowed = $user->exists() && ! user_can( $user, 'edit_posts' ) && ! is_super_admin( $user->ID );

		/**
		 * Filters whether an account may sign in with an emailed link. Accounts
		 * that can edit content are refused by default.
		 *
		 * @param bool    $allowed Whether to allow it.
		 * @param WP_User $user    The account.
		 */
		return (bool) apply_filters( 'slashphoto_signin_link_allowed', $allowed, $user );
	}

	/**
	 * Creates a sign-in link, or '' when the account may not have one.
	 *
	 * @param WP_User      $user    Account.
	 * @param WP_Post|null $gallery Gallery to open after signing in.
	 * @param int          $ttl     Lifetime in seconds.
	 */
	public static function create( WP_User $user, ?WP_Post $gallery, int $ttl ): string {
		if ( ! self::eligible( $user ) ) {
			return '';
		}
		$token  = bin2hex( random_bytes( 24 ) );
		$tokens = self::tokens( $user->ID );
		// Keep the newest few, so old links stop working.
		$tokens = array_slice( $tokens, -( self::MAX_TOKENS - 1 ), null, true );

		$tokens[ self::hash( $token ) ] = [
			'expires' => time() + max( 60, $ttl ),
			'gallery' => $gallery ? $gallery->ID : 0,
		];
		update_user_meta( $user->ID, self::META, $tokens );
		return add_query_arg( self::QUERY, $user->ID . '-' . $token, home_url( '/' ) );
	}

	/**
	 * Valid, unexpired tokens of an account.
	 *
	 * @param int $user_id Account.
	 * @return array<string, array{expires: int, gallery: int}>
	 */
	private static function tokens( int $user_id ): array {
		$tokens = get_user_meta( $user_id, self::META, true );
		$valid  = [];
		foreach ( is_array( $tokens ) ? $tokens : [] as $hash => $token ) {
			if ( is_string( $hash ) && is_array( $token ) && (int) ( $token['expires'] ?? 0 ) > time() ) {
				$valid[ $hash ] = [
					'expires' => (int) $token['expires'],
					'gallery' => (int) ( $token['gallery'] ?? 0 ),
				];
			}
		}
		return $valid;
	}

	/**
	 * Stored form of a token.
	 *
	 * @param string $token Token from the link.
	 */
	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Checks a link value ("<user ID>-<token>").
	 *
	 * @param string $value   Link value.
	 * @param bool   $consume Remove the token when it is valid.
	 * @return array{user: WP_User, gallery: int}|null
	 */
	public static function verify( string $value, bool $consume ): ?array {
		if ( ! preg_match( '/^(\d{1,20})-([a-f0-9]{48})$/D', $value, $match ) ) {
			return null;
		}
		$user = get_userdata( (int) $match[1] );
		if ( ! $user || ! self::eligible( $user ) ) {
			return null;
		}
		$tokens = self::tokens( $user->ID );
		$hash   = self::hash( $match[2] );
		$found  = null;
		foreach ( $tokens as $stored => $token ) {
			if ( hash_equals( $stored, $hash ) ) {
				$found = $token;
			}
		}
		if ( null === $found ) {
			return null;
		}
		if ( $consume ) {
			unset( $tokens[ $hash ] );
			if ( $tokens ) {
				update_user_meta( $user->ID, self::META, $tokens );
			} else {
				delete_user_meta( $user->ID, self::META );
			}
		}
		return [
			'user'    => $user,
			'gallery' => $found['gallery'],
		];
	}

	/**
	 * Shows the confirmation page for a link, or signs in on its POST.
	 */
	public function handle_link(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the one-time token is the credential.
		$value = isset( $_REQUEST[ self::QUERY ] ) && is_string( $_REQUEST[ self::QUERY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::QUERY ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification
		if ( '' === $value ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$result = self::enabled() ? self::verify( $value, $post ) : null;
		if ( ! $result ) {
			status_header( 403 );
			echo $this->page( [ 'state' => 'invalid' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
			exit;
		}
		$target = $this->target( $result['gallery'] );
		if ( ! $post ) {
			$html = $this->page(
				[
					'state'  => 'confirm',
					'value'  => $value,
					'action' => home_url( '/' ),
					'name'   => $result['user']->display_name,
				]
			);
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
			exit;
		}

		$user = $result['user'];
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core login hook.
		wp_safe_redirect( $target, 303 );
		exit;
	}

	/**
	 * Where to go after signing in: the gallery while it exists, else home.
	 *
	 * @param int $gallery_id Gallery.
	 */
	private function target( int $gallery_id ): string {
		$gallery = $gallery_id ? get_post( $gallery_id ) : null;
		if ( $gallery && PostType::POST_TYPE === $gallery->post_type && 'publish' === $gallery->post_status ) {
			return (string) get_permalink( $gallery );
		}
		return home_url( '/' );
	}

	/**
	 * Renders the standalone confirmation page.
	 *
	 * @param array $args Template arguments.
	 */
	private function page( array $args ): string {
		return $this->templates->render(
			'signin.php',
			$args + [
				'site'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'login' => wp_login_url(),
			]
		);
	}

	/**
	 * Handles "email me a sign-in link" from a client-only gallery. Always
	 * answers the same way, so the form does not reveal who has an account.
	 */
	public function handle_request(): void {
		$gallery_id = isset( $_POST['gallery'] ) ? absint( $_POST['gallery'] ) : 0;
		$gallery    = $gallery_id ? get_post( $gallery_id ) : null;
		if ( ! $gallery || PostType::POST_TYPE !== $gallery->post_type || 'publish' !== $gallery->post_status ) {
			wp_safe_redirect( home_url( '/' ), 303 );
			exit;
		}
		$back = add_query_arg( self::SENT, '1', (string) get_permalink( $gallery ) );
		if ( ! check_ajax_referer( self::ACTION, '_wpnonce', false ) || ! self::enabled() ) {
			wp_safe_redirect( (string) get_permalink( $gallery ), 303 );
			exit;
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$this->send_requested( $email, $gallery );
		wp_safe_redirect( $back, 303 );
		exit;
	}

	/**
	 * Sends a requested link when the address belongs to an account that
	 * may open the gallery. Returns whether a message was sent (for tests;
	 * the visitor is never told).
	 *
	 * @param string  $email   Address typed by the visitor.
	 * @param WP_Post $gallery Gallery the form was on.
	 */
	public function send_requested( string $email, WP_Post $gallery ): bool {
		$user = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		if ( ! $user || ! self::eligible( $user ) || ! $this->access->account_allowed( $gallery, $user->ID ) || $this->access->expired( $gallery ) ) {
			return false;
		}
		$throttle = 'slashphoto_signin_' . $user->ID;
		if ( get_transient( $throttle ) ) {
			return false;
		}
		set_transient( $throttle, 1, self::THROTTLE );

		$url   = self::create( $user, $gallery, self::REQUEST_TTL );
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$title = trim( wp_strip_all_tags( wp_specialchars_decode( $gallery->post_title, ENT_QUOTES ), true ) );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Your sign-in link', 'slashphoto' ), $site );
		$body    = [
			/* translators: %s: gallery title */
			sprintf( __( 'Use this link to sign in and open %s. It works once, for one hour.', 'slashphoto' ), $title ),
			$url,
			__( 'If you did not ask for it, you can ignore this email.', 'slashphoto' ),
		];
		return wp_mail( $user->user_email, $subject, implode( "\n\n", $body ) );
	}

	/**
	 * Form data for the client-only gallery notice, or null when the form
	 * is not offered.
	 *
	 * @return array{action: string, nonce: string, sent: bool}|null
	 */
	public static function form(): ?array {
		if ( ! self::enabled() || is_user_logged_in() ) {
			return null;
		}
		return [
			'action' => admin_url( 'admin-post.php' ),
			'nonce'  => wp_create_nonce( self::ACTION ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message.
			'sent'   => isset( $_GET[ self::SENT ] ),
		];
	}
}
