<?php
/**
 * Logging to the WooCommerce logger.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to WooCommerce > Status > Logs with source `bitsnap`. Secrets are redacted
 * before anything is written. Debug lines are written only when the debug setting is on.
 * Errors are always written.
 */
class Logger {

	/**
	 * Log source.
	 */
	public const SOURCE = 'bitsnap';

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
	 * Logs a debug line when debug logging is on.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		if ( $this->settings->is_yes( 'debug' ) ) {
			$this->write( 'debug', $message, $context );
		}
	}

	/**
	 * Logs an info line when debug logging is on.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		if ( $this->settings->is_yes( 'debug' ) ) {
			$this->write( 'info', $message, $context );
		}
	}

	/**
	 * Logs an error, always.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Writes a line.
	 *
	 * @param string              $level   PSR-3 level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 * @return void
	 */
	private function write( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$line = Redactor::redact( $message );
		if ( array() !== $context ) {
			$line .= ' ' . Redactor::redact( (string) wp_json_encode( Redactor::redact_array( $context ) ) );
		}
		wc_get_logger()->log( $level, $line, array( 'source' => self::SOURCE ) );
	}
}
