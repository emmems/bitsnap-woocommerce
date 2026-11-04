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
		return is_array( $value ) ? $value : array();
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
		$all         = $this->all();
		$all[ $key ] = $this->secrets->encrypt( $plain );
		update_option( self::OPTION, $all );
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
