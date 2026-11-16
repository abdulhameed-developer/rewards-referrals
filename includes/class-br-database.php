<?php
defined( 'ABSPATH' ) || exit;

final class RR_Database {
	public const SCHEMA_VERSION = 23;
	public static function table( $suffix ) {
		global $wpdb;
		if ( ! in_array( $suffix, array( 'referrals', 'ledger', 'rules', 'audit', 'wallet_locks', 'redemptions', 'referral_codes', 'referral_history', 'point_wallets', 'point_lots', 'point_allocations', 'redemption_items', 'wallet_balances', 'catalog', 'catalog_queue', 'credit_facts', 'commission_totals', 'customer_lookup', 'request_limits', 'notification_outbox', 'wallet_rebuilds', 'recovery_events', 'legacy_lot_migrations' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown rewards storage table.' );
		}
		return $wpdb->prefix . 'br_' . $suffix;
	}

	public static function install( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Rewards & Referrals separately on each site. Network activation is not supported.', 'rewards-referrals' ), '', array( 'back_link' => true ) );
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$referrals = self::table( 'referrals' );
		$ledger    = self::table( 'ledger' );
		$rules     = self::table( 'rules' );
		$audit     = self::table( 'audit' );
		$wallet_locks = self::table( 'wallet_locks' );
		$redemptions = self::table( 'redemptions' );
		$referral_codes = self::table( 'referral_codes' );
		$referral_history = self::table( 'referral_history' );
		$point_wallets = self::table( 'point_wallets' );
		$point_lots = self::table( 'point_lots' );
		$point_allocations = self::table( 'point_allocations' );
		$redemption_items = self::table( 'redemption_items' );
		dbDelta( "CREATE TABLE $referrals (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			referrer_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned NOT NULL,
			referral_code varchar(48) NOT NULL,
			created_at datetime NOT NULL,
			approved tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_id (customer_id),
			KEY referrer_id (referrer_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $ledger (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			amount bigint(20) NOT NULL,
			transaction_type varchar(32) NOT NULL,
			state varchar(16) NOT NULL DEFAULT 'available',
			initial_state varchar(16) NOT NULL DEFAULT 'available',
			available_at datetime NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			referral_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rule_version bigint(20) unsigned NOT NULL DEFAULT 0,
			reference_id bigint(20) unsigned NOT NULL DEFAULT 0,
			idempotency_key varchar(191) NOT NULL,
			meta longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY customer_state (customer_id,state),
			KEY customer_cursor (customer_id,id),
			KEY order_id (order_id),
			KEY created_at (created_at),
			KEY state_type (state,transaction_type),
			KEY pending_maturity (state,available_at),
			KEY customer_maturity (customer_id,state,available_at,id),
			KEY reference_type (reference_id,transaction_type),
			KEY referral_type_order (referral_id,transaction_type,state,order_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $rules (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			version bigint(20) unsigned NOT NULL,
			settings longtext NOT NULL,
			starts_at datetime NOT NULL,
			ends_at datetime NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY version (version)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $audit (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL,
			action varchar(48) NOT NULL,
			subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
			details longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY action_actor_cursor (action,actor_id,id),
			KEY actor_cursor (actor_id,id),
			KEY created_at (created_at)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $wallet_locks (
			customer_id bigint(20) unsigned NOT NULL,
			owner_signature char(64) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (customer_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $redemptions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			points bigint(20) unsigned NOT NULL,
			status varchar(24) NOT NULL DEFAULT 'reserved',
			reservation_transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
			idempotency_key varchar(191) NOT NULL,
			checkout_key varchar(64) NULL DEFAULT NULL,
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			UNIQUE KEY idempotency_key (idempotency_key),
			UNIQUE KEY checkout_key (checkout_key),
			KEY customer_status (customer_id,status),
			KEY status_updated (status,updated_at)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $referral_codes (
			customer_id bigint(20) unsigned NOT NULL,
			referral_code varchar(48) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (customer_id),
			UNIQUE KEY referral_code (referral_code)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $referral_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			referral_id bigint(20) unsigned NOT NULL,
			referrer_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned NOT NULL,
			referral_code varchar(48) NOT NULL,
			created_at datetime NOT NULL,
			reassigned_at datetime NOT NULL,
			actor_id bigint(20) unsigned NOT NULL,
			reason text NOT NULL,
			PRIMARY KEY  (id),
			KEY customer_history (customer_id,reassigned_at),
			KEY referrer_history (referrer_id,id),
			KEY referral_id (referral_id)
		) ENGINE=InnoDB $collate;" );
		if ( version_compare( (string) get_option( 'br_schema_version', '0' ), '7', '<' ) ) {
			$wpdb->query( "UPDATE $ledger SET initial_state = CASE WHEN transaction_type = 'redemption_reservation' THEN 'reserved' ELSE state END" );
		}
		dbDelta( "CREATE TABLE $point_wallets (
			customer_id bigint(20) unsigned NOT NULL,
			opening_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			debt bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (customer_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $point_lots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			source_transaction_id bigint(20) unsigned NOT NULL,
			remaining bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'available',
			available_at datetime NULL,
			expires_at datetime NULL,
			expiry_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_source (customer_id,source_transaction_id),
			KEY customer_available (customer_id,status,id),
			KEY customer_maturity (customer_id,status,available_at,id),
			KEY customer_expiry (customer_id,status,expires_at,id),
			KEY expiry_due (status,expires_at,customer_id),
			KEY maturity_due (status,available_at,customer_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $point_allocations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			reservation_transaction_id bigint(20) unsigned NOT NULL,
			lot_id bigint(20) unsigned NOT NULL,
			amount bigint(20) unsigned NOT NULL,
			returned bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'held',
			PRIMARY KEY  (id),
			UNIQUE KEY reservation_lot (reservation_transaction_id,lot_id),
			KEY customer_reservation (customer_id,reservation_transaction_id,status)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $redemption_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			policy_scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_id bigint(20) unsigned NOT NULL,
			quantity int(10) unsigned NOT NULL,
			refunded_quantity int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_item (order_id,item_id),
			KEY customer_product_date (customer_id,product_id,created_at),
			KEY customer_parent_date (customer_id,parent_id,created_at)
			,KEY customer_scope_date (customer_id,policy_scope_id,created_at)
		) ENGINE=InnoDB $collate;" );
		$balances = self::table( 'wallet_balances' );
		dbDelta( "CREATE TABLE $balances (
			customer_id bigint(20) unsigned NOT NULL,
			balance bigint(20) NOT NULL DEFAULT 0,
			pending bigint(20) NOT NULL DEFAULT 0,
			reserved bigint(20) NOT NULL DEFAULT 0,
			spent bigint(20) NOT NULL DEFAULT 0,
			earned bigint(20) NOT NULL DEFAULT 0,
			referral_earned bigint(20) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (customer_id)
		) ENGINE=InnoDB $collate;" );
		$catalog = self::table( 'catalog' ); $queue = self::table( 'catalog_queue' );
		dbDelta( "CREATE TABLE $catalog (
			product_id bigint(20) unsigned NOT NULL,
			catalog_id bigint(20) unsigned NOT NULL,
			title_sort varchar(191) NOT NULL,
			points bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			generation varchar(36) NOT NULL,
			PRIMARY KEY  (product_id),
			KEY parent (catalog_id,product_id),
			KEY title_page (generation,title_sort,product_id),
			KEY points_page (generation,points,product_id),
			KEY date_page (generation,created_at,product_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $queue (
			product_id bigint(20) unsigned NOT NULL,
			scan_after bigint(20) unsigned NOT NULL DEFAULT 0,
			revision bigint(20) unsigned NOT NULL DEFAULT 1,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (product_id),
			KEY oldest (updated_at,product_id)
		) ENGINE=InnoDB $collate;" );
		$facts = self::table( 'credit_facts' ); $totals = self::table( 'commission_totals' );
		dbDelta( "CREATE TABLE $facts (
			credit_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned NOT NULL,
			kind varchar(32) NOT NULL,
			relationship_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rule_version bigint(20) unsigned NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 0,
			points bigint(20) unsigned NOT NULL DEFAULT 0,
			items bigint(20) unsigned NOT NULL DEFAULT 0,
			volume bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (credit_id),
			KEY customer_date (customer_id,kind,active,created_at,credit_id),
			KEY customer_version_date (customer_id,kind,active,rule_version,created_at,credit_id),
			KEY relationship_order (relationship_id,order_id,credit_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $totals (
			relationship_id bigint(20) unsigned NOT NULL,
			rule_version bigint(20) unsigned NOT NULL,
			scope_kind varchar(8) NOT NULL,
			scope_order bigint(20) unsigned NOT NULL DEFAULT 0,
			orders decimal(30,0) NOT NULL DEFAULT 0,
			items decimal(30,0) NOT NULL DEFAULT 0,
			points decimal(30,0) NOT NULL DEFAULT 0,
			volume decimal(30,0) NOT NULL DEFAULT 0,
			PRIMARY KEY  (relationship_id,rule_version,scope_kind,scope_order)
		) ENGINE=InnoDB $collate;" );
		$lookup = self::table( 'customer_lookup' ); $limits = self::table( 'request_limits' );
		dbDelta( "CREATE TABLE $lookup (
			customer_id bigint(20) unsigned NOT NULL,
			display_name varchar(191) NOT NULL,
			login_prefix varchar(60) NOT NULL,
			email_prefix varchar(100) NOT NULL,
			PRIMARY KEY  (customer_id),
			KEY name_prefix (display_name,customer_id),
			KEY login_prefix (login_prefix,customer_id),
			KEY email_prefix (email_prefix,customer_id)
		) ENGINE=InnoDB $collate;" );
		dbDelta( "CREATE TABLE $limits (
			actor_id bigint(20) unsigned NOT NULL,
			window_id bigint(20) unsigned NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (actor_id)
		) ENGINE=InnoDB $collate;" );
		$outbox = self::table( 'notification_outbox' );
		dbDelta( "CREATE TABLE $outbox (
			event_id bigint(20) unsigned NOT NULL,
			status varchar(16) NOT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			claim_token varchar(36) NOT NULL DEFAULT '',
			next_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			error_code varchar(48) NOT NULL DEFAULT '',
			PRIMARY KEY  (event_id),
			KEY due_jobs (status,next_at,event_id),
			KEY status_cursor (status,event_id),
			KEY stale_jobs (status,updated_at,event_id),
			KEY age_cleanup (status,created_at,event_id)
		) ENGINE=InnoDB $collate;" );
		$rebuilds = self::table( 'wallet_rebuilds' );
		dbDelta( "CREATE TABLE $rebuilds (
			customer_id bigint(20) unsigned NOT NULL,
			scan_after bigint(20) unsigned NOT NULL DEFAULT 0,
			upper_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL,
			generation varchar(36) NOT NULL,
			balance bigint(20) NOT NULL DEFAULT 0,
			pending bigint(20) NOT NULL DEFAULT 0,
			reserved bigint(20) NOT NULL DEFAULT 0,
			spent bigint(20) NOT NULL DEFAULT 0,
			earned bigint(20) NOT NULL DEFAULT 0,
			referral_earned bigint(20) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			error_code varchar(48) NOT NULL DEFAULT '',
			PRIMARY KEY  (customer_id),
			KEY recovery (status,updated_at,customer_id)
		) ENGINE=InnoDB $collate;" );
		$recovery = self::table( 'recovery_events' );
		dbDelta( "CREATE TABLE $recovery (
			event_key varchar(96) NOT NULL,
			kind varchar(8) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_a bigint(20) unsigned NOT NULL,
			customer_b bigint(20) unsigned NOT NULL,
			status varchar(16) NOT NULL,
			auto_due tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (event_key),
			KEY buyer_hold (customer_a,status,event_key),
			KEY referrer_hold (customer_b,status,event_key),
			KEY recovery_queue (status,auto_due,updated_at,event_key)
		) ENGINE=InnoDB $collate;" );
		$legacy = self::table( 'legacy_lot_migrations' );
		dbDelta( "CREATE TABLE $legacy (
			customer_id bigint(20) unsigned NOT NULL,
			upper_id bigint(20) unsigned NOT NULL DEFAULT 0,
			scan_after bigint(20) unsigned NOT NULL DEFAULT 0,
			opening_lot_id bigint(20) unsigned NOT NULL DEFAULT 0,
			balance bigint(20) NOT NULL DEFAULT 0,
			source_balance bigint(20) NOT NULL DEFAULT 0,
			pending bigint(20) NOT NULL DEFAULT 0,
			reserved bigint(20) NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'building',
			generation varchar(36) NOT NULL,
			error_code varchar(40) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (customer_id),
			KEY recovery (status,updated_at,customer_id)
		) ENGINE=InnoDB $collate;" );
		$ready = self::verify_schema();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$identity = RR_Wallet_Identity::start();
		if ( is_wp_error( $identity ) ) { return $identity; }
		update_option( 'br_schema_version', (string) self::SCHEMA_VERSION, false );
		// Capture one atomic trust baseline; failures remain fail-closed and retry through the existing worker.
		if ( ! RR_Wallet_Identity::ready() ) { RR_Wallet_Identity::work(); }
		delete_option( 'rr_schema_error' );
		delete_option( 'rr_schema_retry_after' );
		if ( ! get_option( 'br_settings' ) ) {
			add_option( 'br_settings', RR_Rules::defaults() );
		}
		if ( ! get_option( 'br_rules_version' ) ) {
			update_option( 'br_rules_version', 1, false );
		}
		self::snapshot_rules( RR_Rules::get() );
		// Activation and upgrades can run before init has registered the account endpoint.
		global $wp_rewrite;
		if ( $wp_rewrite instanceof WP_Rewrite ) {
			RR_Frontend::endpoint();
			flush_rewrite_rules();
		} else {
			add_action( 'init', static function () {
				RR_Frontend::endpoint();
				flush_rewrite_rules();
			}, 20 );
		}
	}

	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( 'br_schema_version', '0' ), (string) self::SCHEMA_VERSION, '<' ) ) {
			if ( time() >= (int) get_option( 'rr_schema_retry_after', 0 ) ) {
				$result = self::install();
				if ( is_wp_error( $result ) ) { update_option( 'rr_schema_error', $result->get_error_code(), false ); update_option( 'rr_schema_retry_after', time() + 300, false ); }
			}
			if ( (int) get_option( 'br_schema_version' ) >= self::SCHEMA_VERSION ) { return; }
			add_action( 'admin_notices', static function () {
				if ( current_user_can( 'manage_woocommerce' ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Rewards database upgrade has not completed. Reward writes are unavailable until its transactional tables are ready. Check database permissions and retry activation; customer history has not been deleted.', 'rewards-referrals' ) . '</p></div>'; }
			} );
		}
	}

	public static function activate( $network_wide = false ) {
		$result = self::install( $network_wide );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) ); }
	}

	/** Order markers and financial rows must share transactional storage for reliable rollback. */
	public static function verify_order_storage() {
		global $wpdb;
		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$sync = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		$tables = array( $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta' );
		if ( ! $hpos || $sync ) { $tables[] = $wpdb->posts; $tables[] = $wpdb->postmeta; }
		if ( $hpos || $sync ) {
			foreach ( array( 'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta' ) as $suffix ) { $tables[] = $wpdb->prefix . $suffix; }
		}
		$marks = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)", $tables ), OBJECT_K );
		if ( $wpdb->last_error || count( (array) $rows ) !== count( $tables ) ) { return new WP_Error( 'rr_order_storage', __( 'WooCommerce order storage could not be verified. Rewards accounting is paused until the store administrator checks database health.', 'rewards-referrals' ), array( 'status' => 503 ) ); }
		foreach ( $tables as $table ) {
			if ( ! isset( $rows[ $table ] ) || 'InnoDB' !== $rows[ $table ]->ENGINE ) { return new WP_Error( 'rr_order_storage', __( 'WooCommerce order tables must use InnoDB before rewards accounting can continue safely. Ask the store administrator to repair transactional storage.', 'rewards-referrals' ), array( 'status' => 503 ) ); }
		}
		return true;
	}

	public static function verify_schema() {
		global $wpdb;
		$required = array( 'legacy_lot_migrations' => array( 'customer_id','upper_id','scan_after','opening_lot_id','balance','source_balance','pending','reserved','status','generation','error_code','updated_at' ), 'ledger' => array( 'id', 'customer_id', 'initial_state' ), 'wallet_locks' => array( 'customer_id','owner_signature' ), 'redemptions' => array( 'order_id' ), 'point_wallets' => array( 'customer_id','opening_cursor','debt' ), 'point_lots' => array( 'source_transaction_id','remaining' ), 'point_allocations' => array( 'reservation_transaction_id','returned' ), 'wallet_balances' => array( 'customer_id','balance','pending','reserved','spent','earned','referral_earned' ), 'catalog' => array( 'product_id','catalog_id','generation' ), 'catalog_queue' => array( 'product_id','scan_after','revision' ), 'credit_facts' => array( 'credit_id','active','points','volume' ), 'commission_totals' => array( 'relationship_id','rule_version','scope_kind','scope_order','orders','points' ), 'customer_lookup' => array( 'customer_id','display_name','login_prefix','email_prefix' ), 'request_limits' => array( 'actor_id','window_id','hits' ), 'notification_outbox' => array( 'event_id','status','attempts','claim_token','next_at','error_code' ), 'wallet_rebuilds' => array( 'customer_id','scan_after','upper_id','status','generation','balance','error_code' ), 'recovery_events' => array( 'event_key','customer_a','customer_b','status','auto_due','order_id','refund_id' ) );
		foreach ( $required as $suffix => $columns ) {
			$table = self::table( $suffix );
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $wpdb->dbname, $table ) );
			$actual = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $wpdb->dbname, $table ) );
			if ( 'InnoDB' !== $engine || array_diff( $columns, $actual ) ) { return new WP_Error( 'rr_schema_incomplete', __( 'Rewards transactional storage could not be installed completely. Check database permissions and available space, then retry. The schema version was not advanced.', 'rewards-referrals' ) ); }
		}
		$audit = self::table( 'audit' );
		$indexes = $wpdb->get_col( "SHOW INDEX FROM $audit", 2 );
		if ( ! in_array( 'action_actor_cursor', $indexes, true ) || ! in_array( 'actor_cursor', $indexes, true ) ) { return new WP_Error( 'rr_schema_indexes', __( 'Rewards audit indexes are missing. Retry the database upgrade after checking database permissions.', 'rewards-referrals' ) ); }
		foreach ( array( 'referral_history' => array( 'referrer_history' ), 'legacy_lot_migrations' => array( 'recovery' ), 'ledger' => array( 'customer_maturity','customer_cursor' ), 'point_lots' => array( 'customer_maturity','customer_expiry','maturity_due' ), 'catalog' => array( 'parent','title_page','points_page','date_page' ), 'catalog_queue' => array( 'oldest' ), 'credit_facts' => array( 'customer_date','customer_version_date','relationship_order' ), 'customer_lookup' => array( 'name_prefix','login_prefix','email_prefix' ), 'notification_outbox' => array( 'due_jobs','stale_jobs','age_cleanup','status_cursor' ), 'wallet_rebuilds' => array( 'recovery' ), 'recovery_events' => array( 'buyer_hold','referrer_hold','recovery_queue' ) ) as $suffix => $names ) {
			$table = self::table( $suffix );
			$actual = $wpdb->get_col( "SHOW INDEX FROM $table", 2 );
			if ( array_diff( $names, $actual ) ) { return new WP_Error( 'rr_schema_catalog_indexes', __( 'Rewards catalogue indexes are missing. Check database permissions and retry the upgrade.', 'rewards-referrals' ) ); }
		}
		return true;
	}

	public static function snapshot_rules( $settings ) {
		global $wpdb;
		$table = self::table( 'rules' );
		$version = (int) get_option( 'br_rules_version', 1 );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE version = %d", $table, $version ) );
		if ( $exists ) { return; }
		$wpdb->insert( $table, array(
			'version' => $version,
			'settings' => wp_json_encode( $settings ),
			'starts_at' => current_time( 'mysql', true ),
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
		), array( '%d', '%s', '%s', '%d', '%s' ) );
	}
}
