<?php
namespace Bitsnap\WooCommerce\Tests\Doubles;

use Bitsnap\WooCommerce\OAuth\Transport;

/**
 * Transport that answers from a script. `/oauth/register` answers with client-1, client-2, … by itself.
 * Other paths take the next queued answer, or 500 when the queue is empty.
 */
final class ScriptedTransport implements Transport {

	/** @var array<string,array<int,array{int,string}>> */
	public $queue = array();

	/** @var array<int,array{path:string,body:array,json:bool}> */
	public $requests = array();

	/** @var int */
	public $registrations = 0;

	/** @var callable|null Runs before each answer, with the path. */
	public $before = null;

	public function queue( string $path, int $status, $body ): void {
		$this->queue[ $path ][] = array( $status, is_string( $body ) ? $body : json_encode( $body ) );
	}

	public function post( string $url, array $body, bool $json = false ): array {
		$path               = (string) parse_url( $url, PHP_URL_PATH );
		$this->requests[]   = array(
			'path' => $path,
			'body' => $body,
			'json' => $json,
		);
		if ( $this->before ) {
			call_user_func( $this->before, $path );
		}
		if ( '/oauth/register' === $path && empty( $this->queue[ $path ] ) ) {
			++$this->registrations;
			return array(
				'status' => 201,
				'body'   => json_encode( array( 'client_id' => 'client-' . $this->registrations ) ),
			);
		}
		if ( ! empty( $this->queue[ $path ] ) ) {
			$next = array_shift( $this->queue[ $path ] );
			return array(
				'status' => $next[0],
				'body'   => $next[1],
			);
		}
		return array(
			'status' => 500,
			'body'   => '',
		);
	}

	/** @return array<int,array> Bodies posted to a path. */
	public function bodies( string $path ): array {
		$out = array();
		foreach ( $this->requests as $request ) {
			if ( $request['path'] === $path ) {
				$out[] = $request['body'];
			}
		}
		return $out;
	}
}
