<?php
/** Bounded, frozen-wallet conversion of ledger-only legacy balances to provenance lots. */
defined( 'ABSPATH' ) || exit;
final class RR_Legacy_Lot_Migration {
	public static function init() {
		add_action( 'rr_legacy_lot_migration', array( __CLASS__, 'work' ) );
		add_action( 'rr_legacy_lot_poll', array( __CLASS__, 'poll' ) );
		add_action( 'admin_post_rr_legacy_lot_resume', array( __CLASS__, 'request' ) );
		if ( ! wp_next_scheduled( 'rr_legacy_lot_poll' ) ) { wp_schedule_event( time() + 120, 'hourly', 'rr_legacy_lot_poll' ); }
	}
	public static function pending() { return new WP_Error( 'rr_legacy_lot_maintenance', __( 'Historical points are being safely converted in batches. This wallet is temporarily locked; no new points were committed. Please retry after maintenance finishes.', 'rewards-referrals' ) ); }
	public static function busy( $customer ) {
		global $wpdb; $table = RR_Database::table( 'legacy_lot_migrations' );
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE customer_id=%d FOR UPDATE", $table, $customer ) );
		return $wpdb->last_error || ( $status && 'complete' !== $status );
	}
	public static function enqueue( $customer, $delay = 5 ) { RR_Background::schedule( 'rr_legacy_lot_migration', $delay, array( (int) $customer ) ); }
	public static function poll() {
		global $wpdb; $table = RR_Database::table( 'legacy_lot_migrations' );
		$ids = $wpdb->get_col( "SELECT customer_id FROM $table WHERE status='building' ORDER BY updated_at,customer_id LIMIT 10" );
		foreach ( (array) $ids as $id ) { self::enqueue( $id ); }
		// Scan a bounded USER-ID page, not an unbounded joined ledger history. This discovers
		// dormant available-only legacy wallets even if their first wake-up was interrupted.
		$after = absint( get_option( 'rr_legacy_after_customer', 0 ) );
		$users = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE ID>%d ORDER BY ID LIMIT 100", $wpdb->users, $after ) );
		if ( ! $users && $after ) { update_option( 'rr_legacy_after_customer', 0, false ); return; }
		if ( ! $users || $wpdb->last_error ) { return; }
		$wallets = RR_Database::table( 'point_wallets' ); $ledger = RR_Database::table( 'ledger' );
		$marks = implode( ',', array_fill( 0, count( $users ), '%d' ) );
		$initialized = $wpdb->get_col( $wpdb->prepare( "SELECT customer_id FROM $wallets WHERE customer_id IN ($marks)", array_map( 'intval', $users ) ) );
		if ( $wpdb->last_error ) { return; }
		foreach ( array_diff( array_map( 'intval', $users ), array_map( 'intval', $initialized ) ) as $id ) {
			$source = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i FORCE INDEX(customer_cursor) WHERE customer_id=%d ORDER BY id LIMIT 1", $ledger, $id ) );
			if ( $wpdb->last_error ) { return; }
			if ( $source ) { self::enqueue( $id ); }
		}
		update_option( 'rr_legacy_after_customer', (int) end( $users ), false );
		if ( count( $users ) === 100 ) { RR_Background::schedule( 'rr_legacy_lot_poll', 5 ); }
	}
	/** All source and destination reads/writes here hold RR_Ledger's per-wallet lock. */
	public static function work( $customer ) {
		$customer = RR_Ledger::validated_integer( $customer, 1, PHP_INT_MAX );
		if ( ! $customer ) { return false; }
		global $wpdb;
		$table = RR_Database::table( 'legacy_lot_migrations' ); $ledger = RR_Database::table( 'ledger' );
		$present = $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID=%d', $wpdb->users, $customer ) );
		if ( $wpdb->last_error ) { self::enqueue( $customer, 60 ); return false; }
		if ( ! $present ) {
			// Terminal operational metadata only: re-check absence in the same conditional write.
			$marked = $wpdb->query( $wpdb->prepare( "UPDATE %i j LEFT JOIN %i u ON u.ID=j.customer_id SET j.status='failed',j.error_code='customer_missing',j.updated_at=%s WHERE j.customer_id=%d AND j.status='building' AND u.ID IS NULL", $table, $wpdb->users, current_time( 'mysql', true ), $customer ) );
			if ( false === $marked ) { self::enqueue( $customer, 60 ); }
			return false;
		}
		$started = RR_Ledger::begin_wallet_transaction( $customer, false );
		if ( is_wp_error( $started ) ) {
			// Keep blocked ownership held, but rotate its checkpoint behind healthy work.
			$queued = $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM %i WHERE customer_id=%d AND status='building'", $table, $customer ) );
			if ( $wpdb->last_error || $queued ) {
				$wpdb->update( $table, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'customer_id' => $customer, 'status' => 'building' ) );
				self::enqueue( $customer, 60 );
			}
			return false;
		}
		$wallets = RR_Database::table( 'point_wallets' ); $lots = RR_Database::table( 'point_lots' ); $allocations = RR_Database::table( 'point_allocations' );
		$again = false; $hard_error = ''; $job = null;
		try {
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id=%d FOR UPDATE", $table, $customer ), ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'checkpoint_read' ); }
			if ( ! get_userdata( $customer ) ) {
				$marked = $job && 'building' === $job['status'] ? $wpdb->update( $table, array( 'status' => 'failed', 'error_code' => 'customer_missing', 'updated_at' => current_time( 'mysql', true ) ), array( 'customer_id' => $customer, 'generation' => $job['generation'], 'status' => 'building' ) ) : 0;
				RR_Ledger::finish_wallet_transaction( false !== $marked ); return false;
			}
			if ( ! $job ) {
				$initialized = $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM %i WHERE customer_id=%d FOR UPDATE", $wallets, $customer ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'wallet_read' ); }
				if ( $initialized ) { RR_Ledger::finish_wallet_transaction( true ); return true; }
				$rebuilds = RR_Database::table( 'wallet_rebuilds' );
				$rebuilding = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE customer_id=%d AND status<>'complete' FOR UPDATE", $rebuilds, $customer ) );
				if ( $rebuilding || $wpdb->last_error ) { RR_Ledger::finish_wallet_transaction( false ); self::enqueue( $customer, 60 ); return false; }
				$counter = RR_Wallet_Balances::locked( $customer, true );
				if ( ! $counter ) { RR_Ledger::finish_wallet_transaction( false ); RR_Background::schedule( 'rr_wallet_rebuild', 5, array( $customer ) ); self::enqueue( $customer, 60 ); return false; }
				$orphaned_lot = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE customer_id=%d LIMIT 1 FOR UPDATE", $lots, $customer ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'provenance_read' ); }
				$orphaned_allocation = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE customer_id=%d LIMIT 1 FOR UPDATE", $allocations, $customer ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'provenance_read' ); }
				if ( $orphaned_lot || $orphaned_allocation ) { $hard_error = 'orphaned_provenance'; throw new RuntimeException( $hard_error ); }
				$upper = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i FORCE INDEX(customer_cursor) WHERE customer_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE", $ledger, $customer ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'source_read' ); }
				$now = current_time( 'mysql', true );
				if ( 1 !== $wpdb->insert( $wallets, array( 'customer_id' => $customer, 'opening_cursor' => (int) $upper, 'debt' => max( 0, -(int) $counter['balance'] ), 'created_at' => $now ) ) ) { throw new RuntimeException( 'wallet_write' ); }
				if ( 1 !== $wpdb->insert( $lots, array( 'customer_id' => $customer, 'source_transaction_id' => 0, 'remaining' => max( 0, (int) $counter['balance'] ), 'status' => 'available', 'created_at' => $now ) ) ) { throw new RuntimeException( 'opening_write' ); }
				$job = array( 'customer_id' => $customer, 'upper_id' => (int) $upper, 'scan_after' => 0, 'opening_lot_id' => (int) $wpdb->insert_id, 'balance' => (int) $counter['balance'], 'source_balance' => 0, 'pending' => 0, 'reserved' => 0, 'status' => 'building', 'generation' => wp_generate_uuid4(), 'error_code' => '', 'updated_at' => $now );
				if ( 1 !== $wpdb->insert( $table, $job ) ) { throw new RuntimeException( 'checkpoint_write' ); }
			}
			if ( 'building' !== $job['status'] ) { RR_Ledger::finish_wallet_transaction( true ); return 'complete' === $job['status']; }
			$checkpoint = (int) $job['scan_after'];
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,amount,state,transaction_type,available_at,created_at FROM %i FORCE INDEX(customer_cursor) WHERE customer_id=%d AND id>%d AND id<=%d ORDER BY id LIMIT 200 FOR UPDATE", $ledger, $customer, $checkpoint, $job['upper_id'] ), ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'source_read' ); }
			foreach ( $rows as $row ) {
				if ( in_array( $row['state'], array( 'available','reserved','spent','adjusted','expired' ), true ) ) { $job['source_balance'] = self::sum( (int) $job['source_balance'], (int) $row['amount'] ); }
				if ( 'reserved' === $row['state'] && 'redemption_reservation' === $row['transaction_type'] && (int) $row['amount'] < 0 ) {
					$held = abs( (int) $row['amount'] );
					if ( 1 !== $wpdb->insert( $allocations, array( 'customer_id' => $customer, 'reservation_transaction_id' => (int) $row['id'], 'lot_id' => (int) $job['opening_lot_id'], 'amount' => $held, 'returned' => 0, 'status' => 'held' ) ) ) { throw new RuntimeException( 'allocation_write' ); }
					$job['reserved'] = self::sum( (int) $job['reserved'], $held );
				}
				if ( 'pending' === $row['state'] && (int) $row['amount'] > 0 ) {
					$reversals = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE customer_id=%d AND reference_id=%d AND transaction_type='refund_reversal' AND state='pending' AND id<=%d FOR UPDATE", $ledger, $customer, $row['id'], $job['upper_id'] ) );
					if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $reversals, -PHP_INT_MAX, 0 ) ) { throw new RuntimeException( 'reversal_read' ); }
					$net = self::sum( (int) $row['amount'], (int) $reversals );
					if ( $net < 0 ) { $hard_error = 'invalid_pending_source'; throw new RuntimeException( $hard_error ); }
					if ( 1 !== $wpdb->insert( $lots, array( 'customer_id' => $customer, 'source_transaction_id' => (int) $row['id'], 'remaining' => $net, 'status' => 'pending', 'available_at' => $row['available_at'], 'created_at' => $row['created_at'] ) ) ) { throw new RuntimeException( 'pending_write' ); }
					$job['pending'] = self::sum( (int) $job['pending'], $net );
				}
				$job['scan_after'] = (int) $row['id'];
			}
			if ( count( $rows ) < 200 ) {
				$latest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i FORCE INDEX(customer_cursor) WHERE customer_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE", $ledger, $customer ) );
				$counter = RR_Wallet_Balances::locked( $customer, true );
				if ( $wpdb->last_error || $latest !== (int) $job['upper_id'] || ! $counter || (int) $job['source_balance'] !== (int) $job['balance'] || (int) $counter['balance'] !== (int) $job['balance'] || (int) $counter['pending'] !== (int) $job['pending'] || (int) $counter['reserved'] !== (int) $job['reserved'] ) { $hard_error = 'source_mismatch'; throw new RuntimeException( $hard_error ); }
				$job['status'] = 'complete';
			}
			$job['updated_at'] = current_time( 'mysql', true ); $job['error_code'] = '';
			if ( false === $wpdb->replace( $table, $job ) ) { throw new RuntimeException( 'checkpoint_write' ); }
			$finished = RR_Ledger::finish_wallet_transaction( true );
			$again = is_wp_error( $finished ) || 'building' === $job['status'];
			return ! is_wp_error( $finished );
		} catch ( Throwable $error ) {
			RR_Ledger::finish_wallet_transaction( false );
			if ( 'range' === $error->getMessage() ) { $hard_error = 'counter_range'; }
			$locked = RR_Ledger::begin_wallet_transaction( $customer, false );
			if ( ! is_wp_error( $locked ) ) {
				// Conditional failure marker cannot overwrite another worker's completed checkpoint.
				if ( $job && ! empty( $job['generation'] ) ) {
					$code = $hard_error ?: 'batch_retry';
					$marked = $wpdb->update( $table, array( 'status' => $hard_error ? 'failed' : 'building', 'error_code' => $code, 'updated_at' => current_time( 'mysql', true ) ), array( 'customer_id' => $customer, 'generation' => $job['generation'], 'scan_after' => $checkpoint ?? 0, 'status' => 'building' ) );
					if ( 0 === $marked && $hard_error && ! $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM %i WHERE customer_id=%d FOR UPDATE", $table, $customer ) ) ) { $marked = self::failed_stub( $customer, $code ); }
					RR_Ledger::finish_wallet_transaction( false !== $marked );
				} elseif ( $hard_error ) { $marked = self::failed_stub( $customer, $hard_error ); RR_Ledger::finish_wallet_transaction( false !== $marked ); }
				else { RR_Ledger::finish_wallet_transaction( true ); }
			}
			$again = ! $hard_error;
			return false;
		} finally { if ( $again ) { self::enqueue( $customer, 60 ); } }
	}
	private static function failed_stub( $customer, $code ) {
		global $wpdb;
		return $wpdb->insert( RR_Database::table( 'legacy_lot_migrations' ), array( 'customer_id' => $customer, 'upper_id' => 0, 'scan_after' => 0, 'opening_lot_id' => 0, 'balance' => 0, 'source_balance' => 0, 'pending' => 0, 'reserved' => 0, 'status' => 'failed', 'generation' => wp_generate_uuid4(), 'error_code' => $code, 'updated_at' => current_time( 'mysql', true ) ) );
	}
	private static function sum( $a, $b ) { if ( ( $b > 0 && $a > PHP_INT_MAX - $b ) || ( $b < 0 && $a < -PHP_INT_MAX - $b ) ) { throw new RuntimeException( 'range' ); } return $a + $b; }

	/** Restart only provenance created by an INCOMPLETE migration, after an operator pauses old writers. */
	public static function restart( $customer ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! RR_Ledger::validated_integer( $customer, 1, PHP_INT_MAX ) || ! get_userdata( $customer ) ) { return new WP_Error( 'rr_legacy_permission', __( 'An authorized manager and existing customer are required.', 'rewards-referrals' ) ); }
		$start = RR_Ledger::begin_wallet_transaction( $customer, false ); if ( is_wp_error( $start ) ) { return $start; }
		global $wpdb; $jobs = RR_Database::table( 'legacy_lot_migrations' ); $wallets = RR_Database::table( 'point_wallets' ); $lots = RR_Database::table( 'point_lots' );
		try {
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id=%d FOR UPDATE", $jobs, $customer ), ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'restart_read' ); }
			$wallet = $wpdb->get_row( $wpdb->prepare( "SELECT opening_cursor,debt FROM %i WHERE customer_id=%d FOR UPDATE", $wallets, $customer ), ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'restart_read' ); }
			// An initial batch can roll back before creating any provenance. Its failure
			// marker is recoverable only when no wallet, lot or allocation exists at all.
			if ( $job && 'failed' === $job['status'] && 'source_mismatch' === $job['error_code'] && ! $wallet && 0 === (int) $job['upper_id'] && 0 === (int) $job['scan_after'] && 0 === (int) $job['opening_lot_id'] ) {
				$allocations = RR_Database::table( 'point_allocations' );
				$lot_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE customer_id=%d LIMIT 1 FOR UPDATE", $lots, $customer ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'restart_read' ); }
				$allocation_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE customer_id=%d LIMIT 1 FOR UPDATE", $allocations, $customer ) );
				if ( $wpdb->last_error || $lot_exists || $allocation_exists ) { throw new RuntimeException( 'restart_ownership' ); }
				if ( 1 !== $wpdb->delete( $jobs, array( 'customer_id' => $customer, 'generation' => $job['generation'], 'status' => 'failed', 'error_code' => 'source_mismatch' ) ) ) { throw new RuntimeException( 'restart_write' ); }
				$rebuild = RR_Wallet_Rebuild::restart( $customer ); if ( is_wp_error( $rebuild ) ) { throw new RuntimeException( 'counter_restart' ); }
				RR_Admin::audit( 'legacy_lot_restart', $customer, array( 'generation' => $job['generation'], 'reason' => 'initial_source_mismatch' ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'audit_write' ); }
				$done = RR_Ledger::finish_wallet_transaction( true ); if ( is_wp_error( $done ) ) { return $done; }
				self::enqueue( $customer, 60 ); return true;
			}
			$opening = $job ? $wpdb->get_row( $wpdb->prepare( "SELECT customer_id,source_transaction_id,remaining,status,expires_at,expiry_count FROM %i WHERE id=%d FOR UPDATE", $lots, $job['opening_lot_id'] ), ARRAY_A ) : null;
			if ( $wpdb->last_error || ! $job || 'failed' !== $job['status'] || ! $wallet || (int) $wallet['opening_cursor'] !== (int) $job['upper_id'] || ! $opening || (int) $opening['customer_id'] !== (int) $customer || 0 !== (int) $opening['source_transaction_id'] ) { RR_Ledger::finish_wallet_transaction( false ); return new WP_Error( 'rr_legacy_restart_source', __( 'Only a failed, incomplete migration with verified ownership can be restarted. Existing provenance needs investigation.', 'rewards-referrals' ) ); }

			// A job marker alone cannot prove ownership if old/external writers touched the partial lots.
			if ( (int) $wallet['debt'] !== max( 0, -(int) $job['balance'] ) || (int) $opening['remaining'] !== max( 0, (int) $job['balance'] ) || 'available' !== $opening['status'] || $opening['expires_at'] || (int) $opening['expiry_count'] ) { RR_Ledger::finish_wallet_transaction( false ); return new WP_Error( 'rr_legacy_external_provenance', __( 'The incomplete opening lot changed outside migration. Preserve it and reconcile the source before recovery.', 'rewards-referrals' ) ); }
			$source = RR_Database::table( 'ledger' ); $allocations = RR_Database::table( 'point_allocations' );
			$unexpected_lot = $wpdb->get_var( $wpdb->prepare( "SELECT p.id FROM $lots p LEFT JOIN $source s ON s.id=p.source_transaction_id AND s.customer_id=p.customer_id WHERE p.customer_id=%d AND p.id<>%d AND (p.source_transaction_id=0 OR p.source_transaction_id>%d OR s.id IS NULL OR s.amount<=0 OR p.status<>'pending' OR p.expires_at IS NOT NULL OR p.expiry_count<>0 OR p.remaining<>s.amount+COALESCE((SELECT SUM(r.amount) FROM $source r WHERE r.customer_id=p.customer_id AND r.reference_id=s.id AND r.transaction_type='refund_reversal' AND r.state='pending' AND r.id<=%d),0)) LIMIT 1 FOR UPDATE", $customer, $job['opening_lot_id'], $job['scan_after'], $job['upper_id'] ) );
			$lot_read_failed = (bool) $wpdb->last_error;
			$unexpected_allocation = $wpdb->get_var( $wpdb->prepare( "SELECT a.id FROM %i a LEFT JOIN %i s ON s.id=a.reservation_transaction_id AND s.customer_id=a.customer_id WHERE a.customer_id=%d AND (a.lot_id<>%d OR a.reservation_transaction_id>%d OR s.id IS NULL OR s.amount>=0 OR s.transaction_type<>'redemption_reservation' OR a.amount<>-s.amount OR a.returned<>0 OR a.status<>'held') LIMIT 1 FOR UPDATE", $allocations, $source, $customer, $job['opening_lot_id'], $job['scan_after'] ) );
			if ( $unexpected_lot || $unexpected_allocation || $lot_read_failed || $wpdb->last_error ) { RR_Ledger::finish_wallet_transaction( false ); return new WP_Error( 'rr_legacy_external_provenance', __( 'Some incomplete provenance does not belong to the migration checkpoint. Preserve it and investigate before restarting.', 'rewards-referrals' ) ); }
			foreach ( array( 'point_allocations','point_lots','point_wallets','wallet_balances','legacy_lot_migrations' ) as $suffix ) { if ( false === $wpdb->delete( RR_Database::table( $suffix ), array( 'customer_id' => $customer ) ) ) { throw new RuntimeException( 'restart_write' ); } }
			$rebuild = RR_Wallet_Rebuild::restart( $customer ); if ( is_wp_error( $rebuild ) ) { throw new RuntimeException( 'counter_restart' ); }
			RR_Admin::audit( 'legacy_lot_restart', $customer, array( 'generation' => $job['generation'], 'reason' => $job['error_code'] ) );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'audit_write' ); }
			$done = RR_Ledger::finish_wallet_transaction( true ); if ( is_wp_error( $done ) ) { return $done; }
			self::enqueue( $customer, 60 ); return true;
		} catch ( Throwable $error ) { RR_Ledger::finish_wallet_transaction( false ); return new WP_Error( 'rr_legacy_restart_write', __( 'The historical conversion restart could not be committed. The existing hold remains in place.', 'rewards-referrals' ) ); }
	}
	public static function request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_legacy_lot_resume' );
		$customer = isset( $_POST['customer_id'] ) && is_scalar( $_POST['customer_id'] ) ? RR_Ledger::validated_integer( wp_unslash( $_POST['customer_id'] ), 1, PHP_INT_MAX ) : null;
		if ( ! $customer || ! get_userdata( $customer ) ) { wp_die( esc_html__( 'Choose an existing customer.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); }
		if ( isset( $_POST['restart'] ) && 'yes' === $_POST['restart'] ) {
			if ( ! isset( $_POST['maintenance_ack'] ) || 'yes' !== $_POST['maintenance_ack'] ) { wp_die( esc_html__( 'Pause old-version and external writers and confirm maintenance first.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); }
			$result = self::restart( $customer ); if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 503 ) ); }
		}
		self::enqueue( $customer ); wp_safe_redirect( admin_url( 'admin.php?page=rewards-referrals-insights&tab=tools&rr_view=wallet&customer=' . $customer ) ); exit;
	}
	public static function render( $customer ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $customer ) { return; }
		global $wpdb; $table = RR_Database::table( 'legacy_lot_migrations' );
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT status,scan_after,upper_id,error_code FROM %i WHERE customer_id=%d", $table, $customer ), ARRAY_A );
		if ( $job ) {
			/* translators: 1: migration status, 2: scanned ledger ID, 3: final ledger ID, 4: diagnostic code. */
			$message = sprintf( __( 'Historical lots: %1$s; source cursor %2$s of %3$s. Diagnostic code: %4$s', 'rewards-referrals' ), $job['status'], $job['scan_after'], $job['upper_id'], $job['error_code'] );
			echo '<p role="status">' . esc_html( $message ) . '</p>';
		}
		echo '<h4>' . esc_html__( 'Convert historical point lots', 'rewards-referrals' ) . '</h4><p>' . esc_html__( 'Existing spendable history becomes a non-expiring opening lot. Pending credits and active reservations retain their source links. Batches do not edit ledger amounts. Keep old-version and external writers paused. A source mismatch stays locked for investigation: restore a matching backup or repair and reconcile the source before continuing. Resuming a job never discards its hold.', 'rewards-referrals' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rr_legacy_lot_resume"><input type="hidden" name="customer_id" value="' . esc_attr( $customer ) . '">'; wp_nonce_field( 'rr_legacy_lot_resume' );
		if ( $job && 'failed' === $job['status'] ) { echo '<input type="hidden" name="restart" value="yes"><label><input type="checkbox" name="maintenance_ack" value="yes" required> ' . esc_html__( 'I paused old-version/external writers and reviewed the source mismatch. Restart only the incomplete conversion from the current ledger.', 'rewards-referrals' ) . '</label>'; }
		echo '<button class="rr-button rr-button-light">' . esc_html__( 'Schedule / resume historical conversion', 'rewards-referrals' ) . '</button></form>';
	}
}
