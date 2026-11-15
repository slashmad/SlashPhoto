<?php
/**
 * Stored SnapOtter connection.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto\SnapOtter;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Address and API key of the photographer's own SnapOtter server.
 *
 * Only an API key is stored, never a password. When the photographer signs
 * in with a user name and password, SlashPhoto uses them once to create its
 * own API key and forgets them. The key is encrypted with a key derived from
 * the site's secret salts (wp-config.php), so a copy of the database alone
 * does not reveal it.
 */
final class Connection {

	public const OPTION   = 'slashphoto_snapotter';
	public const KEY_NAME = 'SlashPhoto';

	/**
	 * Stored data: url, key (encrypted), key_id (when SlashPhoto created
	 * the key), version, checked (Unix time of the last successful test).
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$data = get_option( self::OPTION, [] );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Whether an address and key are saved.
	 */
	public static function is_configured(): bool {
		$data = self::data();
		return ! empty( $data['url'] ) && '' !== self::key();
	}

	/**
	 * The server address without trailing slash.
	 */
	public static function url(): string {
		return untrailingslashit( (string) ( self::data()['url'] ?? '' ) );
	}

	/**
	 * The decrypted API key, or '' (also when the site salts have changed).
	 */
	public static function key(): string {
		$stored = (string) ( self::data()['key'] ?? '' );
		return '' === $stored ? '' : self::decrypt( $stored );
	}

	/**
	 * A client for the saved connection.
	 */
	public static function client(): ?Client {
		return self::is_configured() ? new Client( self::url(), self::key() ) : null;
	}

	/**
	 * Normalises a server address: http(s) only, no query or fragment.
	 *
	 * @param string $url Address as typed.
	 * @return string|WP_Error
	 */
	public static function normalize_url( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return new WP_Error( 'slashphoto_snapotter_url', __( 'Enter the full address of your SnapOtter server, for example http://192.168.1.10:1349.', 'slashphoto' ) );
		}
		$normalized = strtolower( $parts['scheme'] ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( $parts['path'] ?? '' );
		return untrailingslashit( esc_url_raw( $normalized ) );
	}

	/**
	 * Connects with a user name and password: signs in once, creates an API
	 * key named "SlashPhoto" and stores only that key.
	 *
	 * @param string $url      Server address.
	 * @param string $username User name.
	 * @param string $password Password (not stored).
	 * @return true|WP_Error
	 */
	public static function connect_with_login( string $url, string $username, string $password ) {
		$url = self::normalize_url( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$client = new Client( $url );
		$token  = $client->login( $username, $password );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$key = ( new Client( $url, $token ) )->create_key( self::KEY_NAME . ' (' . wp_parse_url( home_url(), PHP_URL_HOST ) . ')' );
		( new Client( $url, $token ) )->logout();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return self::save( $url, $key['key'], $key['id'] );
	}

	/**
	 * Connects with an API key created in SnapOtter (Settings → API Keys).
	 *
	 * @param string $url Server address.
	 * @param string $key API key.
	 * @return true|WP_Error
	 */
	public static function connect_with_key( string $url, string $key ) {
		$url = self::normalize_url( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$key = trim( $key );
		if ( ! preg_match( '/^[A-Za-z0-9_\-.]{16,512}$/', $key ) ) {
			return new WP_Error( 'slashphoto_snapotter_key', __( 'That does not look like a SnapOtter API key.', 'slashphoto' ) );
		}
		return self::save( $url, $key, '' );
	}

	/**
	 * Tests the key and stores the connection.
	 *
	 * @param string $url    Server address.
	 * @param string $key    API key.
	 * @param string $key_id ID of a key SlashPhoto created, or ''.
	 * @return true|WP_Error
	 */
	private static function save( string $url, string $key, string $key_id ) {
		$client  = new Client( $url, $key );
		$version = $client->check();
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		update_option(
			self::OPTION,
			[
				'url'     => $url,
				'key'     => self::encrypt( $key ),
				'key_id'  => $key_id,
				'version' => $version,
				'checked' => time(),
			],
			false
		);
		return true;
	}

	/**
	 * Re-tests the saved connection and records the result.
	 *
	 * @return string|WP_Error SnapOtter version.
	 */
	public static function test() {
		$client = self::client();
		if ( ! $client ) {
			return new WP_Error( 'slashphoto_snapotter_off', __( 'SnapOtter is not connected.', 'slashphoto' ) );
		}
		$version = $client->check();
		if ( ! is_wp_error( $version ) ) {
			$data            = self::data();
			$data['version'] = $version;
			$data['checked'] = time();
			update_option( self::OPTION, $data, false );
		}
		return $version;
	}

	/**
	 * Forgets the connection, deleting the API key on the server when
	 * SlashPhoto created it.
	 */
	public static function disconnect(): void {
		$data   = self::data();
		$client = self::client();
		if ( $client && ! empty( $data['key_id'] ) ) {
			$client->delete_key( (string) $data['key_id'] );
		}
		delete_option( self::OPTION );
	}

	/**
	 * Encrypts with a key derived from the site's salts.
	 *
	 * @param string $plain Secret.
	 */
	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'p:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding, not obfuscation.
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 's:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding of ciphertext.
	}

	/**
	 * Decrypts a stored secret, or returns '' when it cannot be read.
	 *
	 * @param string $stored Stored value.
	 */
	private static function decrypt( string $stored ): string {
		$raw = base64_decode( substr( $stored, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding.
		if ( false === $raw ) {
			return '';
		}
		if ( str_starts_with( $stored, 'p:' ) ) {
			return $raw;
		}
		if ( ! str_starts_with( $stored, 's:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::secret() );
		return false === $plain ? '' : $plain;
	}

	/**
	 * 32-byte key from the site's secret salts.
	 */
	private static function secret(): string {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|slashphoto-snapotter', true );
	}
}
