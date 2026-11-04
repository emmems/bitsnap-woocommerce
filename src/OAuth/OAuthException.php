<?php
/**
 * Error of the OAuth flow.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The message is a machine code, never output. Admin\OAuthMessages turns the error into text.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Thrown by {@see Service}. It carries a short error name and never a response body, so a secret cannot leak
 * through an exception message.
 *
 * Names: `insecure_url`, `invalid_state`, `access_denied`, `registration_failed`, `network`, `server_error`,
 * `invalid_response`, `invalid_grant`, `invalid_client`, `not_connected`, `busy`, or the OAuth error code
 * the server sent.
 */
final class OAuthException extends \RuntimeException {

	/**
	 * Short error name.
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Constructor.
	 *
	 * @param string $error  Short error name.
	 * @param int    $status HTTP status, 0 if none.
	 */
	public function __construct( string $error, int $status = 0 ) {
		parent::__construct( $error, $status );
		$this->error = $error;
	}

	/**
	 * Short error name.
	 *
	 * @return string
	 */
	public function error_name(): string {
		return $this->error;
	}
}
