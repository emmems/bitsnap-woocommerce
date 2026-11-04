<?php
/**
 * Scheduled check of orders whose webhook never arrived.
 *
 * @package Bitsnap\WooCommerce
 */

namespace Bitsnap\WooCommerce\Woo;

use Bitsnap\WooCommerce\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every 15 minutes an Action Scheduler job looks at pending Bitsnap orders older than 15
 * minutes, at most 20 per run, and asks Bitsnap for their state. It stops after 48 hours.
 */
final class Reconciler {

	public const HOOK = 'bitsnap_wc_reconcile';

	public const GROUP = 'bitsnap-for-woocommerce';

	/**
	 * Orders checked per run.
	 */
	public const BATCH = 20;

	/**
	 * Candidate orders read per run before the meta check.
	 */
	public const SCAN = 100;

	/**
	 * Wait before the first check, in seconds.
	 */
	public const MIN_AGE = 900;

	/**
	 * Age after which the job gives up, in seconds.
	 */
	public const MAX_AGE = 172800;

	/**
	 * Order sync.
	 *
	 * @var OrderSync
	 */
	private $sync;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param OrderSync $sync   Order sync.
	 * @param Logger    $logger Logger.
	 */
	public function __construct( OrderSync $sync, Logger $logger ) {
		$this->sync   = $sync;
		$this->logger = $logger;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'schedule' ), 20 );
	}

	/**
	 * Schedules the recurring action once.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, self::MIN_AGE, self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Removes the schedule (on deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Job body.
	 *
	 * The query uses only arguments both order stores (HPOS and posts) support. Meta is checked in PHP,
	 * because `meta_query` is not available with the legacy posts storage.
	 *
	 * @return void
	 */
	public function run(): void {
		$now = time();

		$page   = max( 1, (int) get_option( 'bitsnap_reconcile_page', 1 ) );
		$orders = wc_get_orders(
			array(
				'status'         => array( 'pending' ),
				'payment_method' => Gateway::IDS,
				'limit'          => self::BATCH,
				'page'           => $page,
				'orderby'        => 'date ID',
				'order'          => 'DESC',
				'date_created'   => ( $now - self::MAX_AGE ) . '...' . ( $now - self::MIN_AGE ),
			)
		);

		update_option( 'bitsnap_reconcile_page', count( $orders ) < self::BATCH ? 1 : $page + 1, false );
		$checked = 0;
		foreach ( $orders as $order ) {
			if ( $checked >= self::BATCH ) {
				break;
			}
			if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( Meta::ORDER_ID ) ) {
				continue;
			}
			if ( (int) $order->get_meta( Meta::RECONCILED_AT ) > $now - ( self::MIN_AGE - 60 ) ) {
				continue;
			}
			++$checked;
			$order->update_meta_data( Meta::RECONCILED_AT, $now );
			$order->save();
			$this->sync->sync( $order, 'reconcile' );
		}

		$this->give_up( $now );
	}

	/**
	 * Marks orders pending for more than 48 hours so they are not checked again.
	 *
	 * Newest first, so an order that just crossed 48 hours is always among the first rows.
	 *
	 * @param int $now Current Unix time.
	 * @return void
	 */
	private function give_up( int $now ): void {
		$old  = wc_get_orders(
			array(
				'status'         => array( 'pending' ),
				'payment_method' => Gateway::IDS,
				'limit'          => self::SCAN,
				'orderby'        => 'date ID',
				'order'          => 'DESC',
				'date_created'   => '<' . ( $now - self::MAX_AGE ),
			)
		);
		$done = 0;
		foreach ( $old as $order ) {
			if ( $done >= self::BATCH ) {
				break;
			}
			if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( Meta::ORDER_ID ) || '' !== (string) $order->get_meta( Meta::GAVE_UP ) ) {
				continue;
			}
			++$done;
			$order->update_meta_data( Meta::GAVE_UP, $now );
			$order->add_order_note( __( 'Bitsnap: this order is still unpaid after 48 hours. The plugin stopped checking it automatically.', 'bitsnap-for-woocommerce' ) );
			$order->save();
			$this->logger->info( 'Gave up on order ' . $order->get_id() . ' after 48 hours.' );
		}
	}
}
