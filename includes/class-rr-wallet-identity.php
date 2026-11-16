<?php
/** Durable wallet ownership. Numeric WordPress IDs alone do not identify an account incarnation. */
defined( 'ABSPATH' ) || exit;
final class RR_Wallet_Identity {
	private const TOKEN = 'rr_wallet_identity_token';
	private const STATE = 'rr_wallet_identity_state';
	private const QUARANTINED = '0000000000000000000000000000000000000000000000000000000000000000';
	private const SOURCES = array( array( 'wallet_locks','customer_id' ), array( 'ledger','customer_id' ), array( 'point_wallets','customer_id' ), array( 'point_lots','customer_id' ), array( 'point_allocations','customer_id' ), array( 'wallet_balances','customer_id' ), array( 'redemptions','customer_id' ), array( 'referral_codes','customer_id' ), array( 'referrals','customer_id' ), array( 'referrals','referrer_id' ), array( 'referral_history','customer_id' ), array( 'referral_history','referrer_id' ), array( 'recovery_events','customer_a' ), array( 'recovery_events','customer_b' ), array( 'credit_facts','customer_id' ), array( 'wallet_rebuilds','customer_id' ), array( 'legacy_lot_migrations','customer_id' ) );
	private static $maintenance_customer = 0;

	private static function token_key() { global $wpdb; return $wpdb->prefix . self::TOKEN; }

	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'registered' ), 0 );
		if ( ! self::ready() ) { RR_Background::schedule( 'rr_customer_index', 1 ); }
	}
	private static function error( $pending = false, $conflict = false ) {
		return new WP_Error( $pending ? 'rr_wallet_identity_pending' : 'rr_wallet_identity', $pending ? __( 'Rewards ownership verification is completing safely in the background. Please retry shortly.', 'rewards-referrals' ) : __( 'This rewards account needs an ownership review. Its earlier history has been preserved; contact the store administrator.', 'rewards-referrals' ), $conflict ? array( 'status' => 503, 'ownership_conflict' => true ) : array( 'status' => 503 ) );
	}
	private static function state() {
		global $wpdb;
		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, self::STATE ) );
		if ( $wpdb->last_error || ! is_string( $json ) ) { return null; }
		$state = json_decode( $json, true );
		return is_array( $state ) && 1 === ( $state['version'] ?? null ) ? $state : null;
	}
	public static function ready() { $state = self::state(); return $state && 'complete' === ( $state['status'] ?? '' ); }
	/** The trust baseline is captured atomically; it is never inferred from backdatable registration dates. */
	public static function start() {
		if ( self::state() ) { return true; }
		add_option( self::STATE, wp_json_encode( array( 'version' => 1, 'status' => 'snapshot_pending' ) ), '', false );
		return self::state() ? true : self::error( true );
	}
	private static function fingerprint( $user, $token ) { return hash( 'sha256', $user->ID . "\n" . $user->user_login . "\n" . $user->user_registered . "\n" . $token ); }
	private static function token( $customer, $create ) {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id LIMIT 2 FOR UPDATE', $wpdb->usermeta, $customer, self::token_key() ) );
		if ( $wpdb->last_error || count( $rows ) > 1 ) { return self::error(); }
		if ( ! $rows && $create ) {
			$token = wp_generate_uuid4();
			if ( 1 !== $wpdb->insert( $wpdb->usermeta, array( 'user_id' => $customer, 'meta_key' => self::token_key(), 'meta_value' => $token ) ) ) { return self::error(); }
			wp_cache_delete( $customer, 'user_meta' );
			return $token;
		}
		return isset( $rows[0] ) && is_string( $rows[0] ) && preg_match( '/^(?:[a-f0-9-]{36}|t[a-f0-9]{63})$/D', $rows[0] ) ? $rows[0] : self::error();
	}
	private static function history_exists( $customer ) {
		global $wpdb;
		foreach ( array_slice( self::SOURCES, 1 ) as $source ) {
			$table = RR_Database::table( $source[0] ); $column = $source[1];
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE %i=%d LIMIT 1', $column, $table, $column, $customer ) );
			if ( $wpdb->last_error ) { return self::error(); }
			if ( null !== $id ) { return true; }
		}
		// Global legacy usermeta is captured at the upgrade cutover. It can also belong to
		// another blog and is not itself proof of this site's post-cutover wallet history.
		return false;
	}
	/** Called inside RR_Ledger's sorted wallet lock, before projection, lot or financial work. */
	public static function locked( $customer ) {
		if ( self::$maintenance_customer === (int) $customer ) { return true; }
		if ( ! self::ready() ) { return self::error( true ); }
		return self::bind_locked( $customer );
	}
	private static function bind_locked( $customer ) {
		global $wpdb;
		$table = RR_Database::table( 'wallet_locks' );
		$binding = $wpdb->get_row( $wpdb->prepare( 'SELECT owner_signature FROM %i WHERE customer_id=%d FOR UPDATE', $table, $customer ) );
		$signature = $binding ? $binding->owner_signature : null;
		$user = $wpdb->get_row( $wpdb->prepare( 'SELECT ID,user_login,user_registered FROM %i WHERE ID=%d FOR UPDATE', $wpdb->users, $customer ) );
		if ( $wpdb->last_error || null === $signature ) { return self::error(); }
		if ( '' !== $signature ) {
			if ( self::QUARANTINED === $signature || ! $user ) { return self::error(); }
			$token = self::token( $customer, false );
			return ! is_wp_error( $token ) && hash_equals( $signature, self::fingerprint( $user, $token ) ) ? true : self::error();
		}
		$history = self::history_exists( $customer );
		if ( is_wp_error( $history ) ) { return $history; }
		$trusted = $user && ! $history;
		if ( ! $trusted ) {
			if ( 1 !== $wpdb->update( $table, array( 'owner_signature' => self::QUARANTINED ), array( 'customer_id' => $customer, 'owner_signature' => '' ) ) ) { return self::error(); }
			return self::error();
		}
		$token = self::token( $customer, true );
		if ( is_wp_error( $token ) ) { return $token; }
		return 1 === $wpdb->update( $table, array( 'owner_signature' => self::fingerprint( $user, $token ) ), array( 'customer_id' => $customer, 'owner_signature' => '' ) ) ? true : self::error();
	}
	/** Registration never trusts old numeric-ID history, including while the baseline is running. */
	public static function registered( $customer ) { self::maintain( (int) $customer ); }
	private static function maintain( $customer ) {
		global $wpdb;
		self::$maintenance_customer = $customer;
		try {
			$start = RR_Ledger::begin_wallet_transaction( $customer, false );
			if ( is_wp_error( $start ) ) { return false; }
			$result = self::bind_locked( $customer );
			// A quarantine is an ownership marker, not a financial mutation; persist it even when access is denied.
			$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT owner_signature FROM %i WHERE customer_id=%d FOR UPDATE', RR_Database::table( 'wallet_locks' ), $customer ) );
			$commit = ! $wpdb->last_error && is_string( $stored ) && '' !== $stored;
			$finish = RR_Ledger::finish_wallet_transaction( $commit );
			return $commit && ! is_wp_error( $finish );
		} finally { self::$maintenance_customer = 0; }
	}
	/** One atomic, indexed financial-identity cutover; interrupted attempts roll back and retry. */
	public static function work() {
		$state = self::state();
		if ( ! $state || 'complete' === $state['status'] ) { return 0; }
		global $wpdb;
		$lock = 'rr_identity_' . substr( md5( $wpdb->prefix ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return 0; }
		$previous = $wpdb->suppress_errors( true ); $started = false;
		try {
			$state = self::state();
			if ( ! $state || 'complete' === $state['status'] ) { return 0; }
			// Never implicitly commit a caller-owned transaction to take the trust snapshot.
			$probe = $wpdb->query( 'SAVEPOINT rr_identity_probe' );
			$external = false !== $probe && false !== $wpdb->query( 'RELEASE SAVEPOINT rr_identity_probe' );
			if ( false === $probe || $external || false === $wpdb->query( 'START TRANSACTION' ) ) { return 0; }
			$started = true;
			$tables = array( $wpdb->users, $wpdb->usermeta, $wpdb->options, RR_Database::table( 'wallet_locks' ) );
			foreach ( self::SOURCES as $source ) { $tables[] = RR_Database::table( $source[0] ); }
			$tables = array_values( array_unique( $tables ) );
			$marks = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
			$engines = $wpdb->get_col( $wpdb->prepare( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)", $tables ) );
			if ( $wpdb->last_error || count( $engines ) !== count( $tables ) || count( array_filter( $engines, static function ( $v ) { return 'InnoDB' === $v; } ) ) !== count( $tables ) ) { return 0; }
			$parts = array(); foreach ( self::SOURCES as $source ) { $table = RR_Database::table( $source[0] ); $column = $source[1]; $parts[] = "SELECT $column AS customer_id FROM $table"; }
			$legacy_users = $wpdb->usermeta;
			$parts[] = "SELECT user_id AS customer_id FROM $legacy_users WHERE meta_key IN ('_br_referral_code','_br_referrer_id','_rr_pending_referral')";
			$parts[] = "SELECT CAST(meta_value AS UNSIGNED) AS customer_id FROM $legacy_users WHERE meta_key='_br_referrer_id' AND meta_value REGEXP '^[1-9][0-9]{0,18}$' AND CAST(meta_value AS DECIMAL(20,0))<=9223372036854775807";
			$financial = implode( ' UNION ', $parts ); $owners = RR_Database::table( 'wallet_locks' );
			$cutover = current_time( 'mysql', true ); $seed = bin2hex( random_bytes( 32 ) );
			// INSERT...SELECT takes current locking reads of the core identity/meta rows. Missing owners
			// receive quarantine in this first statement; later same-ID insertions cannot change that decision.
			$capture = $wpdb->query( $wpdb->prepare( "INSERT INTO %i(customer_id,owner_signature,updated_at) SELECT f.customer_id,CASE WHEN u.ID IS NULL THEN %s ELSE CONCAT('t',SUBSTRING(SHA2(CONCAT(UUID(),%s,f.customer_id),256),1,63)) END,%s FROM ($financial) f LEFT JOIN %i u ON u.ID=f.customer_id LEFT JOIN %i m ON m.user_id=u.ID AND m.meta_key=%s WHERE f.customer_id>0 FOR UPDATE ON DUPLICATE KEY UPDATE owner_signature=IF(owner_signature='',VALUES(owner_signature),owner_signature)", $owners, self::QUARANTINED, $seed, $cutover, $wpdb->users, $wpdb->usermeta, self::token_key() ) );
			unset( $seed );
			if ( false === $capture ) { return 0; }
			$tokens = $wpdb->query( $wpdb->prepare( "INSERT INTO %i(user_id,meta_key,meta_value) SELECT u.ID,%s,l.owner_signature FROM %i l INNER JOIN %i u ON u.ID=l.customer_id LEFT JOIN %i m ON m.user_id=u.ID AND m.meta_key=%s WHERE l.owner_signature LIKE 't%%' AND m.umeta_id IS NULL", $wpdb->usermeta, self::token_key(), $owners, $wpdb->users, $wpdb->usermeta, self::token_key() ) );
			if ( false === $tokens ) { return 0; }
			$bound = $wpdb->query( $wpdb->prepare( "UPDATE %i l LEFT JOIN %i u ON u.ID=l.customer_id LEFT JOIN %i m ON m.user_id=u.ID AND m.meta_key=%s SET l.owner_signature=CASE WHEN u.ID IS NULL OR m.umeta_id IS NULL OR m.meta_value IS NULL OR m.meta_value NOT REGEXP '^(t[a-f0-9]{63}|[a-f0-9-]{36})$' OR (SELECT COUNT(*) FROM %i mm WHERE mm.user_id=u.ID AND mm.meta_key=%s)<>1 THEN %s ELSE SHA2(CONCAT(u.ID,CHAR(10),u.user_login,CHAR(10),u.user_registered,CHAR(10),m.meta_value),256) END WHERE l.owner_signature LIKE 't%%'", $owners, $wpdb->users, $wpdb->usermeta, self::token_key(), $wpdb->usermeta, self::token_key(), self::QUARANTINED ) );
			if ( false === $bound ) { return 0; }
			$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM %i WHERE owner_signature='' OR owner_signature LIKE 't%%' LIMIT 1", $owners ) );
			if ( $wpdb->last_error || null !== $remaining ) { return 0; }
			$complete = wp_json_encode( array( 'version' => 1, 'status' => 'complete', 'cutover' => $cutover, 'baseline' => 'atomic_financial_identity_snapshot' ) );
			if ( 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $wpdb->options, $complete, self::STATE ) ) || false === $wpdb->query( 'COMMIT' ) ) { return 0; }
			$started = false;
			wp_cache_delete( self::STATE, 'options' );
			return (int) $capture;
		} catch ( Throwable $error ) { return 0; }
		finally {
			if ( $started ) { $wpdb->query( 'ROLLBACK' ); }
			$wpdb->suppress_errors( $previous );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
	/** Checked bounded page read. Unknown new wallets bind under their normal financial lock. */
	public static function allowed( $customers ) {
		global $wpdb; $result = array();
		$ids = array_slice( array_unique( array_filter( array_map( 'absint', (array) $customers ) ) ), 0, 100 );
		$ready = self::ready();
		foreach ( $ids as $id ) { $result[ $id ] = self::error( ! $ready ); }
		if ( ! $ids || ! $ready ) { return $result; }
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) ); $locks = RR_Database::table( 'wallet_locks' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT u.ID,u.user_login,u.user_registered,l.owner_signature,m.meta_value AS token FROM %i u LEFT JOIN %i l ON l.customer_id=u.ID LEFT JOIN %i m ON m.user_id=u.ID AND m.meta_key=%s WHERE u.ID IN ($marks)", array_merge( array( $wpdb->users, $locks, $wpdb->usermeta, self::token_key() ), $ids ) ) );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { return $result; }
		// Only a successful complete identity read can certify a conflict. Storage/pending
		// uncertainty retains the unflagged error so maintenance must stop and retry.
		foreach ( $ids as $id ) { $result[ $id ] = self::error( false, true ); }
		$counts = array(); foreach ( $rows as $row ) { $id = (int) $row->ID; $counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1; }
		foreach ( $rows as $row ) {
			$id = (int) $row->ID;
			if ( 1 !== $counts[ $id ] ) { continue; }
			if ( '' === (string) $row->owner_signature ) {
				$start = RR_Ledger::begin_wallet_transaction( $id, false );
				if ( is_wp_error( $start ) ) { $result[ $id ] = $start; }
				else { $finish = RR_Ledger::finish_wallet_transaction( true ); $result[ $id ] = is_wp_error( $finish ) ? $finish : true; }
			} elseif ( is_string( $row->token ) && preg_match( '/^(?:[a-f0-9-]{36}|t[a-f0-9]{63})$/D', $row->token ) && self::QUARANTINED !== $row->owner_signature && hash_equals( $row->owner_signature, self::fingerprint( $row, $row->token ) ) ) { $result[ $id ] = true; }
		}
		return $result;
	}
	public static function check( $customer ) { $allowed = self::allowed( array( $customer ) ); return $allowed[ $customer ] ?? self::error(); }
}
