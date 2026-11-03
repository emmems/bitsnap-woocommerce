<?php
/**
 * PHPUnit bootstrap. The unit tests cover the pure classes and need no WordPress.
 */

// The source files exit when ABSPATH is missing, as WordPress.org requires. Define it for the tests.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/Autoloader.php';

\Bitsnap\WooCommerce\Autoloader::register( dirname( __DIR__ ) . '/src/' );
