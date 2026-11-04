<?php
/**
 * Tokens in the gateway settings.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

use Bitsnap\WooCommerce\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@see TokenStore} on the gateway settings option. Access and refresh tokens are secrets, so they are
 * stored encrypted. Every write is one `update_option`, so a rotated token pair is never half saved.
 */
final class SettingsTokenStore implements TokenStore {

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
	 * Settings key of one value of an environment.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param string $suffix      `access`, `refresh`, `expires`, `project_id` or `project_name`.
	 * @return string
	 */
	public static function key( string $environment, string $suffix ): string {
		return 'oauth_' . $environment . '_' . $suffix;
	}

	/**
	 * Token set of an environment.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param bool   $fresh       Read from the database.
	 * @return array<string,mixed>
	 */
	public function get( string $environment, bool $fresh = false ): array {
		if ( $fresh ) {
			$this->settings->reload();
		}
		$all     = $this->settings->all();
		$access  = $this->settings->secrets()->decrypt( (string) ( $all[ self::key( $environment, 'access' ) ] ?? '' ) ) ?? '';
		$refresh = $this->settings->secrets()->decrypt( (string) ( $all[ self::key( $environment, 'refresh' ) ] ?? '' ) ) ?? '';
		if ( '' === $access && '' === $refresh ) {
			return array();
		}
		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_at'    => (int) ( $all[ self::key( $environment, 'expires' ) ] ?? 0 ),
			'project_id'    => ( $all[ self::key( $environment, 'project_id' ) ] ?? '' ),
			'project_name'  => ( $all[ self::key( $environment, 'project_name' ) ] ?? '' ),
			'environment'   => $environment,
		);
	}

	/**
	 * Saves a token set in one write.
	 *
	 * @param string              $environment 'test' or 'live'.
	 * @param array<string,mixed> $tokens      Token set.
	 * @return void
	 */
	public function save( string $environment, array $tokens ): void {
		$this->settings->update(
			array(
				self::key( $environment, 'expires' )      => (string) (int) ( $tokens['expires_at'] ?? 0 ),
				self::key( $environment, 'project_id' )   => (string) ( $tokens['project_id'] ?? '' ),
				self::key( $environment, 'project_name' ) => (string) ( $tokens['project_name'] ?? '' ),
			),
			array(
				self::key( $environment, 'access' )  => (string) ( $tokens['access_token'] ?? '' ),
				self::key( $environment, 'refresh' ) => (string) ( $tokens['refresh_token'] ?? '' ),
			)
		);
	}

	/**
	 * Removes the token set of one environment.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return void
	 */
	public function clear( string $environment ): void {
		$keys = array();
		foreach ( array( 'access', 'refresh', 'expires', 'project_id', 'project_name' ) as $suffix ) {
			$keys[] = self::key( $environment, $suffix );
		}
		$this->settings->update( array(), array(), $keys );
	}

	/**
	 * Client id stored for a registration fingerprint.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return string
	 */
	public function client_id( string $fingerprint ): string {
		return $fingerprint === $this->settings->get( 'oauth_client_fp' ) ? $this->settings->get( 'oauth_client_id' ) : '';
	}

	/**
	 * Stores the client id.
	 *
	 * @param string $client_id   Client id.
	 * @param string $fingerprint Fingerprint.
	 * @return void
	 */
	public function save_client_id( string $client_id, string $fingerprint ): void {
		$this->settings->update(
			array(
				'oauth_client_id' => $client_id,
				'oauth_client_fp' => $fingerprint,
			)
		);
	}

	/**
	 * Forgets the client registration.
	 *
	 * @return void
	 */
	public function forget_client(): void {
		$this->settings->update( array(), array(), array( 'oauth_client_id', 'oauth_client_fp' ) );
	}
}
