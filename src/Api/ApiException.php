<?php
/**
 * Error from the Bitsnap API.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by {@see Client} when a call fails, in transport or with an error body.
 */
class ApiException extends \RuntimeException {

	/**
	 * Machine-readable reason such as `total_mismatch` or `missing_scope`. Empty if unknown.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Connect error code such as `invalid_argument`, or `network` for a transport failure.
	 *
	 * @var string
	 */
	private $connect_code;

	/**
	 * HTTP status, 0 for a transport failure.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Extra facts from `ErrorInfo.metadata`.
	 *
	 * @var array<string,string>
	 */
	private $metadata;

	/**
	 * Constructor.
	 *
	 * @param string               $message      Message (English, for logs and notes).
	 * @param int                  $http_status  HTTP status, 0 if none.
	 * @param string               $connect_code Connect code.
	 * @param string               $reason       `ErrorInfo.reason`.
	 * @param array<string,string> $metadata     `ErrorInfo.metadata`.
	 */
	public function __construct( string $message, int $http_status = 0, string $connect_code = '', string $reason = '', array $metadata = array() ) {
		parent::__construct( $message, $http_status );
		$this->http_status  = $http_status;
		$this->connect_code = $connect_code;
		$this->reason       = $reason;
		$this->metadata     = $metadata;
	}

	/**
	 * Machine-readable reason.
	 *
	 * @return string
	 */
	public function get_reason(): string {
		return $this->reason;
	}

	/**
	 * Connect code.
	 *
	 * @return string
	 */
	public function get_connect_code(): string {
		return $this->connect_code;
	}

	/**
	 * HTTP status.
	 *
	 * @return int
	 */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/**
	 * Metadata of the error.
	 *
	 * @return array<string,string>
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/**
	 * Whether repeating the same call may succeed (transport failure, 429 or 5xx).
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return 0 === $this->http_status || 429 === $this->http_status || $this->http_status >= 500;
	}
}
