<?php
/** OAuth admin-post handlers. */
namespace Bitsnap\WooCommerce\Admin;

use Bitsnap\WooCommerce\OAuth\Protocol;
use Bitsnap\WooCommerce\OAuth\Service;
use Bitsnap\WooCommerce\Plugin;
use Bitsnap\WooCommerce\Woo\Gateway;

/** Handles merchant connect callback and disconnect operations. */
final class OAuthActions {
	private $plugin;
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin; }
	public function register(): void {
		add_action( 'admin_post_bitsnap_oauth_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_bitsnap_oauth_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_bitsnap_oauth_disconnect', array( $this, 'disconnect' ) ); }
	private function service(): Service {
		return new Service( $this->plugin->settings() ); }
	private function back(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bitsnap' ); }
	public function connect(): void {
		$env = isset( $_GET['env'] ) ? sanitize_key( wp_unslash( $_GET['env'] ) ) : '';
		check_admin_referer( 'bitsnap_oauth_connect_' . $env );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! in_array( $env, array( 'test', 'live' ), true ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bitsnap-for-woocommerce' ), '', array( 'response' => 403 ) ); }
		try {
			$base      = $this->plugin->credentials()->base_url();
			$client    = $this->service()->client_id( $env, $base );
			$verifier  = Protocol::verifier();
			$state     = wp_generate_password( 40, false );
			$transient = 'bitsnap_oauth_state_' . hash( 'sha256', $state );
			set_transient(
				$transient,
				array(
					'state'    => $state,
					'user'     => get_current_user_id(),
					'expires'  => time() + 600,
					'verifier' => $verifier,
					'env'      => $env,
				),
				10 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( Protocol::authorize_url( $base, $client, admin_url( 'admin-post.php?action=bitsnap_oauth_callback' ), $state, Protocol::challenge( $verifier ), $env ), array( 'https' ) );
			exit;
		} catch ( \Throwable $e ) {
			Notices::flash( 'error', $e->getMessage() );
			wp_safe_redirect( $this->back() );
			exit; }
	}
	public function callback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bitsnap-for-woocommerce' ), '', array( 'response' => 403 ) ); }
		$state  = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$key    = 'bitsnap_oauth_state_' . hash( 'sha256', $state );
		$record = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $record ) || ! Protocol::valid_state( $record, $state, get_current_user_id(), time() ) ) {
			Notices::flash( 'error', __( 'The Bitsnap connection expired or could not be verified. Please connect again.', 'bitsnap-for-woocommerce' ) );
			wp_safe_redirect( $this->back() );
			exit; }
		if ( isset( $_GET['error'] ) ) {
			Notices::flash( 'error', sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ) ) );
			wp_safe_redirect( $this->back() );
			exit; }
		try {
			$env    = $record['env'];
			$base   = $this->plugin->credentials()->base_url();
			$client = $this->service()->client_id( $env, $base );
			$tokens = $this->service()->token_request(
				$base,
				array(
					'grant_type'    => 'authorization_code',
					'code'          => sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ),
					'redirect_uri'  => admin_url( 'admin-post.php?action=bitsnap_oauth_callback' ),
					'client_id'     => $client,
					'code_verifier' => $record['verifier'],
					'resource'      => rtrim( $base, '/' ) . '/api/v1',
				),
				$env
			);
			$this->service()->save( $tokens );
			$client_api = $this->plugin->client( $env );
			$hook       = $client_api->call(
				'WebhooksService',
				'CreateWebhook',
				array(
					'name'   => 'WooCommerce: ' . wp_parse_url( home_url(), PHP_URL_HOST ),
					'url'    => Gateway::webhook_url(),
					'events' => RegisterWebhook::EVENTS,
				),
				'woo-webhook-' . wp_generate_uuid4()
			);
			if ( ! empty( $hook['signingSecret'] ) ) {
				$this->plugin->settings()->set_secret( 'webhook_secret_' . $env, $hook['signingSecret'] );
			} Notices::flash( 'success', __( 'Connected with Bitsnap and registered the webhook.', 'bitsnap-for-woocommerce' ) );
		} catch ( \Throwable $e ) {
			Notices::flash( 'error', $e->getMessage() ); }
		wp_safe_redirect( $this->back() );
		exit;
	}
	public function disconnect(): void {
		$env = isset( $_GET['env'] ) ? sanitize_key( wp_unslash( $_GET['env'] ) ) : '';
		check_admin_referer( 'bitsnap_oauth_disconnect_' . $env );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! in_array( $env, array( 'test', 'live' ), true ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bitsnap-for-woocommerce' ), '', array( 'response' => 403 ) ); }
		$service = $this->service();
		$base    = $this->plugin->credentials()->base_url();
		try {
			$this->plugin->client( $env )->call( 'WebhooksService', 'DeleteWebhook', array( 'url' => Gateway::webhook_url() ) );
		} catch ( \Throwable $e ) {
			/* Deletion is best effort; revoke the grant regardless. */ } $client = $service->client_id( $env, $base );
		wp_remote_post(
			rtrim( $base, '/' ) . '/oauth/revoke',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'token'     => $service->refresh_token( $env ),
					'client_id' => $client,
				),
			)
		);
		$service->clear( $env );
		$all = $this->plugin->settings()->all();
		unset( $all[ 'webhook_secret_' . $env ] );
		update_option( \Bitsnap\WooCommerce\Support\Settings::OPTION, $all );
		Notices::flash( 'success', __( 'Disconnected from Bitsnap.', 'bitsnap-for-woocommerce' ) );
		wp_safe_redirect( $this->back() );
		exit;
	}
}
