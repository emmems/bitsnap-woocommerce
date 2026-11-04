<?php
namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\OAuth\Protocol;
use PHPUnit\Framework\TestCase;

final class OAuthProtocolTest extends TestCase {

	public function test_pkce_s256_matches_the_rfc_7636_appendix_b_vector(): void {
		$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		self::assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Protocol::challenge( $verifier ) );
	}

	public function test_a_new_verifier_is_43_url_safe_characters_and_never_repeats(): void {
		$a = Protocol::verifier();
		$b = Protocol::verifier();
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $a );
		self::assertNotSame( $a, $b );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', Protocol::new_state() );
	}

	public function test_authorize_url_carries_every_parameter_of_the_contract(): void {
		$challenge = Protocol::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' );
		$url       = Protocol::authorize_url( 'https://bitsnap.pl/', 'client-1', 'https://shop.example/wp-admin/admin-post.php?action=bitsnap_oauth_callback', 'st4te', $challenge, 'live' );

		self::assertStringStartsWith( 'https://bitsnap.pl/oauth/authorize?', $url );
		self::assertStringContainsString( 'scope=woocommerce%20offline_access', $url, 'a space is %20, not +' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		self::assertSame(
			array(
				'response_type'         => 'code',
				'client_id'             => 'client-1',
				'redirect_uri'          => 'https://shop.example/wp-admin/admin-post.php?action=bitsnap_oauth_callback',
				'scope'                 => 'woocommerce offline_access',
				'state'                 => 'st4te',
				'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
				'code_challenge_method' => 'S256',
				'resource'              => 'https://bitsnap.pl/api/v1',
				'environment'           => 'live',
			),
			$query
		);
	}

	public function test_state_is_valid_only_for_the_same_state_user_and_time(): void {
		$record = array(
			'state'    => 'abc',
			'user'     => 4,
			'expires'  => 100,
			'verifier' => 'v',
			'env'      => 'test',
		);
		self::assertTrue( Protocol::valid_state( $record, 'abc', 4, 99 ) );
		self::assertTrue( Protocol::valid_state( $record, 'abc', 4, 100 ), 'valid until the expiry second' );
		self::assertFalse( Protocol::valid_state( $record, 'abc', 4, 101 ), 'expired' );
		self::assertFalse( Protocol::valid_state( $record, 'abc', 5, 99 ), 'another user' );
		self::assertFalse( Protocol::valid_state( $record, 'abd', 4, 99 ), 'another state' );
		self::assertFalse( Protocol::valid_state( $record, '', 4, 99 ), 'empty state' );
		self::assertFalse( Protocol::valid_state( array(), 'abc', 4, 99 ), 'no record' );
		self::assertFalse( Protocol::valid_state( array_merge( $record, array( 'env' => 'staging' ) ), 'abc', 4, 99 ), 'unknown environment' );
		unset( $record['verifier'] );
		self::assertFalse( Protocol::valid_state( $record, 'abc', 4, 99 ), 'no verifier' );
	}

	public function test_refresh_window_is_the_last_sixty_seconds(): void {
		self::assertTrue( Protocol::should_refresh( 1000, 1000 ), 'expired' );
		self::assertTrue( Protocol::should_refresh( 1060, 1000 ), 'exactly 60 s left' );
		self::assertFalse( Protocol::should_refresh( 1061, 1000 ), '61 s left' );
		self::assertTrue( Protocol::should_refresh( 0, 1000 ), 'unknown expiry' );
	}

	public function test_a_401_refreshes_once_and_nothing_else_does(): void {
		self::assertTrue( Protocol::should_refresh_after_response( 401, false ) );
		self::assertFalse( Protocol::should_refresh_after_response( 401, true ), 'only once per call' );
		foreach ( array( 200, 400, 403, 404, 429, 500 ) as $status ) {
			self::assertFalse( Protocol::should_refresh_after_response( $status, false ), (string) $status );
		}
	}

	public function test_token_answer_is_parsed_with_the_bitsnap_fields(): void {
		$tokens = Protocol::parse_tokens(
			array(
				'access_token'         => 'bsat_a',
				'refresh_token'        => 'r1',
				'token_type'           => 'Bearer',
				'expires_in'           => 3600,
				'scope'                => 'checkout:create',
				'bitsnap_project_id'   => 18,
				'bitsnap_project_name' => 'Lece na kodach',
				'bitsnap_environment'  => 'live',
			),
			1000
		);
		self::assertSame(
			array(
				'access_token'  => 'bsat_a',
				'refresh_token' => 'r1',
				'expires_at'    => 4600,
				'project_id'    => '18',
				'project_name'  => 'Lece na kodach',
				'environment'   => 'live',
			),
			$tokens
		);
	}

	public function test_a_grant_answer_without_the_bitsnap_fields_is_rejected(): void {
		$base = array(
			'access_token'         => 'a',
			'refresh_token'        => 'r',
			'expires_in'           => 60,
			'bitsnap_project_id'   => 'p',
			'bitsnap_project_name' => 'Shop',
			'bitsnap_environment'  => 'test',
		);
		self::assertNotNull( Protocol::parse_tokens( $base, 0 ) );
		foreach ( array( 'access_token', 'refresh_token', 'expires_in', 'bitsnap_project_id', 'bitsnap_project_name', 'bitsnap_environment' ) as $missing ) {
			$data = $base;
			unset( $data[ $missing ] );
			self::assertNull( Protocol::parse_tokens( $data, 0 ), $missing );
		}
		self::assertNull( Protocol::parse_tokens( array_merge( $base, array( 'bitsnap_environment' => 'staging' ) ), 0 ), 'unknown environment' );
		self::assertNull( Protocol::parse_tokens( array_merge( $base, array( 'expires_in' => 0 ) ), 0 ), 'already expired' );
		self::assertNull( Protocol::parse_tokens( array_merge( $base, array( 'expires_in' => 'soon' ) ), 0 ), 'not a number' );
		self::assertNull( Protocol::parse_tokens( array(), 0 ) );
	}

	public function test_a_refresh_answer_may_leave_out_project_fields_and_the_refresh_token(): void {
		$tokens = Protocol::parse_tokens(
			array(
				'access_token' => 'new',
				'expires_in'   => 300,
			),
			10,
			false
		);
		self::assertSame( 'new', $tokens['access_token'] );
		self::assertSame( '', $tokens['refresh_token'] );
		self::assertSame( 310, $tokens['expires_at'] );
		self::assertSame( '', $tokens['environment'] );
		self::assertNull( Protocol::parse_tokens( array( 'access_token' => 'new' ), 10, false ), 'still needs a lifetime' );
		self::assertNull(
			Protocol::parse_tokens(
				array(
					'access_token'        => 'new',
					'expires_in'          => 300,
					'bitsnap_environment' => 'moon',
				),
				10,
				false
			),
			'an environment that is sent must be valid'
		);
	}

	public function test_reregistration_follows_the_oauth_error_code_not_the_status(): void {
		$invalid_client = '{"error":"invalid_client","error_description":"unknown client"}';
		self::assertTrue( Protocol::should_reregister( 401, $invalid_client ), 'RFC 6749 allows 401' );
		self::assertTrue( Protocol::should_reregister( 400, $invalid_client ), 'RFC 6749 and 7591 allow 400' );
		self::assertFalse( Protocol::should_reregister( 401, '{"error":"invalid_token"}' ), 'a 401 for another reason' );
		self::assertFalse( Protocol::should_reregister( 401, 'Unauthorized' ), 'a 401 without JSON' );
		self::assertFalse( Protocol::should_reregister( 401, '' ), 'a 401 without a body' );
		self::assertFalse( Protocol::should_reregister( 400, '{"error":"invalid_grant"}' ), 'the grant is gone, not the client' );
		self::assertFalse( Protocol::should_reregister( 500, $invalid_client ), 'a server error says nothing' );
		self::assertFalse( Protocol::should_reregister( 200, $invalid_client ) );
		self::assertSame( 'invalid_grant', Protocol::error_code( '{"error":"invalid_grant"}' ) );
		self::assertSame( '', Protocol::error_code( '{"error":["x"]}' ) );
		self::assertSame( '', Protocol::error_code( 'null' ) );
	}

	public function test_oauth_needs_https_except_on_localhost(): void {
		self::assertTrue( Protocol::is_allowed_url( 'https://shop.example/wp-admin/admin-post.php' ) );
		self::assertTrue( Protocol::is_allowed_url( 'http://localhost:8888/wp-admin/admin-post.php' ) );
		self::assertTrue( Protocol::is_allowed_url( 'http://127.0.0.1:4321' ) );
		self::assertFalse( Protocol::is_allowed_url( 'http://shop.example/wp-admin/admin-post.php' ) );
		self::assertFalse( Protocol::is_allowed_url( 'http://localhost.evil.example/' ) );
		self::assertFalse( Protocol::is_allowed_url( 'javascript:alert(1)' ) );
		self::assertFalse( Protocol::is_allowed_url( '' ) );
	}

	public function test_registration_request_matches_the_contract(): void {
		self::assertSame(
			array(
				'client_name'                => 'Bitsnap for WooCommerce – shop.example',
				'redirect_uris'              => array( 'https://shop.example/cb' ),
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'software_id'                => 'bitsnap-for-woocommerce',
				'software_version'           => '0.2.0',
			),
			Protocol::registration_request( 'Bitsnap for WooCommerce – shop.example', 'https://shop.example/cb', '0.2.0' )
		);
	}
}
