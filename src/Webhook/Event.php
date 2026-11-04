<?php
/**
 * Parsed webhook event.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Webhook;

use Bitsnap\WooCommerce\Mapping\ApiOrder;
use Bitsnap\WooCommerce\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the payload `{ projectId, environment?, eventData: { id, eventId, event, order, customer, refund? } }`.
 */
final class Event {

	/**
	 * Reads the environment named in a payload without trusting it.
	 *
	 * Only used to decide which secret to try first. The signature decides trust.
	 *
	 * @param string $body Raw body.
	 * @return string|null 'test', 'live' or null.
	 */
	public static function peek_environment( string $body ): ?string {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$raw = '';
		if ( isset( $data['environment'] ) && is_string( $data['environment'] ) ) {
			$raw = $data['environment'];
		} elseif ( isset( $data['eventData']['order']['environment'] ) && is_string( $data['eventData']['order']['environment'] ) ) {
			$raw = $data['eventData']['order']['environment'];
		}
		return ApiOrder::environment( $raw );
	}

	/**
	 * Parses a verified body.
	 *
	 * @param string $body Raw body.
	 * @return array{id:string,legacy_id:string,type:string,environment:?string,order:array<string,mixed>,refund:?array<string,mixed>}|null Null if the body is not a valid event.
	 */
	public static function parse( string $body ): ?array {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['eventData'] ) || ! is_array( $data['eventData'] ) ) {
			return null;
		}
		$event = $data['eventData'];
		$type  = isset( $event['event'] ) && is_string( $event['event'] ) ? strtoupper( trim( $event['event'] ) ) : '';
		if ( '' === $type ) {
			return null;
		}

		$legacy_id = isset( $event['id'] ) && is_scalar( $event['id'] ) ? (string) $event['id'] : '';
		$id        = isset( $event['eventId'] ) && is_string( $event['eventId'] ) ? trim( $event['eventId'] ) : '';
		if ( '' === $id && '' !== $legacy_id ) {
			$id = $legacy_id . ':' . $type;
		}
		if ( '' === $id ) {
			// No event id to deduplicate on. Fall back to a hash of the body so a verbatim retry is still caught.
			$id = 'body:' . hash( 'sha256', $body );
		}

		$order       = ApiOrder::normalize( $event['order'] ?? array() );
		$environment = self::peek_environment( $body );
		if ( null === $order['environment'] ) {
			$order['environment'] = $environment;
		}

		return array(
			'id'          => $id,
			'legacy_id'   => $legacy_id !== $order['id'] ? $legacy_id : '',
			'type'        => $type,
			'environment' => $environment,
			'order'       => $order,
			'refund'      => self::refund( $event['refund'] ?? null ),
		);
	}

	/**
	 * Normalises the `refund` object (section 9.3).
	 *
	 * @param mixed $refund Decoded refund.
	 * @return array{id:string,amount:?int,status:string,reason:string,source:string}|null
	 */
	private static function refund( $refund ): ?array {
		if ( ! is_array( $refund ) ) {
			return null;
		}
		$str = static function ( string $key ) use ( $refund ): string {
			return isset( $refund[ $key ] ) && is_scalar( $refund[ $key ] ) ? (string) $refund[ $key ] : '';
		};
		return array(
			'id'     => $str( 'id' ),
			'amount' => Money::amount_of( $refund['amount'] ?? null ),
			'status' => ApiOrder::enum( $str( 'status' ), 'REFUND_STATUS_' ),
			'reason' => $str( 'reason' ),
			'source' => strtolower( $str( 'source' ) ),
		);
	}
}
