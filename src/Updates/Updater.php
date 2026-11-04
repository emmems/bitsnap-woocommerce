<?php
/**
 * Self-hosted updates: wp-admin offers new versions published as GitHub releases.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Updates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the Plugin Update Checker library (MIT, bundled in `vendor/`) to the GitHub releases of the plugin.
 *
 * Every release has one asset, `bitsnap-for-woocommerce.zip`, built by the Release workflow.
 * The checker installs that asset, never the source archive (which has no `vendor/` and no `build/`).
 * Update checks can be turned off with the constant `BITSNAP_WC_DISABLE_UPDATES` or the filter `bitsnap_wc_enable_updates`.
 */
final class Updater {

	/**
	 * GitHub repository that publishes the releases.
	 */
	public const REPOSITORY_URL = 'https://github.com/emmems/bitsnap-woocommerce/';

	/**
	 * Plugin slug, also the name of the folder in `wp-content/plugins` and of the release asset.
	 */
	public const SLUG = 'bitsnap-for-woocommerce';

	/**
	 * Name of the one release asset the checker installs.
	 */
	public const ASSET_NAME = 'bitsnap-for-woocommerce.zip';

	/**
	 * The update checker, once created.
	 *
	 * @var object|null
	 */
	private static $checker = null;

	/**
	 * Hooks the setup. Call it early: the checker is created on `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'setup' ), 5 );
	}

	/**
	 * Tells whether update checks are allowed.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$disabled = defined( 'BITSNAP_WC_DISABLE_UPDATES' ) && constant( 'BITSNAP_WC_DISABLE_UPDATES' );
		/**
		 * Filters whether the plugin checks GitHub for new versions.
		 *
		 * @param bool $enabled True unless `BITSNAP_WC_DISABLE_UPDATES` is set.
		 */
		return (bool) apply_filters( 'bitsnap_wc_enable_updates', ! $disabled );
	}

	/**
	 * Creates the update checker. Only the admin, cron and WP-CLI check for updates, so shop visitors never trigger a request.
	 *
	 * @return object|null The checker, or null when updates are off or the library is missing.
	 */
	public static function setup() {
		if ( null !== self::$checker ) {
			return self::$checker;
		}
		if ( ! self::enabled() ) {
			return null;
		}
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return null;
		}

		$library = BITSNAP_WC_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $library ) ) {
			return null;
		}
		require_once $library;

		$factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return null;
		}

		$checker = $factory::buildUpdateChecker( self::REPOSITORY_URL, BITSNAP_WC_FILE, self::SLUG );
		$checker->setBranch( 'main' );
		// Install the release asset only: never fall back to the source archive.
		$api = $checker->getVcsApi();
		$api->enableReleaseAssets( '/' . preg_quote( self::ASSET_NAME, '/' ) . '$/i', $api::REQUIRE_RELEASE_ASSETS );
		// A tag or branch without that asset (a failed release) must not become an update.
		$checker->addResultFilter( array( self::class, 'only_release_asset' ) );

		self::$checker = $checker;
		return $checker;
	}

	/**
	 * Drops an update whose package is not the release asset.
	 *
	 * @param object|null $info Plugin info from the checker.
	 * @return object|null
	 */
	public static function only_release_asset( $info ) {
		if ( ! is_object( $info ) ) {
			return $info;
		}
		$url  = isset( $info->download_url ) ? (string) $info->download_url : '';
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( substr( $path, -strlen( '/' . self::ASSET_NAME ) ) !== '/' . self::ASSET_NAME ) {
			return null;
		}
		return $info;
	}

	/**
	 * Returns the checker created by `setup()`, if any.
	 *
	 * @return object|null
	 */
	public static function checker() {
		return self::$checker;
	}
}
