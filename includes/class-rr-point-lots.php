<?php
defined( 'ABSPATH' ) || exit;

/** FIFO provenance for unspent and held credits. All writes run under RR_Ledger's customer lock. */
final class RR_Point_Lots {
	private static $expiring = array();
	private static $refund_offsets = array();

	private static function tables() { return array( RR_Database::table( 'point_wallets' ), RR_Database::table( 'point_lots' ), RR_Database::table( 'point_allocations' ) ); }
	private static function error( $code = 'rr_lot_write' ) { return new WP_Error( $code, __( 'Point expiry accounting could not be safely updated. Please retry or contact the store.', 'rewards-referrals' ) ); }

	/** Retained orphan lots never become another account's funds when an importer reuses a deleted ID. */
	private static function proven_source_sql( $lots ) {
		$ledger = RR_Database::table( 'ledger' );
		// Source zero is the original opening marker. Other lots need their own positive credit in the matching phase.
		return "($lots.source_transaction_id=0 OR EXISTS(SELECT 1 FROM $ledger rr_lot_source WHERE rr_lot_source.id=$lots.source_transaction_id AND rr_lot_source.customer_id=$lots.customer_id AND rr_lot_source.amount>0 AND (($lots.status='pending' AND rr_lot_source.state='pending') OR ($lots.status IN ('available','expired') AND rr_lot_source.state IN ('available','adjusted')))))";
	}

	public static function prepare( $customer_id ) {
		$result = self::initialize( $customer_id );
		return is_wp_error( $result ) ? $result : self::expire_locked( $customer_id );
	}

	/** Existing spendable history is an opening non-expiring lot; past credits are never retroactively expired. */
	private static function initialize( $customer_id ) {
		global $wpdb;
		list( $wallets, $lots, $allocations ) = self::tables();
		if ( RR_Legacy_Lot_Migration::busy( $customer_id ) ) { RR_Legacy_Lot_Migration::enqueue( $customer_id ); return RR_Legacy_Lot_Migration::pending(); }
		$initialized = $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM %i WHERE customer_id=%d FOR UPDATE", $wallets, $customer_id ) );
		if ( $wpdb->last_error ) { return self::error(); }
		if ( $initialized ) { return true; }
		$ledger = RR_Database::table( 'ledger' );
		$probe = $wpdb->get_results( $wpdb->prepare( "SELECT id,amount,state,transaction_type FROM %i FORCE INDEX(customer_cursor) WHERE customer_id=%d ORDER BY id LIMIT 201 FOR UPDATE", $ledger, $customer_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error(); }
		if ( count( $probe ) > 200 ) { RR_Legacy_Lot_Migration::enqueue( $customer_id ); return RR_Legacy_Lot_Migration::pending(); }
		$projection = RR_Wallet_Balances::locked( $customer_id, true );
		if ( ! $projection ) { return self::error( 'rr_wallet_rebuild_pending' ); }
		// A pre-upgrade counter may be stale. Validate the bounded source page before
		// publishing opening provenance, using the same contribution rules as reconstruction.
		$source_totals = array( 'balance' => 0, 'pending' => 0, 'reserved' => 0 );
		foreach ( $probe as $row ) {
			if ( null === RR_Ledger::validated_integer( (string) $row['amount'], -PHP_INT_MAX, PHP_INT_MAX ) ) { return self::error(); }
			$contribution = RR_Wallet_Balances::contribution( $row );
			foreach ( $source_totals as $field => $total ) {
				$delta = $contribution[ $field ];
				if ( ( $delta > 0 && $total > PHP_INT_MAX - $delta ) || ( $delta < 0 && $total < -PHP_INT_MAX - $delta ) ) { return self::error(); }
				$source_totals[ $field ] += $delta;
			}
		}
		foreach ( $source_totals as $field => $total ) {
			if ( $total !== (int) $projection[ $field ] ) { return new WP_Error( 'rr_legacy_lot_maintenance', __( 'Historical points do not match the derived balance index. Pause old reward writers and rebuild this customer’s balance index in Tools & diagnostics before retrying.', 'rewards-referrals' ) ); }
		}
		$balance = (int) $projection['balance'];
		$cursor = $probe ? (int) end( $probe )['id'] : 0;
		$now = current_time( 'mysql', true );
		if ( 1 !== $wpdb->insert( $wallets, array( 'customer_id' => $customer_id, 'opening_cursor' => $cursor, 'debt' => max( 0, -$balance ), 'created_at' => $now ) ) ) { return self::error(); }
		if ( 1 !== $wpdb->insert( $lots, array( 'customer_id' => $customer_id, 'source_transaction_id' => 0, 'remaining' => max( 0, $balance ), 'status' => 'available', 'created_at' => $now ) ) ) { return self::error(); }
		$opening_lot = (int) $wpdb->insert_id;
		$held = $wpdb->get_results( $wpdb->prepare( "SELECT id,amount FROM %i WHERE customer_id=%d AND transaction_type='redemption_reservation' AND state='reserved' AND amount<0 ORDER BY id LIMIT 201 FOR UPDATE", $ledger, $customer_id ) );
		if ( count( $held ) > 200 || $wpdb->last_error ) { return self::error( 'rr_legacy_lot_maintenance' ); }
		$held_total = 0;
		foreach ( $held as $reservation ) {
			$held_total += abs( (int) $reservation->amount );
			if ( 1 !== $wpdb->insert( $allocations, array( 'customer_id' => $customer_id, 'reservation_transaction_id' => (int) $reservation->id, 'lot_id' => $opening_lot, 'amount' => abs( (int) $reservation->amount ), 'returned' => 0, 'status' => 'held' ) ) ) { return self::error(); }
		}
		$pending = $wpdb->get_results( $wpdb->prepare( "SELECT c.id,c.amount,c.available_at,c.created_at,COALESCE((SELECT SUM(r.amount) FROM %i r WHERE r.reference_id=c.id AND r.customer_id=c.customer_id AND r.transaction_type='refund_reversal' AND r.state='pending'),0) AS reversals FROM %i c WHERE c.customer_id=%d AND c.state='pending' AND c.amount>0 ORDER BY c.id LIMIT 201 FOR UPDATE", $ledger, $ledger, $customer_id ) );
		if ( count( $pending ) > 200 || $wpdb->last_error ) { return self::error( 'rr_legacy_lot_maintenance' ); }
		$pending_total = 0;
		foreach ( $pending as $credit ) {
			if ( null === RR_Ledger::validated_integer( (string) $credit->reversals, -PHP_INT_MAX, 0 ) ) { return self::error(); }
			$net = (int) $credit->amount + (int) $credit->reversals;
			if ( $net < 0 || $pending_total > PHP_INT_MAX - $net ) { return self::error(); }
			$pending_total += $net;
			if ( 1 !== $wpdb->insert( $lots, array( 'customer_id' => $customer_id, 'source_transaction_id' => $credit->id, 'remaining' => $net, 'status' => 'pending', 'available_at' => $credit->available_at, 'created_at' => $credit->created_at ) ) ) { return self::error(); }
		}
		if ( $pending_total !== (int) $projection['pending'] || $held_total !== (int) $projection['reserved'] ) { return self::error(); }
		return true;
	}

	private static function debt( $customer_id, $credit ) {
		global $wpdb;
		list( $wallets ) = self::tables();
		$debt = $wpdb->get_var( $wpdb->prepare( "SELECT debt FROM %i WHERE customer_id=%d FOR UPDATE", $wallets, $customer_id ) );
		if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $debt, 0, PHP_INT_MAX ) ) { return self::error(); }
		$debt = (int) $debt;
		$offset = min( $debt, $credit );
		if ( $offset && false === $wpdb->update( $wallets, array( 'debt' => $debt - $offset ), array( 'customer_id' => $customer_id ) ) ) { return self::error(); }
		return $credit - $offset;
	}

	private static function increase_debt( $customer_id, $amount ) {
		global $wpdb;
		list( $wallets ) = self::tables();
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE %i SET debt=debt+%d WHERE customer_id=%d", $wallets, $amount, $customer_id ) ) ? true : self::error();
	}

	private static function put_back( $customer_id, $lot_id, $amount ) {
		global $wpdb;
		list( , $lots ) = self::tables();
		$proven = self::proven_source_sql( $lots );
		$lot = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id=%d AND customer_id=%d AND $proven FOR UPDATE", $lots, $lot_id, $customer_id ) );
		if ( $wpdb->last_error || ! $lot ) { return self::error( 'rr_lot_source_missing' ); }
		$amount = self::debt( $customer_id, $amount );
		if ( is_wp_error( $amount ) ) { return $amount; }
		return ! $amount || 1 === $wpdb->query( $wpdb->prepare( "UPDATE %i SET remaining=remaining+%d,status=IF(status='expired','available',status) WHERE id=%d AND customer_id=%d", $lots, $amount, $lot_id, $customer_id ) ) ? true : self::error();
	}

	private static function opening_return( $customer_id, $amount ) {
		global $wpdb;
		list( , $lots ) = self::tables();
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE customer_id=%d AND source_transaction_id=0", $lots, $customer_id ) );
		return $id ? self::put_back( $customer_id, $id, $amount ) : self::error();
	}

	/** Consume one source first for reversals, then available FIFO lots. Reservations retain allocations. */
	private static function consume( $customer_id, $amount, $reservation_id = 0, $source_id = 0, $allow_debt = false ) {
		global $wpdb;
		list( , $lots, $allocations ) = self::tables();
		$remaining = $amount;
		$proven = self::proven_source_sql( $lots );
		$source_first = $source_id ? $wpdb->prepare( 'CASE WHEN source_transaction_id=%d THEN 0 ELSE 1 END,', $source_id ) : '';
		while ( $remaining ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,remaining FROM $lots WHERE customer_id=%d AND status='available' AND remaining>0 AND $proven ORDER BY $source_first id LIMIT 100 FOR UPDATE", $customer_id ) );
			if ( $wpdb->last_error ) { return self::error(); }
			if ( ! $rows ) { break; }
			foreach ( $rows as $lot ) {
				$used = min( $remaining, (int) $lot->remaining );
				if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET remaining=remaining-%d WHERE id=%d AND remaining>=%d", $lots, $used, $lot->id, $used ) ) ) { return self::error(); }
				if ( $reservation_id && 1 !== $wpdb->insert( $allocations, array( 'customer_id' => $customer_id, 'reservation_transaction_id' => $reservation_id, 'lot_id' => $lot->id, 'amount' => $used, 'returned' => 0, 'status' => 'held' ) ) ) { return self::error(); }
				$remaining -= $used;
				if ( ! $remaining ) { break; }
			}
		}
		if ( $remaining && ! $allow_debt ) { return self::error( 'rr_lot_insufficient' ); }
		return $remaining ? self::increase_debt( $customer_id, $remaining ) : true;
	}

	private static function legacy_reservation( $customer_id, $reservation_id ) {
		global $wpdb;
		list( $wallets ) = self::tables();
		$cursor = (int) $wpdb->get_var( $wpdb->prepare( "SELECT opening_cursor FROM %i WHERE customer_id=%d", $wallets, $customer_id ) );
		return $reservation_id > 0 && $reservation_id <= $cursor;
	}

	private static function return_reservation( $customer_id, $reservation_id, $amount, $release ) {
		global $wpdb;
		list( , , $allocations ) = self::tables();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id=%d AND reservation_transaction_id=%d ORDER BY id FOR UPDATE", $allocations, $customer_id, $reservation_id ) );
		if ( $wpdb->last_error ) { return self::error(); }
		if ( ! $rows ) { return self::legacy_reservation( $customer_id, $reservation_id ) ? self::opening_return( $customer_id, $amount ) : self::error( 'rr_lot_allocation_missing' ); }
		$remaining = $amount;
		foreach ( $rows as $allocation ) {
			if ( ( $release && 'held' !== $allocation->status ) || ( ! $release && 'spent' !== $allocation->status ) ) { continue; }
			$return = min( $remaining, max( 0, (int) $allocation->amount - (int) $allocation->returned ) );
			if ( ! $return ) { continue; }
			$result = self::put_back( $customer_id, $allocation->lot_id, $return );
			if ( is_wp_error( $result ) ) { return $result; }
			$values = array( 'returned' => (int) $allocation->returned + $return );
			if ( $release ) { $values['status'] = 'released'; }
			if ( false === $wpdb->update( $allocations, $values, array( 'id' => $allocation->id ) ) ) { return self::error(); }
			$remaining -= $return;
			if ( ! $remaining ) { break; }
		}
		return $remaining ? self::error( 'rr_lot_return_limit' ) : true;
	}

	public static function apply( $transaction_id, $entry, $meta ) {
		global $wpdb;
		list( , $lots, $allocations ) = self::tables();
		$customer_id = (int) $entry['customer_id'];
		$amount = (int) $entry['amount'];
		$type = $entry['transaction_type'];
		$state = $entry['state'];
		$reference = (int) $entry['reference_id'];
		if ( 'expiry_refund_offset' === $type ) {
			$expected = self::$refund_offsets[ $customer_id ] ?? array();
			return ( $expected['amount'] ?? 0 ) === $amount && ( $expected['source'] ?? 0 ) === $reference && 'available' === $state ? true : self::error( 'rr_expiry_offset_source' );
		}
		if ( 'point_expiration' === $type ) {
			$proven = self::proven_source_sql( $lots );
			$lot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id=%d AND source_transaction_id=%d AND $proven FOR UPDATE", $lots, $customer_id, $reference ) );
			if ( ! $lot || 'available' !== $lot->status || ! $lot->expires_at || $lot->expires_at > current_time( 'mysql', true ) || (int) $lot->remaining < abs( $amount ) ) { return self::error( 'rr_lot_expiry_source' ); }
			return 1 === $wpdb->query( $wpdb->prepare( "UPDATE %i SET remaining=remaining-%d,expiry_count=expiry_count+1 WHERE id=%d", $lots, abs( $amount ), $lot->id ) ) ? true : self::error();
		}
		if ( 'reservation_release' === $type || 'redemption_restore' === $type ) { return self::return_reservation( $customer_id, $reference, $amount, 'reservation_release' === $type ); }
		if ( 'redemption_debit' === $type ) {
			$total = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE customer_id=%d AND reservation_transaction_id=%d AND status='held'", $allocations, $customer_id, $reference ) );
			if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $total, 0, PHP_INT_MAX ) ) { return self::error(); }
			$total = (int) $total;
			$proven = self::proven_source_sql( 'p' );
			$invalid = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i a LEFT JOIN %i p ON p.id=a.lot_id AND p.customer_id=a.customer_id WHERE a.customer_id=%d AND a.reservation_transaction_id=%d AND a.status='held' AND (p.id IS NULL OR NOT $proven)", $allocations, $lots, $customer_id, $reference ) );
			if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $invalid, 0, PHP_INT_MAX ) || (int) $invalid > 0 ) { return self::error( 'rr_lot_source_missing' ); }
			if ( $total === abs( $amount ) ) { return false !== $wpdb->update( $allocations, array( 'status' => 'spent' ), array( 'customer_id' => $customer_id, 'reservation_transaction_id' => $reference, 'status' => 'held' ) ) ? true : self::error(); }
			return self::legacy_reservation( $customer_id, $reference ) ? self::consume( $customer_id, abs( $amount ) ) : self::error( 'rr_lot_allocation_missing' );
		}
		if ( $amount > 0 && in_array( $state, array( 'available', 'adjusted', 'pending' ), true ) ) {
			$remaining = 'pending' === $state ? $amount : self::debt( $customer_id, $amount );
			if ( is_wp_error( $remaining ) ) { return $remaining; }
			$days = isset( $meta['expiration_days'] ) ? RR_Ledger::validated_integer( $meta['expiration_days'], 0, 36500 ) : 0;
			if ( null === $days ) { return self::error( 'rr_lot_expiry_policy' ); }
			$created = current_time( 'mysql', true );
			$available_at = $entry['available_at'] ?: $created;
			$expires = $days ? gmdate( 'Y-m-d H:i:s', strtotime( $available_at . ' UTC' ) + $days * DAY_IN_SECONDS ) : null;
			return 1 === $wpdb->insert( $lots, array( 'customer_id' => $customer_id, 'source_transaction_id' => $transaction_id, 'remaining' => $remaining, 'status' => 'pending' === $state ? 'pending' : 'available', 'available_at' => $entry['available_at'], 'expires_at' => $expires, 'created_at' => $created ) ) ? true : self::error();
		}
		if ( $amount < 0 && 'pending' === $state && 'refund_reversal' === $type ) {
			return 1 === $wpdb->query( $wpdb->prepare( "UPDATE %i SET remaining=remaining-%d WHERE customer_id=%d AND source_transaction_id=%d AND status='pending' AND remaining>=%d", $lots, abs( $amount ), $customer_id, $reference, abs( $amount ) ) ) ? true : self::error();
		}
		if ( $amount < 0 && in_array( $state, array( 'available', 'reserved', 'adjusted' ), true ) ) {
			$offset = 0;
			if ( 'refund_reversal' === $type ) {
				$ledger = RR_Database::table( 'ledger' );
				$expired = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE customer_id=%d AND reference_id=%d AND transaction_type='point_expiration'", $ledger, $customer_id, $reference ) );
				if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $expired, -PHP_INT_MAX, 0 ) ) { return self::error(); }
				$expired = abs( (int) $expired );
				$prior_offsets = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE customer_id=%d AND reference_id=%d AND transaction_type='expiry_refund_offset'", $ledger, $customer_id, $reference ) );
				if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $prior_offsets, 0, PHP_INT_MAX ) ) { return self::error(); }
				$prior_offsets = (int) $prior_offsets;
				$offset = min( abs( $amount ), max( 0, $expired - $prior_offsets ) );
			}
			$result = self::consume( $customer_id, abs( $amount ) - $offset, 'redemption_reservation' === $type ? $transaction_id : 0, 'refund_reversal' === $type ? $reference : 0, 'refund_reversal' === $type );
			if ( is_wp_error( $result ) || ! $offset ) { return $result; }
			self::$refund_offsets[ $customer_id ] = array( 'amount' => $offset, 'source' => $reference );
			try {
				$result = RR_Ledger::add( array( 'customer_id' => $customer_id, 'amount' => $offset, 'type' => 'expiry_refund_offset', 'state' => 'available', 'reference_id' => $reference, 'order_id' => (int) $entry['order_id'], 'key' => 'expiry-refund-offset:reversal:' . $transaction_id, 'meta' => array( 'refund_reversal_id' => $transaction_id, 'source_credit_id' => $reference, 'reason' => 'Offset reward points already removed by expiry before their refund reversal.' ) ) );
				return is_wp_error( $result ) ? $result : true;
			} finally { unset( self::$refund_offsets[ $customer_id ] ); }
		}
		return true;
	}

	/** A checkout can clear a small backlog; larger backlogs are drained by bounded workers. */
	public static function expire_locked( $customer_id, $background = false ) {
		if ( isset( self::$expiring[ $customer_id ] ) ) { return true; }
		global $wpdb; list( , $lots ) = self::tables();
		$proven = self::proven_source_sql( $lots );
		self::$expiring[ $customer_id ] = true;
		try {
			$budget = $background ? 100 : 25;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_transaction_id,remaining,expires_at,expiry_count FROM %i FORCE INDEX(customer_expiry) WHERE customer_id=%d AND status='available' AND expires_at IS NOT NULL AND expires_at<=%s AND (remaining>0 OR %d=1) AND $proven ORDER BY expires_at,id LIMIT %d FOR UPDATE", $lots, $customer_id, current_time( 'mysql', true ), $background ? 1 : 0, $budget + 1 ) );
			if ( $wpdb->last_error ) { return self::error(); }
			if ( ! $background && count( $rows ) > $budget ) { RR_Background::schedule( 'rr_expire_customer_lots', 5, array( (int) $customer_id ) ); return new WP_Error( 'rr_wallet_rebuild_pending', __( 'Due point expiry is being processed safely in batches. This wallet is temporarily locked; please retry shortly.', 'rewards-referrals' ) ); }
			foreach ( array_slice( $rows, 0, $budget ) as $lot ) {
				if ( (int) $lot->remaining > 0 ) {
					$result = RR_Ledger::add( array( 'customer_id' => $customer_id, 'amount' => -(int) $lot->remaining, 'type' => 'point_expiration', 'state' => 'expired', 'reference_id' => (int) $lot->source_transaction_id, 'key' => 'expiry:credit:' . $lot->source_transaction_id . ':generation:' . ( (int) $lot->expiry_count + 1 ), 'meta' => array( 'expires_at' => $lot->expires_at, 'source_credit_id' => (int) $lot->source_transaction_id ) ) );
					if ( is_wp_error( $result ) ) { return $result; }
				}
				// Empty due lots also leave the available-date index; returned allocations reactivate them.
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='expired' WHERE id=%d AND customer_id=%d AND remaining=0 AND status='available'", $lots, $lot->id, $customer_id ) ) ) { return self::error(); }
			}
			if ( count( $rows ) > $budget ) { RR_Background::schedule( 'rr_expire_customer_lots', 5, array( (int) $customer_id ) ); }
			return true;
		} finally { unset( self::$expiring[ $customer_id ] ); }
	}
	public static function expire_customer( $customer_id, $background = false ) {
		$started = RR_Ledger::begin_wallet_transaction( $customer_id ); if ( is_wp_error( $started ) ) { return $started; }
		$ready = self::initialize( $customer_id );
		$result = is_wp_error( $ready ) ? $ready : self::expire_locked( $customer_id, $background );
		$finished = RR_Ledger::finish_wallet_transaction( ! is_wp_error( $result ) );
		return is_wp_error( $result ) ? $result : $finished;
	}
	public static function expire_customer_batch( $customer_id ) {
		$customer_id = RR_Ledger::validated_integer( $customer_id, 1, PHP_INT_MAX ); if ( ! $customer_id || ! get_userdata( $customer_id ) ) { return false; }
		// Expiry must be able to drain a backlog which is blocking a deferred refund; no hold is removed.
		$result = RR_Recovery::run( array( $customer_id ), static function () use ( $customer_id ) { return self::expire_customer( $customer_id, true ); } );
		if ( is_wp_error( $result ) ) { RR_Background::schedule( 'rr_expire_customer_lots', 60, array( $customer_id ) ); }
		return $result;
	}
	/** Mature complete source families, so a credit and all pending reversals move together. */
	public static function mature_locked( $customer_id, $now ) {
		global $wpdb; list( , $lots ) = self::tables(); $ledger = RR_Database::table( 'ledger' );
		$result = self::initialize( $customer_id ); if ( is_wp_error( $result ) ) { return $result; }
		$proven = self::proven_source_sql( $lots );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_transaction_id,remaining FROM %i FORCE INDEX(customer_maturity) WHERE customer_id=%d AND status='pending' AND available_at IS NOT NULL AND available_at<=%s AND $proven ORDER BY available_at,id LIMIT 100 FOR UPDATE", $lots, $customer_id, $now ) );
		if ( $wpdb->last_error ) { return self::error(); }
		$net = 0; $changed = 0;
		foreach ( $rows as $lot ) {
			$credit = $wpdb->get_row( $wpdb->prepare( "SELECT amount,available_at FROM %i WHERE id=%d AND customer_id=%d AND state='pending' AND amount>0 FOR UPDATE", $ledger, $lot->source_transaction_id, $customer_id ) );
			if ( ! $credit || $wpdb->last_error || ! $credit->available_at || $credit->available_at > $now ) { return self::error( 'rr_maturity_source' ); }
			$refunds = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) total,COUNT(*) n FROM %i FORCE INDEX(reference_type) WHERE reference_id=%d AND customer_id=%d AND transaction_type='refund_reversal' AND state='pending' FOR UPDATE", $ledger, $lot->source_transaction_id, $customer_id ) );
			if ( ! $refunds || $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $refunds->total, -PHP_INT_MAX, 0 ) ) { return self::error( 'rr_maturity_source' ); }
			$family = (int) $credit->amount + (int) $refunds->total;
			if ( $family < 0 || $family !== (int) $lot->remaining || $net > PHP_INT_MAX - $family ) { return self::error( 'rr_maturity_source' ); }
			$remaining = self::debt( $customer_id, $family );
			if ( is_wp_error( $remaining ) || 1 !== $wpdb->update( $lots, array( 'status' => 'available', 'remaining' => $remaining ), array( 'id' => $lot->id, 'status' => 'pending' ) ) ) { return self::error(); }
			$positive = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state='available' WHERE id=%d AND customer_id=%d AND state='pending'", $ledger, $lot->source_transaction_id, $customer_id ) );
			$negative = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state='available' WHERE reference_id=%d AND customer_id=%d AND transaction_type='refund_reversal' AND state='pending'", $ledger, $lot->source_transaction_id, $customer_id ) );
			if ( 1 !== $positive || (int) $refunds->n !== $negative ) { return self::error( 'rr_maturity_write' ); }
			$net += $family; $changed += 1 + $negative;
		}
		$more = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i FORCE INDEX(customer_maturity) WHERE customer_id=%d AND status='pending' AND available_at IS NOT NULL AND available_at<=%s AND $proven ORDER BY available_at,id LIMIT 1", $lots, $customer_id, $now ) );
		if ( $wpdb->last_error ) { return self::error(); }
		return array( 'net' => $net, 'changed' => $changed, 'more' => (bool) $more );
	}
	public static function expire_batch() {
		global $wpdb; list( , $lots ) = self::tables();
		$proven = self::proven_source_sql( 'p' );
		$after = absint( get_option( 'rr_expiry_after_customer', 0 ) ); $now = current_time( 'mysql', true );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.customer_id FROM $lots p INNER JOIN {$wpdb->users} u ON u.ID=p.customer_id WHERE p.status='available' AND p.expires_at IS NOT NULL AND p.expires_at<=%s AND $proven AND p.customer_id>%d ORDER BY p.customer_id LIMIT 10", $now, $after ) );
		if ( ! $ids && $after ) { update_option( 'rr_expiry_after_customer', 0, false ); $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.customer_id FROM $lots p INNER JOIN {$wpdb->users} u ON u.ID=p.customer_id WHERE p.status='available' AND p.expires_at IS NOT NULL AND p.expires_at<=%s AND $proven ORDER BY p.customer_id LIMIT 10", $now ) ); }
		foreach ( (array) $ids as $id ) { self::expire_customer_batch( (int) $id ); }
		if ( $ids ) { update_option( 'rr_expiry_after_customer', (int) end( $ids ), false ); }
		return count( (array) $ids );
	}
	/** Reads never drain an entire wallet. Due wallets are unavailable until expiry workers commit. */
	public static function expire_due_for_customers( $ids ) {
		global $wpdb; list( , $lots ) = self::tables(); $pending = array();
		$proven = self::proven_source_sql( $lots );
		foreach ( array_slice( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ), 0, 100 ) as $id ) {
			$due = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i FORCE INDEX(customer_expiry) WHERE customer_id=%d AND status='available' AND expires_at IS NOT NULL AND expires_at<=%s AND remaining>0 AND $proven ORDER BY expires_at,id LIMIT 1", $lots, $id, current_time( 'mysql', true ) ) );
			if ( $due || $wpdb->last_error ) { $pending[] = $id; RR_Background::schedule( 'rr_expire_customer_lots', 5, array( $id ) ); }
		}
		return $pending;
	}

	public static function expiring_summary( $customer_id, $days = 30 ) {
		global $wpdb;
		list( , $lots ) = self::tables();
		$customer_id = absint( $customer_id );
		$proven = self::proven_source_sql( $lots );
		self::expire_due_for_customers( array( $customer_id ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(remaining),0) AS points,MIN(expires_at) AS next_expiry FROM %i WHERE customer_id=%d AND status='available' AND remaining>0 AND expires_at>%s AND expires_at<=%s AND $proven", $lots, $customer_id, current_time( 'mysql', true ), gmdate( 'Y-m-d H:i:s', time() + max( 1, min( 36500, absint( $days ) ) ) * DAY_IN_SECONDS ) ), ARRAY_A );
		return array( 'points' => (int) ( $row['points'] ?? 0 ), 'next_expiry' => $row['next_expiry'] ?? null );
	}
}
