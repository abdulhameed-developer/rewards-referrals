<?php
/** Bounded workers own idempotency; scheduling is a wake-up, not the source of truth. */
defined( 'ABSPATH' ) || exit;
final class RR_Background {
	private static $deferred = array();
	private static $shutdown_registered = false;
	private static function after_transaction( $hook, $delay, $args ) {
		self::$deferred[ md5( serialize( array( $hook, $args ) ) ) ] = array( $hook, $delay, $args );
		if ( ! self::$shutdown_registered ) { self::$shutdown_registered = true; add_action( 'shutdown', static function () { self::flush(); }, 100 ); }
	}
	/** Wakeups are advisory. Never write scheduler rows inside a caller-owned transaction. */
	public static function flush( $external = false ) {
		if ( ! self::$deferred || $external || RR_Ledger::transaction_open() ) { return; }
		if ( self::connection_transaction_open() ) { return; }
		$pending = self::$deferred; self::$deferred = array();
		foreach ( $pending as $item ) { self::schedule( $item[0], $item[1], $item[2] ); }
	}

	private static function connection_transaction_open() {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true ); $error = $wpdb->last_error;
		$probe = $wpdb->query( 'SAVEPOINT rr_background_probe' );
		$released = false !== $probe ? $wpdb->query( 'RELEASE SAVEPOINT rr_background_probe' ) : false;
		$wpdb->suppress_errors( $previous ); $wpdb->last_error = $error;
		return false === $probe || false !== $released;
	}
	public static function init() { add_action( 'rr_background_dispatch', array( __CLASS__, 'dispatch' ), 10, 2 ); }
	public static function dispatch( $hook, $args ) {
		if ( in_array( $hook, array( 'rr_commission_index','rr_customer_index','rr_outbox_worker','rr_retry_order_rewards','rr_wallet_rebuild','rr_wallet_rebuild_poll','rr_retry_refund_rewards','rr_retry_cancel_rewards','rr_recovery_worker','rr_legacy_lot_migration','rr_legacy_lot_poll', 'rr_referral_index', 'rr_expire_customer_lots', 'rr_mature_customer_points', 'rr_retry_confirm_rewards', 'rr_retry_release_rewards' ), true ) && is_array( $args ) ) { do_action_ref_array( $hook, $args ); }
	}
	public static function schedule( $hook, $delay = 5, $args = array() ) {
		if ( RR_Ledger::transaction_open() ) { self::after_transaction( $hook, $delay, $args ); return; }
		if ( ! did_action( 'init' ) ) { add_action( 'init', static function () use ( $hook, $delay, $args ) { self::schedule( $hook, $delay, $args ); }, 20 ); return; }
		if ( self::connection_transaction_open() ) { self::after_transaction( $hook, $delay, $args ); return; }
		if ( apply_filters( 'rr_background_use_action_scheduler', true ) && function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_schedule_single_action' ) ) {
			$pending = as_get_scheduled_actions( array( 'hook' => $hook, 'args' => $args, 'status' => 'pending', 'per_page' => 1 ), 'ids' );
			if ( ! $pending ) { as_schedule_single_action( time() + $delay, $hook, $args, 'rewards-referrals', false ); }
		} else { $dispatch = array( $hook, $args ); if ( ! wp_next_scheduled( 'rr_background_dispatch', $dispatch ) ) { wp_schedule_single_event( time() + $delay, 'rr_background_dispatch', $dispatch ); } }
	}
}
