<?php
/** OAuth registration, authorization and token lifecycle. */
namespace Bitsnap\WooCommerce\OAuth;

use Bitsnap\WooCommerce\Support\Settings;

/** WordPress adapter around the pure OAuth protocol functions. */
final class Service {
	private $settings;
	public function __construct( Settings $settings ) {
		$this->settings = $settings; }
	private function key( string $env, string $suffix ): string {
		return 'oauth_' . $env . '_' . $suffix; }
	public function connected( string $env ): bool {
		return '' !== $this->settings->get_secret( $this->key( $env, 'access' ) ) && '' !== $this->settings->get_secret( $this->key( $env, 'refresh' ) ); }
	public function token( string $env ): string {
		return $this->settings->get_secret( $this->key( $env, 'access' ) ); }
	public function refresh_token( string $env ): string {
		return $this->settings->get_secret( $this->key( $env, 'refresh' ) ); }
	public function expires_at( string $env ): int {
		return (int) $this->settings->get( $this->key( $env, 'expires' ), '0' ); }
	public function info( string $env ): array {
		return array(
			'project_name' => $this->settings->get( $this->key( $env, 'project_name' ) ),
			'environment'  => $env,
		); }
	public function save( array $tokens ): void {
		$env = $tokens['environment'];
		foreach ( array(
			'access'  => 'access_token',
			'refresh' => 'refresh_token',
		) as $key => $field ) {
			$this->settings->set_secret( $this->key( $env, $key ), $tokens[ $field ] ); }
		$all                                       = $this->settings->all();
		$all[ $this->key( $env, 'expires' ) ]      = (string) $tokens['expires_at'];
		$all[ $this->key( $env, 'project_id' ) ]   = $tokens['project_id'];
		$all[ $this->key( $env, 'project_name' ) ] = $tokens['project_name'];
		update_option( Settings::OPTION, $all );
	}
	public function clear( string $env ): void {
		$all = $this->settings->all();
		foreach ( array( 'access', 'refresh', 'expires', 'project_id', 'project_name', 'client_id' ) as $suffix ) {
			unset( $all[ $this->key( $env, $suffix ) ] );
		} update_option( Settings::OPTION, $all ); }
	public function client_id( string $env, string $base ): string {
		$cache = 'bitsnap_oauth_client_' . md5( home_url() . '|' . $base . '|' . $env );
		$id    = get_transient( $cache );
		if ( is_string( $id ) && '' !== $id ) {
			return $id; }
		$response = wp_remote_post(
			rtrim( $base, '/' ) . '/oauth/register',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'client_name'                => 'Bitsnap for WooCommerce – ' . wp_parse_url( home_url(), PHP_URL_HOST ),
						'redirect_uris'              => array( admin_url( 'admin-post.php?action=bitsnap_oauth_callback' ) ),
						'token_endpoint_auth_method' => 'none',
						'grant_types'                => array( 'authorization_code', 'refresh_token' ),
						'response_types'             => array( 'code' ),
						'software_id'                => 'bitsnap-for-woocommerce',
						'software_version'           => defined( 'BITSNAP_WC_VERSION' ) ? BITSNAP_WC_VERSION : 'dev',
					)
				),
			)
		);
		if ( is_wp_error( $response ) || 200 > wp_remote_retrieve_response_code( $response ) || 300 <= wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( __( 'Bitsnap could not register this store. Check the API base URL and try again.', 'bitsnap-for-woocommerce' ) ); }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['client_id'] ) ) {
			throw new \RuntimeException( __( 'Bitsnap returned an invalid client registration response.', 'bitsnap-for-woocommerce' ) ); }
		set_transient( $cache, sanitize_text_field( $data['client_id'] ), DAY_IN_SECONDS * 30 );
		return sanitize_text_field( $data['client_id'] );
	}
	public function token_request( string $base, array $fields, string $env ): array {
		$cache = 'bitsnap_oauth_client_' . md5( home_url() . '|' . $base . '|' . $env );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$response = wp_remote_post(
				rtrim( $base, '/' ) . '/oauth/token',
				array(
					'timeout' => 20,
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => $fields,
				)
			);
			if ( ! is_wp_error( $response ) && 200 <= wp_remote_retrieve_response_code( $response ) && 300 > wp_remote_retrieve_response_code( $response ) ) {
				$parsed = Protocol::parse_tokens( json_decode( wp_remote_retrieve_body( $response ), true ) ?: array() );
				if ( $parsed ) {
					return $parsed; }
			}
			$body = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( 0 === $attempt && is_array( $data ) && Protocol::should_reregister( (int) wp_remote_retrieve_response_code( $response ), (string) ( $data['error'] ?? '' ) ) ) {
				delete_transient( $cache );
				$fields['client_id'] = $this->client_id( $env, $base );
				continue; }
			throw new \RuntimeException( __( 'Bitsnap authorization failed. Please try connecting again.', 'bitsnap-for-woocommerce' ) );
		}
		throw new \RuntimeException( __( 'Bitsnap authorization failed.', 'bitsnap-for-woocommerce' ) );
	}
}
