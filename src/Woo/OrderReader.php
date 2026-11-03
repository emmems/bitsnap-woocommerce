<?php
/**
 * Reads a WooCommerce order into the plain snapshot the mapper needs.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

use Bitsnap\WooCommerce\Mapping\Nip;
use Bitsnap\WooCommerce\Mapping\VatRate;
use Bitsnap\WooCommerce\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place that touches `WC_Order` items. Everything downstream is pure.
 */
final class OrderReader {

	/**
	 * Builds the snapshot described in {@see \Bitsnap\WooCommerce\Mapping\CheckoutSessionBuilder}.
	 *
	 * @param \WC_Order $order       The order.
	 * @param string    $success_url Where Bitsnap sends the buyer after paying.
	 * @return array<string,mixed>
	 */
	public function snapshot( \WC_Order $order, string $success_url ): array {
		$tax_on = wc_tax_enabled();

		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/**
			 * Line item.
			 *
			 * @var \WC_Order_Item_Product $item
			 */
			$quantity = (float) $item->get_quantity();
			$name     = wp_strip_all_tags( $item->get_name() );
			if ( floor( $quantity ) !== $quantity || $quantity < 1 ) {
				// Weighed or fractional quantities cannot be sent as a whole number: send one line for the total.
				$name     = sprintf( '%s × %s', $name, (string) $quantity );
				$quantity = 1.0;
			}
			$product = $item->get_product();
			$items[] = array(
				'name'           => $name,
				'sku'            => $product ? (string) $product->get_sku() : '',
				'quantity'       => (int) $quantity,
				'gross_subtotal' => Money::to_minor( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax() ),
				'gross_total'    => Money::to_minor( (float) $item->get_total() + (float) $item->get_total_tax() ),
				'vat_rate'       => $this->vat_rate( $item, $tax_on, (float) $item->get_subtotal(), (float) $item->get_subtotal_tax() ),
				'image_url'      => $product ? $this->image_url( $product ) : '',
			);
		}

		$fees = array();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fees[] = array(
				'name'        => wp_strip_all_tags( $fee->get_name() ),
				'gross_total' => Money::to_minor( (float) $fee->get_total() + (float) $fee->get_total_tax() ),
				'vat_rate'    => $this->vat_rate( $fee, $tax_on, (float) $fee->get_total(), (float) $fee->get_total_tax() ),
			);
		}

		$shipping = array();
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$shipping[] = array(
				'name'        => wp_strip_all_tags( $line->get_name() ),
				'gross_total' => Money::to_minor( (float) $line->get_total() + (float) $line->get_total_tax() ),
				'vat_rate'    => $this->vat_rate( $line, $tax_on, (float) $line->get_total(), (float) $line->get_total_tax() ),
			);
		}

		$snapshot = array(
			'currency'          => $order->get_currency(),
			'total'             => Money::to_minor( (float) $order->get_total() ),
			'items'             => $items,
			'fees'              => $fees,
			'shipping'          => $shipping,
			'buyer'             => $this->buyer( $order ),
			'success_url'       => $success_url,
			'cancel_url'        => $this->cancel_url( $order ),
			'reference'         => (string) $order->get_id(),
			'order_number'      => (string) $order->get_order_number(),
			'order_key'         => $order->get_order_key(),
			'site_url'          => untrailingslashit( home_url() ),
			'locale'            => 0 === strpos( determine_locale(), 'pl' ) ? 'pl' : 'en',
			'plugin_version'    => defined( 'BITSNAP_WC_VERSION' ) ? BITSNAP_WC_VERSION : '',
			'rounding_label'    => __( 'Rounding', 'bitsnap-for-woocommerce' ),
			/* translators: %d: number of order lines folded into one line. */
			'other_items_label' => __( 'Other items (%d)', 'bitsnap-for-woocommerce' ),
		);

		/**
		 * Filters the order snapshot before it becomes a request.
		 *
		 * Amounts are integer minor units. The total of the lines must still match `total`.
		 *
		 * @param array     $snapshot Snapshot.
		 * @param \WC_Order $order    Order.
		 */
		return apply_filters( 'bitsnap_wc_order_snapshot', $snapshot, $order );
	}

	/**
	 * VAT rate of an order item.
	 *
	 * @param \WC_Order_Item $item        Line, fee or shipping item.
	 * @param bool           $tax_enabled Whether taxes are on.
	 * @param float          $net         Net amount.
	 * @param float          $tax         Tax amount.
	 * @return int|null
	 */
	private function vat_rate( \WC_Order_Item $item, bool $tax_enabled, float $net, float $tax ): ?int {
		$percents = array();
		if ( $tax_enabled && method_exists( $item, 'get_taxes' ) ) {
			$taxes = $item->get_taxes();
			foreach ( (array) ( $taxes['total'] ?? $taxes['subtotal'] ?? array() ) as $rate_id => $amount ) {
				if ( (float) $amount > 0 ) {
					$percents[] = (float) \WC_Tax::get_rate_percent_value( (int) $rate_id );
				}
			}
		}
		// With more than one rate (compound taxes) the sum of percents is wrong: use the amounts instead.
		if ( count( $percents ) > 1 ) {
			$percents = array();
		}
		$rate = VatRate::resolve( $tax_enabled, $percents, $net, $tax );

		/**
		 * Filters the VAT rate sent for an order item.
		 *
		 * @param int|null       $rate Whole percent, or null to leave it to the Bitsnap default.
		 * @param \WC_Order_Item $item Order item.
		 */
		return apply_filters( 'bitsnap_wc_item_vat_rate', $rate, $item );
	}

	/**
	 * Product image URL, https only.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private function image_url( \WC_Product $product ): string {
		$id  = $product->get_image_id();
		$url = $id ? wp_get_attachment_image_url( (int) $id, 'woocommerce_thumbnail' ) : '';
		return is_string( $url ) && 0 === strpos( $url, 'https://' ) ? $url : '';
	}

	/**
	 * Where the buyer lands after cancelling: the pay page of the same order, so they can retry.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function cancel_url( \WC_Order $order ): string {
		$url = add_query_arg(
			array(
				'pay_for_order'     => 'true',
				'key'               => $order->get_order_key(),
				'bitsnap_cancelled' => '1',
			),
			wc_get_endpoint_url( 'order-pay', $order->get_id(), wc_get_checkout_url() )
		);
		return esc_url_raw( $url );
	}

	/**
	 * Buyer details in the API shape.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private function buyer( \WC_Order $order ): array {
		$buyer = array(
			'email'          => $order->get_billing_email(),
			'firstName'      => $order->get_billing_first_name(),
			'lastName'       => $order->get_billing_last_name(),
			'phone'          => $order->get_billing_phone(),
			'company'        => $order->get_billing_company(),
			'nip'            => $this->nip( $order ),
			'billingAddress' => array(
				'line1'      => $order->get_billing_address_1(),
				'line2'      => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'postalCode' => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
			),
		);
		if ( $order->has_shipping_address() ) {
			$buyer['shippingAddress'] = array(
				'line1'      => $order->get_shipping_address_1(),
				'line2'      => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'postalCode' => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
			);
		}
		return $buyer;
	}

	/**
	 * The buyer's NIP, from the meta of common invoice plugins.
	 *
	 * @param \WC_Order $order Order.
	 * @return string Ten digits, or ''.
	 */
	private function nip( \WC_Order $order ): string {
		$meta = array();
		foreach ( Nip::META_KEYS as $key ) {
			$meta[ $key ] = $order->get_meta( $key );
		}
		$nip = Nip::from_meta( $meta );

		/**
		 * Filters the NIP sent for an order.
		 *
		 * Use it when your invoice plugin stores the NIP somewhere else. The value is
		 * validated (ten digits, checksum) after the filter. An invalid value is dropped.
		 *
		 * @param string|null $nip   NIP found in the known meta keys, or null.
		 * @param \WC_Order   $order Order.
		 */
		$filtered = apply_filters( 'bitsnap_wc_order_nip', $nip, $order );
		$valid    = is_scalar( $filtered ) ? Nip::normalize( (string) $filtered ) : null;
		return null === $valid ? '' : $valid;
	}
}
