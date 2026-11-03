<?php
/**
 * Parses Connect error bodies.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Connect error body is `{ code, message, details: [...] }`. Bitsnap attaches an
 * `ErrorInfo` (`reason`, `metadata`) to every error, and also prefixes the message
 * with `[reason]`.
 *
 * A Connect JSON detail is `{ type, value: <base64 proto>, debug?: {...} }`. The reason is
 * read from, in order: `debug.reason`, a plain `reason`, the decoded proto in `value`,
 * and the `[reason]` prefix of the message.
 */
final class ErrorMapper {

	/**
	 * Parses an error response.
	 *
	 * @param string $body Response body.
	 * @return array{code:string,message:string,reason:string,metadata:array<string,string>}
	 */
	public static function parse( string $body ): array {
		$data = json_decode( $body, true );
		$data = is_array( $data ) ? $data : array();

		$code    = isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '';
		$message = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '';
		$reason  = '';
		$meta    = array();

		foreach ( (array) ( $data['details'] ?? array() ) as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}
			$found = self::from_detail( $detail );
			if ( '' !== $found['reason'] ) {
				$reason = $found['reason'];
				$meta   = $found['metadata'];
				break;
			}
		}

		if ( '' === $reason && preg_match( '/^\[([a-z0-9_]+)\]\s*/i', $message, $m ) ) {
			$reason = strtolower( $m[1] );
		}

		return array(
			'code'     => $code,
			'message'  => (string) preg_replace( '/^\[[a-z0-9_]+\]\s*/i', '', $message ),
			'reason'   => $reason,
			'metadata' => $meta,
		);
	}

	/**
	 * Reads reason and metadata from one detail entry.
	 *
	 * @param array<string,mixed> $detail A `details[]` entry.
	 * @return array{reason:string,metadata:array<string,string>}
	 */
	private static function from_detail( array $detail ): array {
		foreach ( array( $detail['debug'] ?? null, $detail ) as $source ) {
			if ( is_array( $source ) && isset( $source['reason'] ) && is_string( $source['reason'] ) && '' !== $source['reason'] ) {
				return array(
					'reason'   => $source['reason'],
					'metadata' => self::string_map( $source['metadata'] ?? array() ),
				);
			}
		}
		if ( isset( $detail['value'] ) && is_string( $detail['value'] ) ) {
			return self::decode_error_info( $detail['value'] );
		}
		return array(
			'reason'   => '',
			'metadata' => array(),
		);
	}

	/**
	 * Decodes a base64 `ErrorInfo` proto: field 1 is the reason, field 2 a repeated map entry.
	 *
	 * @param string $encoded Base64 (standard or URL-safe, padding optional).
	 * @return array{reason:string,metadata:array<string,string>}
	 */
	public static function decode_error_info( string $encoded ): array {
		$empty = array(
			'reason'   => '',
			'metadata' => array(),
		);
		$raw   = base64_decode( strtr( $encoded, '-_', '+/' ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a protobuf field from the API.
		if ( false === $raw || '' === $raw ) {
			return $empty;
		}
		$fields = self::read_fields( $raw );
		$reason = isset( $fields[1][0] ) ? $fields[1][0] : '';
		$meta   = array();
		foreach ( $fields[2] ?? array() as $entry ) {
			$pair = self::read_fields( $entry );
			if ( isset( $pair[1][0] ) ) {
				$meta[ $pair[1][0] ] = $pair[2][0] ?? '';
			}
		}
		return array(
			'reason'   => (string) $reason,
			'metadata' => $meta,
		);
	}

	/**
	 * Reads the length-delimited fields of a protobuf message. Other wire types are skipped.
	 *
	 * @param string $raw Message bytes.
	 * @return array<int,string[]> Field number => values.
	 */
	private static function read_fields( string $raw ): array {
		$fields = array();
		$pos    = 0;
		$len    = strlen( $raw );
		while ( $pos < $len ) {
			$tag = self::read_varint( $raw, $pos );
			if ( null === $tag ) {
				break;
			}
			$number = $tag >> 3;
			$wire   = $tag & 7;
			if ( 2 === $wire ) {
				$size = self::read_varint( $raw, $pos );
				if ( null === $size || $pos + $size > $len ) {
					break;
				}
				$fields[ $number ][] = substr( $raw, $pos, $size );
				$pos                += $size;
			} elseif ( 0 === $wire ) {
				if ( null === self::read_varint( $raw, $pos ) ) {
					break;
				}
			} elseif ( 1 === $wire ) {
				$pos += 8;
			} elseif ( 5 === $wire ) {
				$pos += 4;
			} else {
				break;
			}
		}
		return $fields;
	}

	/**
	 * Reads a varint and advances the position.
	 *
	 * @param string $raw Bytes.
	 * @param int    $pos Position, advanced by reference.
	 * @return int|null The value, or null at the end of the data.
	 */
	private static function read_varint( string $raw, int &$pos ): ?int {
		$result = 0;
		$shift  = 0;
		$len    = strlen( $raw );
		while ( $pos < $len ) {
			$byte    = ord( $raw[ $pos ] );
			$result |= ( $byte & 0x7F ) << $shift;
			++$pos;
			if ( 0 === ( $byte & 0x80 ) ) {
				return $result;
			}
			$shift += 7;
			if ( $shift > 56 ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Keeps only string => scalar pairs.
	 *
	 * @param mixed $value Decoded JSON.
	 * @return array<string,string>
	 */
	private static function string_map( $value ): array {
		$out = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_scalar( $item ) ) {
					$out[ (string) $key ] = (string) $item;
				}
			}
		}
		return $out;
	}
}
