<?php
/** Repairable source-credit facts and totals; all writes share the beneficiary wallet transaction. */
defined( 'ABSPATH' ) || exit;
final class RR_Commission_Index {
	public static function init() {
		add_action( 'rr_commission_index', array( __CLASS__, 'work' ) );
		add_action( 'rr_retry_order_rewards', array( 'RR_WooCommerce', 'award_order' ) );
		if ( ! wp_next_scheduled( 'rr_commission_index' ) ) { wp_schedule_event( time() + 60, 'hourly', 'rr_commission_index' ); }
		if ( ! self::ready() ) { RR_Background::schedule( 'rr_commission_index' ); }
	}
	public static function state() {
		global $wpdb;
		$state = get_option( 'rr_commission_index_state' );
		if ( ! is_array( $state ) ) {
			$ledger = RR_Database::table( 'ledger' );
			$upper = $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM $ledger" );
			if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $upper, 0, PHP_INT_MAX ) ) {
				// A failed read is not an empty history. Never persist a successful checkpoint.
				return array( 'after' => 0, 'upper' => 0, 'complete' => false, 'read_error' => true );
			}
			$upper = (int) $upper;
			$state = array( 'after' => 0, 'upper' => $upper, 'complete' => 0 === $upper );
			add_option( 'rr_commission_index_state', $state, '', false );
		}
		return $state;
	}
	public static function ready() { return ! empty( self::state()['complete'] ); }
	private static function error() { return new WP_Error( 'rr_commission_index', __( 'Referral history is being indexed or could not be verified. The award will need retrying; no partial points were committed.', 'rewards-referrals' ) ); }
	/** Source is re-read with a current read while holding its beneficiary lock. */
	public static function sync_locked( $credit_id ) {
		global $wpdb;
		$ledger = RR_Database::table( 'ledger' ); $facts = RR_Database::table( 'credit_facts' ); $totals = RR_Database::table( 'commission_totals' );
		$source = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id=%d FOR UPDATE", $ledger, $credit_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error(); }
		if ( ! $source || (int) $source['amount'] <= 0 || ! in_array( $source['transaction_type'], array( 'purchase_points','referral_commission' ), true ) ) { return true; }
		$refund = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE reference_id=%d AND transaction_type='refund_reversal' AND amount<0 FOR UPDATE", $ledger, $credit_id ) );
		if ( null === $refund || $wpdb->last_error ) { return self::error(); }
		$net = 'reversed' === $source['state'] ? 0 : max( 0, (int) $source['amount'] + (int) $refund );
		$meta = json_decode( (string) $source['meta'], true );
		$row = array( 'credit_id' => (int) $credit_id, 'customer_id' => (int) $source['customer_id'], 'kind' => $source['transaction_type'], 'relationship_id' => (int) $source['referral_id'], 'order_id' => (int) $source['order_id'], 'rule_version' => (int) $source['rule_version'], 'active' => $net > 0 ? 1 : 0, 'points' => $net, 'items' => $net ? max( 1, absint( $meta['qualifying_item_quantity'] ?? 1 ) ) : 0, 'volume' => $net ? RR_Rules::ratio( absint( $meta['eligible_basis_minor'] ?? 0 ), $net, max( 1, (int) $source['amount'] ), 'floor', 1000000000000000 ) : 0, 'created_at' => $source['created_at'] );
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE credit_id=%d FOR UPDATE", $facts, $credit_id ), ARRAY_A );
		if ( $wpdb->last_error ) { return self::error(); }
		if ( 'referral_commission' === $row['kind'] ) {
			$delta = array( 'orders' => $row['active'] - (int) ( $old['active'] ?? 0 ), 'items' => $row['items'] - (int) ( $old['items'] ?? 0 ), 'points' => $row['points'] - (int) ( $old['points'] ?? 0 ), 'volume' => $row['volume'] - (int) ( $old['volume'] ?? 0 ) );
			foreach ( array_unique( array( 0, $row['rule_version'] ) ) as $version ) {
				foreach ( array( 'all','order' ) as $scope ) {
					$order = 'all' === $scope ? 0 : $row['order_id'];
					if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO %i (relationship_id,rule_version,scope_kind,scope_order,orders,items,points,volume) VALUES (%d,%d,%s,%d,%d,%d,%d,%d) ON DUPLICATE KEY UPDATE orders=orders+VALUES(orders),items=items+VALUES(items),points=points+VALUES(points),volume=volume+VALUES(volume)", $totals, $row['relationship_id'], $version, $scope, $order, $delta['orders'], $delta['items'], $delta['points'], $delta['volume'] ) ) ) { return self::error(); }
				}
			}
		}
		return false === $wpdb->replace( $facts, $row ) ? self::error() : true;
	}
	public static function apply_locked( $id, $entry ) {
		if ( in_array( $entry['transaction_type'], array( 'purchase_points','referral_commission' ), true ) ) { return self::sync_locked( $id ); }
		if ( 'refund_reversal' === $entry['transaction_type'] ) { return self::sync_locked( $entry['reference_id'] ); }
		return true;
	}
	public static function prior( $relationship, $order, $settings ) {
		if ( ! self::ready() ) { return self::error(); }
		global $wpdb; $table = RR_Database::table( 'commission_totals' );
		$version = 'new_version' === $settings['commission_migration'] ? RR_Rules::checked_version() : 0;
		if ( is_wp_error( $version ) ) { return $version; }
		$out = array( 'orders' => 0,'items' => 0,'points' => 0,'volume' => 0,'first_at' => 0 );
		// Two primary-key reads independent of relationship history. Current reads also support caller snapshots.
		foreach ( array( 'all','order' ) as $scope ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT orders,items,points,volume FROM %i WHERE relationship_id=%d AND rule_version=%d AND scope_kind=%s AND scope_order=%d FOR UPDATE", $table, $relationship, $version, $scope, 'all' === $scope ? 0 : $order ), ARRAY_A );
			if ( $wpdb->last_error ) { return self::error(); }
			foreach ( array( 'orders','items','points','volume' ) as $key ) {
				$value = $row[ $key ] ?? '0';
				if ( null === RR_Ledger::validated_integer( (string) $value, 0, PHP_INT_MAX ) ) { return self::error(); }
				$out[ $key ] += ( 'all' === $scope ? 1 : -1 ) * (int) $value;
			}
		}
		return $out;
	}
	public static function first_purchase( $customer, $order, $settings ) {
		if ( ! self::ready() ) { return self::error(); }
		global $wpdb; $table = RR_Database::table( 'credit_facts' );
		$rule_version = 'new_version' === $settings['commission_migration'] ? RR_Rules::checked_version() : 0;
		if ( is_wp_error( $rule_version ) ) { return $rule_version; }
		$version = 'new_version' === $settings['commission_migration'] ? $wpdb->prepare( ' AND rule_version=%d', $rule_version ) : '';
		$index = $version ? 'customer_version_date' : 'customer_date';
		$at = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM $table FORCE INDEX ($index) WHERE customer_id=%d AND kind='purchase_points' AND active=1 AND order_id<>%d$version ORDER BY created_at,credit_id LIMIT 1 FOR UPDATE", $customer, $order ) );
		return $wpdb->last_error ? self::error() : ( $at ? strtotime( $at . ' UTC' ) : 0 );
	}
	/** No history is scanned in checkout: pre-existing credits are indexed in 100-row resumable batches. */
	public static function work() {
		global $wpdb; $lock = 'rr_commissions_' . substr( md5( $wpdb->prefix ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return 0; }
		$count = 0;
		try {
			$s = self::state(); if ( ! empty( $s['read_error'] ) || $s['complete'] ) { return 0; }
			$ledger = RR_Database::table( 'ledger' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,customer_id,amount,transaction_type FROM %i WHERE id>%d AND id<=%d ORDER BY id LIMIT 100", $ledger, $s['after'], $s['upper'] ) );
			if ( $wpdb->last_error ) { return 0; }
			foreach ( $rows as $row ) {
				if ( (int) $row->amount <= 0 || ! in_array( $row->transaction_type, array( 'purchase_points','referral_commission' ), true ) ) { $s['after'] = (int) $row->id; ++$count; continue; }
				// Rebuilding derived facts must retain but not wait on financial holds. A deleted
				// customer's retained source history still needs indexing; no points are awarded here.
				$start = RR_Ledger::begin_wallet_transaction( (int) $row->customer_id, false ); if ( is_wp_error( $start ) ) { return $count; }
				$r = self::sync_locked( (int) $row->id ); $done = RR_Ledger::finish_wallet_transaction( ! is_wp_error( $r ) );
				if ( is_wp_error( $r ) || is_wp_error( $done ) ) { return $count; }
				$s['after'] = (int) $row->id; ++$count;
			}
			$s['complete'] = count( $rows ) < 100;
			update_option( 'rr_commission_index_state', $s, false );
			return $count;
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); if ( ! self::ready() ) { RR_Background::schedule( 'rr_commission_index' ); } }
	}
}
