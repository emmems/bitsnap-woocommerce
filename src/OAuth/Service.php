<?php
/**
 * OAuth registration, authorization and token lifecycle.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Exceptions carry a short error code, never output. Admin\OAuthMessages turns them into text.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * The OAuth client of the plugin ("Connect with Bitsnap"). It knows nothing about WordPress: the network, the
 * short-lived storage and the persistent token store are passed in, so the whole lifecycle runs in unit tests.
 *
 * - One public client registration per site and API base URL (RFC 7591), repeated when the server forgets it.
 * - Authorization code flow with PKCE S256. The state is a single-use record bound to the user, valid 10 minutes.
 * - Access tokens refresh when they expire within 60 seconds, and when the API answers 401. A lock keeps two
 *   requests from using the same rotating refresh token; the one that loses the lock re-reads the stored tokens.
 * - A rotated token pair is saved in one write.
 * - Nothing here logs. Error messages hold a short code and never a response body.
 */
final class Service {

	/**
	 * Lifetime of the refresh lock, in seconds. It outlives one HTTP call with its timeout.
	 */
	private const LOCK_TTL = 180;

	/**
	 * How many times the loser of the refresh lock looks again.
	 */
	private const LOCK_ATTEMPTS = 25;

	/**
	 * Wait between those looks, in microseconds.
	 */
	private const LOCK_WAIT = 200000;

	/**
	 * Network.
	 *
	 * @var Transport
	 */
	private $http;

	/**
	 * Short-lived storage.
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Persistent tokens.
	 *
	 * @var TokenStore
	 */
	private $tokens;

	/**
	 * API base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Callback URL of this site.
	 *
	 * @var string
	 */
	private $redirect_uri;

	/**
	 * Name shown to the merchant on the consent page.
	 *
	 * @var string
	 */
	private $client_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Sleeps for a number of microseconds.
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Constructor.
	 *
	 * @param Transport     $http         Network.
	 * @param Storage       $storage      Short-lived storage.
	 * @param TokenStore    $tokens       Persistent tokens.
	 * @param string        $base         API base URL.
	 * @param string        $redirect_uri Callback URL.
	 * @param string        $client_name  Client name.
	 * @param string        $version      Plugin version.
	 * @param callable|null $clock        Returns the time, for tests.
	 * @param callable|null $sleep        Sleeps, for tests.
	 */
	public function __construct( Transport $http, Storage $storage, TokenStore $tokens, string $base, string $redirect_uri, string $client_name, string $version, ?callable $clock = null, ?callable $sleep = null ) {
		$this->http         = $http;
		$this->storage      = $storage;
		$this->tokens       = $tokens;
		$this->base         = rtrim( $base, '/' );
		$this->redirect_uri = $redirect_uri;
		$this->client_name  = $client_name;
		$this->version      = $version;
		$this->clock        = $clock ? $clock : 'time';
		$this->sleep        = $sleep ? $sleep : 'usleep';
	}

	/**
	 * Whether an environment is connected.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return bool
	 */
	public function connected( string $environment ): bool {
		$set = $this->tokens->get( $environment );
		return ! empty( $set['access_token'] ) && ! empty( $set['refresh_token'] );
	}

	/**
	 * Facts about a connection, for the settings page.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return array{project_name:string,project_id:string,environment:string}
	 */
	public function info( string $environment ): array {
		$set = $this->tokens->get( $environment );
		return array(
			'project_name' => isset( $set['project_name'] ) ? (string) $set['project_name'] : '',
			'project_id'   => isset( $set['project_id'] ) ? (string) $set['project_id'] : '',
			'environment'  => $environment,
		);
	}

	/**
	 * Starts a connection.
	 *
	 * @param string $environment 'test' or 'live', preselected on the consent page.
	 * @param int    $user        Id of the signed-in WordPress user.
	 * @return string The URL to send the merchant to.
	 * @throws OAuthException When OAuth cannot be used or the site cannot register.
	 */
	public function begin( string $environment, int $user ): string {
		if ( ! in_array( $environment, array( 'test', 'live' ), true ) ) {
			throw new OAuthException( 'invalid_state' );
		}
		$this->assert_urls();
		$client   = $this->client_id();
		$verifier = Protocol::verifier();
		$state    = Protocol::new_state();
		$this->storage->set(
			self::state_key( $state ),
			array(
				'state'    => $state,
				'user'     => $user,
				'expires'  => $this->now() + Protocol::STATE_TTL,
				'verifier' => $verifier,
				'env'      => $environment,
			),
			Protocol::STATE_TTL
		);
		return Protocol::authorize_url( $this->base, $client, $this->redirect_uri, $state, Protocol::challenge( $verifier ), $environment );
	}

	/**
	 * Finishes a connection from the callback. The state is used up whatever happens.
	 *
	 * @param string $state The `state` of the callback.
	 * @param string $code  The authorization code, '' when the callback carried an error.
	 * @param int    $user  Id of the signed-in WordPress user.
	 * @param string $error The `error` of the callback, '' when there is none.
	 * @return array<string,mixed> The saved token set, with `requested_environment`.
	 * @throws OAuthException When the state is not valid or the server refuses.
	 */
	public function complete( string $state, string $code, int $user, string $error = '' ): array {
		$key    = self::state_key( $state );
		$record = $this->storage->get( $key );
		$this->storage->delete( $key );
		if ( ! is_array( $record ) || ! Protocol::valid_state( $record, $state, $user, $this->now() ) ) {
			throw new OAuthException( 'invalid_state' );
		}
		if ( '' !== $error ) {
			throw new OAuthException( 'access_denied' === $error ? 'access_denied' : 'authorization_failed' );
		}
		if ( '' === $code ) {
			throw new OAuthException( 'invalid_response' );
		}
		$data   = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->redirect_uri,
				'code_verifier' => (string) $record['verifier'],
				'resource'      => Protocol::resource( $this->base ),
			)
		);
		$tokens = Protocol::parse_tokens( $data, $this->now(), true );
		if ( null === $tokens ) {
			throw new OAuthException( 'invalid_response' );
		}
		// The tokens belong to the environment the server issued them for, which the merchant may have changed.
		$this->tokens->save( $tokens['environment'], $tokens );
		$tokens['requested_environment'] = (string) $record['env'];
		return $tokens;
	}

	/**
	 * An access token that is valid for at least another minute.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return string
	 * @throws OAuthException When the environment is not connected or the refresh fails.
	 */
	public function access_token( string $environment ): string {
		$set = $this->tokens->get( $environment );
		if ( empty( $set['access_token'] ) || empty( $set['refresh_token'] ) ) {
			throw new OAuthException( 'not_connected' );
		}
		if ( ! Protocol::should_refresh( (int) ( $set['expires_at'] ?? 0 ), $this->now() ) ) {
			return (string) $set['access_token'];
		}
		return $this->refresh( $environment, (string) $set['access_token'] );
	}

	/**
	 * Gets a new access token, unless another request already did.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param string $stale       The access token that expired or was rejected.
	 * @return string The access token to use.
	 * @throws OAuthException When the refresh fails. Tokens are cleared when the server says the grant is gone.
	 */
	public function refresh( string $environment, string $stale ): string {
		$lock = 'bitsnap_oauth_refresh_' . $environment;
		for ( $i = 0; $i < self::LOCK_ATTEMPTS; $i++ ) {
			if ( $this->storage->add( $lock, 1, self::LOCK_TTL ) ) {
				try {
					return $this->refresh_locked( $environment, $stale );
				} finally {
					$this->storage->delete( $lock );
				}
			}
			// Somebody else is refreshing. Wait, then use what they saved.
			call_user_func( $this->sleep, self::LOCK_WAIT );
			$set = $this->tokens->get( $environment, true );
			if ( empty( $set['access_token'] ) ) {
				throw new OAuthException( 'not_connected' );
			}
			if ( $this->is_newer( $set, $stale ) ) {
				return (string) $set['access_token'];
			}
		}
		throw new OAuthException( 'busy' );
	}

	/**
	 * Revokes the grant of an environment at Bitsnap. It never throws: a failure only means the grant stays
	 * until the merchant revokes it in the Bitsnap dashboard.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return bool Whether Bitsnap confirmed the revocation.
	 */
	public function revoke( string $environment ): bool {
		$set   = $this->tokens->get( $environment );
		$token = ! empty( $set['refresh_token'] ) ? (string) $set['refresh_token'] : ( isset( $set['access_token'] ) ? (string) $set['access_token'] : '' );
		$id    = $this->tokens->client_id( $this->fingerprint() );
		if ( '' === $token || '' === $id ) {
			return false;
		}
		$response = $this->http->post(
			$this->base . '/oauth/revoke',
			array(
				'token'           => $token,
				'token_type_hint' => 'refresh_token',
				'client_id'       => $id,
			)
		);
		return $response['status'] >= 200 && $response['status'] < 300;
	}

	/**
	 * Revokes the grant and forgets the tokens of one environment. The other environment is not touched.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @return bool Whether Bitsnap confirmed the revocation.
	 * @throws OAuthException When another token operation remains busy.
	 */
	public function disconnect( string $environment ): bool {
		$lock = 'bitsnap_oauth_refresh_' . $environment;
		for ( $i = 0; $i < self::LOCK_ATTEMPTS; ++$i ) {
			if ( $this->storage->add( $lock, 1, self::LOCK_TTL ) ) {
				try {
					return $this->revoke( $environment );
				} finally {
					try {
						$this->tokens->clear( $environment );
					} finally {
						$this->storage->delete( $lock );
					}
				}
			}
			call_user_func( $this->sleep, self::LOCK_WAIT );
		}
		throw new OAuthException( 'busy' );
	}

	/**
	 * The client id of this site, registered when needed.
	 *
	 * @return string
	 * @throws OAuthException When the registration fails.
	 */
	public function client_id(): string {
		$id = $this->tokens->client_id( $this->fingerprint() );
		return '' !== $id ? $id : $this->register();
	}

	/**
	 * Refreshes with the lock held.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param string $stale       The access token that expired or was rejected.
	 * @return string
	 * @throws OAuthException When the refresh fails.
	 */
	private function refresh_locked( string $environment, string $stale ): string {
		// Another request may have refreshed while this one waited for the lock.
		$set = $this->tokens->get( $environment, true );
		if ( empty( $set['access_token'] ) || empty( $set['refresh_token'] ) ) {
			throw new OAuthException( 'not_connected' );
		}
		if ( $this->is_newer( $set, $stale ) ) {
			return (string) $set['access_token'];
		}
		try {
			$data = $this->token_request(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => (string) $set['refresh_token'],
					'resource'      => Protocol::resource( $this->base ),
				)
			);
		} catch ( OAuthException $e ) {
			if ( 'invalid_grant' === $e->error_name() ) {
				// The grant was revoked or the refresh token already used: connecting again is the only way out.
				$current = $this->tokens->get( $environment, true );
				if ( ( $current['refresh_token'] ?? '' ) === $set['refresh_token'] ) {
					$this->tokens->clear( $environment );
				}
			}
			throw $e;
		}
		$new = Protocol::parse_tokens( $data, $this->now(), false );
		if ( null === $new || ( '' !== $new['environment'] && $new['environment'] !== $environment ) ) {
			throw new OAuthException( 'invalid_response' );
		}
		$merged = array(
			'access_token'  => $new['access_token'],
			// A server that does not rotate refresh tokens keeps the old one.
			'refresh_token' => '' !== $new['refresh_token'] ? $new['refresh_token'] : (string) $set['refresh_token'],
			'expires_at'    => $new['expires_at'],
			'project_id'    => '' !== $new['project_id'] ? $new['project_id'] : (string) ( $set['project_id'] ?? '' ),
			'project_name'  => '' !== $new['project_name'] ? $new['project_name'] : (string) ( $set['project_name'] ?? '' ),
			'environment'   => $environment,
		);
		$this->tokens->save( $environment, $merged );
		return $merged['access_token'];
	}

	/**
	 * Whether a stored token set holds a usable access token other than the stale one.
	 *
	 * @param array<string,mixed> $set   Stored token set.
	 * @param string              $stale The access token that expired or was rejected.
	 * @return bool
	 */
	private function is_newer( array $set, string $stale ): bool {
		return ! empty( $set['access_token'] )
			&& (string) $set['access_token'] !== $stale
			&& ! Protocol::should_refresh( (int) ( $set['expires_at'] ?? 0 ), $this->now() );
	}

	/**
	 * Calls the token endpoint. When the server no longer knows the client, the site registers again and tries once more.
	 *
	 * @param array<string,string> $fields Form fields without `client_id`.
	 * @return array<string,mixed> Decoded answer.
	 * @throws OAuthException When the server refuses.
	 */
	private function token_request( array $fields ): array {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$fields['client_id'] = $this->client_id();
			$response            = $this->http->post( $this->base . '/oauth/token', $fields );
			if ( $response['status'] >= 200 && $response['status'] < 300 ) {
				$data = json_decode( $response['body'], true );
				if ( ! is_array( $data ) ) {
					throw new OAuthException( 'invalid_response', $response['status'] );
				}
				return $data;
			}
			if ( 0 === $attempt && Protocol::should_reregister( $response['status'], $response['body'] ) ) {
				$this->tokens->forget_client();
				continue;
			}
			throw $this->error_from( $response );
		}
		throw new OAuthException( 'invalid_client' );
	}

	/**
	 * Registers the site as a public client.
	 *
	 * @return string The client id.
	 * @throws OAuthException When the registration fails.
	 */
	private function register(): string {
		$this->assert_urls();
		$response = $this->http->post(
			$this->base . '/oauth/register',
			Protocol::registration_request( $this->client_name, $this->redirect_uri, $this->version ),
			true
		);
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			throw new OAuthException( 0 === $response['status'] ? 'network' : 'registration_failed', $response['status'] );
		}
		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || empty( $data['client_id'] ) || ! is_string( $data['client_id'] ) ) {
			throw new OAuthException( 'registration_failed', $response['status'] );
		}
		$this->tokens->save_client_id( $data['client_id'], $this->fingerprint() );
		return $data['client_id'];
	}

	/**
	 * Turns a failed answer into an exception without keeping the body.
	 *
	 * @param array{status:int,body:string} $response Answer.
	 * @return OAuthException
	 */
	private function error_from( array $response ): OAuthException {
		if ( 0 === $response['status'] ) {
			return new OAuthException( 'network' );
		}
		$code = Protocol::error_code( $response['body'] );
		if ( 1 === preg_match( '/^[a-z_]{1,40}$/', $code ) ) {
			return new OAuthException( $code, $response['status'] );
		}
		return new OAuthException( $response['status'] >= 500 ? 'server_error' : 'http_error', $response['status'] );
	}

	/**
	 * OAuth needs https, except on localhost.
	 *
	 * @return void
	 * @throws OAuthException When the base URL or the callback is not allowed.
	 */
	private function assert_urls(): void {
		if ( ! Protocol::is_allowed_url( $this->base ) || ! Protocol::is_allowed_url( $this->redirect_uri ) ) {
			throw new OAuthException( 'insecure_url' );
		}
	}

	/**
	 * Identifies the registration: a new base URL or callback needs a new client.
	 *
	 * @return string
	 */
	private function fingerprint(): string {
		return hash( 'sha256', $this->base . '|' . $this->redirect_uri );
	}

	/**
	 * Current time.
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Storage key of a state.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private static function state_key( string $state ): string {
		return 'bitsnap_oauth_state_' . hash( 'sha256', $state );
	}
}
