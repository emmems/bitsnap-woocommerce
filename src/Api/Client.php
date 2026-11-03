<?php
/**
 * Thin Connect JSON client for the Bitsnap API.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Api;

use Bitsnap\WooCommerce\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Exception messages are logged and shown to administrators in order notes (escaped there). They are never printed raw.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Calls `POST {base}/api/v1/bitsnap.v1.<Service>/<Method>` with a JSON body and a Bearer key.
 */
class Client {

	/**
	 * Proto package of the services.
	 */
	private const PACKAGE = 'bitsnap.v1';

	/**
	 * Base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * API key (`bs_test_`, `bs_live_`) or OAuth token (`bsat_`).
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Base URL such as https://bitsnap.pl.
	 * @param string $token    API key or token.
	 * @param Logger $logger   Logger.
	 * @param int    $timeout  Timeout in seconds.
	 */
	public function __construct( string $base_url, string $token, Logger $logger, int $timeout = 20 ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->token    = $token;
		$this->logger   = $logger;
		$this->timeout  = $timeout;
	}

	/**
	 * Calls a method.
	 *
	 * A call with an idempotency key is repeated once after a transport failure or a 5xx
	 * answer. The key makes that safe.
	 *
	 * @param string              $service         Service name, for example `CheckoutService`.
	 * @param string              $method          Method name, for example `CreateCheckoutSession`.
	 * @param array<string,mixed> $body            Request message.
	 * @param string|null         $idempotency_key `Idempotency-Key` header for write calls.
	 * @return array<string,mixed> Decoded response message.
	 * @throws ApiException When the call fails.
	 */
	public function call( string $service, string $method, array $body, ?string $idempotency_key = null ): array {
		$attempts = null === $idempotency_key ? 1 : 2;
		$error    = null;
		for ( $i = 1; $i <= $attempts; $i++ ) {
			try {
				return $this->send( $service, $method, $body, $idempotency_key );
			} catch ( ApiException $e ) {
				$error = $e;
				if ( ! $e->is_retryable() ) {
					break;
				}
				$this->logger->debug( 'Retryable error, attempt ' . $i . ' of ' . $attempts, array( 'error' => $e->getMessage() ) );
			}
		}
		throw $error; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
	}

	/**
	 * Sends one request.
	 *
	 * @param string              $service         Service name.
	 * @param string              $method          Method name.
	 * @param array<string,mixed> $body            Request message.
	 * @param string|null         $idempotency_key Idempotency key.
	 * @return array<string,mixed>
	 * @throws ApiException When the call fails.
	 */
	private function send( string $service, string $method, array $body, ?string $idempotency_key ): array {
		$url     = sprintf( '%s/api/v1/%s.%s/%s', $this->base_url, self::PACKAGE, $service, $method );
		$headers = array(
			'Authorization'            => 'Bearer ' . $this->token,
			'Content-Type'             => 'application/json',
			'Accept'                   => 'application/json',
			'Connect-Protocol-Version' => '1',
			'User-Agent'               => $this->user_agent(),
		);
		if ( null !== $idempotency_key && '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$this->logger->debug(
			'Request ' . $service . '/' . $method,
			array(
				'url'             => $url,
				'idempotency_key' => $idempotency_key,
				'summary'         => $this->summarize( $body ),
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $this->timeout,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => empty( $body ) ? '{}' : wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Transport error calling ' . $service . '/' . $method . ': ' . $response->get_error_message() );
			throw new ApiException( $response->get_error_message(), 0, 'network' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$this->logger->debug( 'Response ' . $status . ' from ' . $service . '/' . $method, array( 'body' => $raw ) );

		if ( $status >= 200 && $status < 300 ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				if ( '' === trim( $raw ) ) {
					return array();
				}
				throw new ApiException( 'The Bitsnap API returned a response that is not JSON.', $status, 'internal' );
			}
			return $decoded;
		}

		$error = ErrorMapper::parse( $raw );
		$text  = '' !== $error['message'] ? $error['message'] : 'HTTP ' . $status;
		$this->logger->error(
			sprintf( '%s/%s failed: HTTP %d %s %s', $service, $method, $status, $error['code'], $error['reason'] ) . ' ' . $text
		);
		throw new ApiException( $text, $status, $error['code'], $error['reason'], $error['metadata'] );
	}

	/**
	 * User-Agent header value.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		global $wp_version;
		$wc = defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown';
		return sprintf(
			'BitsnapForWooCommerce/%s WooCommerce/%s WordPress/%s; %s',
			defined( 'BITSNAP_WC_VERSION' ) ? BITSNAP_WC_VERSION : 'dev',
			$wc,
			isset( $wp_version ) ? $wp_version : 'unknown',
			home_url()
		);
	}

	/**
	 * A short description of a request body for the debug log. It leaves out buyer data.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return array<string,mixed>
	 */
	private function summarize( array $body ): array {
		$summary = array();
		foreach ( $body as $key => $value ) {
			if ( 'buyer' === $key ) {
				$summary[ $key ] = '(omitted)';
			} elseif ( 'lines' === $key && is_array( $value ) ) {
				$summary[ $key ] = count( $value ) . ' lines';
			} else {
				$summary[ $key ] = $value;
			}
		}
		return $summary;
	}
}
