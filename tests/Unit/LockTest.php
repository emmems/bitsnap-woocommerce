<?php

namespace Bitsnap\WooCommerce\Support {
	function wp_cache_delete( $key, $group ) {
		$GLOBALS['lock_cache_deletes'][] = array( $key, $group );
	}
}

namespace Bitsnap\WooCommerce\Tests\Unit {
	use Bitsnap\WooCommerce\Support\Lock;
	use PHPUnit\Framework\TestCase;

	final class LockDatabase {
		public $options = 'wp_options';
		public $rows_affected = 0;
		public $results = array();
		public $queries = array();
		public $old;
		public $deleted;
		public function prepare( $sql, ...$args ) {
			$this->queries[] = array( $sql, $args );
			return $sql;
		}
		public function query( $sql ) {
			$this->rows_affected = array_shift( $this->results );
			return $this->rows_affected;
		}
		public function get_var( $sql ) { return $this->old; }
		public function delete( $table, $where ) { $this->deleted = $where; }
	}

	final class LockTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['wpdb'] = new LockDatabase();
			$GLOBALS['lock_cache_deletes'] = array();
		}
		public function test_insert_uses_unique_index_and_release_matches_owner(): void {
			$db = $GLOBALS['wpdb'];
			$db->results = array( 1 );
			$lock = new Lock( 'unit-insert' );
			$this->assertStringContainsString( 'INSERT IGNORE INTO wp_options', $db->queries[0][0] );
			$this->assertTrue( Lock::owned( 'unit-insert' ) );
			$token = $db->queries[0][1][1];
			$lock->release();
			$this->assertSame( $token, $db->deleted['option_value'] );
			$this->assertSame( $db->queries[0][1][0], $db->deleted['option_name'] );
			$this->assertFalse( Lock::owned( 'unit-insert' ) );
			$this->assertContains( array( 'notoptions', 'options' ), $GLOBALS['lock_cache_deletes'] );
			$this->assertContains( array( 'alloptions', 'options' ), $GLOBALS['lock_cache_deletes'] );
		}
		public function test_expired_takeover_compares_the_exact_old_value(): void {
			$db = $GLOBALS['wpdb'];
			$db->results = array( 0, 1 );
			$db->old = '1:old-owner';
			$lock = new Lock( 'unit-takeover' );
			$this->assertStringContainsString( 'WHERE option_name = %s AND option_value = %s', $db->queries[2][0] );
			$this->assertSame( '1:old-owner', $db->queries[2][1][2] );
			$this->assertSame( $db->queries[0][1][1], $db->queries[2][1][0] );
			$lock->release();
		}
		/** @dataProvider rejected_leases */
		public function test_lost_insert_or_takeover_never_claims_ownership( $old, $results ): void {
			$db = $GLOBALS['wpdb'];
			$db->old = $old;
			$db->results = $results;
			try {
				new Lock( 'unit-rejected' );
				$this->fail( 'A losing contender acquired the lock.' );
			} catch ( \RuntimeException $e ) {
				$this->assertFalse( Lock::owned( 'unit-rejected' ) );
			}
		}
		public static function rejected_leases(): array {
			return array( array( ( time() + 100 ) . ':active', array( 0 ) ), array( '1:expired', array( 0, 0 ) ), array( null, array( 0 ) ), array( null, array( false ) ) );
		}
	}
}
