<?php
/**
 * Order meta keys written by the plugin.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta keys. All start with an underscore so they stay hidden from the "Custom Fields" box.
 * They are read and written only through the `WC_Order` meta methods, so they work with
 * High-Performance Order Storage.
 */
final class Meta {

	/**
	 * Bitsnap order id of the latest payment session.
	 */
	public const ORDER_ID = '_bitsnap_order_id';

	/**
	 * Environment of the latest session: 'test' or 'live'.
	 */
	public const ENVIRONMENT = '_bitsnap_environment';

	/**
	 * Number of the latest idempotency attempt.
	 */
	public const ATTEMPT = '_bitsnap_attempt';

	/**
	 * Fingerprint of the latest request.
	 */
	public const FINGERPRINT = '_bitsnap_request_fingerprint';

	/**
	 * Unix time the latest session expires.
	 */
	public const EXPIRES_AT = '_bitsnap_expires_at';

	/**
	 * Processed webhook event ids (list, capped).
	 */
	public const EVENTS = '_bitsnap_events';

	/**
	 * Bitsnap refund ids already recorded in WooCommerce (list, capped).
	 */
	public const REFUND_IDS = '_bitsnap_refund_ids';

	/**
	 * 'cod' or 'manual' while the order waits for an offline payment, else empty.
	 */
	public const OFFLINE = '_bitsnap_offline';

	/**
	 * Gateway the buyer paid with, for example `blik`.
	 */
	public const PAYMENT_GATEWAY = '_bitsnap_payment_gateway';

	/**
	 * Unix time the return page checked the payment (reset with every new session).
	 */
	public const RETURN_CHECKED = '_bitsnap_return_checked';

	/**
	 * Unix time the reconcile job last checked the order.
	 */
	public const RECONCILED_AT = '_bitsnap_reconciled_at';

	/**
	 * Set when the reconcile job gave up on the order.
	 */
	public const GAVE_UP = '_bitsnap_gave_up';

	/**
	 * Most ids kept in each list.
	 */
	public const LIST_CAP = 50;

	/** Invoice URL already noted on the order. */
	public const INVOICE_URL = '_bitsnap_invoice_url';
}
