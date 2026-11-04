<?php
namespace Bitsnap\WooCommerce\Tests\Doubles;

use Bitsnap\WooCommerce\OAuth\TokenStore;

/** In-memory TokenStore that counts writes. */
final class ArrayTokenStore implements TokenStore {

	/** @var array<string,array<string,mixed>> */
	public $sets = array();

	/** @var string */
	public $client = '';

	/** @var string */
	public $fingerprint = '';

	/** @var int Number of save() calls. */
	public $saves = 0;

	/** @var int Number of fresh reads. */
	public $fresh_reads = 0;

	public function get( string $environment, bool $fresh = false ): array {
		if ( $fresh ) {
			++$this->fresh_reads;
		}
		return $this->sets[ $environment ] ?? array();
	}

	public function save( string $environment, array $tokens ): void {
		++$this->saves;
		$this->sets[ $environment ] = $tokens;
	}

	public function clear( string $environment ): void {
		unset( $this->sets[ $environment ] );
	}

	public function client_id( string $fingerprint ): string {
		return $fingerprint === $this->fingerprint ? $this->client : '';
	}

	public function save_client_id( string $client_id, string $fingerprint ): void {
		$this->client      = $client_id;
		$this->fingerprint = $fingerprint;
	}

	public function forget_client(): void {
		$this->client      = '';
		$this->fingerprint = '';
	}
}
