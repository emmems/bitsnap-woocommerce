<?php
/**
 * Smoke test that runs inside a real WordPress with WooCommerce (wp-env), with Bitsnap mocked.
 *
 *   npm run env:start
 *   npm run test:smoke
 *
 * It creates test products, orders and a coupon in the site's database. Use a throwaway site (wp-env).
 * Exits with status 1 if any check fails.
 *
 * phpcs:disable
 */

use Bitsnap\WooCommerce\Plugin;
use Bitsnap\WooCommerce\Blocks\PaymentMethod;
use Bitsnap\WooCommerce\Support\Settings;
use Bitsnap\WooCommerce\Webhook\Signature;
use Bitsnap\WooCommerce\Woo\Gateway;
use Bitsnap\WooCommerce\Woo\Meta;
use Bitsnap\WooCommerce\Woo\Reconciler;
use Bitsnap\WooCommerce\Woo\ReturnPage;

// wp eval-file runs this file inside a function, so counters live in $GLOBALS.
delete_option( 'bitsnap_oauth_tokens_test' );
delete_option( 'bitsnap_oauth_tokens_live' );
delete_option( 'bitsnap_reconcile_page' );
$GLOBALS['smoke_failures'] = 0;
$GLOBALS['smoke_checks']   = 0;

function smoke_check( $condition, $label, $detail = '' ) {
	++$GLOBALS['smoke_checks'];
	if ( $condition ) {
		echo "  PASS  $label\n";
	} else {
		++$GLOBALS['smoke_failures'];
		echo "  FAIL  $label" . ( '' !== $detail ? "  ($detail)" : '' ) . "\n";
	}
}

function smoke_section( $name ) {
	echo "\n== $name\n";
}

// ---------------------------------------------------------------- mock Bitsnap

$GLOBALS['bitsnap_requests'] = array();
$GLOBALS['bitsnap_mock']     = array();

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, '/api/v1/bitsnap.v1.' ) ) {
			return $pre;
		}
		$method = substr( $url, strrpos( $url, '/' ) + 1 );
		$GLOBALS['bitsnap_requests'][] = array(
			'url'     => $url,
			'method'  => $method,
			'headers' => $args['headers'],
			'body'    => json_decode( $args['body'], true ),
		);
		$mock = $GLOBALS['bitsnap_mock'][ $method ] ?? array( 200, array() );
		if ( is_callable( $mock ) ) {
			$mock = $mock( json_decode( $args['body'], true ), $args['headers'] );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $mock[1] ),
			'response' => array( 'code' => $mock[0], 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Mocked Bitsnap OAuth server: /oauth/register, /oauth/token, /oauth/revoke.
$GLOBALS['oauth_requests'] = array();
$GLOBALS['oauth_mock']     = array();

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 0 !== strpos( $path, '/oauth/' ) ) {
			return $pre;
		}
		$fields                        = is_array( $args['body'] ) ? $args['body'] : json_decode( $args['body'], true );
		$GLOBALS['oauth_requests'][]   = array(
			'path'    => $path,
			'fields'  => $fields,
			'headers' => $args['headers'],
		);
		$mock   = $GLOBALS['oauth_mock'][ $path ] ?? null;
		$answer = $mock ? $mock( $fields ) : array( 404, array() );
		return array(
			'headers'  => array(),
			'body'     => is_string( $answer[1] ) ? $answer[1] : wp_json_encode( $answer[1] ),
			'response' => array( 'code' => $answer[0], 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

function smoke_oauth_requests( $path, $grant = null ) {
	return array_values(
		array_filter(
			$GLOBALS['oauth_requests'],
			static function ( $r ) use ( $path, $grant ) {
				return $r['path'] === $path && ( null === $grant || ( $r['fields']['grant_type'] ?? '' ) === $grant );
			}
		)
	);
}

class SmokeRedirect extends Exception {
	public $location;
	public function __construct( $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

class SmokeDie extends Exception {}

/** Runs a handler that ends in wp_safe_redirect() and exit; returns the redirect location. */
function smoke_redirect_of( $fn ) {
	$catch = static function ( $location ) {
		throw new SmokeRedirect( $location );
	};
	add_filter( 'wp_redirect', $catch, 0 );
	try {
		$fn();
	} catch ( SmokeRedirect $redirect ) {
		return $redirect->location;
	} finally {
		remove_filter( 'wp_redirect', $catch, 0 );
	}
	return null;
}

/** Runs a handler and returns true when it ended in wp_die(). */
function smoke_dies( $fn ) {
	$handler = static function () {
		return static function () {
			throw new SmokeDie( 'die' );
		};
	};
	add_filter( 'wp_die_handler', $handler );
	try {
		smoke_redirect_of( $fn );
	} catch ( SmokeDie $die ) {
		return true;
	} finally {
		remove_filter( 'wp_die_handler', $handler );
	}
	return false;
}

function smoke_last_request( $method ) {
	$found = null;
	foreach ( $GLOBALS['bitsnap_requests'] as $request ) {
		if ( $request['method'] === $method ) {
			$found = $request;
		}
	}
	return $found;
}

// ---------------------------------------------------------------- setup

smoke_section( 'Activation' );
$plugin_file = plugin_basename( BITSNAP_WC_FILE );
smoke_check( is_plugin_active( $plugin_file ), 'plugin is active (' . $plugin_file . ')' );
smoke_check( class_exists( 'WooCommerce' ), 'WooCommerce is active (' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ')' );

try {
	$compat = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
	$names  = array_merge( $compat['compatible'] ?? array(), $compat['uncertain'] ?? array() );
	smoke_check( in_array( $plugin_file, $compat['compatible'] ?? array(), true ), 'declared compatible with HPOS', implode( ',', $names ) );
	$compat = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'cart_checkout_blocks' );
	smoke_check( in_array( $plugin_file, $compat['compatible'] ?? array(), true ), 'declared compatible with cart_checkout_blocks' );
} catch ( \Throwable $e ) {
	smoke_check( false, 'feature compatibility lookup', $e->getMessage() );
}

// A failed cold wp-env startup can leave WooCommerce's install lease behind.
// This suite owns a disposable database: finish installation before fixtures.
delete_option( 'wc_installing' );
delete_transient( 'wc_installing' );
WC_Install::install();
WC_Install::create_roles();
$plugin = Plugin::instance();

update_option( 'woocommerce_currency', 'PLN' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( 'woocommerce_default_country', 'PL' );
update_option( 'woocommerce_tax_based_on', 'base' );
update_option( 'woocommerce_shipping_tax_class', '' );
update_option( 'woocommerce_ship_to_countries', '' );
$shipping_zone = new WC_Shipping_Zone( 0 );
if ( empty( $shipping_zone->get_shipping_methods() ) ) {
	$shipping_zone->add_shipping_method( 'flat_rate' );
}
delete_transient( 'wc_shipping_method_count' );
update_option( 'woocommerce_coupons_enabled', 'yes' );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
$rate_id = WC_Tax::_insert_tax_rate(
	array(
		'tax_rate_country'  => '',
		'tax_rate_state'    => '',
		'tax_rate'          => '23.0000',
		'tax_rate_name'     => 'VAT',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 1,
		'tax_rate_order'    => 1,
		'tax_rate_class'    => '',
	)
);

delete_option( Settings::OPTION );
update_option(
	Settings::OPTION,
	array(
		'enabled'  => 'yes',
		'title'    => 'Bitsnap',
		'testmode' => 'yes',
		'debug'    => 'yes',
	)
);
$settings = $plugin->settings();
$settings->set_secret( 'api_key_test', 'bs_test_SMOKEKEY' );
$settings->set_secret( 'webhook_secret_test', 'whsec_SMOKESECRET' );
$settings->set_secret( 'api_key_live', 'bs_live_SMOKELIVE' );
$settings->set_secret( 'webhook_secret_live', 'whsec_SMOKELIVESECRET' );

smoke_section( 'Secrets at rest' );
$raw = get_option( Settings::OPTION );
smoke_check( 0 === strpos( $raw['api_key_test'], 'bsenc1:' ), 'API key is stored encrypted' );
smoke_check( false === strpos( wp_json_encode( $raw ), 'SMOKEKEY' ) && false === strpos( wp_json_encode( $raw ), 'SMOKESECRET' ), 'no plain secret in the option' );
smoke_check( 'bs_test_SMOKEKEY' === $plugin->credentials()->api_key( 'test' ), 'credentials decrypt the key' );

smoke_section( 'Gateway' );
$gateways = WC()->payment_gateways()->payment_gateways();
smoke_check( isset( $gateways['bitsnap'] ) && $gateways['bitsnap'] instanceof Gateway, 'gateway is registered with WooCommerce' );
$gateway = new Gateway(); // A new object reads the settings saved above.
smoke_check( $gateway->supports( 'refunds' ) && $gateway->supports( 'products' ), 'supports products and refunds' );
smoke_check( true === $gateway->is_available(), 'available in PLN with a key' );
update_option( 'woocommerce_currency', 'JPY' );
smoke_check( false === $gateway->is_available(), 'hidden in an unsupported currency (JPY)' );
update_option( 'woocommerce_currency', 'EUR' );
smoke_check( true === $gateway->is_available(), 'available in EUR' );
add_filter( 'bitsnap_wc_supported_currencies', static fn() => array( 'PLN' ) );
smoke_check( false === $gateway->is_available(), 'currency list is filterable' );
remove_all_filters( 'bitsnap_wc_supported_currencies' );
update_option( 'woocommerce_currency', 'PLN' );

$html = $gateway->generate_settings_html( $gateway->get_form_fields(), false );
smoke_check( false !== strpos( $html, 'Register webhook' ) && false !== strpos( $html, 'wc-api=bitsnap' ), 'settings page renders the webhook help' );
smoke_check( false === strpos( $html, 'SMOKEKEY' ) && false === strpos( $html, 'bsenc1:' ), 'settings page never prints a secret' );
smoke_check( false !== strpos( $html, 'Saved. Enter a new value to replace it.' ), 'saved secrets show a placeholder' );

// Validation of posted values.
$kept = $gateway->validate_bitsnap_secret_field( 'api_key_test', '' );
smoke_check( 'bs_test_SMOKEKEY' === $plugin->settings()->secrets()->decrypt( $kept ), 'an empty field keeps the stored secret' );
$bad = $gateway->validate_bitsnap_secret_field( 'api_key_test', 'bs_live_wrongprefix' );
smoke_check( $bad === $kept, 'a live key in the test field is rejected' );
$new = $gateway->validate_bitsnap_secret_field( 'api_key_test', ' bs_test_NEW ' );
smoke_check( 'bs_test_NEW' === $plugin->settings()->secrets()->decrypt( $new ), 'a new key is trimmed and encrypted' );
smoke_check( 'https://bitsnap.pl' === $gateway->validate_api_base_url_field( 'api_base_url', '' ), 'empty base URL falls back to the default' );

// ---------------------------------------------------------------- products and an order

$product = new WC_Product_Simple();
$product->set_name( 'Smoke T-shirt' );
$product->set_status( 'publish' );
$product->set_regular_price( '49.00' );
$product->set_sku( 'SMOKE-TS-' . wp_rand( 10000, 99999 ) );
$product->save();

$coupon = new WC_Coupon();
$coupon->set_code( 'smoke5' . wp_rand( 100, 999 ) );
$coupon->set_discount_type( 'fixed_cart' );
$coupon->set_amount( '5' );
$coupon->save();

function smoke_order( $product, $coupon = null, $nip = '' ) {
	$order = wc_create_order();
	$order->add_product( $product, 2 );
	$order->set_billing_first_name( 'Anna' );
	$order->set_billing_last_name( 'Nowak' );
	$order->set_billing_email( 'anna@example.com' );
	$order->set_billing_phone( '500100200' );
	$order->set_billing_address_1( 'Prosta 1' );
	$order->set_billing_city( 'Warszawa' );
	$order->set_billing_postcode( '00-001' );
	$order->set_billing_country( 'PL' );
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'InPost Paczkomat' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_total( '11.37' ); // 13.99 gross: WooCommerce rounds the 23 percent VAT to 2.62.
	$order->add_item( $shipping );
	if ( $coupon ) {
		$order->apply_coupon( $coupon );
	}
	if ( '' !== $nip ) {
		$order->update_meta_data( '_billing_nip', $nip );
	}
	$order->set_payment_method( 'bitsnap' );
	$order->calculate_taxes();
	$order->calculate_totals( false );
	$order->set_status( 'pending' );
	$order->save();
	return $order;
}

smoke_section( 'API destination validation' );
foreach ( array( 'http://bitsnap.pl', 'https://127.0.0.1', 'https://10.0.0.1', 'https://169.254.169.254', 'http://localhost' ) as $unsafe_url ) {
	smoke_check( ! \Bitsnap\WooCommerce\Support\ApiUrl::valid( $unsafe_url ), 'unsafe API destination rejected: ' . $unsafe_url );
}

smoke_section( 'Starting a payment' );
$order = smoke_order( $product, $coupon->get_code(), '123-456-32-18' );
echo '  order #' . $order->get_id() . ' total ' . $order->get_total() . ' ' . $order->get_currency() . "\n";

$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = array(
	200,
	array(
		'url'         => 'https://bitsnap.pl/pay/smoke_1',
		'orderId'     => 'ord_smoke_1',
		'environment' => 'ENVIRONMENT_TEST',
		'expiresAt'   => gmdate( 'c', time() + 3600 ),
	),
);
$result = $gateway->process_payment( $order->get_id() );
smoke_check( 'success' === ( $result['result'] ?? '' ) && 'https://bitsnap.pl/pay/smoke_1' === ( $result['redirect'] ?? '' ), 'process_payment redirects to the Bitsnap URL' );

$request = smoke_last_request( 'CreateCheckoutSession' );
smoke_check( null !== $request, 'CreateCheckoutSession was called' );
$body = $request['body'];
smoke_check( 'Bearer bs_test_NEW' === $request['headers']['Authorization'] || 'Bearer bs_test_SMOKEKEY' === $request['headers']['Authorization'], 'test key is used in test mode' );
smoke_check( 0 === strpos( $request['headers']['Idempotency-Key'], 'woo-' ) && '-' . $order->get_id() . '-1' === substr( $request['headers']['Idempotency-Key'], -strlen( '-' . $order->get_id() . '-1' ) ), 'idempotency key is woo-<site>-<order>-1', $request['headers']['Idempotency-Key'] );
$sum = 0;
foreach ( $body['lines'] as $line ) {
	$sum += $line['unitPrice']['amount'] * $line['quantity'];
}
$sum += $body['shipping']['price']['amount'] ?? 0;
$sum -= $body['discount']['amount'] ?? 0;
smoke_check( $sum === $body['total']['amount'] && (int) round( $order->get_total() * 100 ) === $body['total']['amount'], 'lines + shipping - discount = total = order total', "sum=$sum total={$body['total']['amount']}" );
smoke_check( 4900 === $body['lines'][0]['unitPrice']['amount'] && 2 === $body['lines'][0]['quantity'] && 23 === $body['lines'][0]['vatRate'] && $product->get_sku() === $body['lines'][0]['sku'], 'line has gross unit price, quantity, VAT 23 and SKU', wp_json_encode( $body['lines'][0] ) );
smoke_check( 1399 === ( $body['shipping']['price']['amount'] ?? 0 ) && 'InPost Paczkomat' === ( $body['shipping']['name'] ?? '' ), 'shipping is sent gross', wp_json_encode( $body['shipping'] ?? null ) );
smoke_check( 500 === ( $body['discount']['amount'] ?? 0 ), 'coupon is sent as a gross discount', wp_json_encode( $body['discount'] ?? null ) );
smoke_check( '1234563218' === ( $body['buyer']['nip'] ?? '' ) && 'anna@example.com' === ( $body['buyer']['email'] ?? '' ) && 'PL' === ( $body['buyer']['billingAddress']['country'] ?? '' ), 'buyer with NIP from _billing_nip' );
smoke_check( (string) $order->get_id() === $body['clientReferenceId'] && 'woocommerce' === $body['metadata']['source'] && $order->get_order_key() === $body['metadata']['wcOrderKey'], 'reference and metadata' );
smoke_check( 0 === strpos( $body['successUrl'], 'http' ) && false !== strpos( $body['successUrl'], 'key=' . $order->get_order_key() ), 'success URL is the order-received URL', $body['successUrl'] );
smoke_check( false !== strpos( $body['cancelUrl'], 'pay_for_order=true' ) && false !== strpos( $body['cancelUrl'], 'bitsnap_cancelled=1' ) && false === strpos( $body['cancelUrl'], '&#038;' ), 'cancel URL returns to the pay page, unescaped', $body['cancelUrl'] );

$order = wc_get_order( $order->get_id() );
smoke_check( 'ord_smoke_1' === $order->get_meta( Meta::ORDER_ID ) && 'test' === $order->get_meta( Meta::ENVIRONMENT ) && 1 === (int) $order->get_meta( Meta::ATTEMPT ), 'Bitsnap order id, environment and attempt are saved in order meta' );

// The same request replays the same key.
$gateway->process_payment( $order->get_id() );
$request2 = smoke_last_request( 'CreateCheckoutSession' );
smoke_check( $request2['headers']['Idempotency-Key'] === $request['headers']['Idempotency-Key'], 'an identical retry reuses the idempotency key' );

// API errors become a failure with a note, no exception.
$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = array( 400, array( 'code' => 'invalid_argument', 'message' => '[total_mismatch] nope', 'details' => array( array( 'debug' => array( 'reason' => 'total_mismatch' ) ) ) ) );
wc_load_cart(); // Notices need a session.
$failed_order = smoke_order( $product );
$failed       = $gateway->process_payment( $failed_order->get_id() );
smoke_check( 'failure' === $failed['result'], 'an API error returns failure' );
$notes = wc_get_order_notes( array( 'order_id' => $failed_order->get_id() ) );
smoke_check( false !== strpos( $notes[0]->content ?? '', 'does not equal the sum' ), 'the merchant gets a clear order note', $notes[0]->content ?? '' );
wc_clear_notices();

// Missing customers:pii scope retries without the buyer.
$calls = 0;
$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = static function ( $body ) use ( &$calls ) {
	++$calls;
	if ( isset( $body['buyer'] ) ) {
		return array( 403, array( 'code' => 'permission_denied', 'message' => '[missing_scope] needs customers:pii', 'details' => array( array( 'debug' => array( 'reason' => 'missing_scope', 'metadata' => array( 'scope' => 'customers:pii' ) ) ) ) ) );
	}
	return array( 200, array( 'url' => 'https://bitsnap.pl/pay/nobuyer', 'orderId' => 'ord_nb', 'environment' => 'ENVIRONMENT_TEST', 'expiresAt' => gmdate( 'c', time() + 3600 ) ) );
};
$nb_order = smoke_order( $product );
$nb       = $gateway->process_payment( $nb_order->get_id() );
smoke_check( 'success' === $nb['result'] && 2 === $calls, 'a key without customers:pii retries without buyer details' );
$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = array( 200, array( 'url' => 'https://bitsnap.pl/pay/smoke_1', 'orderId' => 'ord_smoke_1', 'environment' => 'ENVIRONMENT_TEST', 'expiresAt' => gmdate( 'c', time() + 3600 ) ) );

// ---------------------------------------------------------------- webhooks

function smoke_event( $order, $type, $id, $state, $gateway_name = 'blik', $extra = array(), $meta_override = array(), $payment_id = 'pay_1' ) {
	if ( isset( $extra['refund'] ) && ! isset( $extra['refund']['orderId'] ) ) {
		$extra['refund']['orderId'] = (string) $order->get_meta( Meta::PAID_ORDER_ID );
	}
	return wp_json_encode(
		array(
			'projectId' => 'proj_1',
			'eventData' => array_merge(
				array(
					'id'    => $id,
					'event' => $type,
					'order' => array(
						'id'                => (string) $order->get_meta( Meta::ORDER_ID ),
						'clientReferenceId' => (string) $order->get_id(),
						'metadata'          => array_merge( array( 'source' => 'woocommerce', 'wcOrderKey' => $order->get_order_key(), 'siteUrl' => untrailingslashit( home_url() ) ), $meta_override ),
						'paymentGateway'    => $gateway_name,
						'paymentId'         => $payment_id,
						'paymentState'      => $state,
						'amountPaid'        => array( 'amount' => (int) round( $order->get_total() * 100 ), 'currency' => 'PLN' ),
					),
				),
				$extra
			),
		)
	);
}

function smoke_deliver( $body, $secret = 'whsec_SMOKESECRET' ) {
	return Plugin::instance()->handler()->process( 'POST', $body, Signature::compute( $body, $secret ) );
}

// A second PHP/WordPress request shares the database but no process-local state.
function smoke_other_request( $code ) {
	$pipes = array();
	$process = proc_open( array( 'wp', 'eval', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$output = stream_get_contents( $pipes[1] );
	$error = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	return array( $status, trim( $output ), $error );
}
function smoke_concurrent_delivery( $body ) {
	return smoke_other_request( '$body=base64_decode(' . var_export( base64_encode( $body ), true ) . '); echo json_encode(\Bitsnap\WooCommerce\Plugin::instance()->handler()->process("POST",$body,hash_hmac("sha512",$body,"whsec_SMOKESECRET")));' );
}


smoke_section( 'Atomic database lock races' );
// Both independently bootstrapped PHP processes wait at a barrier before acquiring.
foreach ( array( false, true ) as $expired_lock ) {
	$name = 'smoke-race-' . wp_generate_uuid4();
	$key = 'bitsnap_lock_' . hash( 'sha256', $name );
	if ( $expired_lock ) { add_option( $key, '1:expired-owner', '', false ); }
	$barrier = sys_get_temp_dir() . '/bitsnap-race-' . wp_generate_uuid4();
	$children = array();
	for ( $i = 0; $i < 2; ++$i ) {
		$ready = $barrier . '.' . $i;
		$code = 'file_put_contents(' . var_export( $ready, true ) . ', "ready"); while (!file_exists(' . var_export( $barrier, true ) . ')) {usleep(1000);} try {$lock=new \Bitsnap\WooCommerce\Support\Lock(' . var_export( $name, true ) . '); echo "won"; usleep(1000000); $lock->release();} catch (\RuntimeException $e) {echo "lost";}';
		$pipes = array();
		$proc = proc_open( array( 'wp', 'eval', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$children[] = array( $proc, $pipes );
	}
	$deadline = microtime( true ) + 30;
	while ( ( ! file_exists( $barrier . '.0' ) || ! file_exists( $barrier . '.1' ) ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	file_put_contents( $barrier, 'go' );
	$outcomes = array();
	foreach ( $children as $child ) {
		$outcomes[] = trim( stream_get_contents( $child[1][1] ) );
		$error = stream_get_contents( $child[1][2] );
		fclose( $child[1][1] ); fclose( $child[1][2] );
		smoke_check( 0 === proc_close( $child[0] ), 'lock contender PHP exits cleanly', $error );
	}
	sort( $outcomes );
	smoke_check( array( 'lost', 'won' ) === $outcomes, $expired_lock ? 'exactly one expired-lock takeover wins' : 'exactly one absent-lock insert wins', wp_json_encode( $outcomes ) );
	unlink( $barrier ); unlink( $barrier . '.0' ); unlink( $barrier . '.1' );
}

smoke_section( 'Webhooks' );
$handler = $plugin->handler();
$body    = smoke_event( $order, 'TRANSACTION_SUCCESS', 'evt_1', 'PAYMENT_STATE_PAID' );

smoke_check( array( 400, 'invalid_signature' ) === $handler->process( 'POST', $body, 'deadbeef' ), 'a bad signature gets 400' );
smoke_check( array( 400, 'invalid_signature' ) === $handler->process( 'POST', $body, '' ), 'a missing signature gets 400' );
smoke_check( array( 405, 'method_not_allowed' ) === $handler->process( 'GET', $body, Signature::compute( $body, 'whsec_SMOKESECRET' ) ), 'GET gets 405' );
smoke_check( 'pending' === wc_get_order( $order->get_id() )->get_status(), 'the order is still pending after rejected deliveries' );

smoke_check( array( 200, 'ok' ) === smoke_deliver( $body ), 'a signed success event is accepted' );
$order = wc_get_order( $order->get_id() );
smoke_check( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) && $order->is_paid(), 'the order is paid (' . $order->get_status() . ')' );
smoke_check( 'pay_1' === $order->get_transaction_id(), 'transaction id is the Bitsnap payment id' );
smoke_check( 'Bitsnap (BLIK)' === $order->get_payment_method_title(), 'payment method title shows BLIK' );
smoke_check( array( 200, 'duplicate' ) === smoke_deliver( $body ), 'the same event id is a duplicate' );
smoke_check( array( 200, 'ok' ) === smoke_deliver( smoke_event( $order, 'TRANSACTION_FAILURE', 'evt_2', 'PAYMENT_STATE_FAILED' ) ) && wc_get_order( $order->get_id() )->is_paid(), 'a late failure event does not unpay a paid order' );
smoke_check( array( 200, 'ignored' ) === smoke_deliver( smoke_event( $order, 'TRANSACTION_SUCCESS', 'evt_3', 'PAYMENT_STATE_PAID', 'blik', array(), array( 'source' => 'bitsnap' ) ) ), 'events of non-WooCommerce orders are ignored with 200' );
smoke_check( array( 200, 'ignored' ) === smoke_deliver( smoke_event( $order, 'TRANSACTION_SUCCESS', 'evt_4', 'PAYMENT_STATE_PAID', 'blik', array(), array( 'siteUrl' => 'https://another-shop.example' ) ) ), 'events of another site are ignored with 200' );
smoke_check( array( 404, 'unknown_order' ) === smoke_deliver( smoke_event( $order, 'TRANSACTION_SUCCESS', 'evt_5', 'PAYMENT_STATE_PAID', 'blik', array(), array( 'wcOrderKey' => 'wc_order_forged' ) ) ), 'a wrong order key gets 404' );
$ghost = clone $order;
$ghost_body = str_replace( '"clientReferenceId":"' . $order->get_id() . '"', '"clientReferenceId":"99999999"', smoke_event( $order, 'TRANSACTION_SUCCESS', 'evt_6', 'PAYMENT_STATE_PAID' ) );
smoke_check( array( 404, 'unknown_order' ) === smoke_deliver( $ghost_body ), 'an unknown order reference gets 404' );
smoke_check( array( 400, 'invalid_payload' ) === smoke_deliver( '{"not":"an event"}' ), 'a signed body that is not an event gets 400' );
// A live-signed event cannot touch a test order.
smoke_check( array( 404, 'unknown_order' ) === smoke_deliver( smoke_event( $order, 'TRANSACTION_SUCCESS', 'evt_7', 'PAYMENT_STATE_PAID' ), 'whsec_SMOKELIVESECRET' ), 'a live-signed event cannot touch a test-mode order' );


smoke_section( 'Terminal checkout retry' );
$original_session_mock = $GLOBALS['bitsnap_mock']['CreateCheckoutSession'];
foreach ( array( 'FAILED', 'EXPIRED' ) as $terminal ) {
	$retry_order = smoke_order( $product );
	$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = static function ( $request, $headers ) {
		$key = $headers['Idempotency-Key'];
		return array( 200, array( 'url' => 'https://bitsnap.pl/pay/' . $key, 'orderId' => 'session_' . $key, 'environment' => 'ENVIRONMENT_TEST', 'expiresAt' => 0 ) );
	};
	$gateway->process_payment( $retry_order->get_id() );
	$retry_order = \Bitsnap\WooCommerce\Woo\OrderState::reload( $retry_order->get_id() );
	$first_id = $retry_order->get_meta( Meta::ORDER_ID );
	$first_key = smoke_last_request( 'CreateCheckoutSession' )['headers']['Idempotency-Key'];
	smoke_deliver( smoke_event( $retry_order, 'TRANSACTION_' . ( 'FAILED' === $terminal ? 'FAILURE' : 'EXPIRED' ), 'evt_terminal_' . $terminal, $terminal ) );
	// Simulate transport ambiguity on the first retry; the next call must reuse attempt 2.
	$session_mock = $GLOBALS['bitsnap_mock']['CreateCheckoutSession'];
	$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = array( 503, array( 'code' => 'unavailable' ) );
	$gateway->process_payment( $retry_order->get_id() );
	$transport_key = smoke_last_request( 'CreateCheckoutSession' )['headers']['Idempotency-Key'];
	smoke_deliver( smoke_event( $retry_order, 'TRANSACTION_' . ( 'FAILED' === $terminal ? 'FAILURE' : 'EXPIRED' ), 'evt_terminal_late_' . $terminal, $terminal ) );
	$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = $session_mock;
	$retry_result = $gateway->process_payment( $retry_order->get_id() );
	$retry_order = \Bitsnap\WooCommerce\Woo\OrderState::reload( $retry_order->get_id() );
	smoke_check( 'success' === $retry_result['result'] && 2 === (int) $retry_order->get_meta( Meta::ATTEMPT ) && $first_id !== $retry_order->get_meta( Meta::ORDER_ID ) && $first_key !== $transport_key, $terminal . ' retry creates a new attempt and session' );
	smoke_check( $transport_key === smoke_last_request( 'CreateCheckoutSession' )['headers']['Idempotency-Key'], 'ambiguous transport retry keeps the same new attempt key' );
	$gateway->process_payment( $retry_order->get_id() );
	smoke_check( $transport_key === smoke_last_request( 'CreateCheckoutSession' )['headers']['Idempotency-Key'], 'zero expiry remains unknown, not expired' );
}
$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = $original_session_mock;

// Failure and expiry on fresh orders.
$fo = smoke_order( $product );
$fo->update_meta_data( Meta::ORDER_ID, 'ord_f' );
$fo->update_meta_data( Meta::ENVIRONMENT, 'test' );
$fo->save();
smoke_deliver( smoke_event( $fo, 'TRANSACTION_FAILURE', 'evt_f1', 'PAYMENT_STATE_FAILED' ) );
smoke_check( 'failed' === wc_get_order( $fo->get_id() )->get_status(), 'failure event sets the order to failed' );
smoke_deliver( smoke_event( $fo, 'TRANSACTION_SUCCESS', 'evt_f2', 'PAYMENT_STATE_PAID' ) );
smoke_check( wc_get_order( $fo->get_id() )->is_paid(), 'a payment after a failure still completes the order' );

$eo = smoke_order( $product );
$eo->update_meta_data( Meta::ORDER_ID, 'ord_e' );
$eo->update_meta_data( Meta::ENVIRONMENT, 'test' );
$eo->save();
smoke_deliver( smoke_event( $eo, 'TRANSACTION_EXPIRED', 'evt_e1', 'PAYMENT_STATE_EXPIRED' ) );
smoke_check( 'cancelled' === wc_get_order( $eo->get_id() )->get_status(), 'expiry event cancels the order' );

smoke_section( 'Cash on delivery and manual transfer (D4)' );
$cod = smoke_order( $product );
$cod->set_payment_method( 'bitsnap_cod' );
$cod->save();
$cod_result = ( new \Bitsnap\WooCommerce\Woo\CodGateway() )->process_payment( $cod->get_id() );
smoke_check( 'success' === $cod_result['result'], 'COD gateway creates a checkout session' );
smoke_check( 'CHECKOUT_PAYMENT_METHOD_COD' === smoke_last_request( 'CreateCheckoutSession' )['body']['paymentMethod'] && isset( smoke_last_request( 'CreateCheckoutSession' )['body']['shipping'] ), 'COD gateway sends COD with shipping' );
$cod = \Bitsnap\WooCommerce\Woo\OrderState::reload( $cod->get_id() );
$cod->update_meta_data( Meta::ORDER_ID, 'ord_cod' );
$cod->update_meta_data( Meta::ENVIRONMENT, 'test' );
$cod->save();
smoke_deliver( smoke_event( $cod, 'TRANSACTION_CREATED', 'evt_c1', 'PAYMENT_STATE_AWAITING_OFFLINE', 'cod', array(), array(), '' ) );
$cod = wc_get_order( $cod->get_id() );
smoke_check( 'processing' === $cod->get_status() && 'cod' === $cod->get_meta( Meta::OFFLINE ), 'COD choice puts the order in processing' );
smoke_deliver( smoke_event( $cod, 'TRANSACTION_SUCCESS', 'evt_c2', 'PAYMENT_STATE_PAID', 'cod', array(), array(), 'pay_cod' ) );
$cod = wc_get_order( $cod->get_id() );
smoke_check( 'processing' === $cod->get_status() && '' === (string) $cod->get_meta( Meta::OFFLINE ) && 'pay_cod' === $cod->get_transaction_id() && null !== $cod->get_date_paid(), 'COD paid later is recorded without a status change' );

$man = smoke_order( $product );
$man->set_payment_method( 'bitsnap_manual' );
$man->save();
$manual_result = ( new \Bitsnap\WooCommerce\Woo\ManualGateway() )->process_payment( $man->get_id() );
smoke_check( 'success' === $manual_result['result'], 'manual gateway creates a checkout session' );
smoke_check( 'CHECKOUT_PAYMENT_METHOD_MANUAL' === smoke_last_request( 'CreateCheckoutSession' )['body']['paymentMethod'], 'manual gateway sends MANUAL' );
$man = \Bitsnap\WooCommerce\Woo\OrderState::reload( $man->get_id() );
$man->update_meta_data( Meta::ORDER_ID, 'ord_man' );
$man->update_meta_data( Meta::ENVIRONMENT, 'test' );
$man->save();
smoke_deliver( smoke_event( $man, 'TRANSACTION_CREATED', 'evt_m1', 'PAYMENT_STATE_AWAITING_OFFLINE', 'manual', array(), array(), '' ) );
smoke_check( 'on-hold' === wc_get_order( $man->get_id() )->get_status(), 'manual transfer choice puts the order on hold' );
smoke_deliver( smoke_event( $man, 'TRANSACTION_SUCCESS', 'evt_m2', 'PAYMENT_STATE_PAID', 'manual', array(), array(), 'pay_man' ) );
smoke_check( wc_get_order( $man->get_id() )->is_paid(), 'a confirmed manual transfer completes the order' );



smoke_check( 'no' === ( new \Bitsnap\WooCommerce\Woo\CodGateway() )->enabled && 'no' === ( new \Bitsnap\WooCommerce\Woo\ManualGateway() )->enabled, 'manual and COD are disabled by default' );
$shared = get_option( Settings::OPTION );
$shared['bitsnap_cod_enabled'] = 'yes';
$shared['bitsnap_manual_enabled'] = 'yes';
update_option( Settings::OPTION, $shared );
$cod_gateway = new \Bitsnap\WooCommerce\Woo\CodGateway();
$manual_gateway = new \Bitsnap\WooCommerce\Woo\ManualGateway();
smoke_check( $cod_gateway->get_option_key() === $gateway->get_option_key() && $manual_gateway->get_option_key() === $gateway->get_option_key(), 'all gateways share one settings option' );
WC()->initialize_session();
WC()->initialize_cart();
WC()->cart->empty_cart();
$virtual = new WC_Product_Simple();
$virtual->set_name( 'Virtual smoke' );
$virtual->set_status( 'publish' );
$virtual->set_virtual( true );
$virtual->set_regular_price( '10' );
$virtual->save();
smoke_check( false !== WC()->cart->add_to_cart( $virtual->get_id() ), 'virtual product enters the cart' );
smoke_check( ! $cod_gateway->is_available(), 'COD hidden for virtual-only cart' );
WC()->cart->empty_cart();
smoke_check( false !== WC()->cart->add_to_cart( $product->get_id() ), 'physical product enters the cart' );
smoke_check( $cod_gateway->is_available(), 'COD available for shipping cart', 'enabled=' . $cod_gateway->enabled . ', count=' . WC()->cart->get_cart_contents_count() . ', shipping=' . (int) WC()->cart->needs_shipping() );
WC()->cart->empty_cart();


// Backend method refusal is presented as a checkout error without exposing its body.
$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = array( 400, array( 'code' => 'invalid_argument', 'message' => '[invalid_request] COD not configured' ) );
$refused = smoke_order( $product );
$refused->set_payment_method( 'bitsnap_cod' );
$refused->save();
wc_clear_notices();
$refused_result = $cod_gateway->process_payment( $refused->get_id() );
smoke_check( 'failure' === $refused_result['result'] && wc_notice_count( 'error' ) > 0, 'backend method refusal gives buyer a checkout error' );
$GLOBALS['bitsnap_mock']['CreateCheckoutSession'] = array( 200, array( 'url' => 'https://bitsnap.pl/pay/smoke_1', 'orderId' => 'ord_smoke_1', 'environment' => 'ENVIRONMENT_TEST', 'expiresAt' => gmdate( 'c', time() + 3600 ) ) );

smoke_section( 'Durable event identity and legacy fallback' );
foreach ( array( true, false ) as $durable ) {
	$dedupe = smoke_order( $product );
	$dedupe->update_meta_data( Meta::ORDER_ID, 'ord_dedupe' );
	$dedupe->update_meta_data( Meta::ENVIRONMENT, 'test' );
	$dedupe->update_meta_data( Meta::EVENTS, array( 'ord_dedupe', 'previous_event' ) );
	$dedupe->save();
	$extra = $durable ? array( 'eventId' => 'evt_created' ) : array();
	$created = smoke_event( $dedupe, 'TRANSACTION_CREATED', 'ord_dedupe', 'AWAITING_OFFLINE', 'manual', $extra );
	smoke_check( array( 200, 'ok' ) === smoke_deliver( $created ), 'CREATED applies despite stored bare order id' );
	$extra = $durable ? array( 'eventId' => 'evt_paid' ) : array();
	$paid = smoke_event( $dedupe, 'TRANSACTION_SUCCESS', 'ord_dedupe', 'PAID', 'manual', $extra );
	smoke_check( array( 200, 'ok' ) === smoke_deliver( $paid ) && wc_get_order( $dedupe->get_id() )->is_paid(), 'SUCCESS after CREATED applies for same order id' );
	smoke_check( array( 200, 'duplicate' ) === smoke_deliver( $paid ), 'same durable or fallback identity applies once' );
}


$legacy_event = smoke_event( $dedupe, 'TRANSACTION_SUCCESS', 'previous_event', 'PAID' );
smoke_check( array( 200, 'duplicate' ) === smoke_deliver( $legacy_event ), 'already-stored unique legacy identity remains deduplicated' );

smoke_section( 'Invoice, payment attempts and concurrent delivery' );
$invoice = json_decode( smoke_event( $order, 'INVOICE_ISSUED', 'evt_invoice', 'PAYMENT_STATE_PAID' ), true );
$invoice['eventData']['order']['invoiceUrl'] = 'https://bitsnap.pl/invoice/test';
$invoice = wp_json_encode( $invoice );
smoke_check( array( 200, 'ok' ) === smoke_deliver( $invoice ), 'complete signed invoice delivery succeeds' );
smoke_check( 'https://bitsnap.pl/invoice/test' === wc_get_order( $order->get_id() )->get_meta( Meta::INVOICE_URL ), 'invoice delivery records its URL' );
smoke_check( array( 200, 'duplicate' ) === smoke_deliver( $invoice ), 'invoice event is deduplicated' );
$stale = smoke_order( $product );
$stale->update_meta_data( Meta::ORDER_ID, 'new_attempt' );
$stale->update_meta_data( Meta::ENVIRONMENT, 'test' );
$stale->save();
$stale_event = json_decode( smoke_event( $stale, 'TRANSACTION_FAILURE', 'evt_stale_failure', 'PAYMENT_STATE_FAILED' ), true );
$stale_event['eventData']['order']['id'] = 'old_attempt';
smoke_deliver( wp_json_encode( $stale_event ) );
smoke_check( 'pending' === wc_get_order( $stale->get_id() )->get_status(), 'failure from an older attempt cannot cancel the current attempt' );
$stale_event['eventData']['id'] = 'evt_stale_underpaid';
$stale_event['eventData']['event'] = 'TRANSACTION_SUCCESS';
$stale_event['eventData']['order']['amountPaid']['amount'] = 1;
smoke_deliver( wp_json_encode( $stale_event ) );
smoke_check( 'on-hold' === wc_get_order( $stale->get_id() )->get_status(), 'underpayment puts an edited order on hold' );
$stale_event['eventData']['id'] = 'evt_stale_paid';
$stale_event['eventData']['order']['amountPaid']['amount'] = (int) round( $stale->get_total() * 100 );
smoke_deliver( wp_json_encode( $stale_event ) );
smoke_check( wc_get_order( $stale->get_id() )->is_paid() && 'old_attempt' === wc_get_order( $stale->get_id() )->get_meta( Meta::PAID_ORDER_ID ), 'an older attempt paying the full total is accepted and retained for refunds' );

$stale_event['eventData']['id'] = 'evt_stale_mismatch_after_paid';
$stale_event['eventData']['order']['amountPaid']['amount'] = 1;
smoke_deliver( wp_json_encode( $stale_event ) );
$settled = \Bitsnap\WooCommerce\Woo\OrderState::reload( $stale->get_id() );
smoke_check( $settled->is_paid() && 'yes' === $settled->get_meta( Meta::REVIEW ), 'late mismatched old success leaves a paid order settled and flags manual review' );
$settled->update_status( 'completed' );
$stale_event['eventData']['id'] = 'evt_stale_mismatch_after_completed';
smoke_deliver( wp_json_encode( $stale_event ) );
smoke_check( 'completed' === wc_get_order( $stale->get_id() )->get_status(), 'late mismatched success cannot demote a completed order' );
$wrong_refund = smoke_event( $settled, 'REFUND_SUCCEEDED', 'evt_other_attempt_refund', 'PARTIALLY_REFUNDED', 'blik', array( 'refund' => array( 'id' => 'rf_wrong', 'orderId' => 'new_attempt', 'amount' => array( 'amount' => 100, 'currency' => 'PLN' ) ) ) );
smoke_deliver( $wrong_refund );
smoke_check( 0 === count( wc_get_order( $settled->get_id() )->get_refunds() ), 'refund from an attempt that did not pay is ignored' );

$race = smoke_order( $product );
$race->update_meta_data( Meta::ORDER_ID, 'race_attempt' );
$race->update_meta_data( Meta::ENVIRONMENT, 'test' );
$race->save();
$race_body = smoke_event( $race, 'TRANSACTION_SUCCESS', 'evt_race', 'PAYMENT_STATE_PAID' );
$nested = null;
$reenter = static function ( $id ) use ( $race, $race_body, &$nested ) {
	if ( $id === $race->get_id() ) {
		$child = smoke_concurrent_delivery( $race_body );
		$nested = json_decode( $child[1], true );
	}
};
add_action( 'woocommerce_pre_payment_complete', $reenter );
smoke_check( array( 200, 'ok' ) === smoke_deliver( $race_body ), 'outer payment delivery succeeds' );
remove_action( 'woocommerce_pre_payment_complete', $reenter );
smoke_check( array( 500, 'error' ) === $nested && array( 200, 'duplicate' ) === smoke_deliver( $race_body ), 'concurrent duplicate waits for retry and becomes a duplicate' );

// ---------------------------------------------------------------- refunds

smoke_section( 'Refunds' );
$order = wc_get_order( $order->get_id() );
$GLOBALS['bitsnap_mock']['CreateRefund'] = array(
	200,
	array( 'refund' => array( 'id' => 'ref_a', 'orderId' => 'ord_smoke_1', 'amount' => array( 'amount' => 500, 'currency' => 'PLN' ), 'status' => 'REFUND_STATUS_SUCCEEDED', 'createdAt' => gmdate( 'c' ) ) ),
);
$refund_answer = $GLOBALS['bitsnap_mock']['CreateRefund'];
$refund_overlap = null;
$equal_refund_overlap = null;
$GLOBALS['bitsnap_mock']['CreateRefund'] = static function () use ( $order, $refund_answer, &$refund_overlap, &$equal_refund_overlap ) {
	$equal_refund_overlap = smoke_other_request( '$refund=wc_create_refund(array("order_id"=>' . $order->get_id() . ',"amount"=>"5.00","refund_payment"=>true)); echo is_wp_error($refund)?"blocked":"accepted";' );
	$child = smoke_concurrent_delivery( smoke_event( $order, 'REFUND_SUCCEEDED', 'evt_refund_overlap', 'PAYMENT_STATE_PARTIALLY_REFUNDED', 'blik', array( 'refund' => $refund_answer[1]['refund'] ) ) );
	$refund_overlap = json_decode( $child[1], true );
	return $refund_answer;
};
$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '5.00', 'reason' => 'Smoke test', 'refund_payment' => true ) );
smoke_check( 'blocked' === $equal_refund_overlap[1], 'a concurrent equal-value refund cannot share the active submission' );
smoke_check( array( 500, 'error' ) === $refund_overlap, 'refund webhook during process_refund is retried under the order lock' );
$GLOBALS['bitsnap_mock']['CreateRefund'] = $refund_answer;
smoke_check( ! is_wp_error( $refund ), 'admin refund through the gateway succeeds', is_wp_error( $refund ) ? $refund->get_error_message() : '' );
$rr = smoke_last_request( 'CreateRefund' );
smoke_check( null !== $rr && 'woo-refund-' . ( is_wp_error( $refund ) ? '?' : $refund->get_id() ) === $rr['headers']['Idempotency-Key'], 'refund idempotency key is woo-refund-<refund id>', $rr['headers']['Idempotency-Key'] ?? '' );
smoke_check( 'ord_smoke_1' === $rr['body']['orderId'] && 500 === $rr['body']['amount']['amount'] && 'Smoke test' === $rr['body']['reason'], 'refund request has order, amount and reason' );
$order = wc_get_order( $order->get_id() );
smoke_check( in_array( 'ref_a', (array) $order->get_meta( Meta::REFUND_IDS ), true ), 'the Bitsnap refund id is recorded' );

$before = count( $order->get_refunds() );
$ref_body = smoke_event( $order, 'REFUND_SUCCEEDED', 'evt_r1', 'PAYMENT_STATE_PARTIALLY_REFUNDED', 'blik', array( 'refund' => array( 'id' => 'ref_a', 'amount' => array( 'amount' => 500, 'currency' => 'PLN' ), 'status' => 'REFUND_STATUS_SUCCEEDED' ) ) );
smoke_deliver( $ref_body );
smoke_check( $before === count( wc_get_order( $order->get_id() )->get_refunds() ), 'the webhook for a refund made here does not create a second refund' );

$dash = smoke_event( $order, 'REFUND_SUCCEEDED', 'evt_r2', 'PAYMENT_STATE_PARTIALLY_REFUNDED', 'blik', array( 'refund' => array( 'id' => 'ref_dash', 'amount' => array( 'amount' => '1000', 'currency' => 'PLN' ), 'status' => 'REFUND_STATUS_SUCCEEDED', 'source' => 'dashboard' ) ) );
smoke_deliver( $dash );
$order = wc_get_order( $order->get_id() );
smoke_check( $before + 1 === count( $order->get_refunds() ) && 15.0 === (float) $order->get_total_refunded(), 'a refund made in the Bitsnap dashboard appears in WooCommerce (total refunded ' . $order->get_total_refunded() . ')' );

foreach ( $order->get_refunds() as $deleted_refund ) {
	if ( 'ref_dash' === $deleted_refund->get_meta( '_bitsnap_refund_id' ) ) { $deleted_refund->delete( true ); }
}
smoke_check( array( 200, 'ok' ) === smoke_deliver( $dash ), 'same refund event is replayable after its Woo record was deleted' );
smoke_check( $before + 1 === count( wc_get_order( $order->get_id() )->get_refunds() ), 'deleted Woo refund is re-created despite remembered remote and event IDs' );
smoke_check( array( 200, 'duplicate' ) === smoke_deliver( $dash ), 're-created refund is deduplicated again' );
$identity_order = wc_get_order( $order->get_id() );
$identity_order->delete_meta_data( Meta::REFUND_IDS );
$identity_order->save();
smoke_deliver( smoke_event( $order, 'REFUND_SUCCEEDED', 'evt_r3', 'PAYMENT_STATE_PARTIALLY_REFUNDED', 'blik', array( 'refund' => array( 'id' => 'ref_dash', 'amount' => array( 'amount' => '1000', 'currency' => 'PLN' ) ) ) ) );
smoke_check( $before + 1 === count( wc_get_order( $order->get_id() )->get_refunds() ), 'refund identity on the refund record prevents duplication after the order list is cleared' );

$retry_body = smoke_event( $order, 'REFUND_SUCCEEDED', 'evt_refund_retry', 'PAYMENT_STATE_PARTIALLY_REFUNDED', 'blik', array( 'refund' => array( 'id' => 'ref_retry', 'amount' => array( 'amount' => 100, 'currency' => 'PLN' ) ) ) );
$fail_refund = static function ( $refund ) { throw new Exception( 'Simulated database failure' ); };
add_action( 'woocommerce_before_order_refund_object_save', $fail_refund );
$retry_result = smoke_deliver( $retry_body );
smoke_check( array( 500, 'error' ) === $retry_result, 'failed refund persistence returns 500', wp_json_encode( $retry_result ) );
remove_action( 'woocommerce_before_order_refund_object_save', $fail_refund );
smoke_check( ! in_array( 'evt_refund_retry', (array) wc_get_order( $order->get_id() )->get_meta( Meta::EVENTS ), true ), 'failed refund event is not remembered' );
smoke_check( array( 200, 'ok' ) === smoke_deliver( $retry_body ), 'refund delivery can succeed on retry' );
// Keep existing count assertions focused on the earlier dashboard refund.
foreach ( wc_get_order( $order->get_id() )->get_refunds() as $retry_refund ) {
	if ( 'ref_retry' === $retry_refund->get_meta( '_bitsnap_refund_id' ) ) { $retry_refund->delete( true ); }
}

$GLOBALS['bitsnap_mock']['CreateRefund'] = array( 200, array( 'refund' => array( 'id' => 'ref_m', 'status' => 'REFUND_STATUS_MANUAL_REQUIRED' ) ) );
$manual = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '1.00', 'refund_payment' => true ) );
smoke_check( is_wp_error( $manual ) && false !== strpos( $manual->get_error_message(), 'Refund manually' ), 'MANUAL_REQUIRED becomes a clear WP_Error', is_wp_error( $manual ) ? $manual->get_error_message() : 'no error' );
foreach ( array( $cod, $man ) as $offline_order ) {
	$offline_refund = wc_create_refund( array( 'order_id' => $offline_order->get_id(), 'amount' => '1.00', 'refund_payment' => true ) );
	smoke_check( is_wp_error( $offline_refund ) && false !== strpos( $offline_refund->get_error_message(), 'Refund manually' ), $offline_order->get_payment_method() . ' follows MANUAL_REQUIRED backend response' );
}

smoke_check( $before + 1 === count( wc_get_order( $order->get_id() )->get_refunds() ), 'no WooCommerce refund is created when Bitsnap cannot refund' );
$GLOBALS['bitsnap_mock']['CreateRefund'] = array( 409, array( 'code' => 'failed_precondition', 'message' => '[refund_not_supported] x', 'details' => array( array( 'debug' => array( 'reason' => 'refund_not_supported' ) ) ) ) );
$unsupported = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '1.00', 'refund_payment' => true ) );
smoke_check( is_wp_error( $unsupported ) && false !== strpos( $unsupported->get_error_message(), 'cannot be refunded through Bitsnap' ), 'refund_not_supported becomes a clear WP_Error' );

smoke_section( 'Interrupted refund recovery' );
$GLOBALS['bitsnap_mock']['CreateRefund'] = array( 503, array( 'code' => 'unavailable' ) );
$lost = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '1.00', 'refund_payment' => true ) );
$pending = wc_get_order( $order->get_id() )->get_meta( '_bitsnap_pending_refund' );
smoke_check( is_wp_error( $lost ) && is_array( $pending ) && ! empty( $pending['wc_id'] ), 'ambiguous refund failure retains the durable request association' );
$GLOBALS['bitsnap_mock']['CreateRefund'] = array( 200, array( 'refund' => array( 'id' => 'ref_recovered', 'status' => 'REFUND_STATUS_SUCCEEDED' ) ) );
$recover_body = smoke_event( $order, 'REFUND_SUCCEEDED', 'evt_recover_refund', 'PAYMENT_STATE_PARTIALLY_REFUNDED', 'blik', array( 'refund' => array( 'id' => 'ref_recovered', 'amount' => array( 'amount' => 100, 'currency' => 'PLN' ) ) ) );
smoke_check( array( 200, 'ok' ) === smoke_deliver( $recover_body ), 'webhook recovers an interrupted refund submission' );
$replay = smoke_last_request( 'CreateRefund' );
smoke_check( 'woo-refund-' . $pending['wc_id'] === $replay['headers']['Idempotency-Key'], 'recovery replays the original refund idempotency key' );
smoke_check( ! wc_get_order( $order->get_id() )->get_meta( '_bitsnap_pending_refund' ), 'resolved pending refund association is cleared' );

// ---------------------------------------------------------------- return page and reconcile

smoke_section( 'Return page and reconcile job' );
$GLOBALS['bitsnap_mock']['GetOrder'] = static function ( $req ) {
	$o = wc_get_order( (int) $req['clientReferenceId'] );
	return array(
		200,
		array(
			'order' => array(
				'id'                => (string) $o->get_meta( Meta::ORDER_ID ),
				'amountPaid'        => array( 'amount' => (int) round( $o->get_total() * 100 ), 'currency' => $o->get_currency() ),
				'clientReferenceId' => $req['clientReferenceId'],
				'metadata'          => array( 'source' => 'woocommerce', 'wcOrderKey' => $o->get_order_key() ),
				'buyer'             => array( 'email' => 'response-buyer@example.com' ),
				'url'               => 'https://bitsnap.pl/pay/private_response',
				'paymentGateway'    => 'payu',
				'paymentId'         => 'pay_poll',
				'paymentState'      => (int) ( $GLOBALS['bitsnap_batch_paid_id'] ?? 0 ) === $o->get_id() ? 'PAYMENT_STATE_PAID' : ( $GLOBALS['bitsnap_state'] ?? 'PAYMENT_STATE_PAID' ),
			),
		),
	);
};
$GLOBALS['bitsnap_state'] = 'PAYMENT_STATE_PAID';

$ro = smoke_order( $product );
$ro->update_meta_data( Meta::ORDER_ID, 'ord_r' );
$ro->update_meta_data( Meta::ENVIRONMENT, 'test' );
$ro->save();
( new ReturnPage( $plugin->sync() ) )->check( $ro->get_id() );
$ro = wc_get_order( $ro->get_id() );
smoke_check( $ro->is_paid() && 'pay_poll' === $ro->get_transaction_id(), 'the return page completes a paid order whose webhook has not arrived' );
$gr = smoke_last_request( 'GetOrder' );
smoke_check( isset( $gr['body']['clientReferenceId'] ), 'GetOrder is called by clientReferenceId' );

$ro2 = smoke_order( $product );
$ro2->update_meta_data( Meta::ORDER_ID, 'ord_r2' );
$ro2->update_meta_data( Meta::ENVIRONMENT, 'test' );
$ro2->save();
$GLOBALS['bitsnap_state'] = 'PAYMENT_STATE_PENDING';
$n = count( $GLOBALS['bitsnap_requests'] );
$page = new ReturnPage( $plugin->sync() );
$page->check( $ro2->get_id() );
$page->check( $ro2->get_id() );
smoke_check( $n + 1 === count( $GLOBALS['bitsnap_requests'] ), 'the return page asks Bitsnap only once per session' );
smoke_check( 'pending' === wc_get_order( $ro2->get_id() )->get_status(), 'a still-pending payment leaves the order pending' );

// Reconcile: an order pending for 30 minutes.
$GLOBALS['bitsnap_state'] = 'PAYMENT_STATE_PAID';
$rc = smoke_order( $product );
$rc->set_payment_method( 'bitsnap_manual' );
$rc->update_meta_data( Meta::ORDER_ID, 'ord_rc' );
$rc->update_meta_data( Meta::ENVIRONMENT, 'test' );
$rc->set_status( 'on-hold' );
$rc->update_meta_data( Meta::OFFLINE, 'manual' );
$rc->set_date_created( time() - 5 * DAY_IN_SECONDS );
$rc->save();
$fresh = smoke_order( $product ); // Pending for seconds: must not be polled.
$fresh->update_meta_data( Meta::ORDER_ID, 'ord_fresh' );
$fresh->update_meta_data( Meta::ENVIRONMENT, 'test' );
$fresh->save();
$old = smoke_order( $product ); // Pending for three days: gave up.
$old->update_meta_data( Meta::ORDER_ID, 'ord_old' );
$old->update_meta_data( Meta::ENVIRONMENT, 'test' );
$old->set_date_created( time() - 3 * DAY_IN_SECONDS );
$old->save();
$plugin->reconciler()->run();
smoke_check( wc_get_order( $rc->get_id() )->is_paid(), 'the reconcile job completes an on-hold manual order older than 48 hours' );
smoke_check( 'pending' === wc_get_order( $fresh->get_id() )->get_status(), 'the reconcile job leaves a fresh order alone' );
smoke_check( 'pending' === wc_get_order( $old->get_id() )->get_status() && '' !== (string) wc_get_order( $old->get_id() )->get_meta( Meta::GAVE_UP ), 'the reconcile job gives up on an order older than 48 hours' );
smoke_check( false !== as_next_scheduled_action( Reconciler::HOOK, array(), Reconciler::GROUP ), 'the recurring Action Scheduler action is scheduled' );

smoke_section( 'Reconciliation pagination' );
$GLOBALS['bitsnap_state'] = 'PAYMENT_STATE_PENDING';
$batch_ids = array();
$batch_date = time() - 3600;
for ( $i = 0; $i < 45; ++$i ) {
	$pending = smoke_order( $product );
	$pending->update_meta_data( Meta::ORDER_ID, 'batch_' . $i );
	$pending->update_meta_data( Meta::ENVIRONMENT, 'test' );
	$pending->set_date_created( $batch_date - intdiv( $i, 5 ) );
	$pending->save();
	$batch_ids[] = $pending->get_id();
}
$GLOBALS['bitsnap_batch_paid_id'] = end( $batch_ids );
delete_option( 'bitsnap_reconcile_page' );
for ( $i = 0; $i < 4; ++$i ) {
	$plugin->reconciler()->run();
}
$checked_ids = array_filter( $batch_ids, static function ( $id ) { return (int) wc_get_order( $id )->get_meta( Meta::RECONCILED_AT ) > 0; } );
smoke_check( 45 === count( $checked_ids ), 'all 45 persistent pending orders are checked across batches' );
smoke_check( wc_get_order( $GLOBALS['bitsnap_batch_paid_id'] )->is_paid(), 'persistent newer pending orders cannot starve an older paid order' );
unset( $GLOBALS['bitsnap_batch_paid_id'] );


smoke_section( 'Give-up pagination' );
delete_option( 'bitsnap_give_up_page' );
$expired_ids = array();
for ( $i = 0; $i < 105; ++$i ) {
	$expired = smoke_order( $product );
	$expired->update_meta_data( Meta::ORDER_ID, 'expired_' . $i );
	$expired->update_meta_data( Meta::ENVIRONMENT, 'test' );
	$expired->set_date_created( time() - 4 * DAY_IN_SECONDS - $i );
	if ( $i < 100 ) { $expired->update_meta_data( Meta::GAVE_UP, time() ); }
	$expired->save();
	$expired_ids[] = $expired->get_id();
}
$plugin->reconciler()->run();
$plugin->reconciler()->run();
smoke_check( '' !== (string) wc_get_order( end( $expired_ids ) )->get_meta( Meta::GAVE_UP ), 'already marked newest 100 expired orders cannot starve older give-up candidates' );

// ---------------------------------------------------------------- blocks

smoke_section( 'Cart and Checkout Blocks' );
$block = new PaymentMethod( $plugin );
$block->initialize();
smoke_check( true === $block->is_active(), 'the block payment method is active' );
smoke_check( array( 'bitsnap-for-woocommerce-blocks' ) === $block->get_payment_method_script_handles(), 'the block script is registered (build/blocks.js exists)' );
$data = $block->get_payment_method_data();
smoke_check( in_array( 'PLN', $data['supportedCurrencies'], true ) && true === $data['testMode'] && 'Bitsnap' === $data['title'], 'block data has title, test mode and currencies' );
$registered = false;
do_action( 'woocommerce_blocks_loaded' );
try {
	$registry   = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class );
	$registered = $registry->is_registered( 'bitsnap' ) && $registry->is_registered( 'bitsnap_manual' ) && $registry->is_registered( 'bitsnap_cod' );
} catch ( \Throwable $e ) {
	echo '  note: ' . $e->getMessage() . "\n";
}
smoke_check( $registered, 'Bitsnap is registered in the Blocks payment method registry' );

// ---------------------------------------------------------------- connect with Bitsnap (OAuth)

smoke_section( 'Connect with Bitsnap (OAuth)' );
use Bitsnap\WooCommerce\Admin\OAuthActions;
use Bitsnap\WooCommerce\Admin\RegisterWebhook;
use Bitsnap\WooCommerce\OAuth\Service as OAuthService;
use Bitsnap\WooCommerce\OAuth\SettingsTokenStore;
use Bitsnap\WooCommerce\OAuth\TransientStorage;
use Bitsnap\WooCommerce\OAuth\WpTransport;

$saved_mock                   = $GLOBALS['bitsnap_mock'];
$GLOBALS['oauth_requests']    = array();
$GLOBALS['bitsnap_requests']  = array();
$GLOBALS['oauth_state']       = array(
	'clients' => 0,
	'n'       => 0,
	'refresh' => array(),  // current refresh token by environment
	'next_env' => 'test',
	'reject'  => '',        // access token the API answers 401 to
	'reject_client' => 0,   // answer invalid_client to this many token calls
	'grant_error'   => '',  // answer this OAuth error to refresh calls
);
$GLOBALS['oauth_mock']['/oauth/register'] = static function () {
	++$GLOBALS['oauth_state']['clients'];
	return array( 201, array( 'client_id' => 'smoke-client-' . $GLOBALS['oauth_state']['clients'] ) );
};
$GLOBALS['oauth_mock']['/oauth/token'] = static function ( $f ) {
	$st = &$GLOBALS['oauth_state'];
	if ( $st['reject_client'] > 0 ) {
		--$st['reject_client'];
		return array( 401, array( 'error' => 'invalid_client' ) );
	}
	if ( 'authorization_code' === $f['grant_type'] ) {
		$env = $st['next_env'];
	} else {
		if ( '' !== $st['grant_error'] ) {
			return array( 400, array( 'error' => $st['grant_error'] ) );
		}
		$env = array_search( $f['refresh_token'], $st['refresh'], true );
		if ( false === $env ) {
			return array( 400, array( 'error' => 'invalid_grant' ) ); // A reused rotating token.
		}
	}
	++$st['n'];
	$st['refresh'][ $env ] = 'rt_smoke_' . $st['n'];
	return array(
		200,
		array(
			'access_token'         => 'bsat_smoke_' . $st['n'],
			'refresh_token'        => 'rt_smoke_' . $st['n'],
			'token_type'           => 'Bearer',
			'expires_in'           => 3600,
			'scope'                => 'checkout:create orders:read customers:pii refunds:write webhooks:read webhooks:write',
			'bitsnap_project_id'   => '18',
			'bitsnap_project_name' => 'Lece na kodach',
			'bitsnap_environment'  => $env,
		),
	);
};
$GLOBALS['oauth_mock']['/oauth/revoke']         = static function () {
	return array( 200, array() );
};
$GLOBALS['bitsnap_mock']['CreateWebhook']       = static function () {
	++$GLOBALS['oauth_state']['n'];
	return array( 200, array( 'webhook' => array( 'id' => 'wh_smoke_' . $GLOBALS['oauth_state']['n'], 'url' => Gateway::webhook_url() ), 'signingSecret' => 'whsec_OAUTH' . $GLOBALS['oauth_state']['n'] ) );
};
$GLOBALS['bitsnap_mock']['DeleteWebhook']       = array( 200, array() );
$GLOBALS['bitsnap_mock']['ListWebhooks']        = array( 200, array( 'webhooks' => array() ) );
$GLOBALS['bitsnap_mock']['GetOrder']            = static function ( $body, $headers ) {
	if ( 'Bearer ' . $GLOBALS['oauth_state']['reject'] === ( $headers['Authorization'] ?? '' ) ) {
		return array( 401, array( 'code' => 'unauthenticated', 'message' => 'token expired' ) );
	}
	return array( 200, array( 'order' => array( 'id' => 'ord_x', 'clientReferenceId' => $body['clientReferenceId'] ?? '', 'paymentState' => 'PAYMENT_STATE_PENDING' ) ) );
};

$admin_id = 1;
wp_set_current_user( $admin_id );
$actions  = new OAuthActions( $plugin );
$callback = OAuthActions::callback_url();
$flash    = static function () use ( $admin_id ) {
	$notice = get_transient( 'bitsnap_wc_notice_' . $admin_id );
	delete_transient( 'bitsnap_wc_notice_' . $admin_id );
	return is_array( $notice ) ? $notice : array( 'type' => '', 'message' => '' );
};
$connect = static function ( $env, $mutate = null ) use ( $actions, $callback ) {
	$_GET     = array( 'env' => $env );
	$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bitsnap_oauth_connect_' . $env ) );
	$url      = smoke_redirect_of( array( $actions, 'connect' ) );
	if ( null === $url ) {
		return array( 'url' => null );
	}
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$_GET     = array( 'state' => $query['state'] ?? '', 'code' => 'smoke-code' );
	$_REQUEST = array();
	if ( $mutate ) {
		$_GET = $mutate( $_GET );
	}
	$back = smoke_redirect_of( array( $actions, 'callback' ) );
	return array( 'url' => $url, 'query' => $query, 'back' => $back, 'get' => $_GET );
};
$raw_option = static function () {
	return Plugin::instance()->settings()->all();
};

$html = ( new Gateway() )->generate_settings_html( ( new Gateway() )->get_form_fields(), false );
smoke_check( false !== strpos( $html, 'Connect with Bitsnap' ) && false !== strpos( $html, 'action=bitsnap_oauth_connect' ), 'the settings page offers "Connect with Bitsnap"' );

// Connect the test environment through the real handlers.
$flow = $connect( 'test' );
smoke_check( null !== $flow['url'] && 0 === strpos( $flow['url'], 'https://bitsnap.pl/oauth/authorize?' ), 'connect redirects to the Bitsnap consent page (not to the dashboard)', (string) $flow['url'] );
$q = $flow['query'];
smoke_check( 'code' === $q['response_type'] && 'S256' === $q['code_challenge_method'] && 'woocommerce offline_access' === $q['scope'] && 'test' === $q['environment'] && 'https://bitsnap.pl/api/v1' === $q['resource'] && $callback === $q['redirect_uri'] && 'smoke-client-1' === $q['client_id'], 'authorize URL has PKCE S256, scope, resource, environment and the callback' );
smoke_check( 1 === count( smoke_oauth_requests( '/oauth/register' ) ) && true === ( $GLOBALS['oauth_requests'][0]['fields']['redirect_uris'] === array( $callback ) ), 'the site registered once, with its callback' );

$tok = smoke_oauth_requests( '/oauth/token', 'authorization_code' );
smoke_check( 1 === count( $tok ) && 'smoke-code' === $tok[0]['fields']['code'] && 43 === strlen( $tok[0]['fields']['code_verifier'] ) && 'https://bitsnap.pl/api/v1' === $tok[0]['fields']['resource'] && $callback === $tok[0]['fields']['redirect_uri'], 'the callback exchanged the code with a verifier, resource and redirect URI' );
smoke_check( rtrim( strtr( base64_encode( hash( 'sha256', $tok[0]['fields']['code_verifier'], true ) ), '+/', '-_' ), '=' ) === $q['code_challenge'], 'the verifier matches the challenge that was sent' );
smoke_check( 0 === strpos( (string) $flow['back'], admin_url( 'admin.php?page=wc-settings' ) ), 'the callback returns to the gateway settings' );
$notice = $flash();
smoke_check( 'success' === $notice['type'] && false !== strpos( $notice['message'], 'Lece na kodach' ), 'the merchant is told which project was connected', $notice['message'] );

$raw = $raw_option();
smoke_check( 0 === strpos( $raw['oauth_test_access'], 'bsenc1:' ) && 0 === strpos( $raw['oauth_test_refresh'], 'bsenc1:' ), 'access and refresh tokens are stored encrypted' );
smoke_check( false === strpos( wp_json_encode( $raw ), 'bsat_smoke' ) && false === strpos( wp_json_encode( $raw ), 'rt_smoke' ), 'no plain token in the option' );
smoke_check( 'Lece na kodach' === $raw['oauth_test_project_name'] && '18' === $raw['oauth_test_project_id'], 'project id and name are stored' );
$webhook_calls = array_filter( $GLOBALS['bitsnap_requests'], static function ( $r ) { return 'CreateWebhook' === $r['method']; } );
$webhook_call  = end( $webhook_calls );
smoke_check( 'Bearer bsat_smoke_1' === $webhook_call['headers']['Authorization'] && Gateway::webhook_url() === $webhook_call['body']['url'], 'the webhook is registered right after connecting, with the new token' );
smoke_check( 0 === strpos( $raw['webhook_id_test'], 'wh_smoke_' ) && 'whsec_OAUTH' === substr( $plugin->credentials()->webhook_secret( 'test' ), 0, 11 ), 'webhook id (plain) and secret (decrypted) are stored' );
smoke_check( 'oauth' === $plugin->credentials()->mode( 'test' ) && 'bsat_smoke_1' === $plugin->credentials()->api_key( 'test' ), 'OAuth takes precedence over the manual key bs_test_SMOKEKEY' );
smoke_check( 'manual' === $plugin->credentials()->mode( 'live' ) && 'bs_live_SMOKELIVE' === $plugin->credentials()->api_key( 'live' ), 'the live environment is untouched and still uses its manual key' );

$html = ( new Gateway() )->generate_settings_html( ( new Gateway() )->get_form_fields(), false );
smoke_check( false !== strpos( $html, 'Connected to <strong>Lece na kodach</strong> (test)' ) && false !== strpos( $html, 'action=bitsnap_oauth_disconnect' ), 'the settings page shows the connection and a Disconnect button' );
smoke_check( false === strpos( $html, 'bsat_smoke' ) && false === strpos( $html, 'rt_smoke' ) && false === strpos( $html, 'bsenc1:' ), 'the settings page never prints a token' );

// The state is single use.
$replay = $flow['get'];
$_GET   = $replay;
smoke_redirect_of( array( $actions, 'callback' ) );
$notice = $flash();
smoke_check( 'error' === $notice['type'] && 1 === count( smoke_oauth_requests( '/oauth/token', 'authorization_code' ) ), 'replaying the callback is refused without reaching the server' );
$_GET = array( 'state' => 'forged', 'code' => 'x' );
smoke_redirect_of( array( $actions, 'callback' ) );
smoke_check( 'error' === $flash()['type'] && 1 === count( smoke_oauth_requests( '/oauth/token', 'authorization_code' ) ), 'a forged state is refused' );

// A user who may not manage WooCommerce.
$sub = wp_insert_user( array( 'user_login' => 'smoke_sub_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
$_GET     = array( 'env' => 'test' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bitsnap_oauth_connect_test' ) );
smoke_check( smoke_dies( array( $actions, 'connect' ) ), 'a subscriber cannot start a connection' );
$_GET = array( 'state' => 'x', 'code' => 'x' );
smoke_check( smoke_dies( array( $actions, 'callback' ) ), 'a subscriber cannot use the callback' );
$_GET     = array( 'env' => 'test' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bitsnap_oauth_disconnect_test' ) );
smoke_check( smoke_dies( array( $actions, 'disconnect' ) ) && $plugin->oauth()->connected( 'test' ), 'a subscriber cannot disconnect' );
wp_set_current_user( $admin_id );
wp_delete_user( $sub );
// A state of another user.
$_GET     = array( 'env' => 'live' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bitsnap_oauth_connect_live' ) );
$url      = smoke_redirect_of( array( $actions, 'connect' ) );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $other );
$other_admin = wp_insert_user( array( 'user_login' => 'smoke_adm_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
wp_set_current_user( $other_admin );
$_GET = array( 'state' => $other['state'], 'code' => 'x' );
smoke_redirect_of( array( $actions, 'callback' ) );
$other_notice = get_transient( 'bitsnap_wc_notice_' . $other_admin );
delete_transient( 'bitsnap_wc_notice_' . $other_admin );
smoke_check( is_array( $other_notice ) && 'error' === $other_notice['type'] && ! $plugin->oauth()->connected( 'live' ) && 1 === count( smoke_oauth_requests( '/oauth/token', 'authorization_code' ) ), 'a state started by another user is refused without a token request' );
wp_set_current_user( $admin_id );
wp_delete_user( $other_admin );

// Refresh: a token that expires within a minute is replaced before the call.
$settings->update( array( 'oauth_test_expires' => (string) ( time() + 30 ) ) );
$before = count( smoke_oauth_requests( '/oauth/token', 'refresh_token' ) );
$old_refresh = $GLOBALS['oauth_state']['refresh']['test'];
$plugin->client( 'test' )->call( 'OrdersService', 'GetOrder', array( 'clientReferenceId' => '1' ) );
$refreshes = smoke_oauth_requests( '/oauth/token', 'refresh_token' );
$get       = smoke_last_request( 'GetOrder' );
smoke_check( $before + 1 === count( $refreshes ) && $old_refresh === end( $refreshes )['fields']['refresh_token'], 'a token expiring within 60 s is refreshed before the call' );
smoke_check( 0 === strpos( $get['headers']['Authorization'], 'Bearer bsat_smoke_' ) && 'Bearer bsat_smoke_1' !== $get['headers']['Authorization'], 'the call used the new access token', $get['headers']['Authorization'] );
$raw = $raw_option();
smoke_check( $GLOBALS['oauth_state']['refresh']['test'] === $plugin->settings()->get_secret( 'oauth_test_refresh' ) && $old_refresh !== $plugin->settings()->get_secret( 'oauth_test_refresh' ) && (int) $raw['oauth_test_expires'] > time() + 3000, 'the rotated refresh token and the new expiry are saved' );
$n = count( smoke_oauth_requests( '/oauth/token', 'refresh_token' ) );
$plugin->client( 'test' )->call( 'OrdersService', 'GetOrder', array( 'clientReferenceId' => '1' ) );
smoke_check( $n === count( smoke_oauth_requests( '/oauth/token', 'refresh_token' ) ), 'a token with time left is not refreshed' );

// A 401 refreshes and retries once.
$GLOBALS['oauth_state']['reject'] = $plugin->settings()->get_secret( 'oauth_test_access' );
$gets  = count( array_filter( $GLOBALS['bitsnap_requests'], static function ( $r ) { return 'GetOrder' === $r['method']; } ) );
$n     = count( smoke_oauth_requests( '/oauth/token', 'refresh_token' ) );
$reply = $plugin->client( 'test' )->call( 'OrdersService', 'GetOrder', array( 'clientReferenceId' => '2' ) );
$gets2 = count( array_filter( $GLOBALS['bitsnap_requests'], static function ( $r ) { return 'GetOrder' === $r['method']; } ) );
smoke_check( 'PAYMENT_STATE_PENDING' === ( $reply['order']['paymentState'] ?? '' ) && $gets + 2 === $gets2 && $n + 1 === count( smoke_oauth_requests( '/oauth/token', 'refresh_token' ) ), 'a 401 refreshes the token and repeats the call once' );
$GLOBALS['oauth_state']['reject'] = '';
// A 401 that persists is not retried forever.
$GLOBALS['bitsnap_mock']['GetOrder'] = array( 401, array( 'code' => 'unauthenticated', 'message' => 'nope' ) );
$gets = count( array_filter( $GLOBALS['bitsnap_requests'], static function ( $r ) { return 'GetOrder' === $r['method']; } ) );
try {
	$plugin->client( 'test' )->call( 'OrdersService', 'GetOrder', array( 'clientReferenceId' => '3' ) );
	smoke_check( false, 'a persistent 401 throws' );
} catch ( \Bitsnap\WooCommerce\Api\ApiException $e ) {
	$gets2 = count( array_filter( $GLOBALS['bitsnap_requests'], static function ( $r ) { return 'GetOrder' === $r['method']; } ) );
	smoke_check( 401 === $e->get_http_status() && $gets + 2 === $gets2, 'a persistent 401 is retried once, then reported' );
}
$GLOBALS['bitsnap_mock']['GetOrder'] = static function ( $body, $headers ) {
	return array( 200, array( 'order' => array( 'id' => 'ord_x', 'clientReferenceId' => $body['clientReferenceId'] ?? '', 'paymentState' => 'PAYMENT_STATE_PENDING' ) ) );
};

// Actual competing PHP requests: the loser must observe the winner's tokens.
$token_store = new SettingsTokenStore( $plugin->settings() );
$backup_tokens = $token_store->get( 'test', true );
$settings->update( array( 'oauth_test_expires' => (string) ( time() + 5 ) ) );
$process_lock = new \Bitsnap\WooCommerce\Support\Lock( 'tokens:test' );
delete_option( 'bitsnap_smoke_child_ready' );
$pipes = array();
$child = proc_open( array( 'wp', 'eval', 'update_option("bitsnap_smoke_child_ready",1,false); echo \Bitsnap\WooCommerce\Plugin::instance()->oauth()->access_token("test");' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
for ( $i = 0; $i < 100; ++$i ) {
	wp_cache_delete( 'bitsnap_smoke_child_ready', 'options' );
	if ( get_option( 'bitsnap_smoke_child_ready' ) ) { break; }
	usleep( 50000 );
}
$settings->update( array( 'oauth_test_expires' => (string) ( time() + 3500 ) ), array( 'oauth_test_access' => 'bsat_process_winner', 'oauth_test_refresh' => 'rt_process_winner' ) );
$process_lock->release();
$child_output = trim( stream_get_contents( $pipes[1] ) );
$child_error = stream_get_contents( $pipes[2] );
fclose( $pipes[1] ); fclose( $pipes[2] );
smoke_check( 0 === proc_close( $child ) && 'bsat_process_winner' === $child_output, 'a concurrent refresh request uses the winner token without spending the old refresh token', $child_error );
$token_store->save( 'test', $backup_tokens );
delete_option( 'bitsnap_smoke_child_ready' );

// The refresh lock: the loser waits, re-reads the database and uses the winner's tokens.
$other_process = static function () use ( $wpdb ) {
	// What another PHP request does: writes new tokens to the database behind this request's cache.
	$row = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'bitsnap_oauth_tokens_test' ) ) );
	$row['oauth_test_access']  = Plugin::instance()->settings()->seal( 'bsat_from_winner' );
	$row['oauth_test_refresh'] = Plugin::instance()->settings()->seal( 'rt_from_winner' );
	$row['oauth_test_expires'] = (string) ( time() + 3500 );
	$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $row ) ), array( 'option_name' => 'bitsnap_oauth_tokens_test' ) );
	$GLOBALS['smoke_refresh_lock']->release();
};
$plugin->settings()->update( array( 'oauth_test_expires' => (string) ( time() + 10 ) ) );
$storage = new TransientStorage();
smoke_check( true === $storage->add( 'bitsnap_smoke_lock', 1, 30 ) && false === $storage->add( 'bitsnap_smoke_lock', 1, 30 ), 'the transient lock admits one holder' );
$storage->delete( 'bitsnap_smoke_lock' );
$GLOBALS['smoke_refresh_lock'] = new \Bitsnap\WooCommerce\Support\Lock( 'tokens:test' );
$waits  = 0;
$loser  = new OAuthService(
	new WpTransport(),
	$storage,
	new SettingsTokenStore( $plugin->settings() ),
	'https://bitsnap.pl',
	$callback,
	'Smoke',
	'dev',
	null,
	static function () use ( &$waits, $other_process ) {
		if ( 1 === ++$waits ) {
			$other_process();
		}
	}
);
$n = count( smoke_oauth_requests( '/oauth/token', 'refresh_token' ) );
$token = $loser->access_token( 'test' );
smoke_check( 'bsat_from_winner' === $token && $n === count( smoke_oauth_requests( '/oauth/token', 'refresh_token' ) ), 'the loser of the lock uses the tokens the winner saved and does not refresh', $token );
// Put a valid pair back so the next checks have a current refresh token.
$GLOBALS['oauth_state']['refresh']['test'] = 'rt_from_winner';

// Registration is lost: invalid_client re-registers and retries.
$plugin->settings()->update( array( 'oauth_test_expires' => (string) ( time() + 10 ) ) );
$GLOBALS['oauth_state']['reject_client'] = 1;
$clients = $GLOBALS['oauth_state']['clients'];
$plugin->client( 'test' )->call( 'OrdersService', 'GetOrder', array( 'clientReferenceId' => '4' ) );
smoke_check( $clients + 1 === $GLOBALS['oauth_state']['clients'] && 'smoke-client-' . ( $clients + 1 ) === $plugin->settings()->get( 'oauth_client_id' ), 'invalid_client makes the site register again and store the new client' );
$all_refreshes = smoke_oauth_requests( '/oauth/token', 'refresh_token' );
$last          = end( $all_refreshes );
smoke_check( 'smoke-client-' . ( $clients + 1 ) === $last['fields']['client_id'], 'the retry used the new client id' );

// Connect live as well; a revoked live grant must not touch test.
$GLOBALS['oauth_state']['next_env'] = 'live';
$live = $connect( 'live' );
smoke_check( 'success' === $flash()['type'] && $plugin->oauth()->connected( 'live' ) && $plugin->oauth()->connected( 'test' ), 'live connects on its own, test stays connected' );
smoke_check( 'oauth' === $plugin->credentials()->mode( 'live' ) && $plugin->credentials()->api_key( 'live' ) !== $plugin->credentials()->api_key( 'test' ), 'each environment has its own token' );
$GLOBALS['oauth_state']['grant_error'] = 'invalid_grant';
$plugin->settings()->update( array( 'oauth_live_expires' => (string) ( time() + 5 ) ) );
try {
	$plugin->client( 'live' )->call( 'OrdersService', 'GetOrder', array( 'clientReferenceId' => '5' ) );
	smoke_check( false, 'a revoked grant throws' );
} catch ( \Bitsnap\WooCommerce\Api\ApiException $e ) {
	smoke_check( 'oauth_reconnect' === $e->get_reason() && false !== strpos( \Bitsnap\WooCommerce\Woo\ErrorMessages::describe( $e ), 'Connect with Bitsnap' ), 'a revoked grant gives a clear "connect again" error' );
}
$GLOBALS['oauth_state']['grant_error'] = '';
smoke_check( ! $plugin->oauth()->connected( 'live' ) && $plugin->oauth()->connected( 'test' ) && 'bs_live_SMOKELIVE' === $plugin->credentials()->api_key( 'live' ), 'only the revoked environment was cleared; it falls back to its manual key' );

// Disconnect test: webhook deleted by id, grant revoked, only test cleared.
$test_refresh = $plugin->settings()->get_secret( 'oauth_test_refresh' );
$stored_id    = $raw_option()['webhook_id_test'];
$live_before  = array( $raw_option()['webhook_id_live'] ?? '', $plugin->credentials()->webhook_secret( 'live' ), $plugin->credentials()->api_key( 'live' ) );
$GLOBALS['bitsnap_requests'] = array();
$_GET     = array( 'env' => 'test' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bitsnap_oauth_disconnect_test' ) );
smoke_redirect_of( array( $actions, 'disconnect' ) );
$del = smoke_last_request( 'DeleteWebhook' );
smoke_check( null !== $del && array( 'id' => $stored_id ) === $del['body'], 'disconnect deletes the webhook by its stored id', wp_json_encode( $del['body'] ?? null ) );
smoke_check( null === smoke_last_request( 'ListWebhooks' ), 'no listing when the id is known' );
$rev = smoke_oauth_requests( '/oauth/revoke' );
smoke_check( 1 === count( $rev ) && $test_refresh === $rev[0]['fields']['token'] && 'refresh_token' === $rev[0]['fields']['token_type_hint'] && '' !== $rev[0]['fields']['client_id'], 'the refresh token is revoked with the client id' );
$raw = $raw_option();
$left = array_filter( array_keys( $raw ), static function ( $k ) { return 0 === strpos( $k, 'oauth_test_' ) || 'webhook_id_test' === $k || 'webhook_secret_test' === $k; } );
smoke_check( array() === $left, 'every OAuth value, the webhook id and the webhook secret of test are gone', implode( ',', $left ) );
smoke_check( 'manual' === $plugin->credentials()->mode( 'test' ) && 'bs_test_SMOKEKEY' === $plugin->credentials()->api_key( 'test' ), 'the test environment is back on its manual key' );
smoke_check( 'success' === $flash()['type'], 'the merchant sees that it worked' );
smoke_check( isset( $raw['oauth_client_id'] ) && '' !== $live_before[1] && array( $raw['webhook_id_live'] ?? '', $plugin->credentials()->webhook_secret( 'live' ), $plugin->credentials()->api_key( 'live' ) ) === $live_before, 'disconnecting test left the live webhook and key as they were' );

// An install that has no stored webhook id finds it with ListWebhooks.
$GLOBALS['oauth_state']['next_env'] = 'test';
$connect( 'test' );
$flash();
$all = $plugin->settings()->all();
unset( $all['webhook_id_test'] );
update_option( Settings::OPTION, $all );
$GLOBALS['bitsnap_mock']['ListWebhooks'] = array( 200, array( 'webhooks' => array( array( 'id' => 'wh_other', 'url' => 'https://elsewhere.example/?wc-api=bitsnap' ), array( 'id' => 'wh_listed', 'url' => Gateway::webhook_url() ) ) ) );
$GLOBALS['bitsnap_requests'] = array();
$_GET     = array( 'env' => 'test' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bitsnap_oauth_disconnect_test' ) );
smoke_redirect_of( array( $actions, 'disconnect' ) );
$del = smoke_last_request( 'DeleteWebhook' );
smoke_check( null !== smoke_last_request( 'ListWebhooks' ) && array( 'id' => 'wh_listed' ) === ( $del['body'] ?? null ), 'without a stored id, disconnect finds the webhook by URL and deletes it by id' );
$flash();

// A failing delete is reported, and the grant is still revoked.
$GLOBALS['oauth_state']['next_env'] = 'test';
$connect( 'test' );
$flash();
$GLOBALS['bitsnap_mock']['DeleteWebhook'] = array( 500, array( 'code' => 'internal', 'message' => 'down' ) );
$_GET     = array( 'env' => 'test' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bitsnap_oauth_disconnect_test' ) );
smoke_redirect_of( array( $actions, 'disconnect' ) );
$notice = $flash();
smoke_check( 'warning' === $notice['type'] && ! $plugin->oauth()->connected( 'test' ), 'a webhook that cannot be deleted gives a warning, and the store is disconnected anyway' );
smoke_check( '' === $settings->get( 'webhook_id_test' ) && '' === $plugin->credentials()->webhook_secret( 'test' ), 'failed webhook deletion still clears local webhook credentials' );
$GLOBALS['bitsnap_mock']['DeleteWebhook'] = array( 200, array() );

// The manual "Register webhook" button stores the id too.
$GLOBALS['bitsnap_mock']['ListWebhooks'] = array( 200, array( 'webhooks' => array() ) );
$register = new RegisterWebhook( $plugin );
$_GET     = array( 'env' => 'test' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'bitsnap_register_webhook_test' ) );
smoke_redirect_of( array( $register, 'handle' ) );
$raw = $raw_option();
smoke_check( 0 === strpos( (string) ( $raw['webhook_id_test'] ?? '' ), 'wh_smoke_' ) && 'whsec_OAUTH' === substr( $plugin->credentials()->webhook_secret( 'test' ), 0, 11 ) && 'success' === $flash()['type'], 'manual "Register webhook" stores the webhook id and secret' );
$last = smoke_last_request( 'CreateWebhook' );
smoke_check( 'Bearer bs_test_SMOKEKEY' === $last['headers']['Authorization'], 'the manual registration used the manual key' );

$_GET     = array();
$_REQUEST = array();
$GLOBALS['bitsnap_mock'] = $saved_mock;
// Put back what the later sections of this script expect.
$plugin->settings()->set_secret( 'webhook_secret_test', 'whsec_SMOKESECRET' );

// ---------------------------------------------------------------- logging

smoke_section( 'Logging' );
$log_dir  = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : '';
$log_text = '';
foreach ( glob( $log_dir . '*bitsnap*' ) ?: array() as $file ) {
	$log_text .= (string) file_get_contents( $file );
}
smoke_check( '' !== $log_text, 'the debug log has entries' );
smoke_check( false === strpos( $log_text, 'SMOKEKEY' ) && false === strpos( $log_text, 'SMOKESECRET' ) && false === strpos( $log_text, 'bs_test_NEW' ), 'the log contains no key or secret' );
smoke_check( false === strpos( $log_text, 'bsat_smoke' ) && false === strpos( $log_text, 'rt_smoke' ) && false === strpos( $log_text, 'rt_from_winner' ) && false === strpos( $log_text, 'bsat_from_winner' ) && false === strpos( $log_text, 'whsec_OAUTH' ), 'the log contains no OAuth token or webhook secret' );
smoke_check( false === strpos( $log_text, 'anna@example.com' ), 'the log contains no buyer email' );
smoke_check( false === strpos( $log_text, 'response-buyer@example.com' ) && false === strpos( $log_text, 'private_response' ) && false === strpos( $log_text, $order->get_order_key() ), 'logs omit GetOrder buyer details, payment URLs and WooCommerce order keys' );

echo "\n{$GLOBALS['smoke_checks']} checks, {$GLOBALS['smoke_failures']} failed.\n";
if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::halt( 1 );
}
