<?php
/**
 * Where the plugin gets its Bitsnap credentials from.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce;

use Bitsnap\WooCommerce\OAuth\SettingsTokenStore;
use Bitsnap\WooCommerce\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential provider.
 *
 * Two modes, chosen for each environment on its own:
 *
 * - `oauth`: the merchant used "Connect with Bitsnap". The `bsat_` access token is the API credential.
 * - `manual`: the merchant pasted an API key (`bs_test_`, `bs_live_`).
 *
 * OAuth takes precedence when both exist. Both modes use the same webhook secret field, which "Connect with
 * Bitsnap" and "Register webhook" fill. Everything else in the plugin asks this class and never reads the
 * settings itself.
 */
final class Credentials {

	public const MODE_MANUAL = 'manual';

	public const MODE_OAUTH = 'oauth';

	public const ENV_TEST = 'test';

	public const ENV_LIVE = 'live';

	/**
	 * Default API base URL.
	 */
	public const DEFAULT_BASE_URL = 'https://bitsnap.pl';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * How credentials are supplied for an environment.
	 *
	 * @param string|null $environment 'test', 'live', or null for the current one.
	 * @return string One of the `MODE_*` constants.
	 */
	public function mode( ?string $environment = null ): string {
		return '' !== $this->oauth_token( $this->env( $environment ) ) ? self::MODE_OAUTH : self::MODE_MANUAL;
	}

	/**
	 * The environment new payments use.
	 *
	 * @return string 'test' or 'live'.
	 */
	public function environment(): string {
		return $this->settings->is_yes( 'testmode' ) ? self::ENV_TEST : self::ENV_LIVE;
	}

	/**
	 * API credential for an environment: the OAuth access token when the environment is connected, otherwise the
	 * manual API key. The OAuth client keeps the token fresh; this returns what is stored.
	 *
	 * @param string|null $environment 'test', 'live', or null for the current one.
	 * @return string '' when not set.
	 */
	public function api_key( ?string $environment = null ): string {
		$env   = $this->env( $environment );
		$token = $this->oauth_token( $env );
		return '' !== $token ? $token : $this->settings->get_secret( 'api_key_' . $env );
	}

	/**
	 * Webhook signing secret for an environment.
	 *
	 * @param string|null $environment 'test', 'live', or null for the current one.
	 * @return string '' when not set.
	 */
	public function webhook_secret( ?string $environment = null ): string {
		return $this->settings->get_secret( 'webhook_secret_' . $this->env( $environment ) );
	}

	/**
	 * All webhook secrets that are set, by environment.
	 *
	 * @return array<string,string>
	 */
	public function webhook_secrets(): array {
		$out = array();
		foreach ( array( self::ENV_TEST, self::ENV_LIVE ) as $environment ) {
			$secret = $this->webhook_secret( $environment );
			if ( '' !== $secret ) {
				$out[ $environment ] = $secret;
			}
		}
		return $out;
	}

	/**
	 * Whether an API key is set for an environment.
	 *
	 * @param string|null $environment 'test', 'live', or null for the current one.
	 * @return bool
	 */
	public function is_configured( ?string $environment = null ): bool {
		return '' !== $this->api_key( $environment );
	}

	/**
	 * API base URL, without a trailing slash.
	 *
	 * @return string
	 */
	public function base_url(): string {
		$url = trim( $this->settings->get( 'api_base_url' ) );
		return rtrim( '' === $url ? self::DEFAULT_BASE_URL : $url, '/' );
	}

	/**
	 * The stored OAuth access token of an environment.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return string '' when the environment is not connected.
	 */
	private function oauth_token( string $environment ): string {
		return $this->settings->get_secret( SettingsTokenStore::key( $environment, 'access' ) );
	}

	/**
	 * Resolves an optional environment argument.
	 *
	 * @param string|null $environment Requested environment.
	 * @return string 'test' or 'live'.
	 */
	private function env( ?string $environment ): string {
		if ( self::ENV_TEST === $environment || self::ENV_LIVE === $environment ) {
			return $environment;
		}
		return $this->environment();
	}
}
