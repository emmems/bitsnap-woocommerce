<?php
/**
 * PHPUnit bootstrap. The unit tests cover the pure classes and need no WordPress.
 */

define( 'BITSNAP_WC_TESTING', true );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/Autoloader.php';

\Bitsnap\WooCommerce\Autoloader::register( dirname( __DIR__ ) . '/src/' );
