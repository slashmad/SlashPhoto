<?php
/**
 * Encrypted storage of API keys.
 *
 * @package SlashPhoto
 */

namespace SlashPhoto;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets (API keys, webhook secrets) before they are stored in
 * the options table, with a key derived from the site's secret salts, so a
 * database dump alone does not reveal them.
 *
 * Stored format: "s:" + base64(nonce + secretbox), or "p:" + base64(plain)
 * on the rare PHP build without sodium.
 */
final class Secrets {

	/**
	 * Encrypts a secret.
	 *
	 * @param string $plain   Secret.
	 * @param string $context What the secret is for; part of the key.
	 */
	public static function encrypt( string $plain, string $context ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'p:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding, not obfuscation.
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 's:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key( $context ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding of ciphertext.
	}

	/**
	 * Decrypts a stored secret, or returns '' when it cannot be read (for
	 * example after the site's salts changed).
	 *
	 * @param string $stored  Stored value.
	 * @param string $context The context it was encrypted with.
	 */
	public static function decrypt( string $stored, string $context ): string {
		$raw = base64_decode( substr( $stored, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding.
		if ( false === $raw || '' === $raw ) {
			return '';
		}
		if ( str_starts_with( $stored, 'p:' ) ) {
			return $raw;
		}
		if ( ! str_starts_with( $stored, 's:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key( $context ) );
		return false === $plain ? '' : $plain;
	}

	/**
	 * 32-byte key for a context.
	 *
	 * @param string $context Context.
	 */
	private static function key( string $context ): string {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|slashphoto-' . $context, true );
	}
}
