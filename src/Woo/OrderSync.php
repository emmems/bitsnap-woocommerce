<?php
/**
 * Pulls the payment state of an order from Bitsnap and applies it.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Mapping\ApiOrder;
use Bitsnap\WooCommerce\Plugin;
use Bitsnap\WooCommerce\Support\Logger;
use Bitsnap\WooCommerce\Webhook\EventPlanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared by the return page and the reconcile job: `OrdersService.GetOrder` by
 * `clientReferenceId` (section 9.2), then the same planner as the webhook.
 */
final class OrderSync {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Order updater.
	 *
	 * @var OrderUpdater
	 */
	private $updater;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin  Plugin container.
	 * @param OrderUpdater $updater Order updater.
	 * @param Logger       $logger  Logger.
	 */
	public function __construct( Plugin $plugin, OrderUpdater $updater, Logger $logger ) {
		$this->plugin  = $plugin;
		$this->updater = $updater;
		$this->logger  = $logger;
	}

	/**
	 * Checks the payment of one order.
	 *
	 * @param \WC_Order $order  Order started by this plugin.
	 * @param string    $source 'return' or 'reconcile', for the log.
	 * @return string|null The Bitsnap payment state, or null if it could not be read.
	 */
	public function sync( \WC_Order $order, string $source ): ?string {
		$environment = (string) $order->get_meta( Meta::ENVIRONMENT );
		if ( ! in_array( $environment, array( 'test', 'live' ), true ) || ! $this->plugin->credentials()->is_configured( $environment ) ) {
			return null;
		}

		try {
			$response = $this->plugin->client( $environment )->call(
				'OrdersService',
				'GetOrder',
				array( 'clientReferenceId' => (string) $order->get_id() )
			);
		} catch ( ApiException $e ) {
			$this->logger->error( sprintf( 'Could not read the payment of order %d (%s): %s', $order->get_id(), $source, $e->getMessage() ) );
			return null;
		}

		$api_order = ApiOrder::normalize( $response['order'] ?? array() );

		$reference = $api_order['client_reference_id'];
		$key       = $api_order['metadata']['wcOrderKey'] ?? '';
		if ( '' === $reference || $reference !== (string) $order->get_id() || ! hash_equals( $order->get_order_key(), $key ) ) {
			$this->logger->error( 'GetOrder returned an order that does not match WooCommerce order ' . $order->get_id() . '. Ignored.' );
			return null;
		}

		$actions = EventPlanner::plan_for_state( $api_order['payment_state'], $api_order, $this->updater->context( $order ) );
		$this->updater->apply( $order, $actions, $api_order, $source );

		return $api_order['payment_state'];
	}
}
