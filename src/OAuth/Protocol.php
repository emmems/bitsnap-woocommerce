<?php
/**
 * Pure OAuth protocol helpers.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Functions of the OAuth 2.1 / PKCE client that need neither WordPress nor the network.
 * Everything that depends on the current time takes it as an argument.
 */
final class Protocol {

	/**
	 * Scope the plugin asks for. `woocommerce` expands on the Bitsnap side to the scopes the plugin needs.
	 */
	public const SCOPE = 'woocommerce offline_access';

	/**
	 * Seconds before expiry at which an access token is refreshed.
	 */
	public const REFRESH_WINDOW = 60;

	/**
	 * Lifetime of the authorization state, in seconds.
	 */
	public const STATE_TTL = 600;

	/**
	 * Base64url without padding (RFC 7636, section 3).
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE needs base64url (RFC 7636), not obfuscation.
	}

	/**
	 * A new PKCE code verifier: 32 random bytes, 43 characters.
	 *
	 * @return string
	 */
	public static function verifier(): string {
		return self::base64url( random_bytes( 32 ) );
	}

	/**
	 * A new random authorization state.
	 *
	 * @return string 64 hexadecimal characters.
	 */
	public static function new_state(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * The S256 code challenge for a verifier.
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * The resource indicator: the API the token is for.
	 *
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function resource( string $base ): string {
		return rtrim( $base, '/' ) . '/api/v1';
	}

	/**
	 * Whether a URL may be used for OAuth: https, or http on localhost for development.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_allowed_url( string $url ): bool {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class, no WordPress.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' === $scheme ) {
			return true;
		}
		return 'http' === $scheme && in_array( strtolower( $parts['host'] ), array( 'localhost', '127.0.0.1' ), true );
	}

	/**
	 * The dynamic client registration request (RFC 7591).
	 *
	 * @param string $name         Client name.
	 * @param string $redirect_uri Callback URL.
	 * @param string $version      Plugin version.
	 * @return array<string,mixed>
	 */
	public static function registration_request( string $name, string $redirect_uri, string $version ): array {
		return array(
			'client_name'                => $name,
			'redirect_uris'              => array( $redirect_uri ),
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'software_id'                => 'bitsnap-for-woocommerce',
			'software_version'           => $version,
		);
	}

	/**
	 * The URL the merchant is sent to.
	 *
	 * @param string $base        Base URL.
	 * @param string $client      Client id.
	 * @param string $redirect    Redirect URI.
	 * @param string $state       State.
	 * @param string $challenge   S256 code challenge.
	 * @param string $environment 'test' or 'live'.
	 * @return string
	 */
	public static function authorize_url( string $base, string $client, string $redirect, string $state, string $challenge, string $environment ): string {
		return rtrim( $base, '/' ) . '/oauth/authorize?' . http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client,
				'redirect_uri'          => $redirect,
				'scope'                 => self::SCOPE,
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'resource'              => self::resource( $base ),
				'environment'           => $environment,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Whether a stored state record belongs to the state in the callback.
	 *
	 * @param array<string,mixed> $record State record from storage.
	 * @param string              $state  State from the callback.
	 * @param int                 $user   Current user id.
	 * @param int                 $now    Current time.
	 * @return bool
	 */
	public static function valid_state( array $record, string $state, int $user, int $now ): bool {
		if ( '' === $state || ! isset( $record['state'], $record['user'], $record['expires'], $record['verifier'], $record['env'] ) ) {
			return false;
		}
		return hash_equals( (string) $record['state'], $state )
			&& (int) $record['user'] === $user
			&& (int) $record['expires'] >= $now
			&& in_array( $record['env'], array( 'test', 'live' ), true )
			&& '' !== (string) $record['verifier'];
	}

	/**
	 * Whether an access token expiring at a time must be refreshed now.
	 *
	 * @param int $expires_at Expiry time (Unix). 0 means unknown.
	 * @param int $now        Current time.
	 * @return bool
	 */
	public static function should_refresh( int $expires_at, int $now ): bool {
		return $expires_at <= $now + self::REFRESH_WINDOW;
	}

	/**
	 * Whether an API answer calls for a token refresh and one retry.
	 *
	 * @param int  $status            HTTP status of the API answer.
	 * @param bool $already_refreshed Whether this call already refreshed once.
	 * @return bool
	 */
	public static function should_refresh_after_response( int $status, bool $already_refreshed ): bool {
		return 401 === $status && ! $already_refreshed;
	}

	/**
	 * Reads a token endpoint answer.
	 *
	 * @param array<string,mixed> $data            Decoded JSON.
	 * @param int                 $now             Current time.
	 * @param bool                $require_project Whether `bitsnap_project_*` and `bitsnap_environment` must be present (the authorization code grant). A refresh may omit them.
	 * @return array<string,mixed>|null Tokens, or null when the answer is not usable.
	 */
	public static function parse_tokens( array $data, int $now, bool $require_project = true ): ?array {
		$required = array( 'access_token', 'expires_in' );
		if ( $require_project ) {
			$required = array_merge( $required, array( 'refresh_token', 'bitsnap_project_id', 'bitsnap_project_name', 'bitsnap_environment' ) );
		}
		foreach ( $required as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) || '' === (string) $data[ $key ] ) {
				return null;
			}
		}
		$environment = isset( $data['bitsnap_environment'] ) && is_scalar( $data['bitsnap_environment'] ) ? (string) $data['bitsnap_environment'] : '';
		if ( ( $require_project || '' !== $environment ) && ! in_array( $environment, array( 'test', 'live' ), true ) ) {
			return null;
		}
		if ( ! is_numeric( $data['expires_in'] ) || (int) $data['expires_in'] < 1 ) {
			return null;
		}
		return array(
			'access_token'  => (string) $data['access_token'],
			'refresh_token' => isset( $data['refresh_token'] ) && is_scalar( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '',
			'expires_at'    => $now + (int) $data['expires_in'],
			'project_id'    => isset( $data['bitsnap_project_id'] ) && is_scalar( $data['bitsnap_project_id'] ) ? (string) $data['bitsnap_project_id'] : '',
			'project_name'  => isset( $data['bitsnap_project_name'] ) && is_scalar( $data['bitsnap_project_name'] ) ? (string) $data['bitsnap_project_name'] : '',
			'environment'   => $environment,
		);
	}

	/**
	 * The OAuth `error` code of an error answer (RFC 6749, section 5.2).
	 *
	 * @param string $body Raw response body.
	 * @return string '' when the body is not an OAuth error.
	 */
	public static function error_code( string $body ): string {
		$data = json_decode( $body, true );
		return is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';
	}

	/**
	 * Whether a token endpoint answer means the client registration is gone, so the site must register again.
	 * RFC 6749 allows 400 or 401 with `invalid_client`, and RFC 7591 clients are deleted on the server side,
	 * so the decision is based on the error code in the JSON body, not on the status alone.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw response body.
	 * @return bool
	 */
	public static function should_reregister( int $status, string $body ): bool {
		return ( 400 === $status || 401 === $status ) && 'invalid_client' === self::error_code( $body );
	}
}
