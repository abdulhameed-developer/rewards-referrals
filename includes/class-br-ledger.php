<?php
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-rr-point-lots.php';

final class RR_Ledger {
	private const TYPES = array(
		'purchase_points', 'referral_commission', 'redemption_reservation', 'redemption_debit',
		'reservation_release', 'redemption_restore', 'refund_reversal', 'point_expiration',
		'promotional_bonus', 'manual_adjustment', 'manual_credit', 'manual_debit', 'migration_correction', 'expiry_refund_offset',
	);
	private const STATES = array( 'pending', 'available', 'reserved', 'spent', 'expired', 'reversed', 'adjusted' );

	/** Values are deliberately bounded below BIGINT to keep integer arithmetic safe. */
	public const MAX_POINTS = 1000000000000;
	private static $transactions = array();
	private static $transaction_sequence = 0;
	private static $reservation_sweeping = false;

	public static function init() {
		add_action( 'rr_mature_pending_points', array( __CLASS__, 'mature_pending_points' ) );
		add_action( 'rr_expire_credit_lots', array( 'RR_Point_Lots', 'expire_batch' ) );
		add_action( 'rr_expire_customer_lots', array( 'RR_Point_Lots', 'expire_customer_batch' ) );
		add_action( 'rr_mature_customer_points', array( __CLASS__, 'mature_customer_points' ) );
		if ( ! wp_next_scheduled( 'rr_mature_pending_points' ) ) { wp_schedule_event( time() + 60, 'hourly', 'rr_mature_pending_points' ); }
		if ( ! wp_next_scheduled( 'rr_expire_credit_lots' ) ) { wp_schedule_event( time() + 120, 'hourly', 'rr_expire_credit_lots' ); }
	}

	public static function add( $args ) {
		global $wpdb;
		$defaults = array( 'customer_id' => 0, 'amount' => 0, 'type' => 'manual_adjustment', 'state' => 'available', 'order_id' => 0, 'referral_id' => 0, 'reference_id' => 0, 'key' => '', 'meta' => array(), 'available_at' => null );
		$args = wp_parse_args( $args, $defaults );
		$amount = self::validated_integer( $args['amount'], -self::MAX_POINTS, self::MAX_POINTS );
		if ( null === $amount || 0 === $amount ) { return new WP_Error( 'rr_invalid_amount', __( 'Ledger points must be a non-zero whole number within the supported range.', 'rewards-referrals' ) ); }
		$customer_id = self::validated_integer( $args['customer_id'], 1, PHP_INT_MAX );
		$type = is_string( $args['type'] ) ? $args['type'] : '';
		$state = is_string( $args['state'] ) ? $args['state'] : '';
		$key = is_string( $args['key'] ) ? trim( $args['key'] ) : '';
		if ( ! $customer_id || ! get_userdata( $customer_id ) || ! $key || strlen( $key ) > 191 || sanitize_text_field( $key ) !== $key ) { return new WP_Error( 'rr_invalid_entry', __( 'A real customer and a valid idempotency key are required.', 'rewards-referrals' ) ); }
		if ( ! in_array( $type, self::TYPES, true ) ) { return new WP_Error( 'rr_invalid_type', __( 'That ledger transaction type is not allowed.', 'rewards-referrals' ) ); }
		if ( ! in_array( $state, self::STATES, true ) ) { return new WP_Error( 'rr_invalid_state', __( 'That ledger state is not allowed.', 'rewards-referrals' ) ); }
		$positive_types = array( 'purchase_points', 'referral_commission', 'promotional_bonus', 'manual_credit', 'redemption_restore', 'reservation_release', 'expiry_refund_offset' );
		$negative_types = array( 'manual_debit', 'redemption_debit', 'redemption_reservation', 'refund_reversal', 'point_expiration' );
		if ( ( in_array( $type, $positive_types, true ) && $amount < 0 ) || ( in_array( $type, $negative_types, true ) && $amount > 0 ) ) { return new WP_Error( 'rr_invalid_sign', __( 'The signed point amount does not match its transaction type.', 'rewards-referrals' ) ); }
		if ( ( 'reserved' === $state && 'redemption_reservation' !== $type ) || ( 'spent' === $state && 'redemption_debit' !== $type ) || ( 'expired' === $state && 'point_expiration' !== $type ) ) { return new WP_Error( 'rr_invalid_state', __( 'The ledger state does not match its transaction type.', 'rewards-referrals' ) ); }
		if ( 'pending' === $state && $amount < 0 && 'refund_reversal' !== $type ) { return new WP_Error( 'rr_invalid_state', __( 'Only positive point credits may be pending.', 'rewards-referrals' ) ); }
		$entry = array( 'customer_id' => $customer_id, 'amount' => $amount, 'transaction_type' => $type, 'state' => $state );
		foreach ( array( 'order_id', 'referral_id', 'reference_id' ) as $field ) {
			$entry[ $field ] = self::validated_integer( $args[ $field ], 0, PHP_INT_MAX );
			if ( null === $entry[ $field ] ) { return new WP_Error( 'rr_invalid_reference', __( 'Ledger references must be non-negative whole numbers.', 'rewards-referrals' ) ); }
		}
		$available_at = $args['available_at'];
		if ( null !== $available_at && ( ! is_string( $available_at ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $available_at ) || false === strtotime( $available_at . ' UTC' ) ) ) { return new WP_Error( 'rr_invalid_availability', __( 'Point availability must be a valid UTC date.', 'rewards-referrals' ) ); }
		if ( null !== $available_at ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $available_at, new DateTimeZone( 'UTC' ) );
			if ( ! $date || (int) $date->format( 'Y' ) < 1970 || $date->format( 'Y-m-d H:i:s' ) !== $available_at ) { return new WP_Error( 'rr_invalid_availability', __( 'The availability date does not exist.', 'rewards-referrals' ) ); }
		}
		if ( 'pending' !== $state && null !== $available_at ) { return new WP_Error( 'rr_invalid_availability', __( 'Only pending credits may have an availability date.', 'rewards-referrals' ) ); }
		$entry['available_at'] = $available_at;
		$meta_json = self::canonical_json( $args['meta'] );
		if ( false === $meta_json ) { return new WP_Error( 'rr_invalid_meta', __( 'Ledger metadata must be valid JSON data.', 'rewards-referrals' ) ); }
		$locked = self::begin_wallet_transaction( $customer_id );
		if ( is_wp_error( $locked ) ) { return $locked; }
		try {
			$table = RR_Database::table( 'ledger' );
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,customer_id,amount,transaction_type,initial_state AS state,available_at,order_id,referral_id,reference_id,meta FROM %i WHERE idempotency_key = %s", $table, $key ) );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'idempotency_read' ); }
			if ( $existing ) {
				$result = self::idempotent_result( $existing, $entry, $meta_json );
				$finished = self::finish_wallet_transaction( ! is_wp_error( $result ) );
				return is_wp_error( $finished ) ? $finished : $result;
			}
			$rule_version = RR_Rules::checked_version();
			if ( is_wp_error( $rule_version ) ) { self::finish_wallet_transaction( false ); return $rule_version; }
			$lot_ready = RR_Point_Lots::prepare( $customer_id );
			if ( is_wp_error( $lot_ready ) ) { self::finish_wallet_transaction( false ); return $lot_ready; }
			if ( 'refund_reversal' === $type ) {
				$source = $wpdb->get_row( $wpdb->prepare( "SELECT customer_id,state,available_at,amount FROM %i WHERE id = %d FOR UPDATE", $table, $entry['reference_id'] ) );
				if ( ! $source || (int) $source->customer_id !== $customer_id || (int) $source->amount <= 0 ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_reversal_source', __( 'A reward reversal must reference the matching customer credit.', 'rewards-referrals' ) ); }
				if ( ( 'pending' === $state || 'pending' === $source->state ) && ( 'pending' !== $source->state || 'pending' !== $state || (string) $source->available_at !== (string) $available_at ) ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_pending_reversal', __( 'A pending reversal must use the original pending credit’s availability date.', 'rewards-referrals' ) ); }
				$reversed = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE reference_id = %d AND transaction_type = 'refund_reversal' AND amount < 0 FOR UPDATE", $table, $entry['reference_id'] ) );
				if ( $wpdb->last_error || null === self::validated_integer( (string) $reversed, -PHP_INT_MAX, 0 ) ) { throw new RuntimeException( 'reversal_cap_read' ); }
				$already_reversed = abs( (int) $reversed );
				if ( $already_reversed + abs( $amount ) > (int) $source->amount ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_reversal_limit', __( 'The reversal exceeds the original reward credit.', 'rewards-referrals' ) ); }
			}
			$projection = RR_Wallet_Balances::locked( $customer_id, true );
			if ( ! $projection || $wpdb->last_error || null === self::validated_integer( (string) $projection['balance'], -self::MAX_POINTS, self::MAX_POINTS ) || null === self::validated_integer( (string) $projection['pending'], 0, self::MAX_POINTS ) ) { throw new RuntimeException( 'wallet_projection_read' ); }
			$balance = (int) $projection['balance'];
			$affects_balance = in_array( $state, array( 'available', 'reserved', 'spent', 'adjusted', 'expired' ), true );
			$pending = (int) $projection['pending'];
			if ( ( $affects_balance || 'pending' === $state ) && $balance + $pending + $amount > self::MAX_POINTS ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_wallet_limit', __( 'This transaction exceeds the supported wallet limit.', 'rewards-referrals' ) ); }
			if ( $affects_balance && ( $balance + $amount > self::MAX_POINTS || $balance + $amount < -self::MAX_POINTS ) ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_wallet_limit', __( 'This transaction exceeds the supported wallet limit.', 'rewards-referrals' ) ); }
			if ( 'redemption_reservation' === $type && ( $amount >= 0 || 'reserved' !== $state || $balance + $amount < 0 ) ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_insufficient_points', __( 'There are not enough available points for this reward.', 'rewards-referrals' ) ); }
			if ( $amount < 0 && in_array( $type, array( 'manual_adjustment', 'manual_debit', 'redemption_debit' ), true ) && $balance + $amount < 0 ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_insufficient_points', __( 'The debit exceeds this customer’s available points.', 'rewards-referrals' ) ); }
			$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (customer_id,amount,transaction_type,state,initial_state,available_at,order_id,referral_id,rule_version,reference_id,idempotency_key,meta,created_at) VALUES (%d,%d,%s,%s,%s,NULLIF(%s,''),%d,%d,%d,%d,%s,%s,%s)", $table,
				$entry['customer_id'], $entry['amount'], $entry['transaction_type'], $entry['state'], $entry['state'], $available_at ?: '', $entry['order_id'], $entry['referral_id'],
				$rule_version, $entry['reference_id'], $key, $meta_json, current_time( 'mysql', true )
			) );
			if ( 0 === $result ) {
				$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,customer_id,amount,transaction_type,initial_state AS state,available_at,order_id,referral_id,reference_id,meta FROM %i WHERE idempotency_key = %s FOR UPDATE", $table, $key ) );
				$idempotent = $existing ? self::idempotent_result( $existing, $entry, $meta_json ) : new WP_Error( 'rr_ledger_write', __( 'The ledger transaction could not be recorded.', 'rewards-referrals' ) );
				$finished = self::finish_wallet_transaction( ! is_wp_error( $idempotent ) );
				return is_wp_error( $finished ) ? $finished : $idempotent;
			}
			if ( 1 !== $result ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_ledger_write', __( 'The ledger transaction could not be recorded.', 'rewards-referrals' ) ); }
			$transaction_id = (int) $wpdb->insert_id;
			$indexed = RR_Wallet_Balances::apply_locked( $entry );
			if ( is_wp_error( $indexed ) ) { self::finish_wallet_transaction( false ); return $indexed; }
			$lot_applied = RR_Point_Lots::apply( $transaction_id, $entry, $args['meta'] );
			if ( is_wp_error( $lot_applied ) ) { self::finish_wallet_transaction( false ); return $lot_applied; }
			$expired_returns = RR_Point_Lots::expire_locked( $customer_id );
			if ( is_wp_error( $expired_returns ) ) { self::finish_wallet_transaction( false ); return $expired_returns; }
			$commission_indexed = RR_Commission_Index::apply_locked( $transaction_id, $entry );
			if ( is_wp_error( $commission_indexed ) ) { self::finish_wallet_transaction( false ); return $commission_indexed; }
			$staged = RR_Outbox::stage_locked( $transaction_id, $entry );
			if ( is_wp_error( $staged ) ) { self::finish_wallet_transaction( false ); return $staged; }
			$frame = count( self::$transactions ) - 1;
			self::$transactions[ $frame ]['events'][] = array( $transaction_id, $entry );
			$finished = self::finish_wallet_transaction( true );
			return is_wp_error( $finished ) ? $finished : $transaction_id;
		} catch ( Throwable $error ) {
			self::finish_wallet_transaction( false );
			return new WP_Error( 'rr_ledger_exception', __( 'The ledger transaction could not be safely recorded.', 'rewards-referrals' ) );
		}
	}

	public static function validated_integer( $value, $minimum = 0, $maximum = self::MAX_POINTS ) {
		if ( ! is_int( $value ) && ! is_string( $value ) ) { return null; }
		if ( ! preg_match( '/^-?(?:0|[1-9][0-9]*)$/D', (string) $value ) ) { return null; }
		$integer = filter_var( $value, FILTER_VALIDATE_INT );
		return false === $integer || $integer < $minimum || $integer > $maximum ? null : $integer;
	}

	private static function canonical_json( $value ) {
		if ( ! is_array( $value ) ) { return false; }
		$normalize = static function ( $node, $depth = 0 ) use ( &$normalize ) {
			if ( $depth > 32 ) { throw new InvalidArgumentException( 'Metadata is too deeply nested.' ); }
			if ( is_object( $node ) || is_resource( $node ) ) { throw new InvalidArgumentException( 'Unsupported metadata value.' ); }
			if ( is_array( $node ) ) {
				if ( array_keys( $node ) !== range( 0, count( $node ) - 1 ) ) { ksort( $node, SORT_STRING ); }
				foreach ( $node as $key => $child ) { $node[ $key ] = $normalize( $child, $depth + 1 ); }
			}
			return $node;
		};
		try { $json = wp_json_encode( $normalize( $value ), JSON_PRESERVE_ZERO_FRACTION ); return is_string( $json ) && strlen( $json ) <= 65535 ? $json : false; } catch ( Throwable $error ) { return false; }
	}

	private static function idempotent_result( $existing, $entry, $meta_json ) {
		foreach ( $entry as $key => $value ) {
			if ( (string) $existing->$key !== (string) $value ) { return new WP_Error( 'rr_idempotency_conflict', __( 'This idempotency key is already used for a different ledger operation.', 'rewards-referrals' ) ); }
		}
		$existing_meta = json_decode( (string) $existing->meta, true );
		if ( self::canonical_json( is_array( $existing_meta ) ? $existing_meta : array() ) !== $meta_json ) { return new WP_Error( 'rr_idempotency_conflict', __( 'This idempotency key is already used for a different ledger operation.', 'rewards-referrals' ) ); }
		return (int) $existing->id;
	}

	private static function raw_balance( $customer_id ) {
		$row = RR_Wallet_Balances::locked( $customer_id, true );
		if ( ! $row ) { throw new RuntimeException( 'Missing wallet projection under lock.' ); }
		return (int) $row['balance'];
	}

	public static function balances( $customer_id ) {
		$customer_id = self::validated_integer( $customer_id, 1, PHP_INT_MAX );
		$balances = self::balances_for_customers( $customer_id ? array( $customer_id ) : array() );
		return isset( $balances[ $customer_id ] ) ? $balances[ $customer_id ] : self::empty_balance();
	}

	private static function empty_balance() { return array( 'available' => 0, 'debt' => 0, 'pending' => 0, 'reserved' => 0, 'spent' => 0, 'earned' => 0, 'referral_earned' => 0 ); }

	/** One grouped query for a bounded page of customer wallets. */
	public static function balances_for_customers( $customer_ids ) {
		global $wpdb;
		$ids = array();
		foreach ( array_slice( (array) $customer_ids, 0, 100 ) as $value ) {
			$id = self::validated_integer( $value, 1, PHP_INT_MAX );
			if ( $id ) { $ids[ $id ] = $id; }
		}
		$balances = array_fill_keys( array_keys( $ids ), self::empty_balance() );
		if ( ! $ids ) { return $balances; }
		$ownership = RR_Wallet_Identity::allowed( array_values( $ids ) );
		foreach ( $ids as $id ) { if ( true !== ( $ownership[ $id ] ?? false ) ) { $balances[ $id ]['unavailable'] = true; unset( $ids[ $id ] ); } }
		if ( ! $ids ) { return $balances; }
		$expiry_pending = RR_Point_Lots::expire_due_for_customers( array_values( $ids ) );
		$rows = RR_Wallet_Balances::rows( $ids ); $found = array_column( $rows, 'customer_id' );
		foreach ( array_diff( $ids, $found ) as $id ) {
			$started = self::begin_wallet_transaction( (int) $id );
			if ( ! is_wp_error( $started ) ) { self::finish_wallet_transaction( true ); }
		}
		$rows = RR_Wallet_Balances::rows( $ids );
		foreach ( array_diff( $ids, array_column( $rows, 'customer_id' ) ) as $missing ) { $balances[ $missing ]['unavailable'] = true; }
		foreach ( $rows as $row ) {
			$balance = (int) $row['balance'];
			$balances[ (int) $row['customer_id'] ] = array( 'available' => max( 0, $balance ), 'debt' => max( 0, -$balance ), 'pending' => max( 0, (int) $row['pending'] ), 'reserved' => max( 0, (int) $row['reserved'] ), 'spent' => max( 0, (int) $row['spent'] ), 'earned' => (int) $row['earned'], 'referral_earned' => (int) $row['referral_earned'] );
		}
		$rebuilds = RR_Database::table( 'wallet_rebuilds' ); $marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$busy = $wpdb->get_col( $wpdb->prepare( "SELECT customer_id FROM $rebuilds WHERE customer_id IN ($marks) AND status<>'complete'", array_values( $ids ) ) );
		foreach ( $ids as $id ) { if ( in_array( $id, $expiry_pending, true ) || in_array( $id, array_map( 'intval', $busy ), true ) || RR_Recovery::held( $id ) || RR_Legacy_Lot_Migration::busy( $id ) ) { $balances[ $id ]['unavailable'] = true; $balances[ $id ]['available'] = 0; } }
		return $balances;
	}

	/** Atomically claim one real checkout order and reserve its authoritative point total. */
	public static function reserve_redemption( $customer_id, $order_id, $points, $meta = array() ) {
		global $wpdb;
		$customer_id = self::validated_integer( $customer_id, 1, PHP_INT_MAX );
		$order_id = self::validated_integer( $order_id, 1, PHP_INT_MAX );
		$points = self::validated_integer( $points, 1, self::MAX_POINTS );
		if ( ! $customer_id || ! $order_id || ! $points || ! get_userdata( $customer_id ) ) { return new WP_Error( 'rr_invalid_reservation', __( 'A customer, order, and positive point amount are required.', 'rewards-referrals' ) ); }
		$meta_json = self::canonical_json( $meta );
		if ( false === $meta_json ) { return new WP_Error( 'rr_invalid_meta', __( 'Reservation metadata is invalid.', 'rewards-referrals' ) ); }
		$checkout_key = isset( $meta['checkout_key'] ) && is_string( $meta['checkout_key'] ) ? $meta['checkout_key'] : '';
		if ( $checkout_key && ! preg_match( '/^[a-f0-9]{64}$/D', $checkout_key ) ) { return new WP_Error( 'rr_invalid_checkout', __( 'The checkout session could not be validated. Please refresh the cart and try again.', 'rewards-referrals' ) ); }
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) || (int) $order->get_customer_id() !== $customer_id ) { return new WP_Error( 'rr_reservation_owner', __( 'The reward order does not belong to this customer.', 'rewards-referrals' ) ); }
		if ( 'yes' === $order->get_meta( '_rr_rewards_cancelled' ) ) { return new WP_Error( 'rr_order_cancelled', __( 'This order was cancelled. Create a new order to redeem rewards.', 'rewards-referrals' ) ); }
		$started = self::begin_wallet_transaction( $customer_id );
		if ( is_wp_error( $started ) ) { return $started; }
		try {
			$table = RR_Database::table( 'redemptions' );
			$now = current_time( 'mysql', true );
			if ( $checkout_key ) {
				$claimed_checkout = $wpdb->get_row( $wpdb->prepare( "SELECT order_id,status FROM %i WHERE checkout_key = %s FOR UPDATE", $table, $checkout_key ) );
				if ( $claimed_checkout && (int) $claimed_checkout->order_id !== $order_id && in_array( $claimed_checkout->status, array( 'initiated', 'reserved', 'confirmed' ), true ) ) {
					self::finish_wallet_transaction( false );
					return new WP_Error( 'rr_duplicate_checkout', __( 'This checkout has already been received. Please wait for the original order to finish processing.', 'rewards-referrals' ) );
				}
			}
			// Claim the unique order with a write, avoiding a gap lock on a missing order ID.
			$claimed = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (customer_id,order_id,points,status,reservation_transaction_id,idempotency_key,checkout_key,meta,created_at,updated_at) VALUES (%d,%d,%d,'initiated',0,%s,NULLIF(%s,''),%s,%s,%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", $table, $customer_id, $order_id, $points, 'order:' . $order_id . ':redemption', $checkout_key, $meta_json, $now, $now ) );
			if ( false === $claimed ) {
				$duplicate = $checkout_key ? $wpdb->get_row( $wpdb->prepare( "SELECT order_id,status FROM %i WHERE checkout_key = %s", $table, $checkout_key ) ) : null;
				self::finish_wallet_transaction( false );
				return $duplicate && (int) $duplicate->order_id !== $order_id ? new WP_Error( 'rr_duplicate_checkout', __( 'This checkout has already been received. Please wait for the original order to finish processing.', 'rewards-referrals' ) ) : new WP_Error( 'rr_reservation_write', __( 'The reward reservation could not be saved.', 'rewards-referrals' ) );
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE order_id = %d FOR UPDATE", $table, $order_id ) );
			$matches = $row && (int) $row->customer_id === $customer_id && (int) $row->points === $points && self::canonical_json( json_decode( (string) $row->meta, true ) ?: array() ) === $meta_json;
			if ( ! $matches || ( $row->reservation_transaction_id && ! in_array( $row->status, array( 'reserved', 'confirmed' ), true ) ) ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_reservation_conflict', __( 'This order already has a different or closed points reservation.', 'rewards-referrals' ) ); }
			if ( $row->reservation_transaction_id ) {
				$finished = self::finish_wallet_transaction( true );
				return is_wp_error( $finished ) ? $finished : (int) $row->id;
			}
			if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'checkout-draft' ), true ) ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_reservation_order_status', __( 'Points can only be reserved for an unpaid checkout order.', 'rewards-referrals' ) ); }
			if ( self::raw_balance( $customer_id ) < $points ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_insufficient_points', __( 'There are not enough available points for this reward.', 'rewards-referrals' ) ); }
			$transaction_id = self::add( array( 'customer_id' => $customer_id, 'amount' => -$points, 'type' => 'redemption_reservation', 'state' => 'reserved', 'order_id' => $order_id, 'reference_id' => $order_id, 'key' => 'redemption:order:' . $order_id . ':reserve', 'meta' => $meta ) );
			if ( is_wp_error( $transaction_id ) ) { self::finish_wallet_transaction( false ); return $transaction_id; }
			$updated = $wpdb->update( $table, array( 'status' => 'reserved', 'reservation_transaction_id' => $transaction_id, 'updated_at' => $now ), array( 'id' => (int) $row->id, 'status' => 'initiated' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
			if ( 1 !== $updated ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_reservation_write', __( 'The reward reservation could not be saved.', 'rewards-referrals' ) ); }
			$finished = self::finish_wallet_transaction( true );
			return is_wp_error( $finished ) ? $finished : (int) $row->id;
		} catch ( Throwable $error ) {
			self::finish_wallet_transaction( false );
			return new WP_Error( 'rr_reservation_exception', __( 'The reward reservation could not be completed.', 'rewards-referrals' ) );
		}
	}

	public static function confirm_redemption( $order_id ) {
		return self::transition_redemption( $order_id, 'confirmed' );
	}

	public static function release_redemption( $order_id, $reason = 'order_cancelled' ) {
		return self::transition_redemption( $order_id, 'released', $reason );
	}

	/** Restore only points tied to refunded reward quantities, capped at the ordered amount. */
	public static function restore_redemption_refund( $order_id, $refund_id ) {
		global $wpdb;
		$order_id = self::validated_integer( $order_id, 1, PHP_INT_MAX );
		$refund_id = self::validated_integer( $refund_id, 1, PHP_INT_MAX );
		if ( ! $order_id || ! $refund_id ) { return false; }
		$order = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund || ! is_a( $refund, 'WC_Order_Refund' ) || absint( $refund->get_parent_id() ) !== $order_id ) { return false; }
		$buyer = RR_Recovery::buyer( $order );
		if ( ! $buyer ) { return new WP_Error( 'rr_refund_owner', __( 'The original reward customer could not be verified. Refund accounting remains pending for review.', 'rewards-referrals' ) ); }
		$redemptions = RR_Database::table( 'redemptions' );
		$ledger = RR_Database::table( 'ledger' );
		$started = self::begin_wallet_transaction( $buyer );
		if ( is_wp_error( $started ) ) { return $started; }
		try {
			$peek = $wpdb->get_row( $wpdb->prepare( "SELECT customer_id FROM %i WHERE order_id = %d", $redemptions, $order_id ) );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'refund_owner_read' ); }
			if ( ! $peek || (int) $peek->customer_id !== $buyer ) { self::finish_wallet_transaction( false ); return false; }
			$lock_table = RR_Database::table( 'wallet_locks' );
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (customer_id,updated_at) VALUES (%d,%s) ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id)", $lock_table, (int) $peek->customer_id, current_time( 'mysql', true ) ) );
			$wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM %i WHERE customer_id = %d FOR UPDATE", $lock_table, (int) $peek->customer_id ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE order_id = %d FOR UPDATE", $redemptions, $order_id ) );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'refund_reservation_read' ); }
			if ( ! $row || ! in_array( $row->status, array( 'confirmed', 'partially_refunded' ), true ) ) { self::finish_wallet_transaction( false ); return false; }
			$meta = json_decode( (string) $row->meta, true );
			$reward_items = isset( $meta['items'] ) && is_array( $meta['items'] ) ? $meta['items'] : array();
			if ( ! $reward_items ) { self::finish_wallet_transaction( false ); return false; }
			$refunded_quantities = array();
			$refunds = $order->get_refunds();
			if ( ! is_array( $refunds ) ) { $refunds = array(); }
			$has_current_refund = false;
			foreach ( $refunds as $order_refund ) { if ( absint( $order_refund->get_id() ) === $refund_id ) { $has_current_refund = true; break; } }
			if ( ! $has_current_refund ) { $refunds[] = $refund; }
			foreach ( $refunds as $order_refund ) {
				foreach ( $order_refund->get_items( 'line_item' ) as $refunded_item ) {
					$parent_item_id = absint( $refunded_item->get_meta( '_refunded_item_id', true ) );
					if ( isset( $reward_items[ (string) $parent_item_id ] ) ) {
						$refunded_quantities[ $parent_item_id ] = ( $refunded_quantities[ $parent_item_id ] ?? 0 ) + absint( $refunded_item->get_quantity() );
					}
				}
			}
			$target_points = 0;
			foreach ( $reward_items as $item_id => $reward_data ) {
				$item = $order->get_item( absint( $item_id ) );
				$ordered_quantity = absint( $reward_data['quantity'] ?? 0 );
				$quantity = min( $ordered_quantity, absint( $refunded_quantities[ absint( $item_id ) ] ?? 0 ) );
				$target_points += $quantity * absint( $reward_data['points_each'] ?? 0 );
			}
			$target_points = min( absint( $row->points ), $target_points );
			$restored_points = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE customer_id = %d AND reference_id = %d AND transaction_type = %s AND amount > 0", $ledger, (int) $row->customer_id, (int) $row->reservation_transaction_id, 'redemption_restore' ) );
			if ( $wpdb->last_error || null === self::validated_integer( (string) $restored_points, 0, PHP_INT_MAX ) ) { throw new RuntimeException( 'restored_points_read' ); }
			$restored_points = (int) $restored_points;
			$delta = max( 0, $target_points - $restored_points );
			$target_status = $target_points >= absint( $row->points ) ? 'refunded' : 'partially_refunded';
			if ( ! $delta ) {
				$finished = self::finish_wallet_transaction( true );
				return is_wp_error( $finished ) ? $finished : true;
			}
			$entry = self::add( array(
				'customer_id' => (int) $row->customer_id,
				'amount' => $delta,
				'type' => 'redemption_restore',
				'state' => 'available',
				'order_id' => $order_id,
				'reference_id' => (int) $row->reservation_transaction_id,
				'key' => 'refund:' . $refund_id . ':redemption',
				'meta' => array( 'refund_id' => $refund_id, 'redemption_id' => (int) $row->id, 'cumulative_points_restored' => $target_points ),
			) );
			if ( is_wp_error( $entry ) ) { self::finish_wallet_transaction( false ); return $entry; }
			$updated = $wpdb->update( $redemptions, array( 'status' => $target_status, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $row->id ) ), array( '%s', '%s' ), array( '%d' ) );
			if ( false === $updated ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_refund_status', __( 'The reward refund status could not be saved.', 'rewards-referrals' ) ); }
			if ( is_wp_error( self::finish_wallet_transaction( true ) ) ) { return new WP_Error( 'rr_refund_commit', __( 'The points refund could not be committed.', 'rewards-referrals' ) ); }
			return true;
		} catch ( Throwable $error ) {
			self::finish_wallet_transaction( false );
			return new WP_Error( 'rr_refund_exception', __( 'The points refund could not be safely processed.', 'rewards-referrals' ) );
		}
	}

	/** Restore only the remainder not already refunded, retaining the original credit lots. */
	public static function cancel_redemption( $order_id ) {
		global $wpdb;
		$order_id = self::validated_integer( $order_id, 1, PHP_INT_MAX );
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! $order->has_status( 'cancelled' ) || ! RR_Recovery::buyer( $order ) ) { return new WP_Error( 'rr_cancel_order', __( 'A cancelled customer order is required.', 'rewards-referrals' ) ); }
		$started = self::begin_wallet_transaction( (int) RR_Recovery::buyer( $order ) );
		if ( is_wp_error( $started ) ) { return $started; }
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d FOR UPDATE', RR_Database::table( 'redemptions' ), $order_id ) );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'redemption_read' ); }
			if ( ! $row || in_array( $row->status, array( 'released', 'refunded', 'cancelled' ), true ) ) { return self::finish_wallet_transaction( true ); }
			if ( (int) $row->customer_id !== (int) RR_Recovery::buyer( $order ) ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_cancel_owner', __( 'Reward order ownership does not match.', 'rewards-referrals' ) ); }
			if ( 'reserved' === $row->status ) {
				$result = self::release_redemption( $order_id, 'order_cancelled' );
				$finished = self::finish_wallet_transaction( ! is_wp_error( $result ) );
				return is_wp_error( $result ) ? $result : $finished;
			}
			if ( ! in_array( $row->status, array( 'confirmed', 'partially_refunded' ), true ) ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_cancel_state', __( 'This reward cannot be cancelled in its current accounting state.', 'rewards-referrals' ) ); }
			$restored = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM %i WHERE customer_id=%d AND reference_id=%d AND transaction_type=%s AND amount>0', RR_Database::table( 'ledger' ), $row->customer_id, $row->reservation_transaction_id, 'redemption_restore' ) );
			if ( $wpdb->last_error || null === self::validated_integer( (string) $restored, 0, PHP_INT_MAX ) ) { throw new RuntimeException( 'restored_points_read' ); }
			$restored = (int) $restored;
			$delta = max( 0, (int) $row->points - $restored );
			if ( $delta ) {
				$result = self::add( array( 'customer_id' => (int) $row->customer_id, 'amount' => $delta, 'type' => 'redemption_restore', 'order_id' => $order_id, 'reference_id' => (int) $row->reservation_transaction_id, 'key' => 'cancel:order:' . $order_id . ':redemption', 'meta' => array( 'reason' => 'order_cancelled', 'redemption_id' => (int) $row->id ) ) );
				if ( is_wp_error( $result ) ) { self::finish_wallet_transaction( false ); return $result; }
			}
			$updated = $wpdb->update( RR_Database::table( 'redemptions' ), array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ), 'checkout_key' => null ), array( 'id' => (int) $row->id ) );
			if ( false === $updated ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_cancel_write', __( 'Reward cancellation could not be saved.', 'rewards-referrals' ) ); }
			return self::finish_wallet_transaction( true );
		} catch ( Throwable $error ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_cancel_exception', __( 'Reward cancellation could not be safely completed.', 'rewards-referrals' ) ); }
	}

	/** Customer locks always use ascending IDs; nested work uses a savepoint. */
	public static function begin_wallet_transaction( $customer_ids, $initialize = true ) {
		return self::begin_locked_transaction( $customer_ids, $initialize, true );
	}

	/** Derived commission facts may index retained owners; this never grants financial permission. */
	public static function begin_commission_index_transaction( $customer_id ) {
		return self::begin_locked_transaction( $customer_id, false, false );
	}

	private static function begin_locked_transaction( $customer_ids, $initialize, $verify_owner ) {
		global $wpdb;
		$customer_ids = is_array( $customer_ids ) ? $customer_ids : array( $customer_ids );
		$ids = array();
		foreach ( $customer_ids as $value ) {
			$id = self::validated_integer( $value, 1, PHP_INT_MAX );
			if ( null === $id ) { return new WP_Error( 'rr_wallet_customer', __( 'A valid customer is required for a wallet transaction.', 'rewards-referrals' ) ); }
			$ids[] = $id;
		}
		$ids = array_unique( $ids );
		if ( ! $ids || ! self::transactional_tables_ready() ) { return new WP_Error( 'rr_wallet_transaction', __( 'Wallet accounting requires transactional database tables.', 'rewards-referrals' ) ); }
		sort( $ids, SORT_NUMERIC );
		$started = self::start_transaction();
		if ( is_wp_error( $started ) ) { return $started; }
		$lock_table = RR_Database::table( 'wallet_locks' );
		foreach ( $ids as $customer_id ) {
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (customer_id,updated_at) VALUES (%d,%s) ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id)", $lock_table, $customer_id, current_time( 'mysql', true ) ) );
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM %i WHERE customer_id = %d FOR UPDATE", $lock_table, $customer_id ) );
			if ( false === $inserted || (int) $locked !== $customer_id ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_wallet_lock', __( 'The wallet could not be safely locked.', 'rewards-referrals' ) ); }
			if ( $verify_owner ) {
				$owner = RR_Wallet_Identity::locked( $customer_id );
				if ( is_wp_error( $owner ) ) { self::finish_wallet_transaction( false ); return $owner; }
			}
			$indexed = $initialize ? RR_Wallet_Balances::ensure_locked( $customer_id ) : true;
			if ( is_wp_error( $indexed ) ) { self::finish_wallet_transaction( false ); if ( 'rr_wallet_rebuild_pending' === $indexed->get_error_code() ) { RR_Background::schedule( 'rr_wallet_rebuild', 5, array( $customer_id ) ); } return $indexed; }
		}
		return true;
	}

	private static function start_transaction() {
		global $wpdb;
		$savepoint = 'rr_wallet_' . ++self::$transaction_sequence;
		$external = false;
		if ( ! self::$transactions ) {
			// A savepoint probe detects a caller transaction without START TRANSACTION's implicit commit.
			$previous = $wpdb->suppress_errors( true );
			$probe = $wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', $savepoint . '_probe' ) );
			$released = false !== $probe ? $wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', $savepoint . '_probe' ) ) : false;
			$wpdb->suppress_errors( $previous );
			if ( false === $probe ) { return new WP_Error( 'rr_transaction_probe', __( 'The database transaction state could not be checked.', 'rewards-referrals' ) ); }
			$external = false !== $released;
		}
		$nested = (bool) self::$transactions || $external;
		$result = $nested ? $wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', $savepoint ) ) : $wpdb->query( 'START TRANSACTION' );
		if ( false === $result ) { return new WP_Error( 'rr_transaction_start', __( 'The wallet transaction could not be started.', 'rewards-referrals' ) ); }
		self::$transactions[] = array( 'savepoint' => $nested ? $savepoint : '', 'external' => $external, 'events' => array() );
		return true;
	}

	public static function transaction_open() { return (bool) self::$transactions; }

	public static function finish_wallet_transaction( $commit = true ) {
		global $wpdb;
		if ( ! self::$transactions ) { return new WP_Error( 'rr_transaction_missing', __( 'There is no wallet transaction to finish.', 'rewards-referrals' ) ); }
		$frame = array_pop( self::$transactions );
		try {
		$requested_commit = $commit;
		if ( ! empty( $frame['rollback_only'] ) ) { $commit = false; }
		if ( $frame['savepoint'] ) {
			$result = $commit ? true : $wpdb->query( $wpdb->prepare( 'ROLLBACK TO SAVEPOINT %i', $frame['savepoint'] ) );
			$released = $wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', $frame['savepoint'] ) );
			if ( false === $released ) { $result = false; }
		} else {
			$result = $commit ? $wpdb->query( 'COMMIT' ) : $wpdb->query( 'ROLLBACK' );
			if ( false === $result && $commit ) { $wpdb->query( 'ROLLBACK' ); }
		}
		if ( false === $result ) {
			if ( self::$transactions ) { self::$transactions[ count( self::$transactions ) - 1 ]['rollback_only'] = true; }
			return new WP_Error( 'rr_wallet_commit', __( 'The wallet accounting transaction could not be completed.', 'rewards-referrals' ) ); }
		if ( $requested_commit && ! $commit ) { return new WP_Error( 'rr_wallet_rollback_only', __( 'The wallet transaction was rolled back after an earlier database error.', 'rewards-referrals' ) ); }
		if ( $commit ) {
			if ( self::$transactions ) {
				$index = count( self::$transactions ) - 1;
				self::$transactions[ $index ]['events'] = array_merge( self::$transactions[ $index ]['events'], $frame['events'] );
			} elseif ( ! $frame['external'] ) {
				foreach ( $frame['events'] as $event ) { try { do_action( 'rr_ledger_transaction_added', $event[0], $event[1] ); do_action( 'br_ledger_transaction_added', $event[0], $event[1] ); } catch ( Throwable $error ) { error_log( 'Rewards & Referrals: a committed ledger observer failed.' ); } }
			}
			delete_transient( 'rr_dashboard_stats' );
		}
		return true;
		} finally { if ( ! self::$transactions ) { RR_Background::flush( (bool) $frame['external'] ); } }
	}

	private static function transition_redemption( $order_id, $target_status, $reason = '' ) {
		global $wpdb;
		$order_id = self::validated_integer( $order_id, 1, PHP_INT_MAX );
		$redemptions = RR_Database::table( 'redemptions' );
		$ledger = RR_Database::table( 'ledger' );
		if ( ! $order_id ) { return new WP_Error( 'rr_transition_start', __( 'A valid reward order is required.', 'rewards-referrals' ) ); }
		$peek = $wpdb->get_row( $wpdb->prepare( "SELECT customer_id FROM %i WHERE order_id = %d", $redemptions, $order_id ) );
		if ( $wpdb->last_error ) { return new WP_Error( 'rr_redemption_read', __( 'Reward accounting could not be read safely. Please retry.', 'rewards-referrals' ) ); }
		if ( ! $peek ) { return true; }
		$started = self::begin_wallet_transaction( (int) $peek->customer_id );
		if ( is_wp_error( $started ) ) { return $started; }
		try {
			if ( ! $peek ) { self::finish_wallet_transaction( false ); return true; }
			if ( ! self::transactional_tables_ready() ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_transaction_engine', __( 'This accounting operation requires InnoDB tables.', 'rewards-referrals' ) ); }
			$lock_table = RR_Database::table( 'wallet_locks' );
			$wpdb->query( $wpdb->prepare( "SELECT customer_id FROM %i WHERE customer_id = %d FOR UPDATE", $lock_table, absint( $peek->customer_id ) ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE order_id = %d FOR UPDATE", $redemptions, $order_id ) );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'redemption_read' ); }
			if ( ! $row ) { self::finish_wallet_transaction( false ); return true; }
			if ( 'confirmed' === $target_status && 'confirmed' === $row->status ) { self::finish_wallet_transaction( true ); return true; }
			if ( 'released' === $target_status && 'released' === $row->status ) { self::finish_wallet_transaction( true ); return true; }
			if ( 'confirmed' === $target_status && 'reserved' !== $row->status ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_reservation_closed', __( 'The points reservation is no longer active.', 'rewards-referrals' ) ); }
			if ( 'released' === $target_status && 'reserved' !== $row->status ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_reservation_closed', __( 'Only an active points reservation can be released.', 'rewards-referrals' ) ); }
			$reservation_tx = absint( $row->reservation_transaction_id );
			$reservation_state = 'confirmed' === $target_status ? 'reversed' : 'available';
			$changed = $wpdb->update( $ledger, array( 'state' => $reservation_state ), array( 'id' => $reservation_tx, 'customer_id' => absint( $row->customer_id ), 'state' => 'reserved' ), array( '%s' ), array( '%d', '%d', '%s' ) );
			if ( 1 !== $changed ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_reservation_ledger', __( 'The reservation ledger row could not be transitioned.', 'rewards-referrals' ) ); }
			$state_indexed = RR_Wallet_Balances::state_changed_locked( array( 'customer_id' => (int) $row->customer_id, 'amount' => -(int) $row->points, 'transaction_type' => 'redemption_reservation', 'state' => 'reserved' ), $reservation_state );
			if ( is_wp_error( $state_indexed ) ) { self::finish_wallet_transaction( false ); return $state_indexed; }
			if ( 'confirmed' === $target_status ) {
				$entry = self::add( array(
					'customer_id' => absint( $row->customer_id ), 'amount' => -absint( $row->points ), 'type' => 'redemption_debit', 'state' => 'spent',
					'order_id' => $order_id, 'reference_id' => $reservation_tx, 'key' => 'redemption:order:' . $order_id . ':debit',
					'meta' => array( 'reservation_id' => absint( $row->id ) ),
				) );
			} else {
				$entry = self::add( array(
					'customer_id' => absint( $row->customer_id ), 'amount' => absint( $row->points ), 'type' => 'reservation_release', 'state' => 'available',
					'order_id' => $order_id, 'reference_id' => $reservation_tx, 'key' => 'redemption:order:' . $order_id . ':release',
					'meta' => array( 'reservation_id' => absint( $row->id ), 'reason' => sanitize_key( $reason ) ),
				) );
			}
			if ( is_wp_error( $entry ) ) { self::finish_wallet_transaction( false ); return $entry; }
			$values = array( 'status' => $target_status, 'updated_at' => current_time( 'mysql', true ) );
			$updated = $wpdb->update( $redemptions, $values, array( 'id' => absint( $row->id ), 'status' => 'reserved' ), array( '%s', '%s' ), array( '%d', '%s' ) );
			if ( 1 !== $updated ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_redemption_transition', __( 'The reward status could not be updated.', 'rewards-referrals' ) ); }
			if ( 'released' === $target_status && false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET checkout_key = NULL WHERE id = %d", $redemptions, absint( $row->id ) ) ) ) { self::finish_wallet_transaction( false ); return new WP_Error( 'rr_redemption_transition', __( 'The reward status could not be updated.', 'rewards-referrals' ) ); }
			if ( is_wp_error( self::finish_wallet_transaction( true ) ) ) { return new WP_Error( 'rr_transition_commit', __( 'The reward status could not be committed.', 'rewards-referrals' ) ); }
			delete_transient( 'rr_dashboard_stats' );
			return true;
		} catch ( Throwable $error ) {
			self::finish_wallet_transaction( false );
			return new WP_Error( 'rr_transition_exception', __( 'The reward status could not be updated.', 'rewards-referrals' ) );
		}
	}

	private static function transactional_tables_ready() {
		static $ready = null;
		if ( null !== $ready ) { return $ready; }
		global $wpdb;
		$tables = array( $wpdb->users, $wpdb->usermeta, RR_Database::table( 'ledger' ), RR_Database::table( 'wallet_locks' ), RR_Database::table( 'redemptions' ), RR_Database::table( 'point_wallets' ), RR_Database::table( 'point_lots' ), RR_Database::table( 'point_allocations' ), RR_Database::table( 'wallet_balances' ), RR_Database::table( 'credit_facts' ), RR_Database::table( 'commission_totals' ), RR_Database::table( 'notification_outbox' ), RR_Database::table( 'wallet_rebuilds' ), RR_Database::table( 'recovery_events' ), RR_Database::table( 'legacy_lot_migrations' ) );
		$engines = $wpdb->get_col( $wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (' . implode( ',', array_fill( 0, count( $tables ), '%s' ) ) . ')',
			array_merge( array( $wpdb->dbname ), $tables )
		) );
		$ready = count( $tables ) === count( $engines ) && count( $tables ) === count( array_filter( $engines, static function ( $engine ) { return 'InnoDB' === $engine; } ) );
		return $ready;
	}

	/** A source credit and its matching pending reversals always transition in one wallet transaction. */
	public static function mature_customer_points( $customer_id ) {
		$customer_id = self::validated_integer( $customer_id, 1, PHP_INT_MAX ); if ( ! $customer_id || ! get_userdata( $customer_id ) ) { return false; }
		$started = self::begin_wallet_transaction( $customer_id );
		if ( is_wp_error( $started ) ) { RR_Background::schedule( 'rr_mature_customer_points', 60, array( $customer_id ) ); return $started; }
		$result = RR_Point_Lots::mature_locked( $customer_id, current_time( 'mysql', true ) );
		$indexed = is_wp_error( $result ) ? $result : RR_Wallet_Balances::delta_locked( $customer_id, array( 'balance' => $result['net'], 'pending' => -$result['net'] ) );
		$finished = self::finish_wallet_transaction( ! is_wp_error( $indexed ) );
		if ( is_wp_error( $indexed ) || is_wp_error( $finished ) ) { RR_Background::schedule( 'rr_mature_customer_points', 60, array( $customer_id ) ); return is_wp_error( $indexed ) ? $indexed : $finished; }
		if ( $result['more'] ) { RR_Background::schedule( 'rr_mature_customer_points', 5, array( $customer_id ) ); }
		if ( $result['changed'] ) { do_action( 'rr_pending_points_matured', $customer_id, $result['changed'] ); }
		// Expired-after-availability lots are not spendable while the bounded expiry worker catches up.
		RR_Point_Lots::expire_due_for_customers( array( $customer_id ) );
		return $result;
	}
	public static function mature_pending_points() {
		global $wpdb; $source = RR_Database::table( 'ledger' );
		$now = current_time( 'mysql', true ); $after = absint( get_option( 'rr_maturity_after_customer', 0 ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT s.customer_id FROM $source s INNER JOIN {$wpdb->users} u ON u.ID=s.customer_id WHERE s.state='pending' AND s.amount>0 AND s.available_at IS NOT NULL AND s.available_at<=%s AND s.customer_id>%d ORDER BY s.customer_id LIMIT 10", $now, $after ) );
		if ( ! $ids && $after ) { update_option( 'rr_maturity_after_customer', 0, false ); $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT s.customer_id FROM $source s INNER JOIN {$wpdb->users} u ON u.ID=s.customer_id WHERE s.state='pending' AND s.amount>0 AND s.available_at IS NOT NULL AND s.available_at<=%s ORDER BY s.customer_id LIMIT 10", $now ) ); }
		foreach ( (array) $ids as $customer_id ) { self::mature_customer_points( (int) $customer_id ); }
		if ( $ids ) { update_option( 'rr_maturity_after_customer', (int) end( $ids ), false ); }
		return count( (array) $ids );
	}

	public static function expire_reservations() {
		global $wpdb;
		if ( self::$reservation_sweeping ) { return; }
		$lock = 'rr_reservations_' . substr( md5( $wpdb->prefix ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return; }
		self::$reservation_sweeping = true;
		try {
			$table = RR_Database::table( 'redemptions' );
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, 'rr_reservation_sweep_cursor' ) );
			if ( $wpdb->last_error ) { return; }
			$cursor = null === $raw ? array() : ( is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : null );
			if ( ! is_array( $cursor ) ) { return; }
			$valid = isset( $cursor['updated_at'], $cursor['id'] ) && is_string( $cursor['updated_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $cursor['updated_at'] ) && null !== self::validated_integer( $cursor['id'], 1, PHP_INT_MAX );
			if ( $cursor && ! $valid ) { return; }
			$after = $valid ? $wpdb->prepare( ' AND (updated_at>%s OR (updated_at=%s AND id>%d))', $cursor['updated_at'], $cursor['updated_at'], $cursor['id'] ) : '';
			// status_updated includes the InnoDB primary-key suffix: read at most 100 candidates,
			// then check creation age. Blocked/paid/new rows cannot monopolize the first page.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,order_id,created_at,updated_at FROM %i FORCE INDEX(status_updated) WHERE status=%s$after ORDER BY updated_at,id LIMIT 100", $table, 'reserved' ) );
			if ( $wpdb->last_error ) { return; }
			if ( ! $rows && $valid ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,order_id,created_at,updated_at FROM %i FORCE INDEX(status_updated) WHERE status=%s ORDER BY updated_at,id LIMIT 100', $table, 'reserved' ) );
				if ( $wpdb->last_error ) { return; }
			}
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - 1800 );
			foreach ( (array) $rows as $row ) {
				if ( $row->created_at >= $cutoff ) { continue; }
				$order = wc_get_order( $row->order_id );
				if ( $order && ! $order->is_paid() && ! $order->get_date_paid() && ! $order->get_meta( '_rr_confirmation_waiting' ) && in_array( $order->get_status(), array( 'pending', 'failed', 'cancelled', 'on-hold' ), true ) ) {
					self::release_redemption( $row->order_id, 'reservation_timeout' );
				}
			}
			$last = $rows ? end( $rows ) : null;
			$next = maybe_serialize( $last ? array( 'updated_at' => $last->updated_at, 'id' => (int) $last->id ) : array() );
			$stored = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name,option_value,autoload) VALUES(%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'", $wpdb->options, 'rr_reservation_sweep_cursor', $next ) );
			if ( false !== $stored ) { wp_cache_delete( 'rr_reservation_sweep_cursor', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' ); }
		} finally { self::$reservation_sweeping = false; $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	public static function expire_order_reservation( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$table = RR_Database::table( 'redemptions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status,created_at FROM %i WHERE order_id = %d", $table, $order_id ) );
		if ( ! $row || 'reserved' !== $row->status || strtotime( $row->created_at . ' UTC' ) > time() - 1800 ) { return; }
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->is_paid() && ! $order->get_date_paid() && ! $order->get_meta( '_rr_confirmation_waiting' ) && in_array( $order->get_status(), array( 'pending', 'failed', 'cancelled', 'on-hold' ), true ) ) {
			self::release_redemption( $order_id, 'reservation_timeout' );
		}
	}

	public static function transaction_id_by_key( $key ) {
		global $wpdb;
		$table = RR_Database::table( 'ledger' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE idempotency_key = %s", $table, sanitize_text_field( $key ) ) );
	}

	public static function positive_order_transaction( $order_id, $type ) {
		global $wpdb;
		$table = RR_Database::table( 'ledger' );
		$sql = $wpdb->prepare( 'SELECT id,customer_id,amount,referral_id,meta,state,initial_state,available_at,rule_version FROM %i WHERE order_id = %d AND transaction_type = %s AND amount > 0 ORDER BY id ASC LIMIT 1', $table, absint( $order_id ), sanitize_key( $type ) );
		if ( self::$transactions ) { $sql .= ' FOR UPDATE'; }
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values and identifier are prepared above; the optional suffix is a fixed SQL literal.
		return $wpdb->get_row( $sql );
	}

	public static function reversed_amount( $original_transaction_id ) {
		global $wpdb;
		$table = RR_Database::table( 'ledger' );
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM %i WHERE reference_id = %d AND transaction_type = %s AND amount < 0", $table, absint( $original_transaction_id ), 'refund_reversal' ) ) );
	}
}
