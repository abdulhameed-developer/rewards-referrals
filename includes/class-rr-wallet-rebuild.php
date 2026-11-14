<?php
/** Resumable counter reconstruction. Source ledger and provenance are never rewritten. */
defined( 'ABSPATH' ) || exit;
final class RR_Wallet_Rebuild {
	private const FIELDS = array( 'balance','pending','reserved','spent','earned','referral_earned' );
	public static function init() {
		add_action( 'rr_wallet_rebuild', array( __CLASS__, 'work' ) );
		add_action( 'rr_wallet_rebuild_poll', array( __CLASS__, 'poll' ) );
		if ( ! wp_next_scheduled( 'rr_wallet_rebuild_poll' ) ) { wp_schedule_event( time() + 120, 'hourly', 'rr_wallet_rebuild_poll' ); }
		add_action( 'admin_post_rr_wallet_rebuild', array( __CLASS__, 'request' ) );
	}
	public static function poll() {
		global $wpdb; $table = RR_Database::table( 'wallet_rebuilds' );
		$ids = $wpdb->get_col( "SELECT customer_id FROM $table WHERE status='building' ORDER BY updated_at,customer_id LIMIT 10" );
		foreach ( $ids as $id ) { RR_Background::schedule( 'rr_wallet_rebuild', 5, array( (int) $id ) ); }
	}
	public static function busy( $customer ) {
		global $wpdb; $table = RR_Database::table( 'wallet_rebuilds' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM %i WHERE customer_id=%d FOR UPDATE", $table, $customer ) );
		return $wpdb->last_error || ( $row && 'complete' !== $row->status ) || RR_Recovery::held( $customer ) || RR_Legacy_Lot_Migration::busy( $customer );
	}
	public static function pending() { return new WP_Error( 'rr_wallet_rebuild_pending', __( 'This wallet is temporarily locked while maintenance or pending order reversals finish. No new points were committed. Please retry after recovery completes.', 'rewards-referrals' ) ); }
	private static function start_locked( $customer ) {
		global $wpdb; $ledger = RR_Database::table( 'ledger' ); $table = RR_Database::table( 'wallet_rebuilds' );
		$upper = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i FORCE INDEX (customer_cursor) WHERE customer_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE", $ledger, $customer ) );
		if ( $wpdb->last_error ) { return false; }
		$row = array( 'customer_id' => $customer, 'scan_after' => 0, 'upper_id' => (int) $upper, 'status' => 'building', 'generation' => wp_generate_uuid4(), 'updated_at' => current_time( 'mysql', true ), 'error_code' => '' );
		foreach ( self::FIELDS as $field ) { $row[ $field ] = 0; }
		return false !== $wpdb->replace( $table, $row );
	}
	public static function work( $customer ) {
		$customer = RR_Ledger::validated_integer( $customer, 1, PHP_INT_MAX ); if ( ! $customer || ! get_userdata( $customer ) ) { return false; }
		global $wpdb; $table = RR_Database::table( 'wallet_rebuilds' ); $ledger = RR_Database::table( 'ledger' );
		$start = RR_Ledger::begin_wallet_transaction( $customer, false ); if ( is_wp_error( $start ) ) { RR_Background::schedule( 'rr_wallet_rebuild', 60, array( $customer ) ); return false; }
		$again = false;
		try {
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id=%d FOR UPDATE", $table, $customer ), ARRAY_A );
			if ( $wpdb->last_error ) { RR_Ledger::finish_wallet_transaction( false ); return false; }
			if ( ! $job ) {
				if ( RR_Wallet_Balances::locked( $customer, true ) ) { RR_Ledger::finish_wallet_transaction( true ); return true; }
				if ( ! self::start_locked( $customer ) ) { RR_Ledger::finish_wallet_transaction( false ); return false; }
				$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id=%d FOR UPDATE", $table, $customer ), ARRAY_A );
			}
			if ( 'complete' === $job['status'] && ! RR_Wallet_Balances::locked( $customer, true ) ) {
				if ( ! self::start_locked( $customer ) ) { RR_Ledger::finish_wallet_transaction( false ); return false; }
				$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id=%d FOR UPDATE", $table, $customer ), ARRAY_A );
			}
			if ( 'building' !== $job['status'] ) { RR_Ledger::finish_wallet_transaction( true ); return 'complete' === $job['status']; }
			$checkpoint_after = (int) $job['scan_after'];
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,amount,state,transaction_type FROM %i FORCE INDEX (customer_cursor) WHERE customer_id=%d AND id>%d AND id<=%d ORDER BY id LIMIT 250 FOR UPDATE", $ledger, $customer, $job['scan_after'], $job['upper_id'] ), ARRAY_A );
			if ( $wpdb->last_error ) { RR_Ledger::finish_wallet_transaction( false ); $again = true; return false; }
			foreach ( $rows as $row ) {
				$delta = RR_Wallet_Balances::contribution( $row );
				foreach ( self::FIELDS as $field ) {
					$old = (int) $job[ $field ]; $n = $delta[ $field ];
					if ( ( $n > 0 && $old > PHP_INT_MAX - $n ) || ( $n < 0 && $old < -PHP_INT_MAX - $n ) ) { throw new RuntimeException( 'counter_range' ); }
					$job[ $field ] = $old + $n;
				}
				$job['scan_after'] = (int) $row['id'];
			}
			$job['updated_at'] = current_time( 'mysql', true );
			if ( count( $rows ) < 250 ) {
				$latest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i FORCE INDEX (customer_cursor) WHERE customer_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE", $ledger, $customer ) );
				if ( $wpdb->last_error || $latest !== (int) $job['upper_id'] ) { throw new RuntimeException( 'source_changed' ); }
				$projection = array( 'customer_id' => $customer, 'updated_at' => $job['updated_at'] ); foreach ( self::FIELDS as $field ) { $projection[ $field ] = $job[ $field ]; }
				if ( false === $wpdb->replace( RR_Database::table( 'wallet_balances' ), $projection ) ) { throw new RuntimeException( 'projection_write' ); }
				$job['status'] = 'complete';
			}
			if ( false === $wpdb->replace( $table, $job ) ) { throw new RuntimeException( 'checkpoint_write' ); }
			$done = RR_Ledger::finish_wallet_transaction( true ); $again = is_wp_error( $done ) || 'building' === $job['status'];
			return ! is_wp_error( $done );
		} catch ( Throwable $error ) {
			RR_Ledger::finish_wallet_transaction( false );
			// Preserve old counters and committed checkpoints; explicit restart is required after a hard validation failure.
			$locked = RR_Ledger::begin_wallet_transaction( $customer, false );
			if ( ! is_wp_error( $locked ) ) {
				$code = in_array( $error->getMessage(), array( 'counter_range','source_changed','projection_write','checkpoint_write' ), true ) ? $error->getMessage() : 'rebuild_failed';
				$marked = $wpdb->update( $table, array( 'status' => 'failed', 'error_code' => $code, 'updated_at' => current_time( 'mysql', true ) ), array( 'customer_id' => $customer, 'status' => 'building', 'scan_after' => $checkpoint_after ?? 0, 'upper_id' => $job['upper_id'] ?? 0, 'generation' => $job['generation'] ?? '' ) );
				RR_Ledger::finish_wallet_transaction( false !== $marked );
			}
			return false;
		} finally { if ( $again ) { RR_Background::schedule( 'rr_wallet_rebuild', 5, array( $customer ) ); } }
	}
	public static function restart( $customer ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! RR_Ledger::validated_integer( $customer, 1, PHP_INT_MAX ) || ! get_userdata( $customer ) ) { return new WP_Error( 'rr_rebuild_permission', __( 'An authorized manager and existing customer are required.', 'rewards-referrals' ) ); }
		$start = RR_Ledger::begin_wallet_transaction( $customer, false ); if ( is_wp_error( $start ) ) { return $start; }
		$created = self::start_locked( $customer );
		if ( $created ) { RR_Admin::audit( 'wallet_rebuild_requested', $customer ); }
		global $wpdb; $valid = $created && ! $wpdb->last_error; $done = RR_Ledger::finish_wallet_transaction( $valid );
		if ( ! $valid || is_wp_error( $done ) ) { return new WP_Error( 'rr_rebuild_start', __( 'The reconstruction checkpoint could not be safely created.', 'rewards-referrals' ) ); }
		RR_Background::schedule( 'rr_wallet_rebuild', 5, array( $customer ) ); return true;
	}
	public static function request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_wallet_rebuild' );
		$customer = isset( $_POST['customer_id'] ) && is_scalar( $_POST['customer_id'] ) ? RR_Ledger::validated_integer( wp_unslash( $_POST['customer_id'] ), 1, PHP_INT_MAX ) : null;
		if ( ! $customer || ! get_userdata( $customer ) || ! isset( $_POST['maintenance_ack'] ) || 'yes' !== $_POST['maintenance_ack'] ) { wp_die( esc_html__( 'Choose an existing customer and confirm maintenance.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); }
		$result = self::restart( $customer );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 503 ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=rewards-referrals-insights&tab=tools&rr_view=wallet&customer=' . $customer ) ); exit;
	}
	public static function render( $customer ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $customer ) { return; }
		global $wpdb; $table = RR_Database::table( 'wallet_rebuilds' ); $job = $wpdb->get_row( $wpdb->prepare( "SELECT status,scan_after,upper_id,error_code FROM %i WHERE customer_id=%d", $table, $customer ), ARRAY_A );
		if ( $job ) {
			/* translators: 1: rebuild status, 2: scanned ledger ID, 3: final ledger ID, 4: diagnostic code. */
			$message = sprintf( __( 'Balance index: %1$s; source cursor %2$s of %3$s. Diagnostic code: %4$s', 'rewards-referrals' ), $job['status'], $job['scan_after'], $job['upper_id'], $job['error_code'] );
			echo '<p role="status">' . esc_html( $message ) . '</p>';
		}
		echo '<h4>' . esc_html__( 'Rebuild the derived balance index', 'rewards-referrals' ) . '</h4><p>' . esc_html__( 'This never edits ledger amounts or provenance lots. This wallet is locked for reward changes during reconstruction. Drain old-version workers and pause external reward writers first. A source/provenance mismatch needs investigation, not an invented credit.', 'rewards-referrals' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rr_wallet_rebuild"><input type="hidden" name="customer_id" value="' . esc_attr( $customer ) . '">'; wp_nonce_field( 'rr_wallet_rebuild' );
		echo '<label><input type="checkbox" name="maintenance_ack" value="yes" required> ' . esc_html__( 'I have paused old-version and external reward writers for this wallet.', 'rewards-referrals' ) . '</label> <button class="rr-button rr-button-light">' . esc_html__( 'Start / restart bounded rebuild', 'rewards-referrals' ) . '</button></form>';
	}
}
