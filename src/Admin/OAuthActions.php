<?php
/**
 * "Connect with Bitsnap" admin actions.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Admin;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\OAuth\OAuthException;
use Bitsnap\WooCommerce\Plugin;
use Bitsnap\WooCommerce\Webhook\Registrar;
use Bitsnap\WooCommerce\Woo\ErrorMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three `admin-post.php` actions of the OAuth connection: start, callback and disconnect.
 */
final class OAuthActions {

	/**
	 * Plugin container.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Callback URL registered with Bitsnap.
	 *
	 * @return string
	 */
	public static function callback_url(): string {
		return admin_url( 'admin-post.php?action=bitsnap_oauth_callback' );
	}

	/**
	 * Registers the `admin-post.php` actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bitsnap_oauth_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_bitsnap_oauth_callback', array( $this, 'callback' ) );
		add_action( 'admin_post_bitsnap_oauth_disconnect', array( $this, 'disconnect' ) );
	}

	/**
	 * Starts a connection: sends the merchant to the Bitsnap consent page.
	 *
	 * @return void
	 */
	public function connect(): void {
		$environment = $this->environment_from_request();
		check_admin_referer( 'bitsnap_oauth_connect_' . $environment );
		$this->require_capability();

		try {
			$url = $this->plugin->oauth()->begin( $environment, get_current_user_id() );
		} catch ( OAuthException $e ) {
			Notices::flash( 'error', OAuthMessages::describe( $e ) );
			$this->back();
			return;
		}

		// The consent page is on the Bitsnap host, which `wp_safe_redirect` would otherwise replace by the dashboard.
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return $hosts;
			}
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Finishes a connection: Bitsnap sends the merchant back here with a code.
	 *
	 * @return void
	 */
	public function callback(): void {
		$this->require_capability();

		// The OAuth state is the protection against forged requests here: random, single use, bound to this user and
		// valid for ten minutes. A WordPress nonce cannot be added to a redirect that comes from another site.
		$state = self::query_arg( 'state' );
		$code  = self::query_arg( 'code' );
		$error = self::query_arg( 'error' );

		try {
			$tokens = $this->plugin->oauth()->complete( $state, $code, get_current_user_id(), $error );
		} catch ( OAuthException $e ) {
			Notices::flash( 'error', OAuthMessages::describe( $e ) );
			$this->back();
			return;
		}

		$environment = (string) $tokens['environment'];
		$project     = (string) $tokens['project_name'];
		try {
			$this->plugin->webhooks()->register( $environment, $this->plugin->client( $environment ), true );
		} catch ( ApiException $e ) {
			Notices::flash(
				'warning',
				sprintf(
					/* translators: 1: Bitsnap project name, 2: environment (test or live), 3: reason. */
					__( 'Connected to %1$s (%2$s), but the webhook could not be registered: %3$s Use "Register webhook" below, or create the webhook in Bitsnap.', 'bitsnap-for-woocommerce' ),
					$project,
					$environment,
					'missing_signing_secret' === $e->get_reason() ? __( 'Bitsnap did not return its secret.', 'bitsnap-for-woocommerce' ) : ErrorMessages::describe( $e )
				)
			);
			$this->back();
			return;
		}

		Notices::flash(
			'success',
			sprintf(
				/* translators: 1: Bitsnap project name, 2: environment (test or live). */
				__( 'Connected to %1$s (%2$s). The webhook is registered and its secret is saved.', 'bitsnap-for-woocommerce' ),
				$project,
				$environment
			)
		);
		$this->back();
	}

	/**
	 * Ends a connection of one environment: deletes the webhook, revokes the grant, forgets the tokens.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$environment = $this->environment_from_request();
		check_admin_referer( 'bitsnap_oauth_disconnect_' . $environment );
		$this->require_capability();

		// The webhook goes first, because deleting it needs the token that the revocation ends.
		try {
			$webhook = $this->plugin->webhooks()->remove( $environment, $this->plugin->client( $environment ) );
		} catch ( \Throwable $e ) {
			$webhook = Registrar::FAILED;
		}
		try {
			$revoked = $this->plugin->oauth()->disconnect( $environment );
		} catch ( \Throwable $e ) {
			$revoked = false;
		} finally {
			$this->plugin->settings()->update( array(), array(), array( 'webhook_id_' . $environment, 'webhook_secret_' . $environment ) );
		}

		if ( Registrar::FAILED === $webhook || ! $revoked ) {
			Notices::flash( 'warning', __( 'Disconnected this store from Bitsnap, but Bitsnap did not confirm that the webhook was deleted and access revoked. Check "Connected apps" in your Bitsnap settings.', 'bitsnap-for-woocommerce' ) );
		} else {
			Notices::flash( 'success', __( 'Disconnected from Bitsnap.', 'bitsnap-for-woocommerce' ) );
		}
		$this->back();
	}

	/**
	 * The environment of the request, which must be 'test' or 'live'.
	 *
	 * @return string
	 */
	private function environment_from_request(): string {
		$environment = isset( $_GET['env'] ) ? sanitize_key( wp_unslash( $_GET['env'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked by check_admin_referer() right after, with the environment in the action name.
		if ( ! in_array( $environment, array( 'test', 'live' ), true ) ) {
			wp_die( esc_html__( 'Invalid request.', 'bitsnap-for-woocommerce' ), '', array( 'response' => 400 ) );
		}
		return $environment;
	}

	/**
	 * Stops the request unless the user may manage WooCommerce.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bitsnap-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Reads one query argument of the callback, sanitized and limited in length.
	 *
	 * @param string $name Argument name.
	 * @return string '' when it is missing.
	 */
	private static function query_arg( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See callback(): the OAuth state protects this request.
		if ( ! isset( $_GET[ $name ] ) || ! is_string( $_GET[ $name ] ) ) {
			return '';
		}
		return substr( sanitize_text_field( wp_unslash( $_GET[ $name ] ) ), 0, 2048 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Returns to the gateway settings page.
	 *
	 * @return void
	 */
	private function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bitsnap' ) );
		exit;
	}
}
