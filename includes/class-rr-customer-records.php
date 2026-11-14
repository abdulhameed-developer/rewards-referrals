<?php
/** Customer-owned, read-only statements and upcoming point changes. */
defined( 'ABSPATH' ) || exit;

final class RR_Customer_Records {
	public static function init() {
		add_action( 'admin_post_rr_customer_statement', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_nopriv_rr_customer_statement', array( __CLASS__, 'download' ) );
	}

	public static function schedule( $customer ) {
		global $wpdb;
		$table = RR_Database::table( 'point_lots' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT source_transaction_id,remaining,status,CASE WHEN status='pending' THEN available_at ELSE expires_at END AS due_at FROM %i WHERE customer_id=%d AND remaining>0 AND ((status='pending' AND available_at IS NOT NULL) OR (status='available' AND expires_at IS NOT NULL)) ORDER BY due_at,id LIMIT 11", $table, $customer ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { return new WP_Error( 'rr_schedule_storage', __( 'Your upcoming point changes could not be read. Please try again.', 'rewards-referrals' ) ); }
		return $rows;
	}

	public static function render() {
		if ( ! is_user_logged_in() ) { return ''; }
		$rows = self::schedule( get_current_user_id() );
		$out = '<section class="rr-customer-card rr-customer-history" id="rr-point-schedule"><h3>' . esc_html__( 'Upcoming point changes', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Your next ten scheduled availability or expiry events. Amounts can change after a purchase, cancellation or refund. Reserved points are excluded from expiry while held.', 'rewards-referrals' ) . '</p>';
		if ( is_wp_error( $rows ) ) { $out .= '<p role="status">' . esc_html( $rows->get_error_message() ) . '</p>'; }
		elseif ( ! $rows ) { $out .= '<p>' . esc_html__( 'No scheduled changes are recorded for your unspent points.', 'rewards-referrals' ) . '</p>'; }
		else {
			$out .= '<div class="rr-customer-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Upcoming point changes', 'rewards-referrals' ) . '"><table><thead><tr><th>' . esc_html__( 'Change', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Points', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Scheduled date', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 10 ) as $row ) {
				$label = 'pending' === $row['status'] ? __( 'Becomes available', 'rewards-referrals' ) : __( 'Expires if unused', 'rewards-referrals' );
				$out .= '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( number_format_i18n( $row['remaining'] ) ) . '</td><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['due_at'] . ' UTC' ) ) ) . '</td></tr>';
			}
			$out .= '</tbody></table></div>';
			if ( count( $rows ) > 10 ) { $out .= '<p>' . esc_html__( 'More scheduled changes will appear as these are processed.', 'rewards-referrals' ) . '</p>'; }
		}
		$out .= '<details class="rr-statement"><summary>' . esc_html__( 'Download your points statement', 'rewards-referrals' ) . '</summary><p>' . esc_html__( 'Choose up to 31 days in UTC. A download contains up to 1,000 entries; use a smaller period if needed. Only your own points activity is included.', 'rewards-referrals' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rr_customer_statement">' . wp_nonce_field( 'rr_customer_statement', '_wpnonce', true, false );
		$out .= '<label>' . esc_html__( 'From (UTC)', 'rewards-referrals' ) . '<input type="date" name="from" required value="' . esc_attr( gmdate( 'Y-m-01' ) ) . '"></label><label>' . esc_html__( 'To (UTC)', 'rewards-referrals' ) . '<input type="date" name="to" required value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label><button type="submit">' . esc_html__( 'Download CSV', 'rewards-referrals' ) . '</button></form></details></section>';
		return $out;
	}

	public static function statement( $customer, $from, $to ) {
		global $wpdb;
		$customer = RR_Ledger::validated_integer( $customer, 1, PHP_INT_MAX );
		$from = RR_Operations::date( $from ); $to = RR_Operations::date( $to );
		if ( ! $customer || ! $from || ! $to || $from > $to || strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) >= 31 * DAY_IN_SECONDS ) {
			return new WP_Error( 'rr_statement_range', __( 'Choose a valid period of no more than 31 days.', 'rewards-referrals' ) );
		}
		$table = RR_Database::table( 'ledger' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,created_at,transaction_type,state,amount FROM %i WHERE customer_id=%d AND created_at >= %s AND created_at <= %s ORDER BY id ASC LIMIT 1001", $table, $customer, $from . ' 00:00:00', $to . ' 23:59:59' ), ARRAY_A );
		if ( $wpdb->last_error ) { return new WP_Error( 'rr_statement_storage', __( 'Your statement could not be read. Please try again.', 'rewards-referrals' ) ); }
		if ( count( $rows ) > 1000 ) { return new WP_Error( 'rr_statement_large', __( 'This period contains more than 1,000 entries. Choose fewer days; no partial statement was exported.', 'rewards-referrals' ) ); }
		return $rows;
	}

	public static function csv_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[\s\x00-\x1f]*[=+@-]/u', $value ) ? "'" . $value : $value;
	}

	public static function download() {
		if ( ! is_user_logged_in() ) { wp_die( esc_html__( 'Sign in to download your statement.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_customer_statement' );
		$from = isset( $_POST['from'] ) && is_scalar( $_POST['from'] ) ? wp_unslash( $_POST['from'] ) : '';
		$to = isset( $_POST['to'] ) && is_scalar( $_POST['to'] ) ? wp_unslash( $_POST['to'] ) : '';
		// The requested customer is deliberately derived from authentication, never request data.
		$rows = self::statement( get_current_user_id(), $from, $to );
		if ( is_wp_error( $rows ) ) { wp_die( esc_html( $rows->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) ); }
		$stream = fopen( 'php://output', 'w' );
		if ( false === $stream ) { wp_die( esc_html__( 'Your statement could not be downloaded. Please retry.', 'rewards-referrals' ), '', array( 'response' => 500 ) ); }
		nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="my-points-statement.csv"' );
		fputcsv( $stream, array( 'Transaction', 'Date UTC', 'Activity', 'State', 'Points' ), ',', '"', '' );
		foreach ( $rows as $row ) { fputcsv( $stream, array( (int) $row['id'], self::csv_cell( $row['created_at'] ), self::csv_cell( $row['transaction_type'] ), self::csv_cell( $row['state'] ), (int) $row['amount'] ), ',', '"', '' ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the php://output response stream opened above; this does not access the filesystem.
		fclose( $stream ); exit;
	}
}
