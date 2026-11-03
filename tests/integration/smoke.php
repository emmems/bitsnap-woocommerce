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
			$mock = $mock( json_decode( $args['body'], true ) );
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

$plugin = Plugin::instance();

update_option( 'woocommerce_currency', 'PLN' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( 'woocommerce_default_country', 'PL' );
update_option( 'woocommerce_tax_based_on', 'base' );
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
	return wp_json_encode(
		array(
			'projectId' => 'proj_1',
			'eventData' => array_merge(
				array(
					'id'    => $id,
					'event' => $type,
					'order' => array(
						'id'                => 'ord_smoke_1',
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
$man->update_meta_data( Meta::ORDER_ID, 'ord_man' );
$man->update_meta_data( Meta::ENVIRONMENT, 'test' );
$man->save();
smoke_deliver( smoke_event( $man, 'TRANSACTION_CREATED', 'evt_m1', 'PAYMENT_STATE_AWAITING_OFFLINE', 'manual', array(), array(), '' ) );
smoke_check( 'on-hold' === wc_get_order( $man->get_id() )->get_status(), 'manual transfer choice puts the order on hold' );
smoke_deliver( smoke_event( $man, 'TRANSACTION_SUCCESS', 'evt_m2', 'PAYMENT_STATE_PAID', 'manual', array(), array(), 'pay_man' ) );
smoke_check( wc_get_order( $man->get_id() )->is_paid(), 'a confirmed manual transfer completes the order' );

// ---------------------------------------------------------------- refunds

smoke_section( 'Refunds' );
$order = wc_get_order( $order->get_id() );
$GLOBALS['bitsnap_mock']['CreateRefund'] = array(
	200,
	array( 'refund' => array( 'id' => 'ref_a', 'orderId' => 'ord_smoke_1', 'amount' => array( 'amount' => 500, 'currency' => 'PLN' ), 'status' => 'REFUND_STATUS_SUCCEEDED', 'createdAt' => gmdate( 'c' ) ) ),
);
$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '5.00', 'reason' => 'Smoke test', 'refund_payment' => true ) );
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
smoke_deliver( smoke_event( $order, 'REFUND_SUCCEEDED', 'evt_r3', 'PAYMENT_STATE_PARTIALLY_REFUNDED', 'blik', array( 'refund' => array( 'id' => 'ref_dash', 'amount' => array( 'amount' => '1000', 'currency' => 'PLN' ) ) ) ) );
smoke_check( $before + 1 === count( wc_get_order( $order->get_id() )->get_refunds() ), 'the same Bitsnap refund id is not recorded twice (different event id)' );

$GLOBALS['bitsnap_mock']['CreateRefund'] = array( 200, array( 'refund' => array( 'id' => 'ref_m', 'status' => 'REFUND_STATUS_MANUAL_REQUIRED' ) ) );
$manual = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '1.00', 'refund_payment' => true ) );
smoke_check( is_wp_error( $manual ) && false !== strpos( $manual->get_error_message(), 'Refund manually' ), 'MANUAL_REQUIRED becomes a clear WP_Error', is_wp_error( $manual ) ? $manual->get_error_message() : 'no error' );
smoke_check( $before + 1 === count( wc_get_order( $order->get_id() )->get_refunds() ), 'no WooCommerce refund is created when Bitsnap cannot refund' );
$GLOBALS['bitsnap_mock']['CreateRefund'] = array( 409, array( 'code' => 'failed_precondition', 'message' => '[refund_not_supported] x', 'details' => array( array( 'debug' => array( 'reason' => 'refund_not_supported' ) ) ) ) );
$unsupported = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '1.00', 'refund_payment' => true ) );
smoke_check( is_wp_error( $unsupported ) && false !== strpos( $unsupported->get_error_message(), 'cannot be refunded through Bitsnap' ), 'refund_not_supported becomes a clear WP_Error' );

// ---------------------------------------------------------------- return page and reconcile

smoke_section( 'Return page and reconcile job' );
$GLOBALS['bitsnap_mock']['GetOrder'] = static function ( $req ) {
	$o = wc_get_order( (int) $req['clientReferenceId'] );
	return array(
		200,
		array(
			'order' => array(
				'id'                => 'ord_x',
				'clientReferenceId' => $req['clientReferenceId'],
				'metadata'          => array( 'source' => 'woocommerce', 'wcOrderKey' => $o->get_order_key() ),
				'paymentGateway'    => 'payu',
				'paymentId'         => 'pay_poll',
				'paymentState'      => $GLOBALS['bitsnap_state'] ?? 'PAYMENT_STATE_PAID',
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
$rc->update_meta_data( Meta::ORDER_ID, 'ord_rc' );
$rc->update_meta_data( Meta::ENVIRONMENT, 'test' );
$rc->set_date_created( time() - 1800 );
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
smoke_check( wc_get_order( $rc->get_id() )->is_paid(), 'the reconcile job completes an order pending for 30 minutes' );
smoke_check( 'pending' === wc_get_order( $fresh->get_id() )->get_status(), 'the reconcile job leaves a fresh order alone' );
smoke_check( 'pending' === wc_get_order( $old->get_id() )->get_status() && '' !== (string) wc_get_order( $old->get_id() )->get_meta( Meta::GAVE_UP ), 'the reconcile job gives up on an order older than 48 hours' );
smoke_check( false !== as_next_scheduled_action( Reconciler::HOOK, array(), Reconciler::GROUP ), 'the recurring Action Scheduler action is scheduled' );

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
	$registered = $registry->is_registered( 'bitsnap' );
} catch ( \Throwable $e ) {
	echo '  note: ' . $e->getMessage() . "\n";
}
smoke_check( $registered, 'Bitsnap is registered in the Blocks payment method registry' );

// ---------------------------------------------------------------- logging

smoke_section( 'Logging' );
$log_dir  = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : '';
$log_text = '';
foreach ( glob( $log_dir . '*bitsnap*' ) ?: array() as $file ) {
	$log_text .= (string) file_get_contents( $file );
}
smoke_check( '' !== $log_text, 'the debug log has entries' );
smoke_check( false === strpos( $log_text, 'SMOKEKEY' ) && false === strpos( $log_text, 'SMOKESECRET' ) && false === strpos( $log_text, 'bs_test_NEW' ), 'the log contains no key or secret' );
smoke_check( false === strpos( $log_text, 'anna@example.com' ), 'the log contains no buyer email' );

echo "\n{$GLOBALS['smoke_checks']} checks, {$GLOBALS['smoke_failures']} failed.\n";
if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::halt( 1 );
}
