<?php
/**
 * Minimal PSR-4 autoloader, so the plugin needs no Composer at runtime.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps `Bitsnap\WooCommerce\Sub\Name` to `<base>/Sub/Name.php`.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this loader.
	 */
	private const PREFIX = 'Bitsnap\\WooCommerce\\';

	/**
	 * Registers the loader.
	 *
	 * @param string $base_dir Absolute path of the `src` directory, with trailing slash.
	 * @return void
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
