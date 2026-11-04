<?php
/**
 * Webhook ids in the gateway settings.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Webhook;

use Bitsnap\WooCommerce\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@see Store} on the gateway settings option. The secret is stored encrypted as `webhook_secret_<env>`, the
 * same field the manual setup fills. The id is not a secret and is stored as `webhook_id_<env>`.
 */
final class SettingsStore implements Store {

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
	 * Id of the webhook this site created in Bitsnap.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return string
	 */
	public function id( string $environment ): string {
		return $this->settings->get( 'webhook_id_' . $environment );
	}

	/**
	 * Remembers a webhook.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param string $id          Webhook id.
	 * @param string $secret      Signing secret.
	 * @return void
	 */
	public function save( string $environment, string $id, string $secret ): void {
		$values = '' === $id ? array() : array( 'webhook_id_' . $environment => $id );
		$this->settings->update( $values, array( 'webhook_secret_' . $environment => $secret ) );
	}

	/**
	 * Forgets the webhook id and secret of one environment.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return void
	 */
	public function clear( string $environment ): void {
		$this->settings->update( array(), array(), array( 'webhook_id_' . $environment, 'webhook_secret_' . $environment ) );
	}
}
