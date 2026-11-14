<?php
/** Effective reward candidates. This index is never authority for checkout eligibility. */
defined( 'ABSPATH' ) || exit;
final class RR_Catalog {
	private const STATE = 'rr_catalog_state';
	public static function init() {
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'saved' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'deleted' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
		add_action( 'update_option_br_settings', array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'rr_catalog_worker', array( __CLASS__, 'work' ) );
		add_action( 'rr_catalog_continue', array( __CLASS__, 'work' ) );
		if ( ! wp_next_scheduled( 'rr_catalog_worker' ) ) { wp_schedule_event( time() + 30, 'hourly', 'rr_catalog_worker' ); }
		self::state();
	}
	public static function state() {
		$state = get_option( self::STATE );
		if ( ! is_array( $state ) || empty( $state['generation'] ) ) { $state = array( 'generation' => wp_generate_uuid4(), 'cursor' => 0, 'complete' => false ); add_option( self::STATE, $state, '', false ); }
		return $state;
	}
	public static function reset() { update_option( self::STATE, array( 'generation' => wp_generate_uuid4(), 'cursor' => 0, 'complete' => false ), false ); self::schedule(); }
	public static function settings_changed( $old, $new ) {
		foreach ( array( 'reward_default_mode','redemption_enabled' ) as $key ) { if ( ( $old[ $key ] ?? '' ) !== ( $new[ $key ] ?? '' ) ) { self::reset(); break; } }
	}
	private static function schedule() {
		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_schedule_single_action' ) ) {
			// Ignore the currently running action so a bounded batch can schedule its successor.
			$pending = as_get_scheduled_actions( array( 'hook' => 'rr_catalog_continue', 'status' => 'pending', 'per_page' => 1 ), 'ids' );
			if ( ! $pending ) { as_schedule_single_action( time() + 5, 'rr_catalog_continue', array(), 'rewards-referrals', false ); }
		} elseif ( ! wp_next_scheduled( 'rr_catalog_continue' ) ) { wp_schedule_single_event( time() + 5, 'rr_catalog_continue' ); }
	}
	public static function saved( $product ) {
		if ( ! $product instanceof WC_Product ) { return; }
		if ( $product->is_type( 'variable' ) ) { self::queue( $product->get_id() ); }
		else { self::sync( $product->get_id() ); }
	}
	public static function status_changed( $new, $old, $post ) {
		if ( $new !== $old && in_array( $post->post_type, array( 'product','product_variation' ), true ) ) { self::queue( $post->ID ); }
	}
	public static function meta_changed( $meta_id, $id, $key, $value ) {
		if ( in_array( get_post_type( $id ), array( 'product','product_variation' ), true ) && ( str_starts_with( $key, '_rr_' ) || in_array( $key, array( '_price','_stock_status','_visibility','_manage_stock','_stock' ), true ) ) ) { self::queue( $id ); }
	}
	public static function queue( $id ) {
		global $wpdb;
		$table = RR_Database::table( 'catalog_queue' ); $index = RR_Database::table( 'catalog' );
		// Hide potentially stale candidates until this object's effective policy is re-evaluated.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE product_id=%d OR catalog_id=%d", $index, $id, $id ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (product_id,scan_after,revision,updated_at) VALUES (%d,0,1,%s) ON DUPLICATE KEY UPDATE scan_after=0,revision=revision+1,updated_at=VALUES(updated_at)", $table, $id, current_time( 'mysql', true ) ) );
		self::schedule();
	}
	public static function deleted( $id ) {
		if ( ! in_array( get_post_type( $id ), array( 'product','product_variation' ), true ) ) { return; }
		global $wpdb; $table = RR_Database::table( 'catalog' ); $queue = RR_Database::table( 'catalog_queue' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE product_id=%d OR catalog_id=%d", $table, $id, $id ) );
		$wpdb->delete( $queue, array( 'product_id' => $id ) );
	}
	public static function eligible( $product ) {
		if ( ! $product || ! $product->is_type( array( 'simple','variation' ) ) || 'publish' !== $product->get_status() || '' === $product->get_price( 'edit' ) || ! $product->is_in_stock() ) { return false; }
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $parent || 'publish' !== $parent->get_status() || 'hidden' === $parent->get_catalog_visibility( 'edit' ) ) { return false; }
		$mode = RR_WooCommerce::reward_mode( $product );
		return in_array( $mode, array( 'points_only','cash_or_points','points_plus_cash' ), true ) && RR_WooCommerce::points_price( $product ) > 0 && ( 'points_plus_cash' !== $mode || RR_Rules::to_scaled_integer( RR_WooCommerce::cash_price( $product ), wc_get_price_decimals() ) > 0 );
	}
	public static function sync( $id, $generation = null ) {
		global $wpdb;
		$id = absint( $id ); $lock = 'rr_catalog_' . substr( md5( $wpdb->prefix ), 0, 10 ) . '_' . $id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) { return false; }
		try {
			clean_post_cache( $id );
			$product = wc_get_product( $id ); $table = RR_Database::table( 'catalog' );
			if ( ! self::eligible( $product ) ) { return false !== $wpdb->delete( $table, array( 'product_id' => $id ) ); }
			$state = self::state();
			$row = array( 'product_id' => $id, 'catalog_id' => $product->get_parent_id() ?: $id, 'title_sort' => mb_substr( $product->get_name(), 0, 191 ), 'points' => RR_WooCommerce::points_price( $product ), 'created_at' => get_post_field( 'post_date_gmt', $id ), 'generation' => null !== $generation ? $generation : $state['generation'] );
			return false !== $wpdb->replace( $table, $row );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	/** At most 100 initial records plus 5 x 100 child records; no frontend catalogue scan. */
	public static function work() {
		global $wpdb;
		$lock = 'rr_catalog_work_' . substr( md5( $wpdb->prefix ), 0, 16 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return 0; }
		$count = 0;
		try {
			$state = self::state();
			if ( ! $state['complete'] ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE ID>%d AND post_type IN ('product','product_variation') ORDER BY ID LIMIT 100", $wpdb->posts, $state['cursor'] ) );
				if ( $wpdb->last_error ) { return 0; }
				$good = true;
				foreach ( $ids as $id ) { if ( ! self::sync( $id, $state['generation'] ) ) { $good = false; break; } ++$count; }
				if ( $good ) {
					$next = $state; $next['cursor'] = $ids ? (int) end( $ids ) : $state['cursor']; $next['complete'] = count( $ids ) < 100;
					// Compare-and-swap the whole state: a concurrent global-policy reset must win.
					$wpdb->query( $wpdb->prepare( "UPDATE %i SET option_value=%s WHERE option_name=%s AND option_value=%s", $wpdb->options, maybe_serialize( $next ), self::STATE, maybe_serialize( $state ) ) );
					wp_cache_delete( self::STATE, 'options' );
				}
			}
			$table = RR_Database::table( 'catalog_queue' );
			$jobs = $wpdb->get_results( "SELECT product_id,scan_after,revision FROM $table ORDER BY updated_at,product_id LIMIT 5" );
			foreach ( $jobs as $job ) {
				if ( ! self::sync( $job->product_id ) ) { continue; }
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_parent=%d AND post_type='product_variation' AND ID>%d ORDER BY ID LIMIT 100", $wpdb->posts, $job->product_id, $job->scan_after ) );
				if ( $wpdb->last_error ) { continue; }
				$good = true;
				foreach ( $ids as $id ) { if ( ! self::sync( $id ) ) { $good = false; break; } ++$count; }
				if ( ! $good ) { continue; }
				if ( count( $ids ) < 100 ) { $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE product_id=%d AND revision=%d", $table, $job->product_id, $job->revision ) ); }
				else { $wpdb->query( $wpdb->prepare( "UPDATE %i SET scan_after=%d WHERE product_id=%d AND revision=%d", $table, end( $ids ), $job->product_id, $job->revision ) ); }
			}
			$state = self::state();
			if ( ! $state['complete'] || $wpdb->get_var( "SELECT product_id FROM $table LIMIT 1" ) ) { self::schedule(); }
			return $count;
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	public static function page( $category = 0, $sort = '', $cursor = '' ) {
		global $wpdb;
		$state = self::state(); $table = RR_Database::table( 'catalog' );
		$sorts = array( '' => array( 'title_sort','ASC','title_page' ), 'newest' => array( 'created_at','DESC','date_page' ), 'points_low' => array( 'points','ASC','points_page' ), 'points_high' => array( 'points','DESC','points_page' ) );
		$sort = isset( $sorts[ $sort ] ) ? $sort : ''; list( $column,$direction,$index ) = $sorts[ $sort ];
		$where = $wpdb->prepare( 'c.generation=%s', $state['generation'] );
		if ( $category ) {
			$term = get_term( $category, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) { $where .= ' AND 1=0'; }
			else {
				$children = get_term_children( $category, 'product_cat' ); $ids = array_merge( array( (int) $category ), is_array( $children ) ? array_map( 'absint', $children ) : array() );
				$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tr.object_id=c.catalog_id AND tt.taxonomy='product_cat' AND tt.term_id IN ($marks))", $ids );
			}
		}
		if ( is_string( $cursor ) && strlen( $cursor ) <= 1200 && '' !== $cursor ) {
			$decoded = json_decode( base64_decode( strtr( $cursor, '-_', '+/' ), true ) ?: '', true );
			if ( is_array( $decoded ) && ( $decoded['s'] ?? null ) === $sort && ( $decoded['g'] ?? '' ) === $state['generation'] && is_scalar( $decoded['v'] ?? null ) && strlen( (string) $decoded['v'] ) <= 800 && is_scalar( $decoded['i'] ?? null ) && ctype_digit( (string) $decoded['i'] ) ) {
				$op = 'ASC' === $direction ? '>' : '<';
				$value = 'points' === $column ? absint( $decoded['v'] ) : (string) $decoded['v'];
				$format = 'points' === $column ? '%d' : '%s';
				$where .= $wpdb->prepare( " AND (c.$column $op $format OR (c.$column=$format AND c.product_id $op %d))", $value, $value, absint( $decoded['i'] ) );
			}
		}
		$rows = $wpdb->get_results( "SELECT c.* FROM $table c FORCE INDEX ($index) WHERE $where ORDER BY c.$column $direction,c.product_id $direction LIMIT 13", ARRAY_A );
		if ( $wpdb->last_error ) { return new WP_Error( 'rr_catalog_read', __( 'Rewards are temporarily unavailable. Please try again shortly.', 'rewards-referrals' ) ); }
		$more = count( $rows ) > 12; $rows = array_slice( $rows, 0, 12 ); $next = '';
		if ( $more && $rows ) { $last = end( $rows ); $next = rtrim( strtr( base64_encode( wp_json_encode( array( 's' => $sort,'g' => $state['generation'],'v' => $last[ $column ],'i' => $last['product_id'] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' ); }
		$queue = RR_Database::table( 'catalog_queue' );
		$pending = (bool) $wpdb->get_var( "SELECT product_id FROM $queue LIMIT 1" );
		return array( 'rows' => $rows, 'next' => $next, 'building' => ! $state['complete'] || $pending );
	}
}
