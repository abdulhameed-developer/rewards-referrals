<?php
/** Durable accounting holds bridge maintenance and deferred order callbacks. */
defined( 'ABSPATH' ) || exit;
final class RR_Recovery {
	private static $scopes = array();
	public static function init() {
		add_action( 'rr_recovery_worker', array( __CLASS__, 'work' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'review_failed_order' ), 10 );
		if ( ! wp_next_scheduled( 'rr_recovery_worker' ) ) { wp_schedule_event( time() + 120, 'hourly', 'rr_recovery_worker' ); }
	}
	public static function run( $customers, $callback, $ignore_events = null ) {
		self::$scopes[] = array( 'customers' => array_map( 'absint', (array) $customers ), 'events' => $ignore_events );
		try { return $callback(); } finally { array_pop( self::$scopes ); }
	}
	public static function key( $kind, $order, $refund = 0 ) { return $kind . ':' . absint( $order ) . ':' . absint( $refund ); }
	public static function held( $customer ) {
		// Recovery may bypass only the wallets of its own order, never unrelated holds.
		$scope = self::$scopes ? end( self::$scopes ) : array();
		$ignore = array();
		if ( in_array( absint( $customer ), $scope['customers'] ?? array(), true ) ) {
			if ( null === $scope['events'] ) { return false; }
			$ignore = array_slice( array_filter( (array) $scope['events'], 'is_string' ), 0, 10 );
		}
		global $wpdb; $table = RR_Database::table( 'recovery_events' );
		foreach ( array( 'customer_a','customer_b' ) as $column ) {
			$args = array( $customer ); $except = '';
			if ( $ignore ) { $except = ' AND event_key NOT IN (' . implode( ',', array_fill( 0, count( $ignore ), '%s' ) ) . ')'; $args = array_merge( $args, $ignore ); }
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT event_key FROM $table WHERE $column=%d AND status='queued'$except LIMIT 1 FOR UPDATE", $args ) );
			if ( $id || $wpdb->last_error ) { return true; }
		}
		return false;
	}
	public static function record( $kind, $order, $refund, $customers ) {
		if ( ! in_array( $kind, array( 'refund','cancel','review','confirm','release' ), true ) ) { return new WP_Error( 'rr_recovery_kind' ); }
		$customers = array_values( array_unique( array_filter( array_map( 'absint', $customers ) ) ) ); if ( ! $customers ) { return new WP_Error( 'rr_recovery_customer' ); } sort( $customers, SORT_NUMERIC );
		$start = RR_Ledger::begin_wallet_transaction( $customers, false ); if ( is_wp_error( $start ) ) { return $start; }
		global $wpdb; $table = RR_Database::table( 'recovery_events' ); $key = self::key( $kind, $order, $refund ); $now = current_time( 'mysql', true );
		if ( 'review' === $kind ) {
			$current = wc_get_order( $order ); if ( $current ) { $current->get_data_store()->read( $current ); }
			if ( ! $current || ! $current->has_status( 'failed' ) ) { RR_Ledger::finish_wallet_transaction( true ); return true; }
		}
		$r = $wpdb->query( $wpdb->prepare( "INSERT INTO %i(event_key,kind,order_id,refund_id,customer_a,customer_b,status,auto_due,created_at,updated_at) VALUES(%s,%s,%d,%d,%d,%d,'queued',%d,%s,%s) ON DUPLICATE KEY UPDATE status=IF(kind='review','queued',status)", $table, $key, $kind, $order, $refund, $customers[0], $customers[1] ?? $customers[0], 'review' === $kind ? 0 : 1, $now, $now ) );
		$done = RR_Ledger::finish_wallet_transaction( false !== $r );
		if ( false === $r || is_wp_error( $done ) ) { return new WP_Error( 'rr_recovery_record' ); }
		RR_Background::schedule( 'rr_recovery_worker', 60 ); return true;
	}
	/** Called in the SAME transaction as the successful reversal; holds disappear only with its commit. */
	public static function complete( $kind, $order, $refund = 0 ) {
		$alive = RR_Ledger::verify_wallet_transaction();
		if ( is_wp_error( $alive ) ) { throw new RuntimeException( $alive->get_error_code() ); }
		global $wpdb; $table = RR_Database::table( 'recovery_events' );
		if ( false === $wpdb->update( $table, array( 'status' => 'complete','updated_at' => current_time( 'mysql', true ) ), array( 'event_key' => self::key( $kind, $order, $refund ) ) ) ) { throw new RuntimeException( 'Recovery checkpoint failed.' ); }
	}
	public static function buyer( $order ) {
		global $wpdb;
		$credit = RR_Ledger::positive_order_transaction( $order->get_id(), 'purchase_points' );
		if ( $wpdb->last_error ) { return 0; }
		if ( $credit ) { return (int) $credit->customer_id; }
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT customer_id FROM %i WHERE order_id=%d LIMIT 1', RR_Database::table( 'redemptions' ), $order->get_id() ) );
		if ( $wpdb->last_error ) { return 0; }
		return $owner ? (int) $owner : (int) $order->get_customer_id();
	}
	private static function owners( $order ) {
		global $wpdb;
		$buyer = self::buyer( $order ); if ( ! $buyer ) { return array(); }
		$ids = array( $buyer ); $commission = RR_Ledger::positive_order_transaction( $order->get_id(), 'referral_commission' );
		if ( $wpdb->last_error ) { return array(); }
		if ( $commission ) { $ids[] = (int) $commission->customer_id; } return $ids;
	}
	public static function review_failed_order( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order || ! $order->has_status( 'failed' ) || ! self::buyer( $order ) ) { return; }
		$active = false;
		global $wpdb;
		foreach ( array( 'purchase_points','referral_commission' ) as $type ) { $credit = RR_Ledger::positive_order_transaction( $order_id, $type ); if ( $wpdb->last_error ) { return; } if ( $credit ) { $reversed = RR_Ledger::reversed_amount( (int) $credit->id ); if ( $wpdb->last_error ) { return; } if ( $reversed < (int) $credit->amount ) { $active = true; } } }
		if ( ! $active ) { return; }
		$r = self::record( 'review', $order_id, 0, self::owners( $order ) );
		$order->add_order_note( is_wp_error( $r ) ? __( 'Payment failed after points were awarded; the wallet review hold could not be recorded. Review rewards immediately.', 'rewards-referrals' ) : __( 'Payment failed after points were awarded. Buyer/referrer wallets are held for review. Confirm payment again or use cancellation / a full WooCommerce refund to resolve this hold; no duplicate earning will be created.', 'rewards-referrals' ) );
	}
	public static function paid( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order || ! self::buyer( $order ) ) { return; }
		global $wpdb; $table = RR_Database::table( 'recovery_events' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT event_key FROM %i WHERE event_key=%s AND status='queued'", $table, self::key( 'review', $order_id ) ) ) ) { return; }
		$start = RR_Ledger::begin_wallet_transaction( self::owners( $order ), false ); if ( is_wp_error( $start ) ) { return; }
		try {
			$order->get_data_store()->read( $order );
			if ( ! $order->has_status( array( 'processing','completed' ) ) ) { RR_Ledger::finish_wallet_transaction( false ); return; }
			self::complete( 'review', $order_id ); RR_Admin::audit( 'payment_review_resolved', $order_id ); RR_Ledger::finish_wallet_transaction( ! $wpdb->last_error ); }
		catch ( Throwable $e ) { RR_Ledger::finish_wallet_transaction( false ); }
	}
	public static function finish_reversal_review( $order_id, $cancelled = false ) {
		global $wpdb;
		if ( ! $cancelled ) { foreach ( array( 'purchase_points','referral_commission' ) as $type ) { $credit = RR_Ledger::positive_order_transaction( $order_id, $type ); if ( $wpdb->last_error ) { throw new RuntimeException( 'Review source could not be read.' ); } if ( $credit ) { $reversed = RR_Ledger::reversed_amount( (int) $credit->id ); if ( $wpdb->last_error ) { throw new RuntimeException( 'Review reversal total could not be read.' ); } if ( $reversed < (int) $credit->amount ) { return; } } } }
		self::complete( 'review', $order_id );
	}
	public static function work() {
		global $wpdb; $table = RR_Database::table( 'recovery_events' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_key,kind,order_id,refund_id FROM %i WHERE status='queued' AND auto_due=1 AND updated_at<=%s ORDER BY updated_at,event_key LIMIT 10", $table, current_time( 'mysql', true ) ) );
		foreach ( (array) $rows as $row ) {
			// Lease even missing/deleted source records so they cannot monopolize the queue.
			$leased = $wpdb->query( $wpdb->prepare( "UPDATE %i SET updated_at=%s WHERE event_key=%s AND status='queued' AND auto_due=1 AND updated_at<=%s", $table, gmdate( 'Y-m-d H:i:s', time() + 60 ), $row->event_key, current_time( 'mysql', true ) ) );
			if ( 1 !== $leased ) { continue; }
			if ( 'refund' === $row->kind ) { RR_WooCommerce::reverse_refund( (int) $row->order_id, (int) $row->refund_id ); }
			elseif ( 'confirm' === $row->kind ) { RR_WooCommerce::retry_order_confirmation( (int) $row->order_id ); }
			elseif ( 'release' === $row->kind ) { RR_WooCommerce::release_order_redemption( (int) $row->order_id ); }
			else { RR_WooCommerce::cancel_order_rewards( (int) $row->order_id ); }
		}
		// Completed retry records are not accounting source; bound operational retention without deleting orders/ledger.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status='complete' AND updated_at<%s LIMIT 200", $table, gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		if ( $wpdb->get_var( "SELECT event_key FROM $table WHERE status='queued' AND auto_due=1 LIMIT 1" ) ) { RR_Background::schedule( 'rr_recovery_worker', 60 ); }
		return count( (array) $rows );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		global $wpdb; $table = RR_Database::table( 'recovery_events' );
		$rows = $wpdb->get_results( "SELECT order_id,refund_id,kind,updated_at FROM $table WHERE status='queued' ORDER BY auto_due,updated_at,event_key LIMIT 20" );
		if ( ! $rows ) { return; }
		echo '<section class="rr-card rr-setting-card"><h3>' . esc_html__( 'Pending accounting recovery', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Affected wallets remain locked while refunds, cancellations, paid confirmations or failed-payment releases recover. Payment review holds require confirmed payment, cancellation or a full refund. Missing orders/refunds require investigation; rebuilding a balance does not discard holds.', 'rewards-referrals' ) . '</p><ul>';
		foreach ( $rows as $row ) { echo '<li>' . esc_html( $row->kind . ' · #' . $row->order_id . ' / #' . $row->refund_id . ' · ' . $row->updated_at . ' UTC' ) . '</li>'; }
		echo '</ul></section>';
	}
}
