<?php
defined( 'ABSPATH' ) || exit;

final class RR_Referrals {
	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture' ) );
		add_action( 'rr_referral_index', array( __CLASS__, 'migrate_codes' ) );
		if ( ! wp_next_scheduled( 'rr_referral_index' ) ) { wp_schedule_event( time() + 60, 'hourly', 'rr_referral_index' ); }
		if ( ! get_option( 'rr_referral_index_ready', false ) ) { RR_Background::schedule( 'rr_referral_index' ); }
		add_action( 'user_register', array( __CLASS__, 'attach_on_registration' ), 20 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'attach_on_registration' ), 20 );
		add_action( 'woocommerce_register_form', array( __CLASS__, 'registration_field' ) );
		add_action( 'admin_post_rr_reassign_referral', array( __CLASS__, 'admin_reassign' ) );
		add_action( 'wp_login', array( __CLASS__, 'attach_existing_customer' ), 20, 2 );
	}

	public static function attach_existing_customer( $login, $user ) {
		if ( ! $user instanceof WP_User ) { return; }
		$policy = RR_Rules::checked_snapshot();
		if ( ! is_wp_error( $policy ) && 'unassigned' === $policy['settings']['existing_customer_policy'] ) { self::attach_on_registration( $user->ID ); }
	}

	public static function registration_field() {
		echo '<p class="form-row form-row-wide"><label for="br_referral_code">' . esc_html__( 'Referral code (optional)', 'rewards-referrals' ) . '</label><input type="text" class="input-text" id="br_referral_code" name="br_referral_code" maxlength="48" autocomplete="off" value=""></p>';
	}

	public static function capture() {
		if ( empty( $_GET['ref'] ) || ! is_scalar( $_GET['ref'] ) || strlen( (string) $_GET['ref'] ) > 48 ) { return; }
		if ( ! apply_filters( 'rr_referral_tracking_allowed', true ) ) { return; }
		$code = sanitize_text_field( wp_unslash( (string) $_GET['ref'] ) );
		$referrer = self::lookup_code( $code );
		if ( strlen( $code ) > 48 || ( ! is_wp_error( $referrer ) && ! $referrer && get_option( 'rr_referral_index_ready', false ) ) || ( ! is_wp_error( $referrer ) && $referrer && get_current_user_id() === $referrer ) ) { return; }
		$policy = RR_Rules::checked_snapshot();
		if ( is_wp_error( $policy ) ) { return; }
		$settings = $policy['settings'];
		$name = 'br_ref';
		$cookie_valid = false;
		if ( isset( $_COOKIE[ $name ] ) && is_scalar( $_COOKIE[ $name ] ) && strlen( (string) $_COOKIE[ $name ] ) <= 48 ) {
			$previous = self::lookup_code( sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) ) );
			$cookie_valid = is_wp_error( $previous ) || $previous || ! get_option( 'rr_referral_index_ready', false );
		}
		if ( $cookie_valid && 'first' === $settings['attribution'] ) { return; }
		$expiry = time() + DAY_IN_SECONDS * min( 365, absint( $settings['cookie_days'] ) );
		if ( $expiry <= time() ) { return; }
		if ( ! headers_sent() ) {
			setcookie( $name, $code, array( 'expires' => $expiry, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			$_COOKIE[ $name ] = $code;
		}
	}

	public static function attach_on_registration( $customer_id ) {
		if ( ! $customer_id || get_user_meta( $customer_id, '_br_referrer_id', true ) ) { return; }
		self::ensure_code( $customer_id );
		$cookie_code = isset( $_COOKIE['br_ref'] ) && is_scalar( $_COOKIE['br_ref'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_COOKIE['br_ref'] ) ) ) : '';
		$cookie_code = strlen( $cookie_code ) <= 48 ? $cookie_code : '';
		$post_code = isset( $_POST['br_referral_code'] ) && is_scalar( $_POST['br_referral_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['br_referral_code'] ) ) ) : '';
		$post_code = strlen( $post_code ) <= 48 ? $post_code : '';
		$policy = RR_Rules::checked_snapshot();
		if ( is_wp_error( $policy ) ) {
			// Save only bounded identity intent. Unknown policy must never invent approval.
			if ( $cookie_code || $post_code ) { self::defer_identity( $customer_id, $cookie_code, $post_code, null ); }
			return;
		}
		$settings = $policy['settings'];
		$cookie_referrer = self::lookup_code( $cookie_code );
		$post_referrer = self::lookup_code( $post_code );
		if ( is_wp_error( $cookie_referrer ) || is_wp_error( $post_referrer ) || ( ! get_option( 'rr_referral_index_ready', false ) && ( ( $cookie_code && ! $cookie_referrer ) || ( $post_code && ! $post_referrer ) ) ) ) {
			self::defer_identity( $customer_id, $cookie_code, $post_code, $settings );
			return;
		}
		$code = ( 'first' === $settings['attribution'] && $cookie_referrer ) ? $cookie_code : ( $post_referrer ? $post_code : $cookie_code );
		$referrer_id = self::lookup_code( $code );
		if ( is_wp_error( $referrer_id ) ) { self::defer_identity( $customer_id, $cookie_code, $post_code, $settings ); return; }
		if ( ! $referrer_id || $referrer_id === (int) $customer_id ) { return; }
		$approved = 'automatic' === $settings['approval'] ? 1 : 0;
		$result = self::write_relationship( absint( $customer_id ), absint( $referrer_id ), $code, $approved, false, 'Registration or eligible unassigned account attribution' );
		if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'rr_referral_cycle', 'rr_referral_invalid', 'rr_referral_depth' ), true ) ) { self::defer_identity( $customer_id, $cookie_code, $post_code, $settings ); }
	}

	/** Keep uncertain attribution blocked until an indexed identity read succeeds. */
	private static function defer_identity( $customer_id, $cookie, $post, $settings ) {
		$pending = array( 'cookie' => $cookie, 'post' => $post );
		if ( null === $settings ) { $pending['policy_unverified'] = 1; }
		else { $pending['attribution'] = $settings['attribution']; $pending['approved'] = 'automatic' === $settings['approval'] ? 1 : 0; }
		add_user_meta( $customer_id, '_rr_pending_referral', $pending, true );
		RR_Background::schedule( 'rr_referral_index' );
	}
	/** Authoritative pending marker read; core metadata caches can contain false empty values after a failed SELECT. */
	public static function pending_identity( $customer_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT umeta_id,meta_value FROM %i WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id LIMIT 1', $wpdb->usermeta, absint( $customer_id ), '_rr_pending_referral' ) );
		if ( $wpdb->last_error ) { return self::storage_error(); }
		if ( ! $row ) { return array( 'present' => false, 'value' => null ); }
		// Only decode the plugin's legacy WordPress array; stored objects must never be instantiated.
		$value = is_serialized( $row->meta_value ) ? @unserialize( $row->meta_value, array( 'allowed_classes' => false ) ) : $row->meta_value;
		return array( 'present' => true, 'value' => is_array( $value ) ? $value : null );
	}
	private static function storage_error() { return new WP_Error( 'rr_referral_storage', __( 'Referral identity could not be verified. The relationship has not changed; retry after database recovery.', 'rewards-referrals' ) ); }

	public static function ensure_code( $customer_id ) {
		global $wpdb;
		$customer_id = absint( $customer_id );
		if ( ! $customer_id || ! get_userdata( $customer_id ) ) { return ''; }
		$table = RR_Database::table( 'referral_codes' );
		$assigned = $wpdb->get_var( $wpdb->prepare( "SELECT referral_code FROM %i WHERE customer_id = %d", $table, $customer_id ) );
		if ( $wpdb->last_error ) { return ''; }
		if ( $assigned ) { update_user_meta( $customer_id, '_br_referral_code', $assigned ); return $assigned; }
		$legacy = strtoupper( sanitize_text_field( (string) get_user_meta( $customer_id, '_br_referral_code', true ) ) );
		if ( $wpdb->last_error ) { return ''; }
		for ( $attempt = 0; $attempt < 100; $attempt++ ) {
			$code = $legacy && 0 === $attempt ? $legacy : strtoupper( wp_generate_password( 14, false, false ) );
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (customer_id,referral_code,created_at) VALUES (%d,%s,%s)", $table, $customer_id, $code, current_time( 'mysql', true ) ) );
			if ( false === $inserted ) { return ''; }
			$assigned = $wpdb->get_var( $wpdb->prepare( "SELECT referral_code FROM %i WHERE customer_id = %d", $table, $customer_id ) );
			if ( $wpdb->last_error ) { return ''; }
			if ( $assigned ) { update_user_meta( $customer_id, '_br_referral_code', $assigned ); return $assigned; }
		}
		return '';
	}

	public static function find_by_code( $code ) {
		$result = self::lookup_code( $code );
		return is_wp_error( $result ) ? 0 : $result;
	}
	/** Internal callers distinguish unavailable storage from a successfully read unknown token. */
	private static function lookup_code( $code ) {
		if ( ! is_scalar( $code ) || ! $code ) { return 0; }
		global $wpdb;
		$code = strtoupper( sanitize_text_field( (string) $code ) );
		if ( strlen( $code ) > 48 ) { return 0; }
		$table = RR_Database::table( 'referral_codes' );
		$customer_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM %i WHERE referral_code = %s", $table, $code ) ) );
		if ( $wpdb->last_error ) { return self::storage_error(); }
		if ( $customer_id ) { $user = get_userdata( $customer_id ); if ( $wpdb->last_error ) { return self::storage_error(); } if ( $user ) { return $customer_id; } }
		return 0; // Unknown public tokens never trigger an unindexed scan of WordPress user metadata.
	}

	/** Migrate legacy referral codes through the usermeta primary key in bounded, restart-safe batches. */
	public static function migrate_codes() {
		global $wpdb;
		$lock = 'rr_referral_index_' . substr( md5( $wpdb->prefix ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return false; }
		try {
			if ( ! get_option( 'rr_referral_index_ready', false ) ) {
				$state = get_option( 'rr_referral_index_cursor', array() );
				if ( ! is_array( $state ) || ! isset( $state['upper'] ) ) {
					$upper = $wpdb->get_var( "SELECT COALESCE(MAX(umeta_id),0) FROM {$wpdb->usermeta}" );
					if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $upper, 0, PHP_INT_MAX ) ) { return false; }
					$state = array( 'after' => 0, 'upper' => (int) $upper );
				}
				if ( ! isset( $state['after'] ) || null === RR_Ledger::validated_integer( $state['after'], 0, PHP_INT_MAX ) || null === RR_Ledger::validated_integer( $state['upper'], 0, PHP_INT_MAX ) || $state['after'] > $state['upper'] ) { return false; }
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id,user_id,meta_key FROM %i WHERE umeta_id>%d AND umeta_id<=%d ORDER BY umeta_id LIMIT 200", $wpdb->usermeta, $state['after'], $state['upper'] ) );
				if ( $wpdb->last_error ) { return false; }
				foreach ( (array) $rows as $row ) {
					if ( '_br_referral_code' === $row->meta_key ) {
						$user = get_userdata( $row->user_id );
						if ( $wpdb->last_error ) { wp_cache_delete( (int) $row->user_id, 'user_meta' ); return false; }
						if ( $user && '' === self::ensure_code( $row->user_id ) ) { return false; }
					}
					$state['after'] = (int) $row->umeta_id;
				}
				update_option( 'rr_referral_index_cursor', $state, false );
				if ( $wpdb->last_error ) { return false; }
				if ( count( $rows ) === 200 ) { RR_Background::schedule( 'rr_referral_index' ); return true; }
				update_option( 'rr_referral_index_ready', true, false );
			}
			$after = absint( get_option( 'rr_referral_pending_after', 0 ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id,user_id FROM %i WHERE meta_key='_rr_pending_referral' AND umeta_id>%d ORDER BY umeta_id LIMIT 50", $wpdb->usermeta, $after ) );
			if ( $wpdb->last_error ) { return false; }
			foreach ( (array) $rows as $row ) {
				$identity = self::pending_identity( $row->user_id );
				if ( is_wp_error( $identity ) ) { return false; }
				if ( ! $identity['present'] ) { $after = (int) $row->umeta_id; continue; }
				$pending = $identity['value'];
				if ( ! is_array( $pending ) ) { $after = (int) $row->umeta_id; continue; }
				if ( ! empty( $pending['policy_unverified'] ) ) {
					$policy = RR_Rules::checked_snapshot();
					if ( is_wp_error( $policy ) ) { RR_Background::schedule( 'rr_referral_index', 60 ); return false; }
					$pending['attribution'] = $policy['settings']['attribution'];
					$pending['approved'] = 'automatic' === $policy['settings']['approval'] ? 1 : 0;
				}
				$user = get_userdata( $row->user_id ); if ( $wpdb->last_error ) { wp_cache_delete( (int) $row->user_id, 'user_meta' ); return false; }
				$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT referrer_id FROM %i WHERE customer_id=%d LIMIT 1', RR_Database::table( 'referrals' ), $row->user_id ) ); if ( $wpdb->last_error ) { return false; }
				if ( $user && ! $existing ) {
					$cookie = isset( $pending['cookie'] ) && is_scalar( $pending['cookie'] ) ? (string) $pending['cookie'] : '';
					$post = isset( $pending['post'] ) && is_scalar( $pending['post'] ) ? (string) $pending['post'] : '';
					$cookie_referrer = self::lookup_code( $cookie ); $post_referrer = self::lookup_code( $post );
					if ( is_wp_error( $cookie_referrer ) || is_wp_error( $post_referrer ) ) { $after = (int) $row->umeta_id; continue; }
					$code = 'first' === ( $pending['attribution'] ?? '' ) && $cookie_referrer ? $cookie : ( $post_referrer ? $post : $cookie );
					$referrer = self::lookup_code( $code );
					if ( is_wp_error( $referrer ) ) { $after = (int) $row->umeta_id; continue; }
					if ( $referrer && (int) $row->user_id !== $referrer ) {
						$result = self::write_relationship( (int) $row->user_id, $referrer, $code, ! empty( $pending['approved'] ) ? 1 : 0, false, 'Deferred legacy referral attribution' );
						if ( is_wp_error( $result ) ) { $after = (int) $row->umeta_id; continue; }
					}
				}
				delete_user_meta( $row->user_id, '_rr_pending_referral' ); $after = (int) $row->umeta_id;
			}
			update_option( 'rr_referral_pending_after', count( $rows ) === 50 ? $after : 0, false );
			if ( count( $rows ) === 50 || $wpdb->get_var( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key='_rr_pending_referral' LIMIT 1" ) ) { RR_Background::schedule( 'rr_referral_index', 60 ); }
			return true;
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	/** Administrator-approved prospective reassignment. Historical beneficiaries are unchanged. */
	public static function reassign( $customer_id, $referrer_id, $reason ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'rr_referral_permission', __( 'Permission denied.', 'rewards-referrals' ) ); }
		if ( ! is_scalar( $reason ) || '' === trim( (string) $reason ) ) { return new WP_Error( 'rr_referral_invalid', __( 'Provide a reason for reassignment.', 'rewards-referrals' ) ); }
		$customer_id = absint( $customer_id );
		$referrer_id = absint( $referrer_id );
		return self::write_relationship( $customer_id, $referrer_id, self::ensure_code( $referrer_id ), 1, true, $reason );
	}

	/** Every edge write shares the same lock and ancestor validation, including opted-in existing accounts. */
	private static function write_relationship( $customer_id, $referrer_id, $code, $approved, $replace, $reason ) {
		global $wpdb;
		if ( ! $customer_id || ! $referrer_id || $customer_id === $referrer_id || ! get_userdata( $customer_id ) || ! get_userdata( $referrer_id ) || ! $code ) { return new WP_Error( 'rr_referral_invalid', __( 'Choose two different valid customers.', 'rewards-referrals' ) ); }
		$table = RR_Database::table( 'referrals' );
		$lock_name = 'rr_referrals_' . substr( md5( $table ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock_name ) ) ) { return new WP_Error( 'rr_referral_busy', __( 'Referral relationships are being updated. Please retry.', 'rewards-referrals' ) ); }
		$open = false;
		$committed = false;
		try {
			foreach ( array( $table, RR_Database::table( 'referral_history' ), RR_Database::table( 'audit' ) ) as $table_name ) {
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table_name ) );
				if ( 'InnoDB' !== $engine ) { return new WP_Error( 'rr_referral_storage', __( 'Referral and audit storage could not be verified. No relationship change was saved.', 'rewards-referrals' ) ); }
			}
			$started = RR_Ledger::begin_wallet_transaction( array( $customer_id, $referrer_id ) );
			if ( is_wp_error( $started ) ) { return $started; }
			$open = true;
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id = %d FOR UPDATE", $table, $customer_id ) );
			if ( $wpdb->last_error ) { return self::storage_error(); }
			if ( $existing && ( ! $replace || (int) $existing->referrer_id === $referrer_id ) ) {
				$finished = RR_Ledger::finish_wallet_transaction( true );
				$open = false;
				return is_wp_error( $finished ) ? $finished : true;
			}
			$ancestor = $referrer_id;
			$seen = array();
			while ( $ancestor ) {
				if ( count( $seen ) >= 1000 ) { return new WP_Error( 'rr_referral_depth', __( 'This referral ancestry is too deep to change safely. Contact the store administrator.', 'rewards-referrals' ) ); }
				if ( $ancestor === $customer_id || isset( $seen[ $ancestor ] ) ) { return new WP_Error( 'rr_referral_cycle', __( 'This relationship would create a referral cycle.', 'rewards-referrals' ) ); }
				$seen[ $ancestor ] = true;
				$ancestor = $wpdb->get_var( $wpdb->prepare( "SELECT referrer_id FROM %i WHERE customer_id = %d", $table, $ancestor ) );
				if ( $wpdb->last_error ) { return self::storage_error(); }
				$ancestor = absint( $ancestor );
			}
			if ( $existing ) {
				$archived = $wpdb->insert( RR_Database::table( 'referral_history' ), array( 'referral_id' => absint( $existing->id ), 'referrer_id' => absint( $existing->referrer_id ), 'customer_id' => $customer_id, 'referral_code' => $existing->referral_code, 'created_at' => $existing->created_at, 'reassigned_at' => current_time( 'mysql', true ), 'actor_id' => get_current_user_id(), 'reason' => substr( sanitize_text_field( $reason ), 0, 200 ) ) );
				if ( 1 !== $archived ) { return new WP_Error( 'rr_referral_archive', __( 'The previous relationship could not be archived.', 'rewards-referrals' ) ); }
			}
			$values = array( 'referrer_id' => $referrer_id, 'customer_id' => $customer_id, 'referral_code' => $code, 'created_at' => current_time( 'mysql', true ), 'approved' => absint( $approved ) ? 1 : 0 );
			if ( $existing && 1 !== $wpdb->delete( $table, array( 'id' => absint( $existing->id ) ), array( '%d' ) ) ) { return new WP_Error( 'rr_referral_write', __( 'The previous active relationship could not be replaced.', 'rewards-referrals' ) ); }
			$result = $wpdb->insert( $table, $values );
			if ( false === $result ) { return new WP_Error( 'rr_referral_write', __( 'The relationship could not be saved.', 'rewards-referrals' ) ); }
			RR_Admin::audit( $existing ? 'referral_reassigned' : 'referral_assigned', $customer_id, array( 'previous_referrer' => $existing ? absint( $existing->referrer_id ) : 0, 'referrer_id' => $referrer_id, 'reason' => substr( sanitize_text_field( $reason ), 0, 200 ) ) );
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_referral_audit', __( 'The relationship audit could not be saved. No relationship change was committed.', 'rewards-referrals' ) ); }
			$finished = RR_Ledger::finish_wallet_transaction( true );
			$open = false;
			if ( is_wp_error( $finished ) ) { return $finished; }
			$committed = true;
			update_user_meta( $customer_id, '_br_referrer_id', $referrer_id );
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_referral_mirror', __( 'The relationship and audit were saved, but the compatibility profile update failed. Refresh and review the customer before retrying.', 'rewards-referrals' ) ); }
			delete_transient( 'rr_dashboard_stats' );
			return true;
		} catch ( Throwable $error ) { return new WP_Error( 'rr_referral_exception', $committed ? __( 'The relationship and audit were saved, but a post-save integration failed. Refresh and review the customer before retrying.', 'rewards-referrals' ) : __( 'The relationship could not be safely saved.', 'rewards-referrals' ) ); }
		finally {
			if ( $open ) { RR_Ledger::finish_wallet_transaction( false ); }
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	public static function admin_reassign() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_reassign_referral' );
		$input = wp_unslash( $_POST );
		foreach ( array( 'customer_id', 'referrer_id', 'reason' ) as $key ) { if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) { wp_die( esc_html__( 'Invalid referral request.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); } }
		$result = self::reassign( absint( $input['customer_id'] ), absint( $input['referrer_id'] ), sanitize_text_field( $input['reason'] ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => RR_Admin::page_for_tab( 'referrals' ), 'tab' => 'referrals', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
