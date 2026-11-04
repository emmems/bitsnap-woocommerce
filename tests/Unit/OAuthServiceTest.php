<?php
namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\OAuth\OAuthException;
use Bitsnap\WooCommerce\OAuth\Service;
use Bitsnap\WooCommerce\Tests\Doubles\ArrayStorage;
use Bitsnap\WooCommerce\Tests\Doubles\ArrayTokenStore;
use Bitsnap\WooCommerce\Tests\Doubles\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase {

	private const REDIRECT = 'https://shop.example/wp-admin/admin-post.php?action=bitsnap_oauth_callback';

	/** @var ScriptedTransport */
	private $http;

	/** @var ArrayStorage */
	private $storage;

	/** @var ArrayTokenStore */
	private $tokens;

	/** @var int */
	private $now = 1000000;

	/** @var Service */
	private $service;

	protected function setUp(): void {
		$this->http    = new ScriptedTransport();
		$this->storage = new ArrayStorage();
		$this->tokens  = new ArrayTokenStore();
		$this->service = $this->make();
	}

	private function make( string $base = 'https://bitsnap.pl', string $redirect = self::REDIRECT ): Service {
		return new Service(
			$this->http,
			$this->storage,
			$this->tokens,
			$base,
			$redirect,
			'Bitsnap for WooCommerce – shop.example',
			'0.2.0',
			function () {
				return $this->now;
			},
			static function () {}
		);
	}

	private function grant( string $env = 'test', string $access = 'bsat_1', string $refresh = 'rt_1', int $expires_in = 3600, string $project = 'Shop' ): array {
		return array(
			'access_token'         => $access,
			'refresh_token'        => $refresh,
			'token_type'           => 'Bearer',
			'expires_in'           => $expires_in,
			'scope'                => 'checkout:create',
			'bitsnap_project_id'   => '18',
			'bitsnap_project_name' => $project,
			'bitsnap_environment'  => $env,
		);
	}

	/** Connects an environment through the real flow and returns the stored set. */
	private function connect( string $env = 'test', string $access = 'bsat_1', string $refresh = 'rt_1', int $expires_in = 3600 ): array {
		$this->service->begin( $env, 7 );
		$state = $this->storage->only_state()['state'];
		$this->http->queue( '/oauth/token', 200, $this->grant( $env, $access, $refresh, $expires_in ) );
		$this->service->complete( $state, 'code-1', 7 );
		$this->storage->data = array();
		return $this->tokens->get( $env );
	}

	/** Sets a token set directly. */
	private function seed( string $env, string $access, string $refresh, int $expires_at ): void {
		$this->tokens->save_client_id( 'client-0', hash( 'sha256', 'https://bitsnap.pl|' . self::REDIRECT ) );
		$this->tokens->sets[ $env ] = array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_at'    => $expires_at,
			'project_id'    => '18',
			'project_name'  => 'Shop',
			'environment'   => $env,
		);
		$this->tokens->saves        = 0;
	}

	// ---- registration and authorize

	public function test_the_site_registers_once_and_reuses_its_client(): void {
		$first = $this->service->begin( 'test', 7 );
		$this->service->begin( 'live', 7 );

		self::assertSame( 1, $this->http->registrations );
		$body = $this->http->bodies( '/oauth/register' )[0];
		self::assertSame( 'none', $body['token_endpoint_auth_method'] );
		self::assertSame( array( self::REDIRECT ), $body['redirect_uris'] );
		self::assertSame( 'bitsnap-for-woocommerce', $body['software_id'] );
		self::assertTrue( $this->http->requests[0]['json'], 'registration is JSON' );
		self::assertStringContainsString( 'client_id=client-1', $first );
		self::assertStringContainsString( 'environment=test', $first );
	}

	public function test_a_new_base_url_or_callback_registers_again(): void {
		$this->service->begin( 'test', 7 );
		$this->make( 'https://other.example' )->begin( 'test', 7 );
		self::assertSame( 2, $this->http->registrations );
	}

	public function test_begin_stores_a_single_use_record_bound_to_the_user(): void {
		$url = $this->service->begin( 'live', 7 );
		$rec = $this->storage->only_state();

		self::assertSame( 7, $rec['user'] );
		self::assertSame( 'live', $rec['env'] );
		self::assertSame( $this->now + 600, $rec['expires'], 'ten minutes' );
		self::assertStringContainsString( 'state=' . $rec['state'], $url );
		self::assertStringContainsString( 'code_challenge=' . \Bitsnap\WooCommerce\OAuth\Protocol::challenge( $rec['verifier'] ), $url );
		self::assertStringNotContainsString( $rec['verifier'], $url, 'the verifier never leaves the site' );
		self::assertStringNotContainsString( $rec['state'], implode( ',', array_keys( $this->storage->data ) ), 'the key holds a hash of the state' );
	}

	public function test_oauth_refuses_an_insecure_site_or_base(): void {
		foreach ( array(
			array( 'http://bitsnap.example', self::REDIRECT ),
			array( 'https://bitsnap.pl', 'http://shop.example/cb' ),
		) as $case ) {
			try {
				$this->make( $case[0], $case[1] )->begin( 'test', 7 );
				self::fail( 'expected insecure_url' );
			} catch ( OAuthException $e ) {
				self::assertSame( 'insecure_url', $e->error_name() );
			}
		}
		self::assertSame( 0, $this->http->registrations );
		$this->make( 'http://localhost:4321', 'http://localhost:8888/cb' )->begin( 'test', 7 );
		self::assertSame( 1, $this->http->registrations, 'localhost is allowed for development' );
	}

	// ---- callback

	public function test_a_valid_callback_exchanges_the_code_with_pkce_and_stores_the_tokens(): void {
		$this->service->begin( 'test', 7 );
		$rec = $this->storage->only_state();
		$this->http->queue( '/oauth/token', 200, $this->grant( 'test', 'bsat_new', 'rt_new', 3600, 'Lece na kodach' ) );

		$tokens = $this->service->complete( $rec['state'], 'the-code', 7 );

		$sent = $this->http->bodies( '/oauth/token' )[0];
		self::assertSame(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => 'the-code',
				'redirect_uri'  => self::REDIRECT,
				'code_verifier' => $rec['verifier'],
				'resource'      => 'https://bitsnap.pl/api/v1',
				'client_id'     => 'client-1',
			),
			$sent
		);
		self::assertFalse( $this->http->requests[1]['json'], 'the token request is form-encoded' );
		self::assertSame( 'Lece na kodach', $tokens['project_name'] );
		self::assertSame( $this->now + 3600, $this->tokens->get( 'test' )['expires_at'] );
		self::assertSame( 'bsat_new', $this->tokens->get( 'test' )['access_token'] );
		self::assertTrue( $this->service->connected( 'test' ) );
		self::assertFalse( $this->service->connected( 'live' ) );
		self::assertSame( 'Lece na kodach', $this->service->info( 'test' )['project_name'] );
	}

	public function test_the_state_can_be_used_once(): void {
		$this->service->begin( 'test', 7 );
		$state = $this->storage->only_state()['state'];
		$this->http->queue( '/oauth/token', 200, $this->grant() );
		$this->service->complete( $state, 'code', 7 );

		$this->expectState( $state, 'code', 7 );
		self::assertCount( 1, $this->http->bodies( '/oauth/token' ), 'the second callback never reached the server' );
	}

	public function test_a_state_of_another_user_is_refused_and_used_up(): void {
		$this->service->begin( 'test', 7 );
		$state = $this->storage->only_state()['state'];

		$this->expectState( $state, 'code', 8 );
		$this->expectState( $state, 'code', 7 ); // The forged attempt used it up.
		self::assertCount( 0, $this->http->bodies( '/oauth/token' ) );
	}

	public function test_an_expired_state_is_refused(): void {
		$this->service->begin( 'test', 7 );
		$state = $this->storage->only_state()['state'];
		$this->now += 601;

		$this->expectState( $state, 'code', 7 );
	}

	public function test_an_unknown_state_is_refused(): void {
		$this->expectState( 'nope', 'code', 7 );
		$this->expectState( '', 'code', 7 );
	}

	public function test_an_error_callback_checks_the_state_first_and_uses_it_up(): void {
		$this->service->begin( 'test', 7 );
		$state = $this->storage->only_state()['state'];
		try {
			$this->service->complete( $state, '', 7, 'access_denied' );
			self::fail( 'expected access_denied' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'access_denied', $e->error_name() );
		}
		$this->expectState( $state, 'code', 7 );

		// An error with a forged state is just an invalid state, not a message chosen by the sender.
		$this->expectState( 'forged', '', 7, 'access_denied' );
		try {
			$this->service->begin( 'test', 7 );
			$this->service->complete( $this->storage->only_state()['state'], '', 7, '<script>' );
			self::fail( 'expected authorization_failed' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'authorization_failed', $e->error_name() );
		}
	}

	public function test_the_tokens_are_kept_for_the_environment_the_server_issued_them_for(): void {
		$this->service->begin( 'test', 7 );
		$state = $this->storage->only_state()['state'];
		$this->http->queue( '/oauth/token', 200, $this->grant( 'live', 'bsat_live', 'rt_live' ) );

		$tokens = $this->service->complete( $state, 'code', 7 );

		self::assertSame( 'live', $tokens['environment'] );
		self::assertSame( 'test', $tokens['requested_environment'] );
		self::assertTrue( $this->service->connected( 'live' ) );
		self::assertFalse( $this->service->connected( 'test' ) );
	}

	public function test_an_answer_without_project_fields_is_not_stored(): void {
		$this->service->begin( 'test', 7 );
		$state = $this->storage->only_state()['state'];
		$data  = $this->grant();
		unset( $data['bitsnap_project_name'] );
		$this->http->queue( '/oauth/token', 200, $data );

		try {
			$this->service->complete( $state, 'code', 7 );
			self::fail( 'expected invalid_response' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'invalid_response', $e->error_name() );
		}
		self::assertSame( array(), $this->tokens->sets );
	}

	// ---- refresh

	public function test_a_token_with_time_left_is_used_without_the_network(): void {
		$this->seed( 'test', 'bsat_ok', 'rt', $this->now + 61 );
		self::assertSame( 'bsat_ok', $this->service->access_token( 'test' ) );
		self::assertSame( array(), $this->http->requests );
	}

	public function test_a_token_that_expires_within_a_minute_is_refreshed(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now + 60 );
		$this->http->queue( '/oauth/token', 200, $this->grant( 'test', 'bsat_new', 'rt_new' ) );

		self::assertSame( 'bsat_new', $this->service->access_token( 'test' ) );

		self::assertSame(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'rt_old',
				'resource'      => 'https://bitsnap.pl/api/v1',
				'client_id'     => 'client-0',
			),
			$this->http->bodies( '/oauth/token' )[0]
		);
	}

	public function test_a_rotated_token_pair_is_saved_in_one_write(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now + 5 );
		$this->http->queue( '/oauth/token', 200, $this->grant( 'test', 'bsat_new', 'rt_new', 900 ) );

		$this->service->access_token( 'test' );

		self::assertSame( 1, $this->tokens->saves );
		$set = $this->tokens->get( 'test' );
		self::assertSame( 'bsat_new', $set['access_token'] );
		self::assertSame( 'rt_new', $set['refresh_token'] );
		self::assertSame( $this->now + 900, $set['expires_at'] );
	}

	public function test_a_server_that_does_not_rotate_keeps_the_refresh_token_and_project(): void {
		$this->seed( 'live', 'bsat_old', 'rt_keep', $this->now );
		$this->http->queue(
			'/oauth/token',
			200,
			array(
				'access_token' => 'bsat_new',
				'token_type'   => 'Bearer',
				'expires_in'   => 600,
			)
		);

		self::assertSame( 'bsat_new', $this->service->access_token( 'live' ) );
		$set = $this->tokens->get( 'live' );
		self::assertSame( 'rt_keep', $set['refresh_token'] );
		self::assertSame( 'Shop', $set['project_name'] );
	}

	public function test_a_refresh_answer_for_another_environment_is_not_stored(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->http->queue( '/oauth/token', 200, $this->grant( 'live', 'bsat_live', 'rt_live' ) );
		try {
			$this->service->access_token( 'test' );
			self::fail( 'expected invalid_response' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'invalid_response', $e->error_name() );
		}
		self::assertSame( 'bsat_old', $this->tokens->get( 'test' )['access_token'] );
		self::assertSame( 0, $this->tokens->saves );
	}

	public function test_after_a_401_a_changed_stored_token_is_used_instead_of_refreshing_again(): void {
		$this->seed( 'test', 'bsat_new', 'rt_new', $this->now + 3000 );
		// The API rejected bsat_old: another request already replaced it.
		self::assertSame( 'bsat_new', $this->service->refresh( 'test', 'bsat_old' ) );
		self::assertSame( array(), $this->http->requests );
		self::assertSame( 1, $this->tokens->fresh_reads, 'it read the database, not a cached copy' );
	}

	public function test_after_a_401_the_same_token_is_refreshed(): void {
		$this->seed( 'test', 'bsat_same', 'rt_1', $this->now + 3000 );
		$this->http->queue( '/oauth/token', 200, $this->grant( 'test', 'bsat_fresh', 'rt_2' ) );
		self::assertSame( 'bsat_fresh', $this->service->refresh( 'test', 'bsat_same' ) );
	}

	public function test_the_loser_of_the_refresh_lock_waits_and_uses_the_winners_tokens(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->storage->data['bitsnap_oauth_refresh_test'] = 1; // Another request holds the lock.
		$waits   = 0;
		$service = new Service(
			$this->http,
			$this->storage,
			$this->tokens,
			'https://bitsnap.pl',
			self::REDIRECT,
			'n',
			'v',
			function () {
				return $this->now;
			},
			function () use ( &$waits ) {
				++$waits;
				if ( 2 === $waits ) { // The winner finishes: saves the rotated pair and releases the lock.
					$this->tokens->sets['test']['access_token']  = 'bsat_won';
					$this->tokens->sets['test']['refresh_token'] = 'rt_won';
					$this->tokens->sets['test']['expires_at']    = $this->now + 3600;
					unset( $this->storage->data['bitsnap_oauth_refresh_test'] );
				}
			}
		);

		self::assertSame( 'bsat_won', $service->access_token( 'test' ) );
		self::assertSame( array(), $this->http->requests, 'the loser did not touch the refresh token' );
		self::assertSame( 2, $waits );
		self::assertSame( 'rt_won', $this->tokens->get( 'test' )['refresh_token'] );
	}

	public function test_a_lock_that_never_clears_ends_in_busy_without_using_the_refresh_token(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->storage->data['bitsnap_oauth_refresh_test'] = 1;
		try {
			$this->service->access_token( 'test' );
			self::fail( 'expected busy' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'busy', $e->error_name() );
		}
		self::assertSame( array(), $this->http->requests );
		self::assertArrayHasKey( 'bitsnap_oauth_refresh_test', $this->storage->data, 'the winner keeps its lock' );
	}

	public function test_the_lock_is_released_after_success_and_after_failure(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->http->queue( '/oauth/token', 200, $this->grant( 'test', 'bsat_b', 'rt_b' ) );
		$this->service->access_token( 'test' );
		self::assertArrayNotHasKey( 'bitsnap_oauth_refresh_test', $this->storage->data );

		$this->tokens->sets['test']['expires_at'] = $this->now;
		$this->http->queue( '/oauth/token', 503, 'down' );
		try {
			$this->service->access_token( 'test' );
			self::fail( 'expected server_error' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'server_error', $e->error_name() );
		}
		self::assertArrayNotHasKey( 'bitsnap_oauth_refresh_test', $this->storage->data );
		self::assertSame( 'rt_b', $this->tokens->get( 'test' )['refresh_token'], 'a failed refresh keeps the tokens' );
	}

	public function test_the_loser_looks_again_after_an_empty_lock_wait_when_disconnected(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->storage->data['bitsnap_oauth_refresh_test'] = 1;
		$service = new Service(
			$this->http,
			$this->storage,
			$this->tokens,
			'https://bitsnap.pl',
			self::REDIRECT,
			'n',
			'v',
			function () {
				return $this->now;
			},
			function () {
				$this->tokens->clear( 'test' );
			}
		);
		try {
			$service->refresh( 'test', 'bsat_old' );
			self::fail( 'expected not_connected' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'not_connected', $e->error_name() );
		}
	}

	public function test_a_revoked_grant_clears_that_environment_only(): void {
		$this->seed( 'test', 'bsat_t', 'rt_t', $this->now );
		$this->tokens->sets['live'] = array(
			'access_token'  => 'bsat_l',
			'refresh_token' => 'rt_l',
			'expires_at'    => $this->now + 3000,
			'project_id'    => '1',
			'project_name'  => 'Live shop',
			'environment'   => 'live',
		);
		$this->http->queue( '/oauth/token', 400, '{"error":"invalid_grant"}' );

		try {
			$this->service->access_token( 'test' );
			self::fail( 'expected invalid_grant' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'invalid_grant', $e->error_name() );
		}
		self::assertFalse( $this->service->connected( 'test' ) );
		self::assertTrue( $this->service->connected( 'live' ) );
	}

	public function test_invalid_grant_does_not_clear_a_newer_token_pair(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->http->queue( '/oauth/token', 400, '{"error":"invalid_grant"}' );
		$this->http->before = function ( $path ) {
			if ( '/oauth/token' === $path ) {
				$this->seed( 'test', 'bsat_winner', 'rt_winner', $this->now + 3500 );
			}
		};
		try {
			$this->service->access_token( 'test' );
			self::fail( 'Expected invalid_grant' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'invalid_grant', $e->error_name() );
		}
		self::assertSame( 'rt_winner', $this->tokens->get( 'test' )['refresh_token'] );
		self::assertSame( 'bsat_winner', $this->service->access_token( 'test' ) );
	}

	public function test_a_temporary_failure_keeps_the_tokens(): void {
		$this->seed( 'test', 'bsat_t', 'rt_t', $this->now );
		foreach ( array( array( 0, 'network' ), array( 502, 'server_error' ) ) as $case ) {
			$this->http->queue( '/oauth/token', $case[0], '' );
			try {
				$this->service->access_token( 'test' );
				self::fail( 'expected ' . $case[1] );
			} catch ( OAuthException $e ) {
				self::assertSame( $case[1], $e->error_name() );
			}
			self::assertTrue( $this->service->connected( 'test' ) );
		}
	}

	// ---- re-registration

	public function test_invalid_client_with_status_401_registers_again_and_retries_once(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->http->queue( '/oauth/token', 401, '{"error":"invalid_client"}' );
		$this->http->queue( '/oauth/token', 200, $this->grant( 'test', 'bsat_new', 'rt_new' ) );

		self::assertSame( 'bsat_new', $this->service->access_token( 'test' ) );

		self::assertSame( 1, $this->http->registrations );
		$sent = $this->http->bodies( '/oauth/token' );
		self::assertSame( 'client-0', $sent[0]['client_id'] );
		self::assertSame( 'client-1', $sent[1]['client_id'], 'the retry uses the new client' );
		self::assertSame( 'client-1', $this->tokens->client );
	}

	public function test_invalid_client_with_status_400_registers_again_too(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->http->queue( '/oauth/token', 400, '{"error":"invalid_client"}' );
		$this->http->queue( '/oauth/token', 200, $this->grant( 'test', 'bsat_new', 'rt_new' ) );

		self::assertSame( 'bsat_new', $this->service->access_token( 'test' ) );
		self::assertSame( 1, $this->http->registrations );
	}

	public function test_a_401_that_is_not_invalid_client_does_not_register_again(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->http->queue( '/oauth/token', 401, 'Unauthorized' );
		try {
			$this->service->access_token( 'test' );
			self::fail( 'expected an error' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'http_error', $e->error_name() );
		}
		self::assertSame( 0, $this->http->registrations );
		self::assertCount( 1, $this->http->bodies( '/oauth/token' ) );
		self::assertSame( 'client-0', $this->tokens->client );
	}

	public function test_registering_again_happens_only_once_per_call(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->http->queue( '/oauth/token', 401, '{"error":"invalid_client"}' );
		$this->http->queue( '/oauth/token', 401, '{"error":"invalid_client"}' );
		try {
			$this->service->access_token( 'test' );
			self::fail( 'expected invalid_client' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'invalid_client', $e->error_name() );
		}
		self::assertSame( 1, $this->http->registrations );
		self::assertCount( 2, $this->http->bodies( '/oauth/token' ) );
		self::assertTrue( $this->service->connected( 'test' ), 'the grant is not given up on a client problem' );
	}

	public function test_a_failed_registration_is_reported(): void {
		$this->http->queue( '/oauth/register', 400, '{"error":"invalid_redirect_uri"}' );
		try {
			$this->service->begin( 'test', 7 );
			self::fail( 'expected registration_failed' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'registration_failed', $e->error_name() );
		}
		$this->http->queue( '/oauth/register', 201, '{"nope":true}' );
		$this->expectException( OAuthException::class );
		$this->service->begin( 'test', 7 );
	}

	// ---- disconnect

	public function test_disconnect_revokes_the_refresh_token_and_clears_one_environment(): void {
		$this->connect( 'test', 'bsat_t', 'rt_t' );
		$this->connect( 'live', 'bsat_l', 'rt_l' );
		$this->http->queue( '/oauth/revoke', 200, '' );

		self::assertTrue( $this->service->disconnect( 'test' ) );

		self::assertSame(
			array(
				'token'           => 'rt_t',
				'token_type_hint' => 'refresh_token',
				'client_id'       => 'client-1',
			),
			$this->http->bodies( '/oauth/revoke' )[0]
		);
		self::assertFalse( $this->service->connected( 'test' ) );
		self::assertTrue( $this->service->connected( 'live' ), 'live stays connected' );
		self::assertSame( 'rt_l', $this->tokens->get( 'live' )['refresh_token'] );
		self::assertSame( 'client-1', $this->tokens->client, 'the registration stays for the other environment' );
	}

	public function test_disconnect_forgets_the_tokens_even_when_revoking_fails(): void {
		$this->connect( 'test' );
		$this->http->queue( '/oauth/revoke', 0, '' );
		self::assertFalse( $this->service->disconnect( 'test' ) );
		self::assertFalse( $this->service->connected( 'test' ) );
	}

	public function test_the_environments_are_independent(): void {
		$this->connect( 'test', 'bsat_t', 'rt_t', 3600 );
		$this->connect( 'live', 'bsat_l', 'rt_l', 30 );

		self::assertSame( 'bsat_t', $this->service->access_token( 'test' ), 'test is fresh' );
		$this->http->queue( '/oauth/token', 200, $this->grant( 'live', 'bsat_l2', 'rt_l2' ) );
		self::assertSame( 'bsat_l2', $this->service->access_token( 'live' ), 'live refreshes alone' );
		self::assertSame( 'rt_t', $this->tokens->get( 'test' )['refresh_token'] );
		self::assertSame( 'rt_l2', $this->tokens->get( 'live' )['refresh_token'] );
	}

	public function test_an_environment_that_is_not_connected_has_no_token(): void {
		try {
			$this->service->access_token( 'live' );
			self::fail( 'expected not_connected' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'not_connected', $e->error_name() );
		}
	}

	public function test_errors_never_carry_a_response_body(): void {
		$this->seed( 'test', 'bsat_old', 'rt_old', $this->now );
		$this->http->queue( '/oauth/token', 400, '{"error":"invalid_request","error_description":"refresh_token rt_old bsat_old"}' );
		try {
			$this->service->access_token( 'test' );
			self::fail( 'expected an error' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'invalid_request', $e->error_name() );
			self::assertStringNotContainsString( 'rt_old', $e->getMessage() . $e->getTraceAsString() );
		}
	}

	private function expectState( string $state, string $code, int $user, string $error = '' ): void {
		try {
			$this->service->complete( $state, $code, $user, $error );
			self::fail( 'expected invalid_state' );
		} catch ( OAuthException $e ) {
			self::assertSame( 'invalid_state', $e->error_name() );
		}
	}
}
