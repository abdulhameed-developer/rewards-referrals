<?php
/** Repairable ledger read model. Writes must share the ledger's customer transaction. */
defined( 'ABSPATH' ) || exit;
final class RR_Wallet_Balances {
	private const FIELDS = array( 'balance', 'pending', 'reserved', 'spent', 'earned', 'referral_earned' );
	private static function error() { return new WP_Error( 'rr_wallet_projection', __( 'The wallet balance index could not be safely updated. No points were committed.', 'rewards-referrals' ) ); }

	/** Called only after acquiring RR_Ledger's customer lock. One initial aggregate per wallet. */
	public static function ensure_locked( $customer ) {
		global $wpdb;
		$table = RR_Database::table( 'wallet_balances' );
		if ( RR_Wallet_Rebuild::busy( $customer ) ) { return RR_Wallet_Rebuild::pending(); }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT customer_id FROM %i WHERE customer_id=%d FOR UPDATE", $table, $customer ) );
		if ( $wpdb->last_error ) { return self::error(); }
		if ( $row ) { return true; }
		$ledger = RR_Database::table( 'ledger' );
		$probe = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i FORCE INDEX (customer_cursor) WHERE customer_id=%d ORDER BY id LIMIT 129 FOR UPDATE", $ledger, $customer ) );
		if ( $wpdb->last_error ) { return self::error(); }
		if ( count( $probe ) > 128 ) { return RR_Wallet_Rebuild::pending(); }
		return self::rebuild_locked( $customer );
	}

	public static function rebuild_locked( $customer ) {
		global $wpdb;
		$ledger = RR_Database::table( 'ledger' ); $table = RR_Database::table( 'wallet_balances' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN state IN ('available','reserved','spent','adjusted','expired') THEN amount ELSE 0 END),0) AS balance, COALESCE(SUM(CASE WHEN state='pending' THEN amount ELSE 0 END),0) AS pending, COALESCE(SUM(CASE WHEN state='reserved' AND transaction_type='redemption_reservation' THEN -amount ELSE 0 END),0) AS reserved, COALESCE(SUM(CASE WHEN transaction_type IN ('redemption_debit','redemption_restore') THEN -amount ELSE 0 END),0) AS spent, COALESCE(SUM(CASE WHEN amount>0 AND state<>'reversed' AND transaction_type IN ('purchase_points','referral_commission','promotional_bonus') THEN amount ELSE 0 END),0) AS earned, COALESCE(SUM(CASE WHEN amount>0 AND state<>'reversed' AND transaction_type='referral_commission' THEN amount ELSE 0 END),0) AS referral_earned FROM %i WHERE customer_id=%d FOR UPDATE", $ledger, $customer ), ARRAY_A );
		if ( ! $row || $wpdb->last_error ) { return self::error(); }
		foreach ( self::FIELDS as $field ) { if ( null === RR_Ledger::validated_integer( (string) $row[ $field ], -PHP_INT_MAX, PHP_INT_MAX ) ) { return self::error(); } }
		$row['customer_id'] = $customer;
		$row['updated_at'] = current_time( 'mysql', true );
		return false === $wpdb->replace( $table, $row ) ? self::error() : true;
	}

	public static function contribution( $entry ) {
		$entry = (array) $entry; $amount = (int) $entry['amount']; $type = $entry['transaction_type']; $state = $entry['state'];
		return array(
			'balance' => in_array( $state, array( 'available','reserved','spent','adjusted','expired' ), true ) ? $amount : 0,
			'pending' => 'pending' === $state ? $amount : 0,
			'reserved' => 'reserved' === $state && 'redemption_reservation' === $type ? -$amount : 0,
			'spent' => in_array( $type, array( 'redemption_debit','redemption_restore' ), true ) ? -$amount : 0,
			'earned' => $amount > 0 && 'reversed' !== $state && in_array( $type, array( 'purchase_points','referral_commission','promotional_bonus' ), true ) ? $amount : 0,
			'referral_earned' => $amount > 0 && 'reversed' !== $state && 'referral_commission' === $type ? $amount : 0,
		);
	}
	public static function apply_locked( $entry ) { return self::delta_locked( $entry['customer_id'], self::contribution( $entry ) ); }
	public static function delta_locked( $customer, $delta ) {
		global $wpdb;
		$values = array();
		foreach ( self::FIELDS as $field ) { $values[] = (int) ( $delta[ $field ] ?? 0 ); }
		if ( ! array_filter( $values ) ) { return true; }
		$table = RR_Database::table( 'wallet_balances' );
		// Every identifier is fixed here; values keep the same signed deltas as the source transition.
		return 1 === $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET balance=balance+%d,pending=pending+%d,reserved=reserved+%d,spent=spent+%d,earned=earned+%d,referral_earned=referral_earned+%d,updated_at=%s WHERE customer_id=%d',
			$table, $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], current_time( 'mysql', true ), $customer
		) ) ? true : self::error();
	}
	public static function state_changed_locked( $entry, $state ) {
		$before = self::contribution( $entry ); $entry = (array) $entry; $entry['state'] = $state; $after = self::contribution( $entry );
		foreach ( self::FIELDS as $field ) { $after[ $field ] -= $before[ $field ]; }
		return self::delta_locked( $entry['customer_id'], $after );
	}
	public static function rows( $ids ) {
		global $wpdb;
		if ( ! $ids ) { return array(); }
		$ids = array_slice( array_filter( array_map( 'absint', (array) $ids ) ), 0, 100 );
		if ( ! $ids ) { return array(); }
		$table = RR_Database::table( 'wallet_balances' ); $marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE customer_id IN ($marks)", array_values( $ids ) ), ARRAY_A );
	}
	public static function locked( $customer, $current_read = false ) {
		global $wpdb;
		$table = RR_Database::table( 'wallet_balances' );
		if ( $current_read ) { return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id=%d FOR UPDATE', $table, $customer ), ARRAY_A ); }
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id=%d', $table, $customer ), ARRAY_A );
	}
}
