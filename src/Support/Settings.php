<?php
/**
 * Access to the gateway settings option.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes `woocommerce_bitsnap_settings`, the option WooCommerce keeps for the gateway.
 * Secret fields are stored encrypted and decrypted here.
 */
final class Settings {

	/**
	 * Option name (`woocommerce_<gateway id>_settings`).
	 */
	public const OPTION = 'woocommerce_bitsnap_settings';

	/**
	 * Keys that hold secrets.
	 */
	public const SECRET_KEYS = array( 'api_key_test', 'api_key_live', 'webhook_secret_test', 'webhook_secret_live', 'oauth_test_access', 'oauth_test_refresh', 'oauth_live_access', 'oauth_live_refresh' );

	/**
	 * Encryption helper.
	 *
	 * @var Secrets
	 */
	private $secrets;

	/**
	 * Constructor.
	 *
	 * @param Secrets $secrets Encryption helper.
	 */
	public function __construct( Secrets $secrets ) {
		$this->secrets = $secrets;
	}

	/**
	 * The encryption helper.
	 *
	 * @return Secrets
	 */
	public function secrets(): Secrets {
		return $this->secrets;
	}

	/**
	 * All stored settings (secrets still encrypted).
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		foreach ( array( 'test', 'live' ) as $environment ) {
			$tokens = get_option( 'bitsnap_oauth_tokens_' . $environment, null );
			if ( is_array( $tokens ) ) {
				foreach ( array_keys( $value ) as $key ) {
					if ( 0 === strpos( $key, 'oauth_' . $environment . '_' ) ) {
						unset( $value[ $key ] );
					}
				}
				$value = array_merge( $value, $tokens );
			}
		}
		return $value;
	}

	/**
	 * Reads a plain setting.
	 *
	 * @param string $key     Setting key.
	 * @param string $fallback Value when unset.
	 * @return string
	 */
	public function get( string $key, string $fallback = '' ): string {
		$all = $this->all();
		return isset( $all[ $key ] ) && is_scalar( $all[ $key ] ) ? (string) $all[ $key ] : $fallback;
	}

	/**
	 * Reads a yes/no setting.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_yes( string $key ): bool {
		return 'yes' === $this->get( $key );
	}

	/**
	 * Reads a secret setting, decrypted.
	 *
	 * @param string $key Setting key such as `api_key_test`.
	 * @return string Plain text, or '' when unset or when it cannot be decrypted.
	 */
	public function get_secret( string $key ): string {
		$plain = $this->secrets->decrypt( $this->get( $key ) );
		return null === $plain ? '' : $plain;
	}

	/**
	 * Whether a secret is stored but cannot be decrypted (the site salts changed).
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_secret_broken( string $key ): bool {
		$stored = $this->get( $key );
		return '' !== $stored && null === $this->secrets->decrypt( $stored );
	}

	/**
	 * Stores a secret, encrypted.
	 *
	 * @param string $key   Setting key.
	 * @param string $plain Plain text.
	 * @return void
	 */
	public function set_secret( string $key, string $plain ): void {
		$this->update( array(), array( $key => $plain ) );
	}

	/**
	 * Changes several settings in one write.
	 *
	 * One `update_option` means a reader never sees half of a change, which matters for a rotated token pair.
	 *
	 * @param array<string,string> $values  Plain settings to set.
	 * @param array<string,string> $secrets Secret settings to set, as plain text. They are encrypted here. An empty value is skipped.
	 * @param string[]             $remove  Keys to remove.
	 * @return void
	 */
	public function update( array $values, array $secrets = array(), array $remove = array() ): void {
		foreach ( array( 'test', 'live' ) as $environment ) {
			$prefix = 'oauth_' . $environment . '_';
			$keys   = array_filter(
				array_unique( array_merge( array_keys( $values ), array_keys( $secrets ), $remove ) ),
				static function ( $key ) use ( $prefix ) {
					return 0 === strpos( $key, $prefix );
				}
			);
			if ( array() === $keys ) {
				continue;
			}
			$lock = Lock::owned( 'tokens:' . $environment ) ? null : new Lock( 'tokens:' . $environment );
			try {
				$this->reload();
				$all    = $this->all();
				$tokens = array_filter(
					$all,
					static function ( $key ) use ( $prefix ) {
						return 0 === strpos( $key, $prefix );
					},
					ARRAY_FILTER_USE_KEY
				);
				foreach ( $keys as $key ) {
					if ( isset( $values[ $key ] ) ) {
						$tokens[ $key ] = $values[ $key ];
					}
					if ( ! empty( $secrets[ $key ] ) ) {
						$tokens[ $key ] = $this->seal( $secrets[ $key ] );
					}
					if ( in_array( $key, $remove, true ) ) {
						unset( $tokens[ $key ] );
					}
					unset( $values[ $key ], $secrets[ $key ] );
				}
				$remove = array_values( array_diff( $remove, $keys ) );
				update_option( 'bitsnap_oauth_tokens_' . $environment, $tokens, false );
			} finally {
				if ( null !== $lock ) {
					$lock->release();
				}
			}
		}
		if ( array() === $values && array() === $secrets && array() === $remove ) {
			return;
		}
		$lock = new Lock( 'settings' );
		try {
			$this->reload();
			$all = get_option( self::OPTION, array() );
			foreach ( $values as $key => $value ) {
				$all[ $key ] = $value;
			}
			foreach ( $secrets as $key => $plain ) {
				if ( '' !== $plain ) {
					$all[ $key ] = $this->seal( $plain );
				}
			}
			foreach ( $remove as $key ) {
				unset( $all[ $key ] );
			}
			// Once migrated, legacy copies must never be used or retained.
			foreach ( array_keys( $all ) as $key ) {
				if ( preg_match( '/^oauth_(test|live)_/', $key, $match ) && is_array( get_option( 'bitsnap_oauth_tokens_' . $match[1], null ) ) ) {
					unset( $all[ $key ] );
				}
			}
			update_option( self::OPTION, $all );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Drops the cached copy of the option, so the next read comes from the database. Another request may have
	 * changed the option after this request loaded it.
	 *
	 * @return void
	 */
	public function reload(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'bitsnap_oauth_tokens_test', 'options' );
		wp_cache_delete( 'bitsnap_oauth_tokens_live', 'options' );
	}

	/**
	 * Encrypts a value for storage, for the settings form.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public function seal( string $plain ): string {
		return $this->secrets->encrypt( $plain );
	}
}
