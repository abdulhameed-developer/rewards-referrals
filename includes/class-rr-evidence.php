<?php
/** Read-only operational evidence. Never repairs or rewrites reward history. */
defined( 'ABSPATH' ) || exit;

final class RR_Evidence {
	public static function init() {
		add_action( 'admin_post_rr_diagnostics', array( __CLASS__, 'download' ) );
	}

	public static function input( $key ) {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? substr( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ), 0, 100 ) : '';
	}

	private static function url( $view, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'rewards-referrals-insights', 'tab' => 'tools', 'rr_view' => $view ), $args ), admin_url( 'admin.php' ) );
	}

	/** Return false for the original diagnostics screen, true for a rendered evidence view. */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return true; }
		$view = self::input( 'rr_view' );
		$views = array( '' => __( 'System & configuration', 'rewards-referrals' ), 'audit' => __( 'Audit history', 'rewards-referrals' ), 'rules' => __( 'Compare rules', 'rewards-referrals' ), 'wallet' => __( 'Wallet integrity', 'rewards-referrals' ) );
		if ( ! isset( $views[ $view ] ) ) { $view = ''; }
		echo '<nav class="rr-evidence-nav" aria-label="' . esc_attr__( 'Diagnostic tools', 'rewards-referrals' ) . '">';
		foreach ( $views as $key => $label ) {
			echo '<a class="rr-button ' . ( $view === $key ? 'rr-button-primary' : 'rr-button-light' ) . '" data-rr-nav href="' . esc_url( self::url( $key ) ) . '"' . ( $view === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a> ';
		}
		echo '</nav>';
		if ( '' === $view ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=rr_diagnostics' ), 'rr_diagnostics' );
			echo '<section class="rr-card rr-setting-card rr-spaced"><h3>' . esc_html__( 'Share a safe support report', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Download software versions, storage health and scheduler status. The report excludes customer identities, site addresses, settings, referral codes, license keys and credentials. Review it before sharing.', 'rewards-referrals' ) . '</p><a class="rr-button rr-button-light" href="' . esc_url( $url ) . '">' . esc_html__( 'Download diagnostic JSON', 'rewards-referrals' ) . '</a></section>';
			return false;
		}
		echo '<section class="rr-card rr-setting-card rr-spaced">';
		if ( 'audit' === $view ) { self::audit_view(); }
		elseif ( 'rules' === $view ) { self::rules_view(); }
		else { self::wallet_view(); }
		echo '</section>';
		return true;
	}

	private static function form( $view ) {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="rr-evidence-filter"><input type="hidden" name="page" value="rewards-referrals-insights"><input type="hidden" name="tab" value="tools"><input type="hidden" name="rr_view" value="' . esc_attr( $view ) . '">';
	}

	/** Keyset pagination avoids reading/skipping every earlier audit row. */
	public static function audit_rows( $before = 0, $action = '', $actor = 0 ) {
		global $wpdb;
		$table = RR_Database::table( 'audit' );
		$where = ' WHERE 1=1';
		if ( $before > 0 ) { $where .= $wpdb->prepare( ' AND id < %d', $before ); }
		if ( '' !== $action ) { $where .= $wpdb->prepare( ' AND action = %s', substr( sanitize_key( $action ), 0, 48 ) ); }
		if ( $actor > 0 ) { $where .= $wpdb->prepare( ' AND actor_id = %d', $actor ); }
		// Equality filters precede the cursor in these schema-10 indexes. An explicit
		// choice avoids a low-cardinality single-column plan sorting the entire event.
		$index = '' !== $action ? ( $actor > 0 ? 'action_actor_cursor' : 'action' ) : ( $actor > 0 ? 'actor_cursor' : 'PRIMARY' );
		$rows = $wpdb->get_results( "SELECT id,actor_id,action,subject_id,created_at FROM $table FORCE INDEX ($index)$where ORDER BY id DESC LIMIT 26", ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { return new WP_Error( 'rr_audit_read', __( 'Audit history is temporarily unavailable. Please try again shortly.', 'rewards-referrals' ) ); }
		return $rows;
	}

	private static function audit_view() {
		$action = sanitize_key( self::input( 'event' ) ); $actor = absint( self::input( 'actor' ) );
		$rows = self::audit_rows( absint( self::input( 'before' ) ), $action, $actor );
		echo '<h3>' . esc_html__( 'Administrative audit history', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Review recorded changes and their actor. System events use actor 0. This history is read-only; it is not a complete log of every WordPress action.', 'rewards-referrals' ) . '</p>';
		self::form( 'audit' );
		echo '<label>' . esc_html__( 'Event code (optional)', 'rewards-referrals' ) . '<input class="rr-control" name="event" maxlength="48" placeholder="points_adjusted" value="' . esc_attr( $action ) . '"></label><label>' . esc_html__( 'Actor ID (optional)', 'rewards-referrals' ) . '<input class="rr-control" type="number" min="1" name="actor" value="' . ( $actor ? esc_attr( $actor ) : '' ) . '"></label><button class="rr-button rr-button-primary">' . esc_html__( 'Filter history', 'rewards-referrals' ) . '</button></form>';
		echo '<div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Event', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Actor', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Subject ID', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Date (UTC)', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		if ( is_wp_error( $rows ) ) { echo '<tr><td colspan="4" role="status">' . esc_html( $rows->get_error_message() ) . '</td></tr></tbody></table></div>'; return; }
		foreach ( array_slice( (array) $rows, 0, 25 ) as $row ) {
			echo '<tr><td>#' . esc_html( $row['id'] ) . ' · ' . esc_html( $row['action'] ) . '</td><td>' . esc_html( $row['actor_id'] ) . '</td><td>' . esc_html( $row['subject_id'] ) . '</td><td>' . esc_html( $row['created_at'] ) . '</td></tr>';
		}
		if ( ! $rows ) { echo '<tr><td colspan="4">' . esc_html__( 'No recorded events match these filters.', 'rewards-referrals' ) . '</td></tr>'; }
		echo '</tbody></table></div><p><a data-rr-nav href="' . esc_url( self::url( 'audit', array( 'event' => $action, 'actor' => $actor ) ) ) . '">' . esc_html__( 'Newest events', 'rewards-referrals' ) . '</a> ';
		if ( count( (array) $rows ) > 25 ) { echo '<a class="rr-button rr-button-light" data-rr-nav href="' . esc_url( self::url( 'audit', array( 'before' => $rows[24]['id'], 'event' => $action, 'actor' => $actor ) ) ) . '">' . esc_html__( 'Older events', 'rewards-referrals' ) . '</a>'; }
		echo '</p>';
	}

	public static function rule_diff( $from, $to ) {
		global $wpdb;
		$table = RR_Database::table( 'rules' ); $snapshots = array();
		foreach ( array( $from, $to ) as $version ) {
			$json = $wpdb->get_var( $wpdb->prepare( "SELECT settings FROM %i WHERE version=%d", $table, $version ) );
			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) { return new WP_Error( 'rr_snapshot_missing', __( 'Both saved rule versions must exist.', 'rewards-referrals' ) ); }
			$snapshots[] = $data;
		}
		$diff = array();
		foreach ( array_unique( array_merge( array_keys( $snapshots[0] ), array_keys( $snapshots[1] ) ) ) as $key ) {
			$a = $snapshots[0][ $key ] ?? null; $b = $snapshots[1][ $key ] ?? null;
			if ( $a !== $b ) { $diff[ $key ] = array( 'before' => $a, 'after' => $b ); }
		}
		return $diff;
	}

	private static function rules_view() {
		$current = absint( get_option( 'br_rules_version', 1 ) );
		$from = absint( self::input( 'version_from' ) ) ?: max( 1, $current - 1 );
		$to = absint( self::input( 'version_to' ) ) ?: $current;
		echo '<h3>' . esc_html__( 'Compare saved program rules', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Compare immutable saved versions. This does not save settings, recalculate points or change historical awards.', 'rewards-referrals' ) . '</p>';
		self::form( 'rules' );
		foreach ( array( 'version_from' => $from, 'version_to' => $to ) as $key => $value ) {
			echo '<label>' . esc_html( 'version_from' === $key ? __( 'Earlier version', 'rewards-referrals' ) : __( 'Later version', 'rewards-referrals' ) ) . '<input class="rr-control" type="number" min="1" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" required></label>';
		}
		echo '<button class="rr-button rr-button-primary">' . esc_html__( 'Compare', 'rewards-referrals' ) . '</button></form>';
		$diff = self::rule_diff( $from, $to );
		if ( is_wp_error( $diff ) ) { echo '<p role="status">' . esc_html( $diff->get_error_message() ) . '</p>'; return; }
		if ( ! $diff ) { echo '<p role="status">' . esc_html__( 'These versions have identical saved settings.', 'rewards-referrals' ) . '</p>'; return; }
		echo '<div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Setting', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Earlier', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Later', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		$fields = RR_Rules::fields();
		foreach ( $diff as $key => $values ) {
			echo '<tr><th>' . esc_html( $fields[ $key ]['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) ) . '</th>';
			foreach ( $values as $value ) { echo '<td>' . esc_html( null === $value ? __( 'Not set', 'rewards-referrals' ) : ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) ) . '</td>'; }
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/** One customer, one consistent read-only snapshot. No expiry, initialization or repair side effects. */
	public static function reconcile( $customer ) {
		global $wpdb;
		$customer = RR_Ledger::validated_integer( $customer, 1, PHP_INT_MAX );
		if ( ! $customer || ! get_userdata( $customer ) ) { return new WP_Error( 'rr_customer', __( 'Choose an existing customer.', 'rewards-referrals' ) ); }
		$ledger = RR_Database::table( 'ledger' ); $lots = RR_Database::table( 'point_lots' );
		$wallets = RR_Database::table( 'point_wallets' ); $allocations = RR_Database::table( 'point_allocations' );
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' ) ) {
			return new WP_Error( 'rr_read_snapshot', __( 'A consistent read could not be started. Retry outside another transaction.', 'rewards-referrals' ) );
		}
		try {
			$wallet = $wpdb->get_row( $wpdb->prepare( "SELECT debt FROM %i WHERE customer_id=%d", $wallets, $customer ) );
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_read_failed', __( 'The wallet evidence could not be read.', 'rewards-referrals' ) ); }
			if ( ! $wallet ) { return new WP_Error( 'rr_uninitialized', __( 'This wallet has no lot-accounting snapshot yet. No history was changed. It will initialize during a normal accounting operation.', 'rewards-referrals' ) ); }
			$l = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN state IN ('available','reserved','spent','adjusted','expired') THEN amount ELSE 0 END),0) AS net, COALESCE(SUM(CASE WHEN state='pending' THEN amount ELSE 0 END),0) AS pending, COALESCE(SUM(CASE WHEN state='reserved' AND transaction_type='redemption_reservation' THEN -amount ELSE 0 END),0) AS held FROM %i WHERE customer_id=%d", $ledger, $customer ) );
			$p = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN status='available' THEN remaining ELSE 0 END),0) AS available, COALESCE(SUM(CASE WHEN status='pending' THEN remaining ELSE 0 END),0) AS pending FROM %i WHERE customer_id=%d", $lots, $customer ) );
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount-returned),0) FROM %i WHERE customer_id=%d AND status='held'", $allocations, $customer ) );
			if ( ! $l || ! $p || null === $held || $wpdb->last_error ) { return new WP_Error( 'rr_read_failed', __( 'The accounting evidence could not be read completely.', 'rewards-referrals' ) ); }
			$checks = array( 'spendable_net' => array( 'ledger' => (int) $l->net, 'provenance' => (int) $p->available - (int) $wallet->debt ), 'pending' => array( 'ledger' => (int) $l->pending, 'provenance' => (int) $p->pending ), 'reserved' => array( 'ledger' => (int) $l->held, 'provenance' => (int) $held ) );
			$projection = RR_Wallet_Balances::locked( $customer );
			if ( ! $projection ) { return new WP_Error( 'rr_projection_missing', __( 'The wallet balance index is unavailable. No history was changed.', 'rewards-referrals' ) ); }
			$checks['indexed_spendable_net'] = array( 'ledger' => (int) $l->net, 'provenance' => (int) $projection['balance'] );
			$checks['indexed_pending'] = array( 'ledger' => (int) $l->pending, 'provenance' => (int) $projection['pending'] );
			$checks['indexed_reserved'] = array( 'ledger' => (int) $l->held, 'provenance' => (int) $projection['reserved'] );
			foreach ( $checks as &$check ) { $check['matches'] = $check['ledger'] === $check['provenance']; }
			unset( $check );
			return $checks;
		} finally { $wpdb->query( 'COMMIT' ); }
	}

	private static function wallet_view() {
		echo '<h3>' . esc_html__( 'Check one wallet’s integrity', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Compare the recorded ledger against unspent credit lots, debt and reservation allocations at one point in time. This check never repairs balances or runs expiry. Matching totals are a limited consistency check, not a complete financial audit.', 'rewards-referrals' ) . '</p>';
		self::form( 'wallet' );
		$customer = absint( self::input( 'customer' ) );
		echo '<label>' . esc_html__( 'Customer ID', 'rewards-referrals' ) . '<input class="rr-control" name="customer" type="number" min="1" required value="' . ( $customer ? esc_attr( $customer ) : '' ) . '"></label><button class="rr-button rr-button-primary">' . esc_html__( 'Check wallet', 'rewards-referrals' ) . '</button></form>';
		if ( ! $customer ) { return; }
		RR_Wallet_Rebuild::render( $customer );
		RR_Legacy_Lot_Migration::render( $customer );
		$result = self::reconcile( $customer );
		if ( is_wp_error( $result ) ) { echo '<p role="status">' . esc_html( $result->get_error_message() ) . '</p>'; return; }
		echo '<div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Balance component', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Ledger', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Provenance', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Result', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		foreach ( $result as $key => $check ) { echo '<tr><th>' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '</th><td>' . esc_html( $check['ledger'] ) . '</td><td>' . esc_html( $check['provenance'] ) . '</td><td>' . esc_html( $check['matches'] ? __( 'Matches', 'rewards-referrals' ) : __( 'Mismatch — investigate before adjusting', 'rewards-referrals' ) ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}

	public static function diagnostics() {
		$health = RR_Operations::health();
		// Explicit allowlist: never serialize arbitrary options, server paths or user data.
		$allowed = array( 'WordPress', 'WooCommerce', 'PHP', 'Schema version', 'HPOS', 'Notification failures', 'WP scheduled maintenance' );
		$out = array( 'plugin_version' => REWARDS_REFERRALS_VERSION, 'generated_utc' => gmdate( 'c' ) );
		foreach ( $health as $key => $value ) { if ( in_array( $key, $allowed, true ) || str_ends_with( $key, ' storage' ) ) { $out[ $key ] = $value; } }
		return $out;
	}

	public static function download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_diagnostics' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rewards-diagnostics.json"' );
		echo wp_json_encode( self::diagnostics(), JSON_PRETTY_PRINT );
		exit;
	}
}
