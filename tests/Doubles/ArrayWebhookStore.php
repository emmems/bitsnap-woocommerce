<?php
namespace Bitsnap\WooCommerce\Tests\Doubles;

use Bitsnap\WooCommerce\Webhook\Store;

/** In-memory webhook Store. */
final class ArrayWebhookStore implements Store {

	/** @var array<string,string> */
	public $ids = array();

	/** @var array<string,string> */
	public $secrets = array();

	public function id( string $environment ): string {
		return $this->ids[ $environment ] ?? '';
	}

	public function save( string $environment, string $id, string $secret ): void {
		if ( '' !== $id ) {
			$this->ids[ $environment ] = $id;
		}
		$this->secrets[ $environment ] = $secret;
	}

	public function clear( string $environment ): void {
		unset( $this->ids[ $environment ], $this->secrets[ $environment ] );
	}
}
