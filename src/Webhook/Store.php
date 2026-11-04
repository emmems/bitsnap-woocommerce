<?php
/**
 * Where the webhook of an environment is remembered.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remembers the Bitsnap webhook id and its signing secret for each environment.
 */
interface Store {

	/**
	 * Id of the webhook this site created in Bitsnap.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return string '' when none is stored.
	 */
	public function id( string $environment ): string;

	/**
	 * Remembers a webhook. An empty id leaves any stored id as it was.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param string $id          Webhook id, '' if the API did not return one.
	 * @param string $secret      Signing secret (`whsec_`).
	 * @return void
	 */
	public function save( string $environment, string $id, string $secret ): void;

	/**
	 * Forgets the webhook id and secret of one environment.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return void
	 */
	public function clear( string $environment ): void;
}
