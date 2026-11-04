<?php
/**
 * Receives Bitsnap webhooks.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Webhook;

use Bitsnap\WooCommerce\Credentials;
use Bitsnap\WooCommerce\Support\Logger;
use Bitsnap\WooCommerce\Woo\Meta;
use Bitsnap\WooCommerce\Woo\OrderUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoint: `{site}/?wc-api=bitsnap` (hook `woocommerce_api_bitsnap`).
 *
 * Answers:
 *
 * - 200 `ok`: the event was applied.
 * - 200 `duplicate`: the event id was seen before.
 * - 200 `ignored`: the event is valid but not for this shop (not a WooCommerce order, or another site's order).
 * - 400 `invalid_signature`, `invalid_payload`, `not_configured`: Bitsnap should not retry these unchanged.
 * - 404 `unknown_order`: no matching order here. This is a definitive answer, so Bitsnap's retries are not wasted forever.
 * - 405: not a POST.
 * - 500: an unexpected error while applying the event. Bitsnap retries, and every action is safe to run twice.
 */
final class Handler {

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private $credentials;

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
	 * @param Credentials  $credentials Credentials.
	 * @param OrderUpdater $updater     Order updater.
	 * @param Logger       $logger      Logger.
	 */
	public function __construct( Credentials $credentials, OrderUpdater $updater, Logger $logger ) {
		$this->credentials = $credentials;
		$this->updater     = $updater;
		$this->logger      = $logger;
	}

	/**
	 * Registers the endpoint.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_api_bitsnap', array( $this, 'handle' ) );
	}

	/**
	 * Reads the request, answers it and stops.
	 *
	 * @return void
	 */
	public function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// The signature header, or the `sig` query parameter that carries the same value. A signature is not a nonce: it is checked below.
		$signature = '';
		if ( isset( $_SERVER['HTTP_X_CONTENT_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CONTENT_SIGNATURE'] ) );
		} elseif ( isset( $_GET['sig'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by HMAC.
			$signature = sanitize_text_field( wp_unslash( $_GET['sig'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$body = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The raw body is needed for the signature.

		$result = $this->process( $method, $body, $signature );

		nocache_headers();
		wp_send_json( array( 'status' => $result[1] ), $result[0] );
	}

	/**
	 * Processes one delivery.
	 *
	 * @param string $method    HTTP method.
	 * @param string $body      Raw body.
	 * @param string $signature Signature header.
	 * @return array{0:int,1:string} HTTP status and a short status word.
	 */
	public function process( string $method, string $body, string $signature ): array {
		if ( 'POST' !== strtoupper( $method ) ) {
			return array( 405, 'method_not_allowed' );
		}

		$secrets = $this->credentials->webhook_secrets();
		if ( array() === $secrets ) {
			$this->logger->error( 'A webhook arrived but no webhook secret is configured.' );
			return array( 400, 'not_configured' );
		}

		$environment = Signature::match_environment( $body, $signature, $secrets, Event::peek_environment( $body ) );
		if ( null === $environment ) {
			$this->logger->error( 'Webhook rejected: the signature does not match any configured secret.' );
			return array( 400, 'invalid_signature' );
		}

		$event = Event::parse( $body );
		if ( null === $event ) {
			$this->logger->error( 'Webhook rejected: the payload is not a valid event.' );
			return array( 400, 'invalid_payload' );
		}

		$api_order = $event['order'];
		$metadata  = $api_order['metadata'];
		if ( 'woocommerce' !== ( $metadata['source'] ?? '' ) || '' === $api_order['client_reference_id'] ) {
			// The project also receives events for orders made outside WooCommerce.
			return array( 200, 'ignored' );
		}
		$site = $metadata['siteUrl'] ?? '';
		if ( '' !== $site && 0 !== strcasecmp( untrailingslashit( $site ), untrailingslashit( home_url() ) ) ) {
			// Two shops can share one Bitsnap project, and each receives every event.
			return array( 200, 'ignored' );
		}

		$order = ctype_digit( $api_order['client_reference_id'] ) ? wc_get_order( (int) $api_order['client_reference_id'] ) : false;
		if ( ! $order instanceof \WC_Order
			|| '' === (string) $order->get_meta( Meta::ORDER_ID )
			|| $environment !== (string) $order->get_meta( Meta::ENVIRONMENT )
			|| ! hash_equals( $order->get_order_key(), (string) ( $metadata['wcOrderKey'] ?? '' ) ) ) {
			$this->logger->info( 'Webhook ' . $event['id'] . ' is for an unknown order (reference ' . $api_order['client_reference_id'] . ').' );
			return array( 404, 'unknown_order' );
		}

		if ( in_array( $event['id'], $this->updater->list_meta( $order, Meta::EVENTS ), true ) ) {
			return array( 200, 'duplicate' );
		}

		try {
			$context = $this->updater->context( $order );
			$actions = array_merge( EventPlanner::plan_invoice( $api_order, $context ), EventPlanner::plan( $event, $context ) );
			$this->updater->apply( $order, $actions, $api_order, 'webhook' );
			$this->updater->remember( $order, Meta::EVENTS, $event['id'] );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Webhook ' . $event['id'] . ' failed: ' . $e->getMessage() );
			return array( 500, 'error' );
		}

		$this->logger->info( 'Webhook ' . $event['id'] . ' (' . $event['type'] . ') applied to order ' . $order->get_id() . ': ' . count( $actions ) . ' action(s).' );
		return array( 200, 'ok' );
	}
}
