<?php
/** Pure OAuth protocol helpers. */
namespace Bitsnap\WooCommerce\OAuth;

/** Pure functions used by the OAuth client and its unit tests. */
final class Protocol {
	public static function verifier(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); }
	public static function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); }
	public static function authorize_url( string $base, string $client, string $redirect, string $state, string $challenge, string $environment ): string {
		return rtrim( $base, '/' ) . '/oauth/authorize?' . http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client,
				'redirect_uri'          => $redirect,
				'scope'                 => 'woocommerce offline_access',
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'resource'              => rtrim( $base, '/' ) . '/api/v1',
				'environment'           => $environment,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}
	public static function valid_state( array $record, string $state, int $user, int $now ): bool {
		return isset( $record['state'], $record['user'], $record['expires'] ) && hash_equals( (string) $record['state'], $state ) && (int) $record['user'] === $user && (int) $record['expires'] >= $now; }
	public static function should_refresh( int $expires_at, int $now ): bool {
		return $expires_at <= $now + 60; }
	public static function parse_tokens( array $data ): ?array {
		$required = array( 'access_token', 'refresh_token', 'expires_in', 'bitsnap_project_id', 'bitsnap_project_name', 'bitsnap_environment' );
		foreach ( $required as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) || '' === (string) $data[ $key ] ) {
				return null; }
		}
		if ( ! in_array( (string) $data['bitsnap_environment'], array( 'test', 'live' ), true ) || (int) $data['expires_in'] < 1 ) {
			return null; }
		return array(
			'access_token'  => (string) $data['access_token'],
			'refresh_token' => (string) $data['refresh_token'],
			'expires_at'    => time() + (int) $data['expires_in'],
			'project_id'    => (string) $data['bitsnap_project_id'],
			'project_name'  => (string) $data['bitsnap_project_name'],
			'environment'   => (string) $data['bitsnap_environment'],
		);
	}
	public static function should_reregister( int $status, string $error ): bool {
		return 401 === $status && 'invalid_client' === $error; }
}
