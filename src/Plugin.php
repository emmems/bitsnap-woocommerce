<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce;

use Bitsnap\WooCommerce\Admin\Notices;
use Bitsnap\WooCommerce\Admin\OAuthActions;
use Bitsnap\WooCommerce\Admin\RegisterWebhook;
use Bitsnap\WooCommerce\Api\Client;
use Bitsnap\WooCommerce\Blocks\PaymentMethod;
use Bitsnap\WooCommerce\OAuth\ApiTokens;
use Bitsnap\WooCommerce\OAuth\Service as OAuthService;
use Bitsnap\WooCommerce\OAuth\SettingsTokenStore;
use Bitsnap\WooCommerce\OAuth\TransientStorage;
use Bitsnap\WooCommerce\OAuth\WpTransport;
use Bitsnap\WooCommerce\Support\Logger;
use Bitsnap\WooCommerce\Support\Secrets;
use Bitsnap\WooCommerce\Support\Settings;
use Bitsnap\WooCommerce\Webhook\Handler;
use Bitsnap\WooCommerce\Webhook\Registrar;
use Bitsnap\WooCommerce\Webhook\SettingsStore as WebhookStore;
use Bitsnap\WooCommerce\Woo\Gateway;
use Bitsnap\WooCommerce\Woo\OrderReader;
use Bitsnap\WooCommerce\Woo\OrderSync;
use Bitsnap\WooCommerce\Woo\OrderUpdater;
use Bitsnap\WooCommerce\Woo\PaymentStarter;
use Bitsnap\WooCommerce\Woo\Reconciler;
use Bitsnap\WooCommerce\Woo\Refunder;
use Bitsnap\WooCommerce\Woo\ReturnPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin into WordPress and builds each service once, on first use.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Built services by name.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * The instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all hooks. Called when WooCommerce is active.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_block_payment_method' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BITSNAP_WC_FILE ), array( $this, 'action_links' ) );

		add_action( 'woocommerce_create_refund', array( Refunder::class, 'capture' ), PHP_INT_MAX, 2 );
		$this->handler()->register();
		( new ReturnPage( $this->sync() ) )->register();
		$this->reconciler()->register();
		( new Notices( $this->settings() ) )->register();
		( new RegisterWebhook( $this ) )->register();
		( new OAuthActions( $this ) )->register();
	}

	/**
	 * Adds the gateway to WooCommerce.
	 *
	 * @param string[] $gateways Gateway class names.
	 * @return string[]
	 */
	public function add_gateway( $gateways ) {
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * Registers the payment method with the Cart and Checkout Blocks.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry.
	 * @return void
	 */
	public function register_block_payment_method( $registry ): void {
		$registry->register( new PaymentMethod( $this ) );
	}

	/**
	 * Adds the "Settings" link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bitsnap' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bitsnap-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings access.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->service( 'settings', fn() => new Settings( Secrets::from_wordpress() ) );
	}

	/**
	 * Credential provider.
	 *
	 * @return Credentials
	 */
	public function credentials(): Credentials {
		return $this->service( 'credentials', fn() => new Credentials( $this->settings() ) );
	}

	/**
	 * Logger.
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		return $this->service( 'logger', fn() => new Logger( $this->settings() ) );
	}

	/**
	 * OAuth client ("Connect with Bitsnap").
	 *
	 * @return OAuthService
	 */
	public function oauth(): OAuthService {
		return $this->service(
			'oauth',
			fn() => new OAuthService(
				new WpTransport(),
				new TransientStorage(),
				new SettingsTokenStore( $this->settings() ),
				$this->credentials()->base_url(),
				OAuthActions::callback_url(),
				'Bitsnap for WooCommerce – ' . wp_parse_url( home_url(), PHP_URL_HOST ),
				defined( 'BITSNAP_WC_VERSION' ) ? BITSNAP_WC_VERSION : 'dev'
			)
		);
	}

	/**
	 * Creates and deletes this site's webhook in Bitsnap.
	 *
	 * @return Registrar
	 */
	public function webhooks(): Registrar {
		return $this->service(
			'webhooks',
			fn() => new Registrar(
				new WebhookStore( $this->settings() ),
				Gateway::webhook_url(),
				'WooCommerce: ' . wp_parse_url( home_url(), PHP_URL_HOST ),
				RegisterWebhook::EVENTS
			)
		);
	}

	/**
	 * API client for an environment. A connected environment uses its OAuth tokens, which refresh as needed;
	 * otherwise the client uses the manual API key.
	 *
	 * @param string|null $environment 'test', 'live', or null for the current one.
	 * @return Client
	 */
	public function client( ?string $environment = null ): Client {
		$credentials = $this->credentials();
		$env         = $environment ? $environment : $credentials->environment();
		$oauth       = $this->oauth();
		return new Client(
			$credentials->base_url(),
			$credentials->api_key( $env ),
			$this->logger(),
			20,
			$oauth->connected( $env ) ? new ApiTokens( $oauth, $env ) : null
		);
	}

	/**
	 * Order updater.
	 *
	 * @return OrderUpdater
	 */
	public function updater(): OrderUpdater {
		return $this->service( 'updater', fn() => new OrderUpdater( $this->logger() ) );
	}

	/**
	 * Payment starter.
	 *
	 * @return PaymentStarter
	 */
	public function starter(): PaymentStarter {
		return $this->service( 'starter', fn() => new PaymentStarter( $this, new OrderReader(), $this->logger() ) );
	}

	/**
	 * Refund service.
	 *
	 * @return Refunder
	 */
	public function refunder(): Refunder {
		return $this->service( 'refunder', fn() => new Refunder( $this, $this->updater(), $this->logger() ) );
	}

	/**
	 * Order sync (return page and reconcile job).
	 *
	 * @return OrderSync
	 */
	public function sync(): OrderSync {
		return $this->service( 'sync', fn() => new OrderSync( $this, $this->updater(), $this->logger() ) );
	}

	/**
	 * Webhook endpoint.
	 *
	 * @return Handler
	 */
	public function handler(): Handler {
		return $this->service( 'handler', fn() => new Handler( $this->credentials(), $this->updater(), $this->logger() ) );
	}

	/**
	 * Reconcile job.
	 *
	 * @return Reconciler
	 */
	public function reconciler(): Reconciler {
		return $this->service( 'reconciler', fn() => new Reconciler( $this->sync(), $this->logger() ) );
	}

	/**
	 * Builds a service once.
	 *
	 * @param string   $name    Service name.
	 * @param callable $factory Builds the service.
	 * @return object
	 */
	private function service( string $name, callable $factory ) {
		if ( ! isset( $this->services[ $name ] ) ) {
			$this->services[ $name ] = $factory();
		}
		return $this->services[ $name ];
	}
}
