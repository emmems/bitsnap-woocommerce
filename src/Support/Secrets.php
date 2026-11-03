<?php
/**
 * Encrypts credentials stored in the database.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Support;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BITSNAP_WC_TESTING' ) ) {
	exit;
}

/**
 * Authenticated encryption (libsodium secretbox) with a key derived from the
 * site's salts.
 *
 * Stored values look like `bsenc1:<base64(nonce . ciphertext)>`. A value without
 * that prefix is read as plain text. That is the fallback when libsodium is
 * missing, and what an older install holds.
 *
 * This protects secrets in database dumps and backups. It does not protect
 * against someone who can read wp-config.php.
 */
final class Secrets {

	/**
	 * Prefix of an encrypted value.
	 */
	public const PREFIX = 'bsenc1:';

	/**
	 * Context string for key derivation.
	 */
	private const KDF_INFO = 'bitsnap-for-woocommerce:secrets:v1';

	/**
	 * Raw 32-byte key, or an empty string when sodium is unavailable.
	 *
	 * @var string
	 */
	private $key = '';

	/**
	 * Constructor.
	 *
	 * @param string $key_material Secret material such as AUTH_KEY . SECURE_AUTH_KEY.
	 */
	public function __construct( string $key_material ) {
		if ( self::is_available() && '' !== $key_material ) {
			$this->key = hash_hkdf( 'sha256', $key_material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KDF_INFO );
		}
	}

	/**
	 * Builds an instance from the WordPress salts.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		$material = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY' ) as $constant ) {
			if ( defined( $constant ) ) {
				$material .= (string) constant( $constant );
			}
		}
		return new self( $material );
	}

	/**
	 * Whether libsodium can be used.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * Whether this instance can encrypt.
	 *
	 * @return bool
	 */
	public function can_encrypt(): bool {
		return '' !== $this->key;
	}

	/**
	 * Encrypts a value. Returns it unchanged when encryption is not possible.
	 *
	 * @param string $plain Plain text.
	 * @return string Encrypted string, or the plain text as a fallback.
	 */
	public function encrypt( string $plain ): string {
		if ( '' === $plain || ! $this->can_encrypt() ) {
			return $plain;
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->key );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text for storage, not obfuscation.
	}

	/**
	 * Decrypts a stored value.
	 *
	 * @param string $stored Value from the database.
	 * @return string|null Plain text, or null if decryption fails (wrong key or damaged value).
	 */
	public function decrypt( string $stored ): ?string {
		if ( ! self::is_encrypted( $stored ) ) {
			return $stored;
		}
		if ( ! $this->can_encrypt() ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reads our own stored value.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );
		return false === $plain ? null : $plain;
	}

	/**
	 * Whether a stored value is in the encrypted format.
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function is_encrypted( string $stored ): bool {
		return 0 === strpos( $stored, self::PREFIX );
	}
}
