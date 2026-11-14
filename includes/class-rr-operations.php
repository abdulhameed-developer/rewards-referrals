<?php
defined( 'ABSPATH' ) || exit;

/** Administrative operations, exports and privacy integration. */
final class RR_Operations {
	public static function init() {
		add_action( 'admin_post_rr_operations', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_rr_export', array( __CLASS__, 'export' ) );
		add_action( 'rr_ledger_transaction_added', array( __CLASS__, 'queue_notification' ), 20, 2 );
		add_action( 'rr_send_notification', array( __CLASS__, 'send_notification' ), 10, 2 );
		add_action( 'rr_accounting_maintenance', array( __CLASS__, 'maintenance' ) );
		if ( ! wp_next_scheduled( 'rr_accounting_maintenance' ) ) { wp_schedule_event( time() + 300, 'hourly', 'rr_accounting_maintenance' ); }
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'privacy_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'privacy_erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
	}

	public static function render( $tab ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$pages = array(
			'campaigns' => array( __( 'Campaigns', 'rewards-referrals' ), __( 'Schedule earning multipliers by customer role. The highest priority eligible campaign applies.', 'rewards-referrals' ) ),
			'reports' => array( __( 'Reports', 'rewards-referrals' ), __( 'Review recorded point movements and export filtered accounting data.', 'rewards-referrals' ) ),
			'notifications' => array( __( 'Notifications', 'rewards-referrals' ), __( 'Choose which point events send customer emails and edit the message templates.', 'rewards-referrals' ) ),
			'tools' => array( __( 'Tools & diagnostics', 'rewards-referrals' ), __( 'Inspect accounting storage, background jobs, configuration and compatibility.', 'rewards-referrals' ) ),
		);
		if ( ! isset( $pages[ $tab ] ) ) { return; }
		echo '<div class="rr-page-heading"><div><span class="rr-section-kicker">' . esc_html__( 'PROGRAM OPERATIONS', 'rewards-referrals' ) . '</span><h2>' . esc_html( $pages[ $tab ][0] ) . '</h2><p>' . esc_html( $pages[ $tab ][1] ) . '</p></div></div>';
		if ( 'campaigns' === $tab || 'reports' === $tab ) {
			if ( RR_Commercial::has_pro() ) { RR_Pro::render( $tab ); }
			else { RR_Commercial::card( $pages[ $tab ][0] ); }
		}
		elseif ( 'notifications' === $tab ) { self::notifications(); }
		else { self::tools(); }
	}

	public static function form_start( $operation, $class = 'rr-settings-form' ) {
		echo '<form class="' . esc_attr( $class ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rr_operations"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		wp_nonce_field( 'rr_operations_' . $operation );
	}

	public static function button( $label, $dirty_save = false ) {
		if ( $dirty_save ) { echo '<div class="rr-save-bar" data-rr-save-bar hidden aria-hidden="true" aria-live="polite"><span>' . esc_html__( 'Review and save your changes.', 'rewards-referrals' ) . '</span>'; }
		echo '<button class="rr-button rr-button-primary" type="submit">' . esc_html( $label ) . '</button>';
		if ( $dirty_save ) { echo '</div>'; }
	}


	public static function report_filter( $input ) {
		$out = array( 'from' => '', 'to' => '', 'report_type' => '', 'customer' => 0 );
		foreach ( array( 'from', 'to' ) as $key ) { $out[ $key ] = self::date( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? $input[ $key ] : '' ); }
		$types = array( 'purchase_points', 'referral_commission', 'redemption_debit', 'redemption_restore', 'refund_reversal', 'point_expiration', 'manual_adjustment', 'promotional_bonus' );
		$type = isset( $input['report_type'] ) && is_scalar( $input['report_type'] ) ? sanitize_key( $input['report_type'] ) : '';
		$out['report_type'] = in_array( $type, $types, true ) ? $type : '';
		$out['customer'] = isset( $input['customer'] ) && is_scalar( $input['customer'] ) ? absint( $input['customer'] ) : 0;
		return $out;
	}

	public static function report_where( $filters ) {
		global $wpdb;
		$where = ' WHERE 1=1';
		if ( $filters['from'] ) { $where .= $wpdb->prepare( ' AND created_at >= %s', $filters['from'] . ' 00:00:00' ); }
		if ( $filters['to'] ) { $where .= $wpdb->prepare( ' AND created_at <= %s', $filters['to'] . ' 23:59:59' ); }
		if ( $filters['report_type'] ) { $where .= $wpdb->prepare( ' AND transaction_type = %s', $filters['report_type'] ); }
		if ( $filters['customer'] ) { $where .= $wpdb->prepare( ' AND customer_id = %d', $filters['customer'] ); }
		return $where;
	}


	public static function notification_defaults() { return array( 'enabled' => 'no', 'purchase_points' => 'yes', 'referral_commission' => 'yes', 'redemption_debit' => 'yes', 'redemption_restore' => 'yes', 'subject' => __( 'Your rewards activity at {site}', 'rewards-referrals' ), 'body' => __( "Hello {name},\n\nYour rewards account has a new activity: {activity}.\nPoints: {points}\nAvailable balance: {balance}\n\nView your rewards: {account}", 'rewards-referrals' ) ); }

	private static function notifications() {
		$s = wp_parse_args( get_option( 'rr_notifications', array() ), self::notification_defaults() );
		self::form_start( 'notifications' );
		echo '<section class="rr-card rr-setting-card"><h3>' . esc_html__( 'Customer rewards emails', 'rewards-referrals' ) . '</h3>';
		foreach ( array( 'enabled' => __( 'Enable customer emails', 'rewards-referrals' ), 'purchase_points' => __( 'Purchase points earned', 'rewards-referrals' ), 'referral_commission' => __( 'Direct referral commission earned', 'rewards-referrals' ), 'redemption_debit' => __( 'Redemption confirmed', 'rewards-referrals' ), 'redemption_restore' => __( 'Reward points refunded', 'rewards-referrals' ) ) as $key => $label ) { echo '<label class="rr-check-label"><input type="checkbox" name="' . esc_attr( $key ) . '" value="yes" ' . checked( $s[ $key ], 'yes', false ) . '>' . esc_html( $label ) . '</label>'; }
		if ( RR_Commercial::has_pro() ) { RR_Pro::notification_fields( $s ); }
		else { echo '<p class="rr-help">' . esc_html__( 'Standard rewards emails are included in Free. Custom subjects and message templates are available in Pro.', 'rewards-referrals' ) . '</p>'; }
		self::button( __( 'Save notification settings', 'rewards-referrals' ), true );
		echo '<p class="rr-help">' . esc_html__( 'Emails are queued and sent using the site’s WordPress mail configuration. Delivery depends on that configuration; the diagnostic screen reports queue failures.', 'rewards-referrals' ) . '</p></section></form>';
		RR_Outbox::render();
		self::help( __( 'Notifications help', 'rewards-referrals' ), array( array( __( 'Why are event boxes enabled while emails are off?', 'rewards-referrals' ), __( 'They define which events are allowed to send once customer emails are enabled. Keep emails off while reviewing the template.', 'rewards-referrals' ) ), array( __( 'Which placeholders can I use?', 'rewards-referrals' ), __( 'Use only the tokens shown beneath the message. They are replaced with the customer, point activity, balance, site, and account values at send time.', 'rewards-referrals' ) ) ) );
	}

	public static function health() {
		global $wpdb;
		$health = array( 'WordPress' => get_bloginfo( 'version' ), 'WooCommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Unavailable', 'rewards-referrals' ), 'PHP' => PHP_VERSION, 'Schema version' => get_option( 'br_schema_version', '0' ), 'Rules version' => get_option( 'br_rules_version', 1 ), 'Active theme' => wp_get_theme()->get( 'Name' ), 'HPOS' => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'Enabled' : 'Disabled' );
		$order_storage = RR_Database::verify_order_storage();
		$health['WooCommerce transactional order storage'] = is_wp_error( $order_storage ) ? $order_storage->get_error_message() : __( 'Ready (InnoDB)', 'rewards-referrals' );
		$owned = array( 'ledger','referrals','rules','audit','wallet_locks','redemptions','referral_codes','referral_history','point_wallets','point_lots','point_allocations','redemption_items','wallet_balances','catalog','catalog_queue','credit_facts','commission_totals','customer_lookup','request_limits','notification_outbox','wallet_rebuilds','recovery_events','legacy_lot_migrations' );
		$names = array_map( array( 'RR_Database','table' ), $owned ); $marks = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$engines = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks)", $names ), OBJECT_K );
		foreach ( $owned as $suffix ) { $name = RR_Database::table( $suffix ); $health[ $suffix . ' storage' ] = isset( $engines[ $name ] ) ? $engines[ $name ]->ENGINE : 'MISSING'; }
		$redemptions = RR_Database::table( 'redemptions' );
		$health['Stale reserved redemptions'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'reserved' AND created_at < %s", $redemptions, gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
		$state = RR_Catalog::state();
		$health['Catalog indexing'] = $state['complete'] ? 'Ready' : 'In progress: ' . $state['cursor'];
		$health['Notification failures'] = 0;
		foreach ( (array) RR_Outbox::counts() as $queue_state ) { $health['Notifications ' . $queue_state['status']] = (int) $queue_state['total']; if ( in_array( $queue_state['status'], array( 'failed','uncertain' ), true ) ) { $health['Notification failures'] += (int) $queue_state['total']; } }
		$health['Commission history index'] = RR_Commission_Index::ready() ? 'Ready' : 'Building';
		$health['Customer lookup index'] = get_option( 'rr_customer_index_complete' ) ? 'Ready' : 'Building';
		$health['WP scheduled maintenance'] = wp_next_scheduled( 'rr_accounting_maintenance' ) ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'rr_accounting_maintenance' ) ) . ' UTC' : 'Not scheduled';
		return $health;
	}

	private static function tools() {
		RR_Recovery::render();
		if ( RR_Evidence::render() ) { return; }
		RR_Customer_Search::render_tools();
		echo '<div class="rr-settings-grid"><section class="rr-card rr-setting-card"><h3>' . esc_html__( 'System health', 'rewards-referrals' ) . '</h3><dl class="rr-health-list">';
		foreach ( self::health() as $name => $value ) { echo '<div><dt>' . esc_html( $name ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>'; }
		echo '</dl></section><section class="rr-card rr-setting-card"><h3>' . esc_html__( 'Configuration transfer', 'rewards-referrals' ) . '</h3><p class="rr-help">' . esc_html__( 'Export settings as JSON. Import accepts settings only and creates a new prospective rule version. Customer balances and credentials are excluded.', 'rewards-referrals' ) . '</p>';
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'rr_export', 'format' => 'settings' ), admin_url( 'admin-post.php' ) ), 'rr_export' );
		echo '<a class="rr-button rr-button-light" href="' . esc_url( $url ) . '">' . esc_html__( 'Export settings JSON', 'rewards-referrals' ) . '</a>';
		self::form_start( 'import' );
		echo '<div class="rr-field rr-spaced"><label for="rr-import">' . esc_html__( 'Paste exported settings JSON', 'rewards-referrals' ) . '</label><textarea class="rr-control" name="settings_json" id="rr-import" maxlength="100000" rows="5" required></textarea></div>';
		self::button( __( 'Validate and import settings', 'rewards-referrals' ) );
		echo '</form><hr>';
		self::form_start( 'maintenance' );
		self::button( __( 'Run safe accounting maintenance', 'rewards-referrals' ) );
		echo '</form><p class="rr-help">' . esc_html__( 'Processes expired unpaid reservations and any supported due accounting transitions in bounded batches. This does not rewrite historical point amounts.', 'rewards-referrals' ) . '</p><hr>';
		self::form_start( 'retention' );
		echo '<label class="rr-check-label"><input type="checkbox" name="delete_on_uninstall" value="yes" ' . checked( get_option( 'rr_delete_on_uninstall', 'no' ), 'yes', false ) . '>' . esc_html__( 'Delete all plugin accounting data when this plugin is uninstalled', 'rewards-referrals' ) . '</label><p class="rr-help">' . esc_html__( 'Keep disabled to preserve financial history. WordPress privacy tools export a customer’s rewards data. Erasure retains accounting amounts, IDs, and relationship history needed to preserve balance integrity; retention is disclosed through WordPress privacy tools.', 'rewards-referrals' ) . '</p>';
		self::button( __( 'Save data retention choice', 'rewards-referrals' ), true );
		echo '</form></section></div>';
		self::help( __( 'Tools and diagnostics help', 'rewards-referrals' ), array( array( __( 'Is maintenance safe?', 'rewards-referrals' ), __( 'It processes supported due transitions in bounded batches and does not rewrite historical point amounts. Run it when system health identifies stale work.', 'rewards-referrals' ) ), array( __( 'What is included in settings export?', 'rewards-referrals' ), __( 'Only program configuration. Customer balances, ledger entries, and credentials are intentionally excluded.', 'rewards-referrals' ) ), array( __( 'Should I delete data on uninstall?', 'rewards-referrals' ), __( 'Normally no: rewards records can be accounting history. Enable deletion only when your retention policy permits it.', 'rewards-referrals' ) ) ) );
	}

	public static function help( $title, $items ) {
		echo '<section class="rr-faq" aria-label="' . esc_attr( $title ) . '"><div class="rr-faq-heading"><span>?</span><div><span class="rr-section-kicker">' . esc_html__( 'GUIDANCE', 'rewards-referrals' ) . '</span><h3>' . esc_html( $title ) . '</h3><p>' . esc_html__( 'Short answers for the decisions on this screen.', 'rewards-referrals' ) . '</p></div></div><div class="rr-faq-list">';
		foreach ( $items as $item ) { echo '<details><summary>' . esc_html( $item[0] ) . '</summary><p>' . esc_html( $item[1] ) . '</p></details>'; }
		echo '</div></section>';
	}

	public static function date( $value ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ) { return ''; }
		$parts = explode( '-', (string) $value );
		return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ? (string) $value : '';
	}

	public static function persist_settings( $settings, $action = 'rules_changed' ) {
		global $wpdb;
		$lock = 'rr_rules_' . substr( md5( $wpdb->prefix ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) { return new WP_Error( 'rr_settings_busy', __( 'Settings are being saved by another request. Please retry.', 'rewards-referrals' ) ); }
		$started = false;
		try {
			foreach ( array( $wpdb->options, RR_Database::table( 'rules' ), RR_Database::table( 'audit' ) ) as $table_name ) {
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table_name ) );
				if ( 'InnoDB' !== $engine ) { return new WP_Error( 'rr_settings_storage', __( 'Settings cannot be saved atomically because a required table does not use InnoDB.', 'rewards-referrals' ) ); }
			}
			$started = RR_Ledger::begin_wallet_transaction( get_current_user_id() );
			if ( is_wp_error( $started ) ) { return $started; }
			// Match policy readers' ordering and lock both rows before upgrading either value.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,option_value FROM %i WHERE option_name IN (%s,%s) ORDER BY option_name FOR UPDATE', $wpdb->options, 'br_settings', 'br_rules_version' ), ARRAY_A );
			if ( $wpdb->last_error || ! is_array( $rows ) || 2 !== count( $rows ) ) { return new WP_Error( 'rr_settings_read', __( 'The saved policy could not be verified. Please retry.', 'rewards-referrals' ) ); }
			$stored = array_column( $rows, 'option_value', 'option_name' );
			$version = RR_Ledger::validated_integer( $stored['br_rules_version'] ?? null, 1, PHP_INT_MAX - 1 );
			if ( null === $version ) { return new WP_Error( 'rr_settings_version', __( 'The saved rule version is invalid. Settings were not changed.', 'rewards-referrals' ) ); }
			$previous = RR_Rules::checked_snapshot();
			if ( is_wp_error( $previous ) ) { return $previous; }
			$previous_settings = $previous['settings'];
			++$version;
			$table = RR_Database::table( 'rules' );
			$written = $wpdb->insert( $table, array( 'version' => $version, 'settings' => wp_json_encode( $settings ), 'starts_at' => current_time( 'mysql', true ), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
			if ( 1 !== $written ) { RR_Ledger::finish_wallet_transaction( false ); $started = false; return new WP_Error( 'rr_settings_snapshot', __( 'The new rule snapshot could not be saved.', 'rewards-referrals' ) ); }
			foreach ( array( 'br_rules_version' => $version, 'br_settings' => $settings ) as $key => $value ) {
				$result = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $wpdb->options, $key, maybe_serialize( $value ) ) );
				if ( false === $result ) { RR_Ledger::finish_wallet_transaction( false ); $started = false; return new WP_Error( 'rr_settings_write', __( 'The program settings could not be saved.', 'rewards-referrals' ) ); }
			}
			RR_Admin::audit( $action, 0, array( 'rule_version' => $version ) );
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_settings_audit', __( 'The settings audit could not be recorded. Settings were not changed.', 'rewards-referrals' ) ); }
			$finished = RR_Ledger::finish_wallet_transaction( true );
			$started = false;
			if ( is_wp_error( $finished ) ) { return $finished; }
			foreach ( array( 'br_settings', 'br_rules_version', 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
			delete_transient( 'rr_dashboard_stats' );
			RR_Catalog::settings_changed( $previous_settings, $settings );
			return $version;
		} finally {
			if ( true === $started ) { RR_Ledger::finish_wallet_transaction( false ); }
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Save an existing administrative configuration from a locked, uncached value with its audit record. */
	public static function update_configuration_option( $name, callable $update, $audit_action ) {
		$defaults = array( 'rr_notifications' => array(), 'rr_delete_on_uninstall' => 'no', 'rr_campaigns' => array() );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! array_key_exists( $name, $defaults ) ) { return new WP_Error( 'rr_configuration_permission', __( 'Permission denied.', 'rewards-referrals' ) ); }
		global $wpdb;
		$lock = 'rr_configuration_' . substr( md5( $wpdb->prefix . $name ), 0, 24 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) { return new WP_Error( 'rr_configuration_busy', __( 'Configuration is being saved by another request. Please retry.', 'rewards-referrals' ) ); }
		$started = false;
		try {
			foreach ( array( $wpdb->options, RR_Database::table( 'audit' ) ) as $table_name ) {
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table_name ) );
				if ( 'InnoDB' !== $engine ) { return new WP_Error( 'rr_configuration_storage', __( 'Configuration storage could not be verified. No changes were saved.', 'rewards-referrals' ) ); }
			}
			$started = RR_Ledger::begin_wallet_transaction( get_current_user_id() );
			if ( is_wp_error( $started ) ) { return $started; }
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, $name ), ARRAY_A );
			if ( $wpdb->last_error || ! is_array( $rows ) ) { return new WP_Error( 'rr_configuration_read', __( 'The saved configuration could not be read. No changes were saved.', 'rewards-referrals' ) ); }
			$previous = $rows ? maybe_unserialize( $rows[0]['option_value'] ) : $defaults[ $name ];
			$valid = 'rr_delete_on_uninstall' === $name ? in_array( $previous, array( 'yes', 'no' ), true ) : is_array( $previous );
			if ( ! $valid ) { return new WP_Error( 'rr_configuration_invalid', __( 'The stored configuration needs review. No changes were saved.', 'rewards-referrals' ) ); }
			$value = $update( $previous );
			if ( is_wp_error( $value ) ) { return $value; }
			$value = sanitize_option( $name, $value );
			$value = apply_filters( "pre_update_option_{$name}", $value, $previous, $name );
			$value = apply_filters( 'pre_update_option', $value, $name, $previous );
			$valid = 'rr_delete_on_uninstall' === $name ? in_array( $value, array( 'yes', 'no' ), true ) : is_array( $value );
			if ( ! $valid ) { return new WP_Error( 'rr_configuration_invalid', __( 'The new configuration is invalid. No changes were saved.', 'rewards-referrals' ) ); }
			if ( $rows && maybe_serialize( $previous ) === maybe_serialize( $value ) ) {
				foreach ( array( $name, 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
				return true;
			}
			if ( $rows ) { do_action( 'update_option', $name, $previous, $value ); }
			else { do_action( 'add_option', $name, $value ); }
			$written = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload=VALUES(autoload)", $wpdb->options, $name, maybe_serialize( $value ) ) );
			if ( false === $written ) { return new WP_Error( 'rr_configuration_write', __( 'Configuration could not be saved. Please retry.', 'rewards-referrals' ) ); }
			RR_Admin::audit( $audit_action, 0, array( 'option' => $name ) );
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_configuration_audit', __( 'The configuration audit could not be saved. No changes were saved.', 'rewards-referrals' ) ); }
			$finished = RR_Ledger::finish_wallet_transaction( true );
			$started = false;
			if ( is_wp_error( $finished ) ) { return $finished; }
			foreach ( array( $name, 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
			// Notify integrations only after both configuration and audit have committed.
			try {
			if ( $rows && $previous !== $value ) {
				do_action( "update_option_{$name}", $previous, $value, $name );
				do_action( 'updated_option', $name, $previous, $value );
			} elseif ( ! $rows ) {
				do_action( "add_option_{$name}", $name, $value );
				do_action( 'added_option', $name, $value );
			}
			} catch ( Throwable $error ) {
				// The save is committed: an integration notification cannot turn it into a failed save.
				if ( function_exists( 'wc_get_logger' ) ) {
					try { wc_get_logger()->error( 'A post-save configuration integration failed after commit.', array( 'source' => 'rewards-referrals' ) ); } catch ( Throwable $logging_error ) { /* Preserve the committed result if logging is unavailable. */ }
				}
			}
			return true;
		} finally {
			if ( true === $started ) { RR_Ledger::finish_wallet_transaction( false ); }
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		$operation = isset( $_POST['operation'] ) && is_scalar( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( ! in_array( $operation, array( 'campaigns', 'notifications', 'import', 'maintenance', 'retention' ), true ) ) { wp_die( esc_html__( 'Unknown operation.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); }
		check_admin_referer( 'rr_operations_' . $operation );
		$tab = 'tools';
		if ( 'campaigns' === $operation ) {
			if ( ! RR_Commercial::has_pro() ) { wp_die( esc_html__( 'An eligible Pro license is required to edit campaigns.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
			$result = RR_Pro::save_campaigns();
			if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 503, 'back_link' => true ) ); }
			$tab = 'campaigns';
		} elseif ( 'notifications' === $operation ) {
			$choices = array();
			foreach ( array( 'enabled', 'purchase_points', 'referral_commission', 'redemption_debit', 'redemption_restore' ) as $key ) { $choices[ $key ] = isset( $_POST[ $key ] ) && 'yes' === $_POST[ $key ] ? 'yes' : 'no'; }
			$result = self::update_configuration_option( 'rr_notifications', static function ( $previous ) use ( $choices ) {
				$s = array_merge( self::notification_defaults(), $previous, $choices );
				if ( RR_Commercial::has_pro() ) { $s = RR_Pro::save_notification_fields( $s ); }
				return $s;
			}, 'notifications_changed' );
			if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 503, 'back_link' => true ) ); }
			$tab = 'notifications';
		} elseif ( 'import' === $operation ) {
			$json = isset( $_POST['settings_json'] ) && is_scalar( $_POST['settings_json'] ) ? wp_unslash( $_POST['settings_json'] ) : '';
			$data = strlen( $json ) <= 100000 ? json_decode( $json, true, 32 ) : null;
			if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'rewards-referrals' || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) || ! method_exists( 'RR_Rules', 'sanitize' ) ) { wp_die( esc_html__( 'This is not a valid Rewards & Referrals settings export.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); }
			$new = RR_Rules::sanitize( $data['settings'], RR_Rules::get() );
			if ( is_wp_error( $new ) ) { wp_die( esc_html( $new->get_error_message() ), '', array( 'response' => 400 ) ); }
			$result = self::persist_settings( $new, 'settings_imported' );
			if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) ); }
		} elseif ( 'retention' === $operation ) {
			$choice = isset( $_POST['delete_on_uninstall'] ) && 'yes' === $_POST['delete_on_uninstall'] ? 'yes' : 'no';
			$result = self::update_configuration_option( 'rr_delete_on_uninstall', static function () use ( $choice ) { return $choice; }, 'retention_changed' );
			if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 503, 'back_link' => true ) ); }
		}
		else { self::maintenance(); RR_Admin::audit( 'maintenance_run' ); }
		delete_transient( 'rr_dashboard_stats' );
		wp_safe_redirect( add_query_arg( array( 'page' => RR_Admin::page_for_tab( $tab ), 'tab' => $tab, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function maintenance() { RR_Recovery::work(); RR_Wallet_Rebuild::poll(); RR_Commission_Index::work(); RR_Customer_Search::work(); RR_Outbox::work(); RR_Catalog::work(); RR_Ledger::expire_reservations(); do_action( 'rr_after_accounting_maintenance' ); }

	public static function export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_export' );
		$format = isset( $_GET['format'] ) && is_scalar( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : '';
		if ( ! in_array( $format, array( 'settings', 'ledger' ), true ) ) { wp_die( esc_html__( 'Unknown export format.', 'rewards-referrals' ), '', array( 'response' => 400 ) ); }
		nocache_headers();
		if ( 'settings' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="rewards-referrals-settings.json"' );
			echo wp_json_encode( array( 'plugin' => 'rewards-referrals', 'version' => REWARDS_REFERRALS_VERSION, 'settings' => RR_Rules::get() ), JSON_PRETTY_PRINT );
		} else {
			global $wpdb;
			$table = RR_Database::table( 'ledger' );
			$where = self::report_where( self::report_filter( wp_unslash( $_GET ) ) );
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="rewards-referrals-ledger.csv"' );
			$stream = fopen( 'php://output', 'w' );
			fputcsv( $stream, array( 'ID', 'Customer ID', 'Signed points', 'Type', 'State', 'Order ID', 'Referral ID', 'Rule version', 'Reference ID', 'Created UTC' ) );
			$cursor = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,customer_id,amount,transaction_type,state,order_id,referral_id,rule_version,reference_id,created_at FROM $table$where AND id > %d ORDER BY id ASC LIMIT 500", $cursor ), ARRAY_A );
				foreach ( $rows as $row ) { fputcsv( $stream, array_map( array( __CLASS__, 'csv_cell' ), array_values( $row ) ) ); $cursor = absint( $row['id'] ); }
			} while ( count( $rows ) === 500 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes the php://output CSV response stream, not a filesystem file.
			fclose( $stream );
		}
		exit;
	}

	public static function csv_cell( $value ) { $value = (string) $value; return preg_match( '/^[\s\x00-\x1f]*[=+\-@]/u', $value ) && ! preg_match( '/^-?\d+$/', $value ) ? "'" . $value : $value; }

	public static function queue_notification( $id, $entry ) {
		$s = wp_parse_args( get_option( 'rr_notifications', array() ), self::notification_defaults() );
		if ( 'yes' === $s['enabled'] && 'yes' === ( $s[ $entry['transaction_type'] ] ?? 'no' ) ) { RR_Outbox::wake(); }
	}
	public static function send_notification( $id, $attempt = 0 ) { RR_Outbox::adopt( $id ); }

	public static function privacy_exporters( $exporters ) { $exporters['rewards-referrals'] = array( 'exporter_friendly_name' => __( 'Rewards & Referrals', 'rewards-referrals' ), 'callback' => array( __CLASS__, 'privacy_export' ) ); return $exporters; }
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { return; }
		wp_add_privacy_policy_content( __( 'Rewards & Referrals', 'rewards-referrals' ), '<p>' . esc_html__( 'Rewards & Referrals stores points transactions, order links, direct referral relationships and referral codes to operate the store loyalty program. A staff-only search index copies customer names, usernames and email addresses from WordPress. A referral link can set an HTTP-only, SameSite=Lax br_ref cookie for the configured attribution period; the store owner must obtain any consent required before permitting referral tracking through the rr_referral_tracking_allowed filter.', 'rewards-referrals' ) . '</p><p>' . esc_html__( 'Optional reward emails use the site mail service. Delivery records store transaction identifiers and status, not copies of the message or recipient address. Accepted/skipped delivery records are retained for 90 days; failed/uncertain records for 365 days; queued notices expire after 90 days. Customer statements and the WordPress personal-data exporter include reward records. Financial transaction amounts, account identifiers and referral history are retained on erasure requests to protect balances and audit integrity. The store owner must review the applicable retention basis. Uninstall preserves plugin data by default; explicit deletion removes owned records. Any Freemius connection has its own opt-in and privacy notice.', 'rewards-referrals' ) . '</p>' );
	}
	public static function privacy_export( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) { return array( 'data' => array(), 'done' => true ); }
		$page = max( 1, absint( $page ) ); $offset = ( $page - 1 ) * 100;
		$table = RR_Database::table( 'ledger' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,amount,transaction_type,state,order_id,created_at FROM %i WHERE customer_id=%d ORDER BY id LIMIT 100 OFFSET %d", $table, $user->ID, $offset ), ARRAY_A );
		if ( $wpdb->last_error ) { return new WP_Error( 'rr_privacy_storage', __( 'Rewards personal data could not be read. Retry after checking database health.', 'rewards-referrals' ) ); }
		$data = array();
		foreach ( (array) $rows as $row ) { $data[] = self::privacy_item( 'rewards-ledger-' . $row['id'], __( 'Rewards history', 'rewards-referrals' ), $row ); }
		$history = RR_Database::table( 'referral_history' );
		$relations = $wpdb->get_results( $wpdb->prepare( "SELECT id,referrer_id,customer_id,referral_code,created_at,reassigned_at,reason FROM %i WHERE customer_id=%d ORDER BY id LIMIT 100 OFFSET %d", $history, $user->ID, $offset ), ARRAY_A );
		if ( $wpdb->last_error ) { return new WP_Error( 'rr_privacy_storage', __( 'Rewards personal data could not be read. Retry after checking database health.', 'rewards-referrals' ) ); }
		foreach ( (array) $relations as $row ) { $data[] = self::privacy_item( 'rewards-referral-history-' . $row['id'], __( 'Referral history', 'rewards-referrals' ), $row ); }
		$outbox = RR_Database::table( 'notification_outbox' );
		$notices = $wpdb->get_results( $wpdb->prepare( "SELECT n.event_id,n.status,n.attempts,n.created_at,n.updated_at,n.error_code FROM %i n INNER JOIN %i l ON l.id=n.event_id WHERE l.customer_id=%d ORDER BY n.event_id LIMIT 100 OFFSET %d", $outbox, $table, $user->ID, $offset ), ARRAY_A );
		if ( $wpdb->last_error ) { return new WP_Error( 'rr_privacy_storage', __( 'Rewards personal data could not be read. Retry after checking database health.', 'rewards-referrals' ) ); }
		foreach ( (array) $notices as $row ) { $data[] = self::privacy_item( 'rewards-notice-' . $row['event_id'], __( 'Reward email delivery', 'rewards-referrals' ), $row ); }
		if ( 1 === $page ) {
			foreach ( array( 'customer_lookup', 'referral_codes', 'referrals' ) as $suffix ) {
				$owned = RR_Database::table( $suffix );
				if ( 'customer_lookup' === $suffix ) {
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_id,display_name,login_prefix,email_prefix FROM %i WHERE customer_id=%d LIMIT 1', $owned, $user->ID ), ARRAY_A );
				} elseif ( 'referral_codes' === $suffix ) {
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_id,referral_code,created_at FROM %i WHERE customer_id=%d LIMIT 1', $owned, $user->ID ), ARRAY_A );
				} else {
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_id,referrer_id,referral_code,created_at,approved FROM %i WHERE customer_id=%d LIMIT 1', $owned, $user->ID ), ARRAY_A );
				}
				if ( $wpdb->last_error ) { return new WP_Error( 'rr_privacy_storage', __( 'Rewards personal data could not be read. Retry after checking database health.', 'rewards-referrals' ) ); }
				if ( $row ) { $data[] = self::privacy_item( 'rewards-' . $suffix . '-' . $user->ID, __( 'Rewards account information', 'rewards-referrals' ), $row ); }
			}
		}
		return array( 'data' => $data, 'done' => count( (array) $rows ) < 100 && count( (array) $relations ) < 100 && count( (array) $notices ) < 100 );
	}
	private static function privacy_item( $id, $label, $row ) {
		$items = array( array( 'name' => __( 'Record type', 'rewards-referrals' ), 'value' => $label ) ); foreach ( $row as $key => $value ) { $items[] = array( 'name' => ucwords( str_replace( '_', ' ', $key ) ), 'value' => (string) $value ); }
		return array( 'group_id' => 'rewards-referrals', 'group_label' => __( 'Rewards & Referrals', 'rewards-referrals' ), 'item_id' => $id, 'data' => $items );
	}
	public static function privacy_erasers( $erasers ) { $erasers['rewards-referrals'] = array( 'eraser_friendly_name' => __( 'Rewards & Referrals', 'rewards-referrals' ), 'callback' => array( __CLASS__, 'privacy_erase' ) ); return $erasers; }
	public static function privacy_erase( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		return array( 'items_removed' => false, 'items_retained' => (bool) $user, 'messages' => $user ? array( __( 'Rewards accounting records and referral relationships are retained for audit and balance integrity. Contact the store owner to review applicable retention requirements.', 'rewards-referrals' ) ) : array(), 'done' => true );
	}
}
