<?php
/**
 * Admin notices.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Admin;

use Bitsnap\WooCommerce\Support\Secrets;
use Bitsnap\WooCommerce\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows one-time messages (for example the result of "Register webhook") and warnings on the
 * gateway settings page.
 */
final class Notices {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Stores a message for the current user, shown on the next admin page load.
	 *
	 * @param string $type    'success', 'warning' or 'error'.
	 * @param string $message Plain text.
	 * @return void
	 */
	public static function flash( string $type, string $message ): void {
		set_transient(
			self::transient_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Prints the notices.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$flash = get_transient( self::transient_key() );
		if ( is_array( $flash ) && isset( $flash['type'], $flash['message'] ) ) {
			delete_transient( self::transient_key() );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( in_array( $flash['type'], array( 'success', 'warning' ), true ) ? $flash['type'] : 'error' ),
				esc_html( (string) $flash['message'] )
			);
		}

		if ( ! $this->on_settings_page() ) {
			return;
		}

		if ( ! Secrets::is_available() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Bitsnap: the PHP sodium extension is missing, so API keys and webhook secrets are stored without encryption. Ask your host to enable sodium.', 'bitsnap-for-woocommerce' )
			);
		}

		foreach ( Settings::SECRET_KEYS as $key ) {
			if ( $this->settings->is_secret_broken( $key ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Bitsnap: a saved key or secret cannot be decrypted. This happens when the site security keys (AUTH_KEY, SECURE_AUTH_KEY) change. Enter the key and the webhook secret again.', 'bitsnap-for-woocommerce' )
				);
				break;
			}
		}
	}

	/**
	 * Whether the current screen is the Bitsnap gateway settings.
	 *
	 * @return bool
	 */
	private function on_settings_page(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reads which screen is open, changes nothing.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return 'wc-settings' === $page && 'bitsnap' === $section;
	}

	/**
	 * Transient name for the current user's message.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return 'bitsnap_wc_notice_' . get_current_user_id();
	}
}
