<?php
/**
 * Creates and deletes the plugin's webhook in Bitsnap.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Webhook;

use Bitsnap\WooCommerce\Api\ApiException;
use Bitsnap\WooCommerce\Api\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Exception messages are logged and shown to administrators escaped.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Registers `{site}/?wc-api=bitsnap` with `WebhooksService.CreateWebhook` and removes it again with
 * `DeleteWebhook`, which takes the webhook id (`{ "id": "<webhook id>" }`), not its URL.
 *
 * The id from the create answer (`webhook.id`) is stored per environment. A site that has none, for example one
 * that registered before ids were stored, finds it with `ListWebhooks` by matching the URL.
 */
final class Registrar {

	/**
	 * The webhook was deleted in Bitsnap, or was already gone.
	 */
	public const DELETED = 'deleted';

	/**
	 * There was nothing to delete.
	 */
	public const NONE = 'none';

	/**
	 * Bitsnap could not delete it. The merchant removes it in the dashboard.
	 */
	public const FAILED = 'failed';

	/**
	 * Where ids and secrets are kept.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Webhook URL of this site.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Webhook name shown in Bitsnap.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Events to subscribe to.
	 *
	 * @var string[]
	 */
	private $events;

	/**
	 * Constructor.
	 *
	 * @param Store    $store  Where ids and secrets are kept.
	 * @param string   $url    Webhook URL of this site.
	 * @param string   $name   Webhook name shown in Bitsnap.
	 * @param string[] $events Events to subscribe to.
	 */
	public function __construct( Store $store, string $url, string $name, array $events ) {
		$this->store  = $store;
		$this->url    = $url;
		$this->name   = $name;
		$this->events = $events;
	}

	/**
	 * Reads a `CreateWebhookResponse`: `{ webhook: { id, url, … }, signingSecret }`.
	 *
	 * @param array<string,mixed> $response Decoded answer.
	 * @return array{id:string,secret:string}
	 */
	public static function parse_created( array $response ): array {
		$webhook = isset( $response['webhook'] ) && is_array( $response['webhook'] ) ? $response['webhook'] : array();
		return array(
			'id'     => isset( $webhook['id'] ) && is_scalar( $webhook['id'] ) ? (string) $webhook['id'] : '',
			'secret' => isset( $response['signingSecret'] ) && is_string( $response['signingSecret'] ) ? $response['signingSecret'] : '',
		);
	}

	/**
	 * Finds the id of the webhook with a URL in a `ListWebhooksResponse`.
	 *
	 * @param array<string,mixed> $response Decoded answer.
	 * @param string              $url      Webhook URL.
	 * @return string '' when there is none.
	 */
	public static function find_id( array $response, string $url ): string {
		foreach ( (array) ( $response['webhooks'] ?? array() ) as $webhook ) {
			if ( is_array( $webhook ) && isset( $webhook['url'], $webhook['id'] ) && $webhook['url'] === $url && is_scalar( $webhook['id'] ) ) {
				return (string) $webhook['id'];
			}
		}
		return '';
	}

	/**
	 * Creates the webhook and stores its id and secret.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param Client $client      Client of that environment.
	 * @param bool   $replace     Delete this site's earlier webhook first, so its secret is not left behind.
	 * @return array{id:string,secret:string}
	 * @throws ApiException When Bitsnap refuses or returns no signing secret.
	 */
	public function register( string $environment, Client $client, bool $replace = false ): array {
		if ( $replace ) {
			$this->remove( $environment, $client );
		}
		$created = self::parse_created(
			$client->call(
				'WebhooksService',
				'CreateWebhook',
				array(
					'name'   => $this->name,
					'url'    => $this->url,
					'events' => $this->events,
				),
				'woo-webhook-' . bin2hex( random_bytes( 8 ) )
			)
		);
		if ( '' === $created['secret'] ) {
			throw new ApiException( 'Bitsnap created the webhook but did not return its signing secret.', 200, 'internal', 'missing_signing_secret' );
		}
		$this->store->save( $environment, $created['id'], $created['secret'] );
		return $created;
	}

	/**
	 * Deletes this site's webhook by id and forgets it. The id is the stored one, or, when none is stored,
	 * the one `ListWebhooks` shows for the URL.
	 *
	 * @param string $environment 'test' or 'live'.
	 * @param Client $client      Client of that environment.
	 * @return string One of the class constants.
	 */
	public function remove( string $environment, Client $client ): string {
		$id = $this->store->id( $environment );
		try {
			if ( '' === $id ) {
				$id = $this->find_by_url( $client );
			}
			if ( '' === $id ) {
				// Nothing is known to be ours, so a secret the merchant pasted is left alone.
				return self::NONE;
			}
			$client->call( 'WebhooksService', 'DeleteWebhook', array( 'id' => $id ) );
		} catch ( ApiException $e ) {
			if ( 404 !== $e->get_http_status() && 'not_found' !== $e->get_connect_code() ) {
				return self::FAILED;
			}
			// Already deleted in the dashboard.
		}
		$this->store->clear( $environment );
		return self::DELETED;
	}

	/**
	 * Id of the webhook for this site's URL that Bitsnap already has.
	 *
	 * Needs `webhooks:read`. When that permission is missing there is nothing to find.
	 *
	 * @param Client $client Client.
	 * @return string '' when there is none.
	 * @throws ApiException When the list call fails for a reason other than a missing permission.
	 */
	public function find_by_url( Client $client ): string {
		try {
			$response = $client->call( 'WebhooksService', 'ListWebhooks', array() );
		} catch ( ApiException $e ) {
			if ( 'missing_scope' === $e->get_reason() ) {
				return '';
			}
			throw $e;
		}
		return self::find_id( $response, $this->url );
	}
}
