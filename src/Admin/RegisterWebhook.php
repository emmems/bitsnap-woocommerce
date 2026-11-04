<?php
/**
 * "Register webhook" action of the settings page.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Admin;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Plugin;
use Bitsnap\WooCommerce\Woo\ErrorMessages;
use Bitsnap\WooCommerce\Woo\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the webhook in Bitsnap (`WebhooksService.CreateWebhook`, needs the `webhooks:write`
 * scope) for `{site}/?wc-api=bitsnap` and stores its signing secret.
 */
final class RegisterWebhook {

	/**
	 * Events the plugin handles.
	 */
	public const EVENTS = array(
		'TRANSACTION_CREATED',
		'TRANSACTION_SUCCESS',
		'TRANSACTION_FAILURE',
		'TRANSACTION_EXPIRED',
		'REFUND_SUCCEEDED',
		'REFUND_FAILED',
		'INVOICE_ISSUED',
	);

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
	 * Registers the `admin-post.php` action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bitsnap_register_webhook', array( $this, 'handle' ) );
	}

	/**
	 * Handles the click on "Register webhook".
	 *
	 * @return void
	 */
	public function handle(): void {
		$environment = isset( $_GET['env'] ) ? sanitize_key( wp_unslash( $_GET['env'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below, with the environment in the action name.
		if ( ! in_array( $environment, array( 'test', 'live' ), true ) ) {
			wp_die( esc_html__( 'Invalid request.', 'bitsnap-for-woocommerce' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'bitsnap_register_webhook_' . $environment );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bitsnap-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$back = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bitsnap' );

		if ( ! $this->plugin->credentials()->is_configured( $environment ) ) {
			Notices::flash(
				'error',
				sprintf(
					/* translators: %s: environment, test or live. */
					__( 'Save the %s API key first, then register the webhook.', 'bitsnap-for-woocommerce' ),
					$environment
				)
			);
			wp_safe_redirect( $back );
			exit;
		}

		$url    = Gateway::webhook_url();
		$client = $this->plugin->client( $environment );

		try {
			if ( $this->exists( $client, $url ) ) {
				Notices::flash(
					'error',
					__( 'A webhook for this site already exists in Bitsnap. Its secret cannot be read again. Delete the webhook in Bitsnap and register it again, or paste its secret into the field below.', 'bitsnap-for-woocommerce' )
				);
				wp_safe_redirect( $back );
				exit;
			}

			$response = $client->call(
				'WebhooksService',
				'CreateWebhook',
				array(
					'name'   => 'WooCommerce: ' . wp_parse_url( home_url(), PHP_URL_HOST ),
					'url'    => $url,
					'events' => self::EVENTS,
				),
				'woo-webhook-' . wp_generate_uuid4()
			);
		} catch ( ApiException $e ) {
			Notices::flash( 'error', ErrorMessages::describe( $e ) );
			wp_safe_redirect( $back );
			exit;
		}

		$secret = isset( $response['signingSecret'] ) && is_string( $response['signingSecret'] ) ? $response['signingSecret'] : '';
		if ( '' === $secret ) {
			Notices::flash( 'error', __( 'Bitsnap created the webhook but did not return its secret. Delete it in Bitsnap and try again.', 'bitsnap-for-woocommerce' ) );
			wp_safe_redirect( $back );
			exit;
		}

		$this->plugin->settings()->set_secret( 'webhook_secret_' . $environment, $secret );
		Notices::flash(
			'success',
			sprintf(
				/* translators: %s: environment, test or live. */
				__( 'The %s webhook was registered in Bitsnap and its secret was saved.', 'bitsnap-for-woocommerce' ),
				$environment
			)
		);
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Whether the environment already has a webhook for the URL.
	 *
	 * Needs `webhooks:read`. When that scope is missing the check is skipped and creation is tried.
	 *
	 * @param \Bitsnap\WooCommerce\Api\Client $client Client.
	 * @param string                          $url    Webhook URL.
	 * @return bool
	 * @throws ApiException When the list call fails for a reason other than a missing scope.
	 */
	private function exists( $client, string $url ): bool {
		try {
			$response = $client->call( 'WebhooksService', 'ListWebhooks', array() );
		} catch ( ApiException $e ) {
			if ( 'missing_scope' === $e->get_reason() ) {
				return false;
			}
			throw $e;
		}
		foreach ( (array) ( $response['webhooks'] ?? array() ) as $webhook ) {
			if ( is_array( $webhook ) && isset( $webhook['url'] ) && $webhook['url'] === $url ) {
				return true;
			}
		}
		return false;
	}
}
