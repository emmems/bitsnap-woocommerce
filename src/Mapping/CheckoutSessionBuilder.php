<?php
/**
 * Builds the `CreateCheckoutSession` request from a plain order snapshot.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Mapping;

use Bitsnap\WooCommerce\Support\Money;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BITSNAP_WC_TESTING' ) ) {
	exit;
}

/**
 * Pure mapping from a WooCommerce order snapshot to the contract's request (section 9.1).
 *
 * The snapshot is a plain array so this class is testable without WordPress.
 * `Woo\OrderReader` builds it from a `WC_Order`. Its shape:
 *
 * - `currency` string, `total` int (minor units, what the buyer pays).
 * - `items` list of `name`, `sku`, `quantity`, `gross_subtotal` (before coupons, with tax),
 *   `gross_total` (after coupons, with tax), `vat_rate` (?int), `image_url`.
 * - `fees` list of `name`, `gross_total` (may be negative), `vat_rate`.
 * - `shipping` list of `name`, `gross_total`, `vat_rate`.
 * - `buyer` array in the API shape, `success_url`, `cancel_url`, `reference`, `order_key`,
 *   `site_url`, `locale`, optional `order_number`, `plugin_version` and label strings.
 *
 * Totals must match to the minor unit. Unit prices are whole minor units, so a line
 * like 3 x 33.333 cannot be exact. The difference between the shop's total and the sum
 * of the lines is closed like this:
 *
 * - Shop total higher than the lines: add a "Rounding" line (quantity 1).
 * - Shop total lower than the lines: add the difference to `discount`.
 */
final class CheckoutSessionBuilder {

	/**
	 * Most lines the API accepts.
	 */
	public const MAX_LINES = 100;

	/**
	 * Longest name sent for a line.
	 */
	private const MAX_NAME = 250;

	/**
	 * Builds the request.
	 *
	 * @param array<string,mixed> $snapshot Order snapshot, see the class description.
	 * @return array{request:array<string,mixed>,delta:int} The request, and the signed
	 *         rounding difference that was closed (0 when the lines added up).
	 */
	public static function build( array $snapshot ): array {
		$currency = strtoupper( (string) ( $snapshot['currency'] ?? '' ) );
		$total    = (int) ( $snapshot['total'] ?? 0 );
		$discount = 0;
		$lines    = array();

		foreach ( (array) ( $snapshot['items'] ?? array() ) as $item ) {
			$quantity = (int) ( $item['quantity'] ?? 0 );
			if ( $quantity <= 0 ) {
				continue;
			}
			$subtotal  = (int) ( $item['gross_subtotal'] ?? 0 );
			$after     = (int) ( $item['gross_total'] ?? $subtotal );
			$discount += max( 0, $subtotal - $after );
			$lines[]   = self::line(
				(string) ( $item['name'] ?? '' ),
				max( 0, (int) round( $subtotal / $quantity ) ),
				$quantity,
				$currency,
				isset( $item['vat_rate'] ) ? (int) $item['vat_rate'] : null,
				(string) ( $item['sku'] ?? '' ),
				(string) ( $item['image_url'] ?? '' )
			);
		}

		foreach ( (array) ( $snapshot['fees'] ?? array() ) as $fee ) {
			$gross = (int) ( $fee['gross_total'] ?? 0 );
			if ( $gross < 0 ) {
				$discount += -$gross;
			} elseif ( $gross > 0 ) {
				$lines[] = self::line(
					(string) ( $fee['name'] ?? '' ),
					$gross,
					1,
					$currency,
					isset( $fee['vat_rate'] ) ? (int) $fee['vat_rate'] : null
				);
			}
		}

		$lines = self::cap_lines( $lines, $currency, (string) ( $snapshot['other_items_label'] ?? 'Other items (%d)' ) );

		$shipping       = self::shipping( (array) ( $snapshot['shipping'] ?? array() ), $currency );
		$shipping_total = null === $shipping ? 0 : $shipping['price']['amount'];

		$lines_total = 0;
		foreach ( $lines as $line ) {
			$lines_total += $line['unitPrice']['amount'] * $line['quantity'];
		}

		$delta = $total - ( $lines_total + $shipping_total - $discount );
		if ( $delta > 0 ) {
			$lines[] = self::line( (string) ( $snapshot['rounding_label'] ?? 'Rounding' ), $delta, 1, $currency, null );
		} elseif ( $delta < 0 ) {
			$discount += -$delta;
		}

		$request = array( 'lines' => $lines );
		if ( null !== $shipping ) {
			$request['shipping'] = $shipping;
		}
		if ( $discount > 0 ) {
			$request['discount'] = Money::make( $discount, $currency );
		}
		$request['total'] = Money::make( $total, $currency );

		$buyer = self::prune( (array) ( $snapshot['buyer'] ?? array() ) );
		if ( array() !== $buyer ) {
			$request['buyer'] = $buyer;
		}

		$request['successUrl']        = (string) ( $snapshot['success_url'] ?? '' );
		$request['cancelUrl']         = (string) ( $snapshot['cancel_url'] ?? '' );
		$request['clientReferenceId'] = self::truncate( (string) ( $snapshot['reference'] ?? '' ), 200 );
		$request['metadata']          = self::metadata( $snapshot );

		$locale = (string) ( $snapshot['locale'] ?? '' );
		if ( in_array( $locale, array( 'pl', 'en' ), true ) ) {
			$request['locale'] = $locale;
		}

		return array(
			'request' => $request,
			'delta'   => $delta,
		);
	}

	/**
	 * Removes the buyer from a request, keeping everything else (used when the key has no PII scope).
	 *
	 * @param array<string,mixed> $request A request from {@see self::build()}.
	 * @return array<string,mixed>
	 */
	public static function without_buyer( array $request ): array {
		unset( $request['buyer'] );
		return $request;
	}

	/**
	 * Builds one order line.
	 *
	 * @param string   $name     Line name.
	 * @param int      $unit     Gross unit price, minor units.
	 * @param int      $quantity Quantity.
	 * @param string   $currency Currency code.
	 * @param int|null $vat_rate Whole percent, or null.
	 * @param string   $sku      SKU.
	 * @param string   $image    Image URL.
	 * @return array<string,mixed>
	 */
	private static function line( string $name, int $unit, int $quantity, string $currency, ?int $vat_rate, string $sku = '', string $image = '' ): array {
		$line = array(
			'name'      => self::truncate( '' === trim( $name ) ? '-' : $name, self::MAX_NAME ),
			'unitPrice' => Money::make( $unit, $currency ),
			'quantity'  => $quantity,
		);
		if ( null !== $vat_rate ) {
			$line['vatRate'] = $vat_rate;
		}
		if ( '' !== $sku ) {
			$line['sku'] = self::truncate( $sku, 100 );
		}
		if ( '' !== $image ) {
			$line['imageUrl'] = $image;
		}
		return $line;
	}

	/**
	 * Folds lines beyond the limit into one line, leaving room for a rounding line.
	 *
	 * @param array<int,array<string,mixed>> $lines    Lines.
	 * @param string                         $currency Currency code.
	 * @param string                         $label    sprintf() pattern with one %d.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cap_lines( array $lines, string $currency, string $label ): array {
		$limit = self::MAX_LINES - 2;
		if ( count( $lines ) <= $limit ) {
			return $lines;
		}
		$keep = array_slice( $lines, 0, $limit - 1 );
		$rest = array_slice( $lines, $limit - 1 );
		$sum  = 0;
		foreach ( $rest as $line ) {
			$sum += $line['unitPrice']['amount'] * $line['quantity'];
		}
		$keep[] = self::line( sprintf( $label, count( $rest ) ), $sum, 1, $currency, null );
		return $keep;
	}

	/**
	 * Combines all shipping lines into the single `shipping` object of the contract.
	 *
	 * Free shipping returns null. With several lines the names are joined, the prices
	 * are added, and the VAT rate is the one of the most expensive line.
	 *
	 * @param array<int,array<string,mixed>> $shipping Shipping lines of the snapshot.
	 * @param string                         $currency Currency code.
	 * @return array<string,mixed>|null
	 */
	private static function shipping( array $shipping, string $currency ): ?array {
		$names = array();
		$sum   = 0;
		$best  = -1;
		$rate  = null;
		foreach ( $shipping as $row ) {
			$gross = (int) ( $row['gross_total'] ?? 0 );
			$name  = trim( (string) ( $row['name'] ?? '' ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
			$sum += $gross;
			if ( $gross > $best ) {
				$best = $gross;
				$rate = isset( $row['vat_rate'] ) ? (int) $row['vat_rate'] : null;
			}
		}
		if ( $sum <= 0 ) {
			return null;
		}
		$joined = implode( ', ', array_unique( $names ) );
		$out    = array(
			'name'  => self::truncate( '' === $joined ? '-' : $joined, self::MAX_NAME ),
			'price' => Money::make( $sum, $currency ),
		);
		if ( null !== $rate ) {
			$out['vatRate'] = $rate;
		}
		return $out;
	}

	/**
	 * Builds the metadata map (at most 20 keys, values up to 500 characters).
	 *
	 * @param array<string,mixed> $snapshot Order snapshot.
	 * @return array<string,string>
	 */
	private static function metadata( array $snapshot ): array {
		$meta   = array(
			'source'     => 'woocommerce',
			'wcOrderKey' => (string) ( $snapshot['order_key'] ?? '' ),
			'siteUrl'    => (string) ( $snapshot['site_url'] ?? '' ),
		);
		$number = (string) ( $snapshot['order_number'] ?? '' );
		if ( '' !== $number && (string) ( $snapshot['reference'] ?? '' ) !== $number ) {
			$meta['wcOrderNumber'] = $number;
		}
		$version = (string) ( $snapshot['plugin_version'] ?? '' );
		if ( '' !== $version ) {
			$meta['pluginVersion'] = $version;
		}
		foreach ( $meta as $key => $value ) {
			$meta[ $key ] = self::truncate( $value, 500 );
		}
		return array_slice( $meta, 0, 20, true );
	}

	/**
	 * Removes empty strings, nulls and empty arrays, recursively. An address with no
	 * street, city or postal code is dropped even if it has a country.
	 *
	 * @param array<string,mixed> $data Data to clean.
	 * @return array<string,mixed>
	 */
	private static function prune( array $data ): array {
		$clean = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::prune( $value );
				if ( preg_match( '/Address$/', (string) $key )
					&& ! isset( $value['line1'] ) && ! isset( $value['city'] ) && ! isset( $value['postalCode'] ) ) {
					continue;
				}
			}
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	/**
	 * Cuts a string to a number of characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function truncate( string $text, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length );
		}
		return substr( $text, 0, $length );
	}
}
