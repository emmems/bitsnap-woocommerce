<?php
namespace Bitsnap\WooCommerce\Tests\Unit;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Api\Client;
use Bitsnap\WooCommerce\Tests\Doubles\ArrayWebhookStore;
use Bitsnap\WooCommerce\Webhook\Registrar;
use PHPUnit\Framework\TestCase;

final class WebhookRegistrarTest extends TestCase {

	private const URL = 'https://shop.example/?wc-api=bitsnap';

	/** @var ArrayWebhookStore */
	private $store;

	/** @var Registrar */
	private $registrar;

	/** @var array<int,array{string,string,array,?string}> Calls the fake client received. */
	private $calls = array();

	/** @var array<string,callable> Answers by `Service/Method`. */
	private $answers = array();

	protected function setUp(): void {
		$this->store     = new ArrayWebhookStore();
		$this->registrar = new Registrar( $this->store, self::URL, 'WooCommerce: shop.example', array( 'TRANSACTION_SUCCESS', 'REFUND_SUCCEEDED' ) );
	}

	private function client(): Client {
		$client = $this->createMock( Client::class );
		$client->method( 'call' )->willReturnCallback(
			function ( string $service, string $method, array $body, ?string $key = null ) {
				$this->calls[] = array( $service, $method, $body, $key );
				$answer        = $this->answers[ $service . '/' . $method ] ?? static function () {
					return array();
				};
				return $answer( $body );
			}
		);
		return $client;
	}

	private function created( string $id = 'wh_1', string $secret = 'whsec_abc' ): callable {
		return static function () use ( $id, $secret ) {
			return array(
				'webhook'       => array(
					'id'  => $id,
					'url' => self::URL,
				),
				'signingSecret' => $secret,
			);
		};
	}

	private function methods(): array {
		return array_map(
			static function ( $call ) {
				return $call[1];
			},
			$this->calls
		);
	}

	public function test_the_create_answer_gives_the_id_and_the_secret(): void {
		self::assertSame(
			array(
				'id'     => 'wh_9',
				'secret' => 'whsec_x',
			),
			Registrar::parse_created(
				array(
					'webhook'       => array( 'id' => 'wh_9' ),
					'signingSecret' => 'whsec_x',
				)
			)
		);
		self::assertSame(
			array(
				'id'     => '',
				'secret' => '',
			),
			Registrar::parse_created( array() )
		);
	}

	public function test_register_creates_the_webhook_and_stores_id_and_secret(): void {
		$this->answers['WebhooksService/CreateWebhook'] = $this->created( 'wh_7', 'whsec_7' );

		$result = $this->registrar->register( 'test', $this->client() );

		self::assertSame( 'wh_7', $result['id'] );
		self::assertSame( array( 'test' => 'wh_7' ), $this->store->ids );
		self::assertSame( array( 'test' => 'whsec_7' ), $this->store->secrets );
		self::assertSame( array( 'CreateWebhook' ), $this->methods() );
		$call = $this->calls[0];
		self::assertSame(
			array(
				'name'   => 'WooCommerce: shop.example',
				'url'    => self::URL,
				'events' => array( 'TRANSACTION_SUCCESS', 'REFUND_SUCCEEDED' ),
			),
			$call[2]
		);
		self::assertMatchesRegularExpression( '/^woo-webhook-[a-f0-9]{16}$/', (string) $call[3] );
	}

	public function test_ids_are_kept_per_environment(): void {
		$this->answers['WebhooksService/CreateWebhook'] = $this->created( 'wh_t', 'whsec_t' );
		$this->registrar->register( 'test', $this->client() );
		$this->answers['WebhooksService/CreateWebhook'] = $this->created( 'wh_l', 'whsec_l' );
		$this->registrar->register( 'live', $this->client() );

		self::assertSame(
			array(
				'test' => 'wh_t',
				'live' => 'wh_l',
			),
			$this->store->ids
		);
	}

	public function test_an_answer_without_a_secret_stores_nothing(): void {
		$this->answers['WebhooksService/CreateWebhook'] = $this->created( 'wh_1', '' );
		try {
			$this->registrar->register( 'test', $this->client() );
			self::fail( 'expected an ApiException' );
		} catch ( ApiException $e ) {
			self::assertSame( 'missing_signing_secret', $e->get_reason() );
		}
		self::assertSame( array(), $this->store->ids );
		self::assertSame( array(), $this->store->secrets );
	}

	public function test_an_answer_without_an_id_still_stores_the_secret(): void {
		$this->answers['WebhooksService/CreateWebhook'] = $this->created( '', 'whsec_only' );
		$this->registrar->register( 'test', $this->client() );
		self::assertSame( array(), $this->store->ids );
		self::assertSame( array( 'test' => 'whsec_only' ), $this->store->secrets );
	}

	public function test_replacing_deletes_the_old_webhook_by_id_before_creating(): void {
		$this->store->save( 'test', 'wh_old', 'whsec_old' );
		$this->answers['WebhooksService/CreateWebhook'] = $this->created( 'wh_new', 'whsec_new' );

		$this->registrar->register( 'test', $this->client(), true );

		self::assertSame( array( 'DeleteWebhook', 'CreateWebhook' ), $this->methods() );
		self::assertSame( array( 'id' => 'wh_old' ), $this->calls[0][2] );
		self::assertSame( 'wh_new', $this->store->id( 'test' ) );
		self::assertSame( 'whsec_new', $this->store->secrets['test'] );
	}

	public function test_replacing_goes_on_when_the_old_webhook_cannot_be_deleted(): void {
		$this->store->save( 'test', 'wh_old', 'whsec_old' );
		$this->answers['WebhooksService/DeleteWebhook'] = static function () {
			throw new ApiException( 'boom', 500, 'internal' );
		};
		$this->answers['WebhooksService/CreateWebhook'] = $this->created( 'wh_new', 'whsec_new' );

		$this->registrar->register( 'test', $this->client(), true );

		self::assertSame( 'wh_new', $this->store->id( 'test' ) );
	}

	public function test_remove_deletes_by_the_stored_id_and_never_by_url(): void {
		$this->store->save( 'test', 'wh_1', 'whsec_1' );

		self::assertSame( Registrar::DELETED, $this->registrar->remove( 'test', $this->client() ) );

		self::assertSame( array( 'DeleteWebhook' ), $this->methods(), 'no listing when the id is known' );
		self::assertSame( array( 'id' => 'wh_1' ), $this->calls[0][2] );
		self::assertArrayNotHasKey( 'url', $this->calls[0][2] );
		self::assertSame( array(), $this->store->ids );
		self::assertSame( array(), $this->store->secrets );
	}

	public function test_remove_without_a_stored_id_finds_it_in_the_list_by_url(): void {
		$this->store->secrets['test']                  = 'whsec_pasted';
		$this->answers['WebhooksService/ListWebhooks'] = static function () {
			return array(
				'webhooks' => array(
					array(
						'id'  => 'wh_other',
						'url' => 'https://another.example/?wc-api=bitsnap',
					),
					array(
						'id'  => 'wh_mine',
						'url' => self::URL,
					),
				),
			);
		};

		self::assertSame( Registrar::DELETED, $this->registrar->remove( 'test', $this->client() ) );

		self::assertSame( array( 'ListWebhooks', 'DeleteWebhook' ), $this->methods() );
		self::assertSame( array( 'id' => 'wh_mine' ), $this->calls[1][2] );
		self::assertSame( array(), $this->store->secrets );
	}

	public function test_remove_without_a_stored_id_and_no_match_deletes_nothing(): void {
		$this->store->secrets['test']                  = 'whsec_pasted';
		$this->answers['WebhooksService/ListWebhooks'] = static function () {
			return array(
				'webhooks' => array(
					array(
						'id'  => 'wh_other',
						'url' => 'https://another.example/?wc-api=bitsnap',
					),
				),
			);
		};

		self::assertSame( Registrar::NONE, $this->registrar->remove( 'test', $this->client() ) );

		self::assertSame( array( 'ListWebhooks' ), $this->methods() );
		self::assertSame( array( 'test' => 'whsec_pasted' ), $this->store->secrets, 'a secret the merchant pasted stays' );
	}

	public function test_remove_without_a_stored_id_and_without_the_read_scope_does_nothing(): void {
		$this->answers['WebhooksService/ListWebhooks'] = static function () {
			throw new ApiException( 'needs webhooks:read', 403, 'permission_denied', 'missing_scope' );
		};
		self::assertSame( Registrar::NONE, $this->registrar->remove( 'test', $this->client() ) );
		self::assertSame( array( 'ListWebhooks' ), $this->methods() );
	}

	public function test_remove_treats_a_webhook_that_is_already_gone_as_deleted(): void {
		$this->store->save( 'test', 'wh_gone', 'whsec_1' );
		$this->answers['WebhooksService/DeleteWebhook'] = static function () {
			throw new ApiException( 'not found', 404, 'not_found' );
		};
		self::assertSame( Registrar::DELETED, $this->registrar->remove( 'test', $this->client() ) );
		self::assertSame( array(), $this->store->ids );
	}

	public function test_remove_reports_a_failure_and_keeps_the_id_for_a_later_try(): void {
		$this->store->save( 'test', 'wh_1', 'whsec_1' );
		$this->answers['WebhooksService/DeleteWebhook'] = static function () {
			throw new ApiException( 'down', 503, 'unavailable' );
		};
		self::assertSame( Registrar::FAILED, $this->registrar->remove( 'test', $this->client() ) );
		self::assertSame( 'wh_1', $this->store->id( 'test' ) );
	}

	public function test_remove_reports_a_failure_of_the_list_call(): void {
		$this->answers['WebhooksService/ListWebhooks'] = static function () {
			throw new ApiException( 'down', 503, 'unavailable' );
		};
		self::assertSame( Registrar::FAILED, $this->registrar->remove( 'test', $this->client() ) );
	}

	public function test_remove_touches_one_environment_only(): void {
		$this->store->save( 'test', 'wh_t', 'whsec_t' );
		$this->store->save( 'live', 'wh_l', 'whsec_l' );
		$this->registrar->remove( 'test', $this->client() );
		self::assertSame( array( 'live' => 'wh_l' ), $this->store->ids );
		self::assertSame( array( 'live' => 'whsec_l' ), $this->store->secrets );
	}

	public function test_find_by_url_ignores_other_urls(): void {
		self::assertSame( '', Registrar::find_id( array( 'webhooks' => array( array( 'id' => 'x', 'url' => 'https://other' ) ) ), self::URL ) );
		self::assertSame( '', Registrar::find_id( array(), self::URL ) );
		self::assertSame( '', Registrar::find_id( array( 'webhooks' => 'nope' ), self::URL ) );
		self::assertSame( 'x', Registrar::find_id( array( 'webhooks' => array( array( 'id' => 'x', 'url' => self::URL ) ) ), self::URL ) );
	}
}
