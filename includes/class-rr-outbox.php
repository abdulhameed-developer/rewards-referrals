<?php
/** Durable internal event delivery. Mail acceptance is not proof of recipient delivery. */
defined( 'ABSPATH' ) || exit;
final class RR_Outbox {
	private const MAX_ATTEMPTS = 4;
	public static function init() {
		add_action( 'rr_outbox_worker', array( __CLASS__, 'work' ) );
		add_action( 'admin_post_rr_outbox_retry', array( __CLASS__, 'retry' ) );
		if ( ! wp_next_scheduled( 'rr_outbox_worker' ) ) { wp_schedule_event( time() + 60, 'hourly', 'rr_outbox_worker' ); }
		if ( ! get_option( 'rr_outbox_retention_after' ) ) { add_option( 'rr_outbox_retention_after', time() + 90 * DAY_IN_SECONDS, '', false ); }
	}
	private static function settings() { return wp_parse_args( get_option( 'rr_notifications', array() ), RR_Operations::notification_defaults() ); }
	/** Invoked before the source ledger transaction commits. No address, message or credentials are stored. */
	public static function stage_locked( $id, $entry ) {
		global $wpdb; $s = self::settings();
		if ( 'yes' !== $s['enabled'] || ! in_array( $entry['transaction_type'], array( 'purchase_points','referral_commission','redemption_debit','redemption_restore' ), true ) || 'yes' !== ( $s[ $entry['transaction_type'] ] ?? 'no' ) ) { return true; }
		$table = RR_Database::table( 'notification_outbox' );
		if ( $id <= (int) get_option( 'rr_outbox_floor', 0 ) ) { return true; }
		$legacy = get_option( 'rr_notified_' . $id, false );
		$now = current_time( 'mysql', true );
		$next = gmdate( 'Y-m-d H:i:s', max( time() + 15, ! empty( $entry['available_at'] ) ? strtotime( $entry['available_at'] . ' UTC' ) : 0 ) );
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i(event_id,status,attempts,next_at,created_at,updated_at,error_code) VALUES(%d,%s,0,%s,%s,%s,%s)", $table, $id, $legacy ? 'accepted' : 'queued', $next, $now, $now, $legacy ? 'legacy_marker' : '' ) );
		return false !== $result ? true : new WP_Error( 'rr_outbox_write', __( 'The rewards notification could not be recorded safely.', 'rewards-referrals' ) );
	}
	/** Compatibility for already scheduled legacy callbacks; no caller can choose a recipient or message. */
	public static function adopt( $id ) {
		global $wpdb; $ledger = RR_Database::table( 'ledger' );
		$id = RR_Ledger::validated_integer( $id, 1, PHP_INT_MAX ); if ( ! $id ) { return; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id=%d", $ledger, $id ), ARRAY_A );
		if ( ! $row || strtotime( $row['created_at'] . ' UTC' ) < time() - 90 * DAY_IN_SECONDS ) { return; }
		$started = RR_Ledger::begin_wallet_transaction( (int) $row['customer_id'] ); if ( is_wp_error( $started ) ) { return; }
		$r = RR_Commission_Index::sync_locked( $id );
		if ( ! is_wp_error( $r ) ) { $r = self::stage_locked( $id, $row ); }
		RR_Ledger::finish_wallet_transaction( ! is_wp_error( $r ) );
		RR_Background::schedule( 'rr_outbox_worker' );
	}
	public static function wake( $id = 0, $entry = array() ) { RR_Background::schedule( 'rr_outbox_worker', 15 ); }
	public static function claim( $id ) {
		global $wpdb; $table = RR_Database::table( 'notification_outbox' ); $now = current_time( 'mysql', true ); $token = wp_generate_uuid4();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='sending',attempts=attempts+1,claim_token=%s,updated_at=%s WHERE event_id=%d AND status IN ('queued','retry') AND next_at<=%s AND attempts<%d", $table, $token, $now, $id, $now, self::MAX_ATTEMPTS ) );
		return 1 === $n ? $token : false;
	}
	private static function finish( $id, $token, $status, $error = '', $delay = 0, $undo_attempt = false ) {
		global $wpdb; $table = RR_Database::table( 'notification_outbox' );
		$attempt = $undo_attempt ? ',attempts=GREATEST(0,attempts-1)' : '';
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE $table SET status=%s,error_code=%s,claim_token='',updated_at=%s,next_at=%s$attempt WHERE event_id=%d AND status='sending' AND claim_token=%s", $status, $error, current_time( 'mysql', true ), gmdate( 'Y-m-d H:i:s', time() + $delay ), $id, $token ) );
	}
	private static function fail( $id, $token, $error, $delay = 60 ) {
		global $wpdb; $table = RR_Database::table( 'notification_outbox' );
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM %i WHERE event_id=%d AND claim_token=%s", $table, $id, $token ) );
		return self::finish( $id, $token, $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry', $error, $delay );
	}
	public static function deliver( $id ) {
		$token = self::claim( $id ); if ( ! $token ) { return false; }
		global $wpdb; $table = RR_Database::table( 'notification_outbox' ); $ledger = RR_Database::table( 'ledger' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT customer_id,amount,transaction_type,state,available_at FROM %i WHERE id=%d", $ledger, $id ) );
		if ( $wpdb->last_error ) { self::fail( $id, $token, 'source_unavailable' ); return false; }
		$s = self::settings();
		if ( ! $row || 'yes' !== $s['enabled'] || 'yes' !== ( $s[ $row->transaction_type ] ?? 'no' ) || 'reversed' === $row->state ) { self::finish( $id, $token, 'skipped', 'event_disabled_or_reversed' ); return false; }
		if ( 'pending' === $row->state ) { self::finish( $id, $token, 'queued', 'awaiting_availability', max( 60, strtotime( $row->available_at . ' UTC' ) - time() ), true ); return false; }
		if ( in_array( $row->transaction_type, array( 'purchase_points','referral_commission' ), true ) ) {
			$facts = RR_Database::table( 'credit_facts' );
			$net = $wpdb->get_var( $wpdb->prepare( "SELECT points FROM %i WHERE credit_id=%d", $facts, $id ) );
			if ( null === $net || $wpdb->last_error ) { if ( ! RR_Commission_Index::ready() ) { self::finish( $id, $token, 'queued', 'awaiting_history_index', 60, true ); } else { self::fail( $id, $token, 'source_unavailable' ); } return false; }
			if ( (int) $net <= 0 ) { self::finish( $id, $token, 'skipped', 'award_fully_refunded' ); return false; }
			$row->amount = (int) $net;
		}
		$user = get_userdata( $row->customer_id );
		if ( ! $user || ! is_email( $user->user_email ) ) { self::finish( $id, $token, 'skipped', 'recipient_unavailable' ); return false; }
		$balance = RR_Ledger::balances( $row->customer_id );
		if ( ! empty( $balance['unavailable'] ) ) { self::finish( $id, $token, 'queued', 'awaiting_wallet_maintenance', 60, true ); return false; }
		$vars = array( '{site}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), '{name}' => $user->display_name, '{activity}' => ucwords( str_replace( '_', ' ', $row->transaction_type ) ), '{points}' => number_format_i18n( (int) $row->amount ), '{balance}' => number_format_i18n( $balance['available'] ), '{account}' => wc_get_account_endpoint_url( 'rewards' ) );
		if ( ! is_string( $s['subject'] ) || ! is_string( $s['body'] ) || ! trim( $s['subject'] ) || ! trim( $s['body'] ) || strlen( $s['subject'] ) > 500 || strlen( $s['body'] ) > 20000 ) { self::finish( $id, $token, 'failed', 'template_invalid' ); return false; }
		$subject = sanitize_text_field( strtr( $s['subject'], $vars ) ); $error = 'mail_not_accepted';
		try { $sent = wp_mail( $user->user_email, $subject, strtr( $s['body'], $vars ) ); }
		catch ( Throwable $e ) { self::finish( $id, $token, 'uncertain', 'transport_exception' ); return false; }
		if ( $sent ) { self::finish( $id, $token, 'accepted' ); return true; }
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM %i WHERE event_id=%d", $table, $id ) );
		self::finish( $id, $token, $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retry', $error, 60 * ( 5 ** max( 0, $attempts - 1 ) ) );
		return false;
	}
	public static function work() {
		global $wpdb; $table = RR_Database::table( 'notification_outbox' ); $now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='skipped',error_code='event_retention_expired',updated_at=%s WHERE status IN ('queued','retry') AND created_at<%s LIMIT 100", $table, $now, gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		// A crash could have happened after SMTP accepted mail: do not automatically resend an ambiguous claim.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='uncertain',claim_token='',error_code='worker_interrupted',updated_at=%s WHERE status='sending' AND updated_at<%s LIMIT 100", $table, $now, gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM %i WHERE status IN ('queued','retry') AND next_at<=%s AND attempts<%d ORDER BY next_at,event_id LIMIT 10", $table, $now, self::MAX_ATTEMPTS ) );
		foreach ( $ids as $id ) { self::deliver( (int) $id ); }
		self::migrate_legacy(); $cleaned = self::cleanup();
		if ( $cleaned >= 200 ) { RR_Background::schedule( 'rr_outbox_worker', 60 ); }
		if ( $wpdb->get_var( "SELECT event_id FROM $table WHERE status IN ('queued','retry') LIMIT 1" ) ) { RR_Background::schedule( 'rr_outbox_worker', 60 ); }
		return count( $ids );
	}
	public static function migrate_legacy() {
		global $wpdb; $table = RR_Database::table( 'notification_outbox' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name LIMIT 100", $wpdb->options, $wpdb->esc_like( 'rr_notified_' ) . '%' ) );
		foreach ( $rows as $row ) {
			$id = RR_Ledger::validated_integer( substr( $row->option_name, strlen( 'rr_notified_' ) ), 1, PHP_INT_MAX );
			$ledger = RR_Database::table( 'ledger' );
			if ( ! $id || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id=%d", $ledger, $id ) ) ) { if ( ! $wpdb->last_error ) { delete_option( $row->option_name ); } continue; }
			// INSERT IGNORE suppresses duplicate legacy imports; marker is deleted only after the durable row exists.
			$now = current_time( 'mysql', true );
			$r = $wpdb->query( $wpdb->prepare( "INSERT INTO %i(event_id,status,attempts,next_at,created_at,updated_at,error_code) VALUES(%d,'accepted',0,%s,%s,%s,'legacy_marker') ON DUPLICATE KEY UPDATE error_code=CASE WHEN status='sending' THEN 'legacy_delivery_overlap' WHEN status IN ('queued','retry','failed') THEN 'legacy_marker' ELSE error_code END,status=CASE WHEN status='sending' THEN 'uncertain' WHEN status IN ('queued','retry','failed') THEN 'accepted' ELSE status END", $table, $id, $now, $now, $now ) );
			if ( false !== $r ) { delete_option( $row->option_name ); }
		}
		if ( count( $rows ) === 100 ) { RR_Background::schedule( 'rr_outbox_worker', 60 ); }
	}
	public static function cleanup() {
		if ( time() < (int) get_option( 'rr_outbox_retention_after', PHP_INT_MAX ) ) { return 0; }
		global $wpdb; $table = RR_Database::table( 'notification_outbox' ); $lock = 'rr_outbox_gc_' . substr( md5( $wpdb->prefix ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return 0; }
		try {
			$ids = array();
			foreach ( array( 'accepted' => 90,'skipped' => 90,'failed' => 365,'uncertain' => 365 ) as $status => $days ) {
				$batch = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM %i FORCE INDEX (stale_jobs) WHERE status=%s AND updated_at<%s ORDER BY updated_at,event_id LIMIT 50", $table, $status, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
				if ( $wpdb->last_error ) { return 0; }
				$ids = array_merge( $ids, $batch );
			}
			if ( ! $ids ) { return 0; }
			$floor = max( (int) get_option( 'rr_outbox_floor', 0 ), max( array_map( 'intval', $ids ) ) );
			// Persist suppression floor before deleting tombstones; a crash can retain extra rows, never reopen old events.
			update_option( 'rr_outbox_floor', $floor, false );
			if ( (int) get_option( 'rr_outbox_floor', 0 ) < $floor ) { return 0; }
			$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			return $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE event_id IN ($marks) AND status IN ('accepted','skipped','failed','uncertain')", $ids ) );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	public static function retry() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_outbox_retry' );
		$id = isset( $_POST['event_id'] ) && is_scalar( $_POST['event_id'] ) ? RR_Ledger::validated_integer( wp_unslash( $_POST['event_id'] ), 1, PHP_INT_MAX ) : null;
		global $wpdb; $table = RR_Database::table( 'notification_outbox' );
		$uncertain = isset( $_POST['duplicate_risk'] ) && 'yes' === $_POST['duplicate_risk'];
		$status = $uncertain ? array( 'failed','uncertain' ) : array( 'failed' );
		if ( ! $id ) { wp_die( esc_html__( 'Choose a valid event.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); }
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE event_id=%d", $table, $id ) );
		if ( $wpdb->last_error ) { wp_die( esc_html__( 'The notification could not be read. No retry was requested. Please check database health and retry.', 'rewards-referrals' ), '', array( 'response' => 503, 'back_link' => true ) ); }
		if ( 'uncertain' === $current && ! $uncertain ) { wp_die( esc_html__( 'Review and acknowledge possible duplicate delivery before retrying.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); }
		$failed = false; $queued = false; $open = false; $prior_transaction = RR_Ledger::transaction_open();
		try {
			foreach ( array( $table, RR_Database::table( 'audit' ) ) as $table_name ) {
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table_name ) );
				if ( 'InnoDB' !== $engine ) { throw new RuntimeException( 'Unverified notification storage.' ); }
			}
			$started = RR_Ledger::begin_wallet_transaction( get_current_user_id() );
			if ( is_wp_error( $started ) ) { $failed = true; }
			else {
				$open = true;
				$marks = implode( ',', array_fill( 0, count( $status ), '%s' ) );
				$r = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='queued',attempts=0,claim_token='',error_code='',next_at=%s,updated_at=%s WHERE event_id=%d AND status IN ($marks)", current_time( 'mysql', true ), current_time( 'mysql', true ), $id, ...$status ) );
				if ( 1 === $r ) { RR_Admin::audit( 'notification_retry', $id, array( 'duplicate_risk_acknowledged' => $uncertain ) ); }
				$failed = false === $r || (bool) $wpdb->last_error;
				$done = RR_Ledger::finish_wallet_transaction( ! $failed );
				$open = false;
				$failed = $failed || is_wp_error( $done );
				$queued = ! $failed && 1 === $r;
			}
		} catch ( Throwable $error ) { $failed = true; }
		finally { if ( $open || ( ! $prior_transaction && RR_Ledger::transaction_open() ) ) { RR_Ledger::finish_wallet_transaction( false ); } }
		if ( $failed ) { wp_die( esc_html__( 'The notification retry could not be saved. No retry was queued. Please check database health and retry.', 'rewards-referrals' ), '', array( 'response' => 503, 'back_link' => true ) ); }
		if ( ! $queued ) { wp_die( esc_html__( 'This notification is no longer eligible for retry. Refresh the queue to review its current state.', 'rewards-referrals' ), '', array( 'response' => 409, 'back_link' => true ) ); }
		try { self::wake(); }
		catch ( Throwable $error ) { wp_die( esc_html__( 'The retry was queued and audited, but its immediate worker could not be scheduled. The regular notification worker can process it. Refresh the queue before taking further action.', 'rewards-referrals' ), '', array( 'response' => 503, 'back_link' => true ) ); }

		wp_safe_redirect( admin_url( 'admin.php?page=rewards-referrals-insights&tab=notifications' ) ); exit;
	}
	public static function counts() {
		global $wpdb; $table = RR_Database::table( 'notification_outbox' );
		return $wpdb->get_results( "SELECT status,COUNT(*) AS total FROM $table GROUP BY status", ARRAY_A );
	}
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		global $wpdb; $table = RR_Database::table( 'notification_outbox' );
		echo '<section class="rr-card rr-setting-card"><h3>' . esc_html__( 'Notification queue health', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Accepted means WordPress mail accepted the request, not confirmed inbox delivery. Interrupted sends require review before retrying because mail may already have been sent. Terminal accepted/skipped history is retained for 90 days; failed/uncertain records are retained for 365 days. Pending notifications expire after 90 days, including long-delayed availability.', 'rewards-referrals' ) . '</p><p>';
		foreach ( (array) self::counts() as $row ) { echo esc_html( ucfirst( $row['status'] ) . ': ' . $row['total'] ) . ' · '; }
		echo '</p><div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Event', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'State / attempts', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Safe error code', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Next attempt (UTC)', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Recovery', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		$states = array( '' => __( 'All states', 'rewards-referrals' ), 'queued' => __( 'Queued', 'rewards-referrals' ), 'retry' => __( 'Retrying', 'rewards-referrals' ), 'sending' => __( 'Sending', 'rewards-referrals' ), 'accepted' => __( 'Accepted by mail transport', 'rewards-referrals' ), 'failed' => __( 'Failed', 'rewards-referrals' ), 'uncertain' => __( 'Needs delivery review', 'rewards-referrals' ), 'skipped' => __( 'Skipped', 'rewards-referrals' ) );
		$filter = isset( $_GET['rr_mail_status'] ) && is_scalar( $_GET['rr_mail_status'] ) ? sanitize_key( wp_unslash( $_GET['rr_mail_status'] ) ) : '';
		if ( ! isset( $states[ $filter ] ) ) { $filter = ''; }
		$before = isset( $_GET['rr_mail_before'] ) && is_scalar( $_GET['rr_mail_before'] ) ? RR_Ledger::validated_integer( wp_unslash( $_GET['rr_mail_before'] ), 1, PHP_INT_MAX ) : null;
		$where = 'WHERE 1=1'; $index = 'PRIMARY';
		if ( $filter ) { $where .= $wpdb->prepare( ' AND status=%s', $filter ); $index = 'status_cursor'; }
		if ( $before ) { $where .= $wpdb->prepare( ' AND event_id<%d', $before ); }
		$rows = $wpdb->get_results( "SELECT event_id,status,attempts,error_code,next_at FROM $table FORCE INDEX ($index) $where ORDER BY event_id DESC LIMIT 26", ARRAY_A );

		if ( ! $rows ) { echo '<tr><td colspan="5">' . esc_html( $wpdb->last_error ? __( 'The notification queue could not be read. Check storage health before retrying.', 'rewards-referrals' ) : __( 'No notification events match this state.', 'rewards-referrals' ) ) . '</td></tr>'; }
		foreach ( array_slice( (array) $rows, 0, 25 ) as $row ) {
			echo '<tr><td>#' . esc_html( $row['event_id'] ) . '</td><td>' . esc_html( $row['status'] . ' / ' . $row['attempts'] ) . '</td><td>' . esc_html( $row['error_code'] ) . '</td><td>' . esc_html( $row['next_at'] ) . '</td><td>';
			if ( in_array( $row['status'], array( 'failed','uncertain' ), true ) ) {
				echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="rr_outbox_retry"><input type="hidden" name="event_id" value="' . esc_attr( $row['event_id'] ) . '">'; wp_nonce_field( 'rr_outbox_retry' );
				if ( 'uncertain' === $row['status'] ) { echo '<label><input type="checkbox" name="duplicate_risk" value="yes" required> ' . esc_html__( 'I reviewed this send and accept possible duplicate delivery.', 'rewards-referrals' ) . '</label>'; }
				echo '<button class="rr-button rr-button-light">' . esc_html__( 'Retry notification', 'rewards-referrals' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div><form method="get" class="rr-evidence-filter" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="rewards-referrals-insights"><input type="hidden" name="tab" value="notifications"><label>' . esc_html__( 'Queue state', 'rewards-referrals' ) . '<select class="rr-control" name="rr_mail_status">';
		foreach ( $states as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $filter, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><button class="rr-button rr-button-light">' . esc_html__( 'Filter queue', 'rewards-referrals' ) . '</button></form><nav aria-label="' . esc_attr__( 'Notification queue pages', 'rewards-referrals' ) . '">';
		$base = admin_url( 'admin.php?page=rewards-referrals-insights&tab=notifications' );
		echo '<a data-rr-nav href="' . esc_url( add_query_arg( 'rr_mail_status', $filter, $base ) ) . '">' . esc_html__( 'Newest events', 'rewards-referrals' ) . '</a> ';
		if ( count( (array) $rows ) > 25 ) { echo '<a class="rr-button rr-button-light" data-rr-nav href="' . esc_url( add_query_arg( array( 'rr_mail_status' => $filter, 'rr_mail_before' => $rows[24]['event_id'] ), $base ) ) . '">' . esc_html__( 'Older events', 'rewards-referrals' ) . '</a>'; }
		echo '</nav></section>';

	}
}
