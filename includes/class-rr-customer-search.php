<?php
/** Bounded indexed prefix lookup. This projection never grants account or wallet access. */
defined( 'ABSPATH' ) || exit;
final class RR_Customer_Search {
	public static function init() {
		add_action( 'wp_ajax_rr_find_customer', array( __CLASS__, 'search' ) );
		add_action( 'admin_post_rr_customer_index_rebuild', array( __CLASS__, 'repair' ) );
		add_action( 'user_register', array( __CLASS__, 'sync' ) );
		add_action( 'profile_update', array( __CLASS__, 'sync' ) );
		add_action( 'deleted_user', array( __CLASS__, 'deleted' ) );
		add_action( 'rr_customer_index', array( __CLASS__, 'work' ) );
		if ( ! wp_next_scheduled( 'rr_customer_index' ) ) { wp_schedule_event( time() + 120, 'hourly', 'rr_customer_index' ); }
		if ( ! get_option( 'rr_customer_index_complete', false ) ) { RR_Background::schedule( 'rr_customer_index' ); }
	}
	public static function deleted( $id ) { global $wpdb; $wpdb->delete( RR_Database::table( 'customer_lookup' ), array( 'customer_id' => absint( $id ) ) ); $wpdb->delete( RR_Database::table( 'request_limits' ), array( 'actor_id' => absint( $id ) ) ); }
	public static function sync( $id ) {
		global $wpdb;
		// Serialize rebuild/profile changes, and re-read the core row after acquiring the lock.
		$lock = 'rr_user_' . substr( md5( $wpdb->prefix ), 0, 12 ) . '_' . absint( $id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) { return false; }
		try {
			$user = $wpdb->get_row( $wpdb->prepare( "SELECT ID,display_name,user_login,user_email FROM %i WHERE ID=%d", $wpdb->users, $id ) );
			if ( $wpdb->last_error ) { return false; }
			$table = RR_Database::table( 'customer_lookup' );
			if ( ! $user ) { return false !== $wpdb->delete( $table, array( 'customer_id' => absint( $id ) ) ); }
			return false !== $wpdb->replace( $table, array( 'customer_id' => $id, 'display_name' => mb_substr( $user->display_name, 0, 191 ), 'login_prefix' => $user->user_login, 'email_prefix' => $user->user_email ) );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	public static function work() {
		if ( ! RR_Wallet_Identity::ready() ) { RR_Wallet_Identity::work(); if ( ! RR_Wallet_Identity::ready() ) { RR_Background::schedule( 'rr_customer_index', 1 ); } }
		global $wpdb; $lock = 'rr_users_work_' . substr( md5( $wpdb->prefix ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return 0; }
		$n = 0;
		try {
			$after = absint( get_option( 'rr_customer_index_after', 0 ) );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE ID>%d ORDER BY ID LIMIT 200", $wpdb->users, $after ) );
			if ( $wpdb->last_error ) { return 0; }
			foreach ( $ids as $id ) { if ( ! self::sync( $id ) ) { return $n; } ++$n; $after = (int) $id; }
			update_option( 'rr_customer_index_after', $after, false );
			update_option( 'rr_customer_index_complete', count( $ids ) < 200, false );
			return $n;
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); if ( ! get_option( 'rr_customer_index_complete', false ) ) { RR_Background::schedule( 'rr_customer_index' ); } }
	}
	public static function allowed( $actor ) {
		global $wpdb; $table = RR_Database::table( 'request_limits' ); $window = intdiv( time(), 60 );
		// Atomic rate claim. The window never resets from client-controlled input.
		$r = $wpdb->query( $wpdb->prepare( "INSERT INTO %i(actor_id,window_id,hits) VALUES(%d,%d,1) ON DUPLICATE KEY UPDATE hits=IF(window_id=VALUES(window_id),LEAST(100,hits+1),1),window_id=VALUES(window_id)", $table, $actor, $window ) );
		$hits = $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM %i WHERE actor_id=%d", $table, $actor ) );
		return false !== $r && null !== $hits && (int) $hits <= 40;
	}
	public static function lookup( $q ) {
		global $wpdb; $table = RR_Database::table( 'customer_lookup' ); $ids = array();
		if ( ctype_digit( $q ) ) { $id = RR_Ledger::validated_integer( $q, 1, PHP_INT_MAX ); if ( $id ) { $ids[] = $id; } }
		elseif ( is_email( $q ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT customer_id FROM %i FORCE INDEX (email_prefix) WHERE email_prefix=%s ORDER BY customer_id LIMIT 11", $table, $q ) );
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_lookup_storage', __( 'Customer search is temporarily unavailable.', 'rewards-referrals' ) ); }
		} else {
			$prefix = $wpdb->esc_like( $q ) . '%';
			// Separate index ranges rather than an unindexed OR/contains search. 11 rows per range, at most 33 candidates.
			foreach ( array( 'display_name' => 'name_prefix', 'email_prefix' => 'email_prefix', 'login_prefix' => 'login_prefix' ) as $column => $index ) {
				$membership = is_multisite() ? $wpdb->prepare( " AND EXISTS (SELECT 1 FROM %i um WHERE um.user_id=c.customer_id AND um.meta_key=%s)", $wpdb->usermeta, $wpdb->prefix . 'capabilities' ) : '';
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT c.customer_id FROM $table c FORCE INDEX ($index) WHERE c.$column LIKE %s$membership ORDER BY c.$column,c.customer_id LIMIT 11", $prefix ) );
				if ( $wpdb->last_error ) { return new WP_Error( 'rr_lookup_storage', __( 'Customer search is temporarily unavailable.', 'rewards-referrals' ) ); }
				$ids = array_merge( $ids, $found );
			}
		}
		$rows = array();
		foreach ( array_unique( $ids ) as $id ) {
			$user = get_userdata( $id );
			if ( ! $user || ( is_multisite() && ! is_user_member_of_blog( $id ) ) ) { continue; }
			// Revalidate against authoritative user fields, so a stale index cannot disclose an obsolete email or identity.
			if ( ! ctype_digit( $q ) ) { $match = false; foreach ( array( $user->display_name,$user->user_email,$user->user_login ) as $field ) { if ( 1 === preg_match( '/\A' . preg_quote( $q, '/' ) . '/iu', $field ) ) { $match = true; break; } } if ( ! $match ) { continue; } }
			$rows[] = array( 'id' => (int) $id, 'label' => $user->display_name . ' · ' . $user->user_email . ' · #' . $id );
			if ( count( $rows ) === 10 ) { break; }
		}
		return $rows;
	}
	public static function repair() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_customer_index_rebuild' );
		global $wpdb; $lock = 'rr_users_work_' . substr( md5( $wpdb->prefix ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) { wp_die( esc_html__( 'Index worker is busy. Retry shortly.', 'rewards-referrals' ), '', array( 'response' => 409 ) ); }
		$failed = false;
		try { update_option( 'rr_customer_index_after', 0, false ); $failed = (bool) $wpdb->last_error; update_option( 'rr_customer_index_complete', false, false ); $failed = $failed || (bool) $wpdb->last_error; if ( ! $failed ) { RR_Admin::audit( 'customer_index_rebuild', 0 ); $failed = (bool) $wpdb->last_error; } }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		if ( $failed ) { wp_die( esc_html__( 'The index reset could not be saved. Check database health and retry.', 'rewards-referrals' ), '', array( 'response' => 503 ) ); }
		RR_Background::schedule( 'rr_customer_index' ); wp_safe_redirect( admin_url( 'admin.php?page=rewards-referrals-insights&tab=tools' ) ); exit;
	}
	public static function render_tools() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$status = __( 'Ready', 'rewards-referrals' );
		if ( ! get_option( 'rr_customer_index_complete' ) ) {
			/* translators: %d: last user ID scanned by the customer search index. */
			$status = sprintf( __( 'Indexing after user ID %d', 'rewards-referrals' ), absint( get_option( 'rr_customer_index_after', 0 ) ) );
		}
		echo '<section class="rr-card rr-setting-card"><h3>' . esc_html__( 'Customer search index', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Names and emails are refreshed automatically after WordPress profile changes. Rebuild this derived index after imports that bypass WordPress hooks. Existing user accounts and reward amounts are never edited.', 'rewards-referrals' ) . '</p><p role="status">' . esc_html( $status ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rr_customer_index_rebuild">'; wp_nonce_field( 'rr_customer_index_rebuild' ); echo '<button class="rr-button rr-button-light">' . esc_html__( 'Rebuild customer search index', 'rewards-referrals' ) . '</button></form></section>';
	}
	public static function search() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'rr_admin_route', 'nonce', false ) ) { wp_send_json_error( array( 'message' => __( 'Your session expired or access is not permitted.', 'rewards-referrals' ) ), 403 ); }
		$q = isset( $_POST['q'] ) && is_scalar( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
		if ( ! $q || mb_strlen( $q ) > 80 || ( mb_strlen( $q ) < 3 && ! ctype_digit( $q ) ) ) { wp_send_json_error( array( 'message' => __( 'Enter a customer ID, or 3–80 characters from the beginning of a name or email.', 'rewards-referrals' ) ), 400 ); }
		if ( ! self::allowed( get_current_user_id() ) ) { wp_send_json_error( array( 'message' => __( 'Too many searches. Please wait a minute and try again.', 'rewards-referrals' ) ), 429 ); }
		$rows = self::lookup( $q );
		if ( is_wp_error( $rows ) ) { wp_send_json_error( array( 'message' => $rows->get_error_message() ), 503 ); }
		wp_send_json_success( array( 'customers' => $rows, 'message' => ! get_option( 'rr_customer_index_complete', false ) ? __( 'Customer index is refreshing. Search by exact ID while indexing.', 'rewards-referrals' ) : __( 'Up to 10 prefix matches. Refine the beginning of the name or email.', 'rewards-referrals' ) ) );
	}
}
