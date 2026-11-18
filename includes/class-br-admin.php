<?php
defined( 'ABSPATH' ) || exit;

final class RR_Admin {
	private const PAGE = 'rewards-referrals';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_rr_admin_route', array( __CLASS__, 'ajax_route' ) );
		add_action( 'wp_ajax_rr_rule_preview', array( __CLASS__, 'rule_preview' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( REWARDS_REFERRALS_FILE ), array( __CLASS__, 'plugin_links' ) );
		add_action( 'admin_post_br_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_br_adjust_points', array( __CLASS__, 'adjust_points' ) );
		add_action( 'admin_post_br_approve_referral', array( __CLASS__, 'approve_referral' ) );
		add_action( 'admin_post_rr_catalog_bulk', array( __CLASS__, 'catalog_bulk' ) );
	}

	public static function menu() {
		$icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a7f3d0" d="M12 2.2 14.9 8l6.4.9-4.6 4.5 1.1 6.4-5.8-3-5.8 3 1.1-6.4-4.6-4.5L9.1 8 12 2.2Z"/><path fill="#34d399" d="m12 5.3 1.9 3.8 4.2.6-3.1 3 .7 4.2-3.7-2-3.7 2 .7-4.2-3.1-3 4.2-.6L12 5.3Z"/></svg>';
		add_menu_page( __( 'Rewards & Referrals', 'rewards-referrals' ), __( 'Rewards', 'rewards-referrals' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ), 'data:image/svg+xml;base64,' . base64_encode( $icon ), 56 );
		foreach ( self::areas() as $area ) {
			add_submenu_page( self::PAGE, $area['label'], $area['label'], 'manage_woocommerce', $area['page'], array( __CLASS__, 'page' ) );
		}
	}

	/** Stored accounting timestamps are UTC; display in the site's configured timezone. */
	public static function local_date( $utc, $with_time = false ) {
		$timestamp = strtotime( (string) $utc . ' UTC' );
		return false === $timestamp ? '' : wp_date( get_option( 'date_format' ) . ( $with_time ? ' ' . get_option( 'time_format' ) : '' ), $timestamp );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE ) ) { return; }
		$current_area = self::areas()[ self::area_for_page( self::current_page_slug() ) ];
		wp_enqueue_style( 'rr-admin', REWARDS_REFERRALS_URL . 'assets/admin.css', array(), REWARDS_REFERRALS_VERSION );
		wp_enqueue_script( 'rr-admin', REWARDS_REFERRALS_URL . 'assets/admin.js', array(), REWARDS_REFERRALS_VERSION, true );
		wp_enqueue_script( 'rr-program-check', REWARDS_REFERRALS_URL . 'assets/program-check.js', array( 'rr-admin' ), REWARDS_REFERRALS_VERSION, true );
		wp_enqueue_script( 'rr-customer-search', REWARDS_REFERRALS_URL . 'assets/customer-search.js', array( 'rr-admin' ), REWARDS_REFERRALS_VERSION, true );
		wp_localize_script( 'rr-admin', 'RRAdminConfig', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'rr_admin_route' ),
			'page' => self::current_page_slug(),
			'pages' => wp_list_pluck( self::areas(), 'page' ),
			'defaultTab' => $current_area['default'],
			'findCustomer' => __( 'Find by ID or the beginning of a name or email', 'rewards-referrals' ),
			'searchCustomers' => __( 'Search customers', 'rewards-referrals' ),
			'searching' => __( 'Searching…', 'rewards-referrals' ),
			'options' => __( 'Options', 'rewards-referrals' ),
			'unsaved' => __( 'Unsaved changes', 'rewards-referrals' ),
			'sectionLoaded' => __( 'Rewards section loaded:', 'rewards-referrals' ),
			'sectionFailed' => __( 'The section could not be loaded. Please retry.', 'rewards-referrals' ),
			'discardChanges' => __( 'You have unsaved changes. Leave this section and discard them?', 'rewards-referrals' ),
			'loadingSection' => __( 'Loading Rewards section…', 'rewards-referrals' ),
			'calculating' => __( 'Calculating…', 'rewards-referrals' ),
			'previewFailed' => __( 'Preview could not be calculated. Please retry.', 'rewards-referrals' ),
			'noProducts' => __( 'No matching simple products or variations. Refine your search or enter an exact product ID.', 'rewards-referrals' ),
			'chooseProduct' => __( 'Choose a product from the search results first.', 'rewards-referrals' ),
			'previewStale' => __( 'Inputs changed. Run the preview again to update this estimate.', 'rewards-referrals' ),
			'previewContext' => __( 'Purchase estimate only; saved rules and current time. No order or rewards were created.', 'rewards-referrals' ),
			'noCustomers' => __( 'No matching customers.', 'rewards-referrals' ),
			'searchFailed' => __( 'Search failed. Try again or enter the customer ID.', 'rewards-referrals' ),
		) );
	}

	public static function ajax_route() {
		$started_at = microtime( true );
		$queries_before = get_num_queries();
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this section.', 'rewards-referrals' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'rr_admin_route', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the dashboard and try again.', 'rewards-referrals' ) ), 403 );
		}
		$tab = isset( $_POST['tab'] ) && is_scalar( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'overview';
		$allowed = array( 'overview', 'settings', 'readiness', 'catalog', 'referrals', 'redemptions', 'wallets', 'ledger', 'campaigns', 'reports', 'notifications', 'tools' );
		if ( ! in_array( $tab, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'That dashboard section is not available.', 'rewards-referrals' ) ), 400 );
		}
		$_GET['paged'] = isset( $_POST['paged'] ) && is_scalar( $_POST['paged'] ) ? absint( wp_unslash( $_POST['paged'] ) ) : 1;
		$_GET['s'] = isset( $_POST['s'] ) && is_scalar( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$_GET['category'] = isset( $_POST['category'] ) && is_scalar( $_POST['category'] ) ? absint( wp_unslash( $_POST['category'] ) ) : 0;
		$_GET['product_type'] = isset( $_POST['product_type'] ) && is_scalar( $_POST['product_type'] ) ? sanitize_key( wp_unslash( $_POST['product_type'] ) ) : '';
		$_GET['product_status'] = isset( $_POST['product_status'] ) && is_scalar( $_POST['product_status'] ) ? sanitize_key( wp_unslash( $_POST['product_status'] ) ) : '';
		$_GET['status'] = isset( $_POST['status'] ) && is_scalar( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$_GET['customer'] = isset( $_POST['customer'] ) && is_scalar( $_POST['customer'] ) ? absint( wp_unslash( $_POST['customer'] ) ) : 0;
		$_GET['q'] = isset( $_POST['q'] ) && is_scalar( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		foreach ( array( 'from', 'to', 'report_type', 'rr_view', 'event', 'actor', 'before', 'version_from', 'version_to', 'pending_before', 'approved_before', 'referral_search', 'preflight_order', 'preflight_date', 'after_customer', 'rr_mail_status', 'rr_mail_before' ) as $key ) { $_GET[ $key ] = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; }
		ob_start();
		if ( 'settings' === $tab ) { self::settings_page(); }
		elseif ( 'readiness' === $tab ) { RR_Program_Check::render(); }
		elseif ( 'catalog' === $tab ) { self::catalog_page(); }
		elseif ( 'referrals' === $tab ) { self::referrals_page(); }
		elseif ( 'redemptions' === $tab ) { self::redemptions_page(); }
		elseif ( 'wallets' === $tab ) { self::wallets_page(); }
		elseif ( in_array( $tab, array( 'campaigns', 'reports', 'notifications', 'tools' ), true ) ) { RR_Operations::render( $tab ); }
		elseif ( 'ledger' === $tab ) { self::ledger_page(); }
		else { self::overview_page(); }
		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html, 'tab' => $tab, 'metrics' => array( 'render_ms' => round( ( microtime( true ) - $started_at ) * 1000, 2 ), 'queries' => get_num_queries() - $queries_before, 'peak_memory_bytes' => memory_get_peak_usage( true ) ) ) );
	}

	public static function plugin_links( $links ) {
		$dashboard = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '"><strong>' . esc_html__( 'Dashboard', 'rewards-referrals' ) . '</strong></a>';
		array_unshift( $links, $dashboard );
		return $links;
	}

	private static function areas() {
		return array(
			'workspace' => array( 'label' => __( 'Workspace', 'rewards-referrals' ), 'page' => self::PAGE, 'tabs' => array( 'overview', 'settings', 'readiness' ), 'default' => 'overview' ),
			'customers' => array( 'label' => __( 'Customers', 'rewards-referrals' ), 'page' => self::PAGE . '-customers', 'tabs' => array( 'catalog', 'referrals', 'wallets' ), 'default' => 'catalog' ),
			'operations' => array( 'label' => __( 'Operations', 'rewards-referrals' ), 'page' => self::PAGE . '-operations', 'tabs' => array( 'redemptions', 'ledger', 'campaigns' ), 'default' => 'redemptions' ),
			'insights' => array( 'label' => __( 'Insights & system', 'rewards-referrals' ), 'page' => self::PAGE . '-insights', 'tabs' => array( 'reports', 'notifications', 'tools' ), 'default' => 'reports' ),
		);
	}

	private static function current_page_slug() {
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::PAGE;
		foreach ( self::areas() as $area ) { if ( $page === $area['page'] ) { return $page; } }
		return self::PAGE;
	}

	private static function area_for_page( $page ) {
		foreach ( self::areas() as $key => $area ) { if ( $page === $area['page'] ) { return $key; } }
		return 'workspace';
	}

	public static function page_for_tab( $tab ) {
		foreach ( self::areas() as $area ) { if ( in_array( $tab, $area['tabs'], true ) ) { return $area['page']; } }
		return self::PAGE;
	}

	private static function tab_url( $tab, $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::page_for_tab( $tab ), 'tab' => $tab ), $extra ), admin_url( 'admin.php' ) );
	}

	public static function rule_preview() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'rr_admin_route', 'nonce', false ) ) { wp_send_json_error( array( 'message' => __( 'Permission denied or session expired.', 'rewards-referrals' ) ), 403 ); }
		$id = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { wp_send_json_error( array( 'message' => __( 'Choose a valid WooCommerce order.', 'rewards-referrals' ) ), 400 ); }
		$result = RR_Rules::purchase_breakdown( $order );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 503 ); }
		/* translators: 1: Purchase points. 2: Rule version. */
		wp_send_json_success( array( 'points' => absint( $result['points'] ), 'rule_version' => $result['rule_version'], 'message' => sprintf( __( 'This order would earn %1$s purchase points using rules version %2$s. No ledger entry was created.', 'rewards-referrals' ), number_format_i18n( $result['points'] ), $result['rule_version'] ) ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rewards-referrals' ) );
		}
		$tabs = array(
			'readiness' => array( __( 'Program check', 'rewards-referrals' ), 'M9 12l2 2 4-4M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6z' ),
			'overview' => array( 'Overview', 'M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z' ),
			'settings' => array( 'Program settings', 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm9 4a8.9 8.9 0 0 0-.1-1.3l2-1.5-2-3.4-2.4 1a9 9 0 0 0-2.2-1.3L14 3h-4l-.4 2.5a9 9 0 0 0-2.2 1.3l-2.4-1-2 3.4 2 1.5a8.9 8.9 0 0 0 0 2.6l-2 1.5 2 3.4 2.4-1a9 9 0 0 0 2.2 1.3L10 21h4l.4-2.5a9 9 0 0 0 2.2-1.3l2.4 1 2-3.4-2-1.5A8.9 8.9 0 0 0 21 12Z' ),
			'catalog' => array( 'Reward catalog', 'M4 4h16v16H4zM8 8h8M8 12h8M8 16h5' ),
			'referrals' => array( 'Referrals', 'M16 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM8 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-6 1.7-6 3.8V21h12v-2.2C14 16.7 11.3 15 8 15Zm8-2c-.5 0-1 .1-1.5.2 1.5 1 2.5 2.4 2.5 4.3V21h5v-2.2c0-3.1-2.7-5.8-6-5.8Z' ),
			'redemptions' => array( 'Redemptions', 'M20 7h-9M14 3l-4 4 4 4M4 17h9m-4-4 4 4-4 4' ),
			'wallets' => array( 'Customer wallets', 'M3 5h18v14H3zM3 9h18M7 15h4' ),
			'ledger' => array( 'Ledger & adjustments', 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm2 4v2h10V7H7Zm0 4v2h10v-2H7Zm0 4v2h6v-2H7Z' ),
			'campaigns' => array( 'Campaigns', 'M4 5h16v14H4zM8 3v4m8-4v4M4 10h16' ),
			'reports' => array( 'Reports', 'M4 20V4m0 16h16M8 16v-4m5 4V7m5 9V9' ),
			'notifications' => array( 'Notifications', 'M4 6h16v12H4zM4 6l8 6 8-6' ),
			'tools' => array( 'Tools & diagnostics', 'M8 3h8v4H8zM5 5H3v16h18V5h-2M7 12h10M7 16h6' ),
		);
		$page_slug = self::current_page_slug();
		$area_key = self::area_for_page( $page_slug );
		$area = self::areas()[ $area_key ];
		$requested_tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( isset( $tabs[ $requested_tab ] ) && ! in_array( $requested_tab, $area['tabs'], true ) ) {
			wp_safe_redirect( self::tab_url( $requested_tab ) );
			exit;
		}
		$active = isset( $tabs[ $requested_tab ] ) && in_array( $requested_tab, $area['tabs'], true ) ? $requested_tab : $area['default'];
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '<div class="wrap rr-wrap"><header class="rr-hero"><div class="rr-brand-lockup">' . self::icon( 'sparkles' ) . '<div><span class="rr-eyebrow">' . esc_html__( 'LOYALTY PROGRAM', 'rewards-referrals' ) . '</span><h1>' . esc_html__( 'Rewards & Referrals', 'rewards-referrals' ) . '</h1><p>' . esc_html__( 'A clear view of customer rewards, referral growth, and the rules behind every point.', 'rewards-referrals' ) . '</p></div></div><div class="rr-hero-side"><span class="rr-live-dot"></span><span data-rr-section-label>' . esc_html( $tabs[ $active ][0] ) . '</span><small>' . esc_html__( 'Rules version', 'rewards-referrals' ) . ' ' . esc_html( get_option( 'br_rules_version', 1 ) ) . '</small></div></header>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Changes saved.', 'rewards-referrals' ) . '</p></div>'; }
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Program settings saved. A new rules version is now active.', 'rewards-referrals' ) . '</p></div>'; }
		$navigation_groups = array( $area['label'] => $area['tabs'] );
		echo '<nav class="rr-tabs" aria-label="' . esc_attr__( 'Rewards dashboard sections', 'rewards-referrals' ) . '">';
		foreach ( $navigation_groups as $group_label => $keys ) {
			echo '<div class="rr-nav-group"><span class="rr-nav-group-label">' . esc_html( $group_label ) . '</span><div class="rr-nav-group-links">';
			foreach ( $keys as $key ) {
				$tab = $tabs[ $key ]; $url = self::tab_url( $key );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
				echo '<a class="rr-tab ' . ( $active === $key ? 'is-active' : '' ) . '" data-rr-nav href="' . esc_url( $url ) . '" ' . ( $active === $key ? 'aria-current="page"' : '' ) . '>' . self::icon( $tab[1] ) . '<span>' . esc_html( $tab[0] ) . '</span></a>';
			}
			echo '</div></div>';
		}
		echo '</nav><main class="rr-content" id="rr-dashboard-content" tabindex="-1"><p class="rr-sr-only" data-rr-status role="status" aria-live="polite"></p>';
		if ( 'settings' === $active ) { self::settings_page(); }
		elseif ( 'readiness' === $active ) { RR_Program_Check::render(); }
		elseif ( 'catalog' === $active ) { self::catalog_page(); }
		elseif ( 'referrals' === $active ) { self::referrals_page(); }
		elseif ( 'redemptions' === $active ) { self::redemptions_page(); }
		elseif ( 'wallets' === $active ) { self::wallets_page(); }
		elseif ( in_array( $active, array( 'campaigns', 'reports', 'notifications', 'tools' ), true ) ) { RR_Operations::render( $active ); }
		elseif ( 'ledger' === $active ) { self::ledger_page(); }
		else { self::overview_page(); }
		echo '</main><footer class="rr-footer"><span>' . esc_html__( 'Rewards & Referrals', 'rewards-referrals' ) . ' <b>·</b> ' . esc_html__( 'Version', 'rewards-referrals' ) . ' ' . esc_html( REWARDS_REFERRALS_VERSION ) . '</span><a href="https://www.linkedin.com/in/abdul-hameed-website-developer/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Built by Abdul Hameed', 'rewards-referrals' ) . ' ↗</a></footer></div>';
	}

	private static function overview_page() {
		$stats = self::stats();
		$settings = RR_Rules::get();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '<section class="rr-welcome"><div><span class="rr-section-kicker">' . esc_html__( 'PROGRAM SNAPSHOT', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Your loyalty program, at a glance.', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'Track points liability, customer referrals, and recent wallet activity from one workspace.', 'rewards-referrals' ) . '</p></div><a class="rr-button rr-button-primary" data-rr-nav href="' . esc_url( self::tab_url( 'settings' ) ) . '">' . self::icon( 'settings' ) . esc_html__( 'Configure program', 'rewards-referrals' ) . '</a></section>';
		echo '<section class="rr-metrics" aria-label="' . esc_attr__( 'Program statistics', 'rewards-referrals' ) . '">';
		self::metric( 'wallet', __( 'Outstanding points', 'rewards-referrals' ), number_format_i18n( $stats['liability'] ), __( 'Net available customer balance', 'rewards-referrals' ), 'mint' );
		self::metric( 'gift', __( 'Points awarded', 'rewards-referrals' ), number_format_i18n( $stats['awarded'] ), __( 'Purchase rewards and commissions', 'rewards-referrals' ), 'violet' );
		self::metric( 'users', __( 'Direct referrals', 'rewards-referrals' ), number_format_i18n( $stats['referrals'] ), __( 'Approved customer relationships', 'rewards-referrals' ), 'blue' );
		self::metric( 'clock', __( 'Needs review', 'rewards-referrals' ), number_format_i18n( $stats['pending'] ), __( 'Referral registrations awaiting approval', 'rewards-referrals' ), $stats['pending'] ? 'amber' : 'slate' );
		echo '</section><section class="rr-dashboard-grid"><article class="rr-card rr-card-main"><div class="rr-card-heading"><div><span class="rr-section-kicker">' . esc_html__( 'LATEST ACTIVITY', 'rewards-referrals' ) . '</span><h3>' . esc_html__( 'Ledger activity', 'rewards-referrals' ) . '</h3></div><a data-rr-nav href="' . esc_url( self::tab_url( 'ledger' ) ) . '">' . esc_html__( 'View ledger', 'rewards-referrals' ) . ' →</a></div>';
		self::recent_ledger( 7 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '</article><aside class="rr-card rr-program-card"><div class="rr-program-top">' . self::icon( 'sparkles' ) . '<span>' . esc_html__( 'ACTIVE PROGRAM', 'rewards-referrals' ) . '</span></div><h3>' . esc_html__( 'Simple rewards. Clear rules.', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Customers earn points on eligible purchases. Their direct referrer can earn a separate commission.', 'rewards-referrals' ) . '</p><div class="rr-rule-line"><span>' . esc_html__( 'Purchase earning', 'rewards-referrals' ) . '</span><strong>' . esc_html( self::format_rate( $settings['points_per_unit'] ) ) . ' ' . esc_html__( 'point(s) per', 'rewards-referrals' ) . ' ' . esc_html( get_woocommerce_currency_symbol() ) . '1</strong></div><div class="rr-rule-line"><span>' . esc_html__( 'Direct commission', 'rewards-referrals' ) . '</span><strong>' . esc_html( number_format_i18n( (float) $settings['commission_rate'], 2 ) ) . '%</strong></div><div class="rr-rule-line"><span>' . esc_html__( 'Commission duration', 'rewards-referrals' ) . '</span><strong>' . esc_html( self::duration_label( $settings ) ) . '</strong></div><a class="rr-button rr-button-light" data-rr-nav href="' . esc_url( self::tab_url( 'settings' ) ) . '">' . esc_html__( 'Review business rules', 'rewards-referrals' ) . '</a></aside></section>';
	}

	private static function settings_page() {
		$s = RR_Rules::get();
		$core = array(
			'purchase_enabled' => array( 'group' => 'Purchase rewards', 'type' => 'select', 'label' => 'Purchase points', 'options' => array( 'yes' => 'Enabled', 'no' => 'Disabled' ) ),
			'points_per_unit' => array( 'group' => 'Purchase rewards', 'type' => 'number', 'label' => 'Points per currency unit', 'min' => 0, 'max' => 1000000, 'step' => '0.01', 'description' => '1 means one currency unit earns one point before other configured policies.' ),
			'commission_enabled' => array( 'group' => 'Referral program', 'type' => 'select', 'label' => 'Direct referral commission', 'options' => array( 'yes' => 'Enabled', 'no' => 'Disabled' ) ),
			'commission_rate' => array( 'group' => 'Referral program', 'type' => 'number', 'label' => 'Commission percentage', 'min' => 0, 'max' => 100, 'step' => '0.01' ),
			'commission_duration' => array( 'group' => 'Referral program', 'type' => 'select', 'label' => 'Commission duration', 'options' => array( 'lifetime' => 'Lifetime', 'first_order' => 'First qualifying order', 'first_n' => 'First N qualifying orders', 'first_item' => 'First qualifying item', 'first_n_items' => 'First N qualifying items', 'days_after_registration' => 'Days after registration', 'days_after_first_purchase' => 'Days after first qualifying purchase', 'fixed_dates' => 'Fixed start and end dates' ) ),
			'first_n' => array( 'group' => 'Referral program', 'type' => 'number', 'label' => 'Qualifying order or item limit', 'min' => 1, 'max' => 1000000, 'step' => 1 ),
			'duration_days' => array( 'group' => 'Referral program', 'type' => 'number', 'label' => 'Commission window in days', 'min' => 1, 'max' => 36500, 'step' => 1 ),
			'cookie_days' => array( 'group' => 'Attribution', 'type' => 'number', 'label' => 'Referral cookie duration in days', 'min' => 1, 'max' => 365, 'step' => 1 ),
			'attribution' => array( 'group' => 'Attribution', 'type' => 'select', 'label' => 'Referral attribution', 'options' => array( 'first' => 'First valid referral click', 'last' => 'Last valid referral click or registration code' ) ),
			'approval' => array( 'group' => 'Attribution', 'type' => 'select', 'label' => 'New relationship approval', 'options' => array( 'automatic' => 'Approve automatically', 'manual' => 'Require administrator approval' ) ),
			'redemption_enabled' => array( 'group' => 'Redemption', 'type' => 'select', 'label' => 'Point redemption', 'options' => array( 'no' => 'Disabled', 'yes' => 'Enabled' ), 'description' => 'Enable after setting point prices on eligible products and reviewing checkout policies.' ),
		);
		$groups = array();
		foreach ( array_merge( $core, RR_Rules::fields() ) as $key => $field ) { $group = 'Referral attribution' === $field['group'] ? 'Attribution' : $field['group']; $groups[ $group ][ $key ] = $field; }
		echo '<div class="rr-page-heading"><div><span class="rr-section-kicker">' . esc_html__( 'CONTROL CENTER', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Program settings', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'Configure earning, referral and redemption policies. Future qualifying activity uses the saved version.', 'rewards-referrals' ) . '</p></div><span class="rr-version-pill">v' . esc_html( get_option( 'br_rules_version', 1 ) ) . '</span></div>';
		echo '<form class="rr-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="br_save_settings">';
		wp_nonce_field( 'br_save_settings' );
		echo '<section class="rr-setup-guide" aria-label="' . esc_attr__( 'Program setup guide', 'rewards-referrals' ) . '"><div><span class="rr-section-kicker">' . esc_html__( 'START HERE', 'rewards-referrals' ) . '</span><h3>' . esc_html__( 'Build your program in three decisions', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Start with earning, referral and redemption choices. Advanced policies stay out of the way until you need them.', 'rewards-referrals' ) . '</p></div><ol><li><strong>' . esc_html__( 'Earning', 'rewards-referrals' ) . '</strong><span>' . esc_html__( 'Choose whether purchases earn points and set the rate.', 'rewards-referrals' ) . '</span></li><li><strong>' . esc_html__( 'Referral', 'rewards-referrals' ) . '</strong><span>' . esc_html__( 'Set direct commission and how long it applies.', 'rewards-referrals' ) . '</span></li><li><strong>' . esc_html__( 'Redemption', 'rewards-referrals' ) . '</strong><span>' . esc_html__( 'Enable points payments only when catalog prices are ready.', 'rewards-referrals' ) . '</span></li></ol></section><div class="rr-settings-grid rr-settings-core">';
		$essential = array( 'Purchase rewards', 'Referral program', 'Redemption' );
		$advanced = array();
		foreach ( $groups as $title => $fields ) {
			if ( ! in_array( $title, $essential, true ) ) { $advanced[ $title ] = $fields; continue; }
			echo '<details class="rr-card rr-setting-card rr-settings-group"' . ( in_array( $title, array( 'Purchase rewards', 'Referral program', 'Redemption' ), true ) ? ' open' : '' ) . '><summary>' . esc_html( $title ) . '</summary><div class="rr-settings-group-content">';
			foreach ( $fields as $key => $field ) { self::schema_field( $key, $field, $s ); }
			echo '</div></details>';
		}
		echo '</div>';
		if ( $advanced ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
			echo '<details class="rr-card rr-advanced-settings"><summary><span>' . self::icon( 'settings' ) . '</span><span><strong>' . esc_html__( 'Advanced policies', 'rewards-referrals' ) . '</strong><small>' . esc_html__( 'Eligibility, limits, dates and attribution. Change these only when your program requires an exception.', 'rewards-referrals' ) . '</small></span></summary><div class="rr-advanced-settings-body rr-settings-grid">';
			foreach ( $advanced as $title => $fields ) { echo '<details class="rr-setting-card rr-settings-group"><summary>' . esc_html( $title ) . '</summary><div class="rr-settings-group-content">'; foreach ( $fields as $key => $field ) { self::schema_field( $key, $field, $s ); } echo '</div></details>'; }
			echo '</div></details>';
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '<div class="rr-save-bar" data-rr-save-bar hidden aria-hidden="true" aria-live="polite"><span>' . self::icon( 'shield' ) . esc_html__( 'You have unsaved program changes. Historical ledger entries retain their original rule version.', 'rewards-referrals' ) . '</span><button class="rr-button rr-button-primary" type="submit">' . esc_html__( 'Save program settings', 'rewards-referrals' ) . '</button></div></form>';
		echo '<section class="rr-card rr-setting-card rr-spaced"><h3>' . esc_html__( 'Preview an existing order', 'rewards-referrals' ) . '</h3><p class="rr-help">' . esc_html__( 'Uses the currently saved rules and order data without writing points. Save policy changes before previewing.', 'rewards-referrals' ) . '</p><form class="rr-preview-form"><label>' . esc_html__( 'WooCommerce order ID', 'rewards-referrals' ) . '<input class="rr-control" type="number" name="order_id" min="1" required></label><button class="rr-button rr-button-light" type="submit">' . esc_html__( 'Calculate preview', 'rewards-referrals' ) . '</button><output class="rr-preview-output" aria-live="polite"></output></form></section>';
		self::faq( __( 'Program settings help', 'rewards-referrals' ), array( array( __( 'When do new rules take effect?', 'rewards-referrals' ), __( 'Immediately for future qualifying activity after you save. Existing ledger entries keep the rule version that created them.', 'rewards-referrals' ) ), array( __( 'Should I open Advanced policies now?', 'rewards-referrals' ), __( 'Only if your program needs date windows, customer limits, exclusions, or a specific attribution model. The three core decisions are enough for a straightforward program.', 'rewards-referrals' ) ), array( __( 'How can I check a rule before launch?', 'rewards-referrals' ), __( 'Use the order preview above after saving. It calculates a result without creating a wallet entry.', 'rewards-referrals' ) ) ) );
	}

	private static function schema_field( $key, $field, $settings ) {
		$value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		echo '<div class="rr-field"><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label>';
		if ( 'select' === $field['type'] ) {
			echo '<select class="rr-control" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
			foreach ( $field['options'] as $option => $label ) { echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>'; }
			echo '</select>';
		} else {
			$extra = '';
			foreach ( array( 'min', 'max', 'step' ) as $attribute ) { if ( isset( $field[ $attribute ] ) ) { $extra .= ' ' . $attribute . '="' . esc_attr( $field[ $attribute ] ) . '"'; } }
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
			echo '<input class="rr-control" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $field['type'] ) . '" value="' . esc_attr( $value ) . '"' . $extra . ( 'text' === $field['type'] ? ' maxlength="5000"' : '' ) . '>';
		}
		if ( ! empty( $field['description'] ) ) { echo '<small>' . esc_html( $field['description'] ) . '</small>'; }
		echo '</div>';
	}

	public static function referral_rows( $approved, $before = 0, $search = '' ) {
		global $wpdb;
		$table = RR_Database::table( 'referrals' );
		$where = $wpdb->prepare( 'approved=%d', $approved ? 1 : 0 );
		if ( $before > 0 ) { $where .= $wpdb->prepare( ' AND id<%d', $before ); }
		if ( '' !== $search ) {
			$id = ctype_digit( (string) $search ) ? absint( $search ) : 0;
			$where .= $wpdb->prepare( ' AND (customer_id=%d OR referrer_id=%d OR referral_code=%s)', $id, $id, substr( (string) $search, 0, 48 ) );
		}
		return $wpdb->get_results( "SELECT id,referrer_id,customer_id,referral_code,created_at FROM $table WHERE $where ORDER BY id DESC LIMIT 26" );
	}

	private static function referrals_page() {
		global $wpdb;
		$table = RR_Database::table( 'referrals' );
		$search = RR_Evidence::input( 'referral_search' );
		$pending = self::referral_rows( 0, absint( RR_Evidence::input( 'pending_before' ) ), $search );
		$approved = self::referral_rows( 1, absint( RR_Evidence::input( 'approved_before' ) ), $search );
		$pending_more = count( $pending ) > 25; $approved_more = count( $approved ) > 25;
		$pending = array_slice( $pending, 0, 25 ); $approved = array_slice( $approved, 0, 25 );
		echo '<form class="rr-evidence-filter" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="rewards-referrals-customers"><input type="hidden" name="tab" value="referrals"><label>' . esc_html__( 'Find a relationship by customer ID, referrer ID or exact referral code', 'rewards-referrals' ) . '<input class="rr-control" name="referral_search" maxlength="48" value="' . esc_attr( $search ) . '"></label><button class="rr-button rr-button-primary">' . esc_html__( 'Search referrals', 'rewards-referrals' ) . '</button></form>';
		echo '<div class="rr-page-heading"><div><span class="rr-section-kicker">' . esc_html__( 'CUSTOMER NETWORK', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Referral relationships', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'Review direct referral links. Commission is limited to the immediate approved referrer.', 'rewards-referrals' ) . '</p></div></div>';
		echo '<section class="rr-card rr-table-card"><div class="rr-card-heading"><div><h3>' . esc_html__( 'Awaiting review', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Approve only referrals that meet your program rules.', 'rewards-referrals' ) . '</p></div><span class="rr-count-pill">' . esc_html( number_format_i18n( count( $pending ) ) ) . '</span></div>';
		if ( $pending ) {
			echo '<div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Referral', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Referrer', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'New customer', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Registered', 'rewards-referrals' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $pending as $row ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
				echo '<tr><td><span class="rr-id">#' . esc_html( $row->id ) . '</span></td><td>' . self::customer_cell( $row->referrer_id ) . '</td><td>' . self::customer_cell( $row->customer_id ) . '</td><td>' . esc_html( self::local_date( $row->created_at ) ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="br_approve_referral"><input type="hidden" name="referral_id" value="' . esc_attr( $row->id ) . '">';
				wp_nonce_field( 'br_approve_referral_' . $row->id );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
				echo '<button class="rr-button rr-button-approve" type="submit">' . self::icon( 'check' ) . esc_html__( 'Approve', 'rewards-referrals' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			self::empty_state( 'check', $search ? __( 'No matching pending referrals', 'rewards-referrals' ) : __( 'You’re all caught up', 'rewards-referrals' ), $search ? __( 'Try another customer ID, referrer ID or exact referral code.', 'rewards-referrals' ) : __( 'There are no referral registrations waiting for approval.', 'rewards-referrals' ) );
		}
		if ( $pending_more ) { echo '<p><a data-rr-nav class="rr-button rr-button-light" href="' . esc_url( self::tab_url( 'referrals', array( 'pending_before' => end( $pending )->id, 'referral_search' => $search ) ) ) . '">' . esc_html__( 'Older pending referrals', 'rewards-referrals' ) . '</a></p>'; }

		echo '</section><section class="rr-card rr-table-card rr-spaced"><div class="rr-card-heading"><div><h3>' . esc_html__( 'Approved referrals', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Latest direct relationships eligible for commission.', 'rewards-referrals' ) . '</p></div></div>';
		if ( $approved ) {
			echo '<div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Referral', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Referrer', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Customer', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Registered', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
			foreach ( $approved as $row ) { echo '<tr><td><span class="rr-id">#' . esc_html( $row->id ) . '</span></td><td>' . self::customer_cell( $row->referrer_id ) . '</td><td>' . self::customer_cell( $row->customer_id ) . '</td><td>' . esc_html( self::local_date( $row->created_at ) ) . '</td></tr>'; }
			echo '</tbody></table></div>';
		} else { self::empty_state( 'users', __( 'No approved referrals yet', 'rewards-referrals' ), __( 'Approved relationships will appear here as customers join.', 'rewards-referrals' ) ); }
		if ( $approved_more ) { echo '<p><a data-rr-nav class="rr-button rr-button-light" href="' . esc_url( self::tab_url( 'referrals', array( 'approved_before' => end( $approved )->id, 'referral_search' => $search ) ) ) . '">' . esc_html__( 'Older approved referrals', 'rewards-referrals' ) . '</a></p>'; }
		echo '<p><a data-rr-nav href="' . esc_url( self::tab_url( 'referrals', array( 'referral_search' => $search ) ) ) . '">' . esc_html__( 'Return to newest referrals', 'rewards-referrals' ) . '</a></p>';

		echo '</section><section class="rr-card rr-setting-card rr-spaced"><h3>' . esc_html__( 'Approve a relationship reassignment', 'rewards-referrals' ) . '</h3><p class="rr-help">' . esc_html__( 'Change the direct referrer for future qualifying purchases. Earlier ledger entries retain their original beneficiary and amount. Self referral and referral cycles are rejected.', 'rewards-referrals' ) . '</p><form class="rr-adjust-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rr_reassign_referral">';
		wp_nonce_field( 'rr_reassign_referral' );
		echo '<label>' . esc_html__( 'Customer ID', 'rewards-referrals' ) . '<input class="rr-control" type="number" min="1" name="customer_id" required><small>' . esc_html__( 'Search by name or email below, or enter a known customer ID.', 'rewards-referrals' ) . '</small></label><label>' . esc_html__( 'New referrer ID', 'rewards-referrals' ) . '<input class="rr-control" type="number" min="1" name="referrer_id" required><small>' . esc_html__( 'Search below and confirm the referrer’s name and email before selecting.', 'rewards-referrals' ) . '</small></label><label class="rr-reason-field">' . esc_html__( 'Reason for reassignment', 'rewards-referrals' ) . '<input class="rr-control" type="text" name="reason" maxlength="200" required></label><button class="rr-button rr-button-primary" type="submit">' . esc_html__( 'Approve reassignment', 'rewards-referrals' ) . '</button></form></section>';
		self::faq( __( 'Referrals help', 'rewards-referrals' ), array( array( __( 'Where do I find a customer ID?', 'rewards-referrals' ), __( 'Open Customer wallets and search by name or email. The customer ID is displayed under the customer’s name.', 'rewards-referrals' ) ), array( __( 'What happens when I approve a referral?', 'rewards-referrals' ), __( 'The relationship becomes eligible for future direct-referrer commission according to the saved program policy.', 'rewards-referrals' ) ), array( __( 'Can I change a relationship later?', 'rewards-referrals' ), __( 'Yes, through reassignment. Earlier ledger entries keep their original beneficiary and amount; self-referrals and cycles are rejected.', 'rewards-referrals' ) ) ) );
	}

	private static function ledger_page() {
		global $wpdb;
		$table = RR_Database::table( 'ledger' );
	$page = max( 1, absint( isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : 1 ) );
		$per_page = 25;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,customer_id,amount,transaction_type,state,order_id,rule_version,created_at FROM %i ORDER BY id DESC LIMIT %d OFFSET %d", $table, $per_page, ( $page - 1 ) * $per_page ) );
		echo '<div class="rr-page-heading"><div><span class="rr-section-kicker">' . esc_html__( 'AUDITABLE WALLET', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Points ledger', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'Every earning and adjustment is recorded as a separate transaction.', 'rewards-referrals' ) . '</p></div><span class="rr-version-pill">' . esc_html( number_format_i18n( $total ) ) . ' ' . esc_html__( 'entries', 'rewards-referrals' ) . '</span></div>';
		echo '<section class="rr-card rr-table-card"><div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Transaction', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Customer', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Activity', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Points', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Order', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Rule', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Date', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		if ( $rows ) { foreach ( $rows as $row ) { echo '<tr><td><span class="rr-id">#' . esc_html( $row->id ) . '</span></td><td>' . self::customer_cell( $row->customer_id ) . '</td><td><span class="rr-type">' . esc_html( ucwords( str_replace( '_', ' ', $row->transaction_type ) ) ) . '</span></td><td><span class="rr-points ' . ( (int) $row->amount < 0 ? 'is-negative' : '' ) . '">' . ( (int) $row->amount > 0 ? '+' : '' ) . esc_html( number_format_i18n( (int) $row->amount ) ) . '</span></td><td>' . ( $row->order_id ? '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $row->order_id ) ) ) . '">#' . esc_html( $row->order_id ) . '</a>' : '—' ) . '</td><td>v' . esc_html( $row->rule_version ) . '</td><td>' . esc_html( self::local_date( $row->created_at ) ) . '</td></tr>'; } }
		else { echo '<tr><td colspan="7">' . esc_html__( 'No points activity has been recorded yet.', 'rewards-referrals' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		$pages = (int) ceil( $total / $per_page );
		self::pagination( $page, $pages, array( 'page' => self::page_for_tab( 'ledger' ), 'tab' => 'ledger' ), __( 'Ledger pages', 'rewards-referrals' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '</section><section class="rr-card rr-adjust-card rr-spaced"><div class="rr-setting-head">' . self::icon( 'wallet' ) . '<div><h3>' . esc_html__( 'Manual points adjustment', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Use only for a documented correction or service recovery. This creates a permanent, auditable ledger transaction.', 'rewards-referrals' ) . '</p></div></div><form class="rr-adjust-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="br_adjust_points">';
		wp_nonce_field( 'br_adjust_points' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '<input type="hidden" name="request_id" value="' . esc_attr( wp_generate_uuid4() ) . '"><label>' . esc_html__( 'Customer ID', 'rewards-referrals' ) . '<input class="rr-control" type="number" name="customer_id" min="1" required aria-describedby="rr-adjust-customer-help"><small id="rr-adjust-customer-help">' . esc_html__( 'Search by name or email below, or enter a known customer ID.', 'rewards-referrals' ) . '</small></label><label>' . esc_html__( 'Signed points', 'rewards-referrals' ) . '<input class="rr-control" type="number" name="amount" required><small>' . esc_html__( 'Use + to credit, − to deduct.', 'rewards-referrals' ) . '</small></label><label class="rr-reason-field">' . esc_html__( 'Reason for adjustment', 'rewards-referrals' ) . '<input class="rr-control" type="text" name="reason" required maxlength="200"></label><button class="rr-button rr-button-primary" type="submit">' . self::icon( 'plus' ) . esc_html__( 'Record adjustment', 'rewards-referrals' ) . '</button></form></section>';
		self::faq( __( 'Ledger and adjustments help', 'rewards-referrals' ), array( array( __( 'Why is an adjustment permanent?', 'rewards-referrals' ), __( 'The ledger is an audit record. Correct a mistake with an opposite adjustment instead of editing history.', 'rewards-referrals' ) ), array( __( 'How do I find a customer ID?', 'rewards-referrals' ), __( 'Search Customer wallets by name or email, then use View wallet. The customer ID is shown under the customer name.', 'rewards-referrals' ) ) ) );
	}

	private static function redemptions_page() {
		global $wpdb;
		$table = RR_Database::table( 'redemptions' );
		$page = max( 1, absint( isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : 1 ) );
		$status = isset( $_GET['status'] ) && is_scalar( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$allowed = array( 'reserved', 'confirmed', 'partially_refunded', 'refunded', 'released', 'cancelled' );
		$where = in_array( $status, $allowed, true ) ? $wpdb->prepare( ' WHERE status = %s', $status ) : '';
		$per_page = 20;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table$where" );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,customer_id,order_id,points,status,created_at,updated_at FROM $table$where ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ) );
		echo '<div class="rr-page-heading"><div><span class="rr-section-kicker">' . esc_html__( 'ORDER REDEMPTIONS', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Redemptions', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'Review reserved, confirmed, and released point redemptions alongside their WooCommerce orders.', 'rewards-referrals' ) . '</p></div><span class="rr-version-pill">' . esc_html( number_format_i18n( $total ) ) . ' ' . esc_html__( 'records', 'rewards-referrals' ) . '</span></div>';
		echo '<section class="rr-card rr-table-card"><form class="rr-ledger-filter" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::page_for_tab( 'redemptions' ) ) . '"><input type="hidden" name="tab" value="redemptions"><label><span class="screen-reader-text">' . esc_html__( 'Filter by redemption status', 'rewards-referrals' ) . '</span><select class="rr-control" name="status"><option value="">' . esc_html__( 'All statuses', 'rewards-referrals' ) . '</option>';
		foreach ( $allowed as $value ) { $label = ucwords( str_replace( '_', ' ', $value ) ); echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><button class="rr-button rr-button-primary" type="submit">' . esc_html__( 'Filter redemptions', 'rewards-referrals' ) . '</button></form><div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Redemption', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Customer', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Order', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Points', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Status', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Created', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Updated', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$order = wc_get_order( absint( $row->order_id ) );
				$customer = get_userdata( absint( $row->customer_id ) );
				$order_link = $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $row->order_id ) . '</a><small>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</small>' : '—';
				$customer_label = $customer ? esc_html( $customer->display_name ) . '<small>' . esc_html( $customer->user_email ) . ' · #' . esc_html( $row->customer_id ) . '</small>' : '#' . esc_html( $row->customer_id );
				$status_label = ucwords( str_replace( '_', ' ', $row->status ) );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
				echo '<tr><td><span class="rr-id">#' . esc_html( $row->id ) . '</span></td><td>' . $customer_label . '</td><td>' . $order_link . '</td><td><span class="rr-points is-negative">−' . esc_html( number_format_i18n( absint( $row->points ) ) ) . '</span></td><td><span class="rr-type">' . esc_html( $status_label ) . '</span></td><td>' . esc_html( self::local_date( $row->created_at, true ) ) . '</td><td>' . esc_html( self::local_date( $row->updated_at, true ) ) . '</td></tr>';
			}
		} else { echo '<tr><td colspan="7">' . esc_html__( 'No redemptions match this filter.', 'rewards-referrals' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		self::pagination( $page, $pages, array( 'page' => self::page_for_tab( 'redemptions' ), 'tab' => 'redemptions', 'status' => $status ), __( 'Redemption pages', 'rewards-referrals' ) );
		echo '</section>';
		self::faq( __( 'Redemptions help', 'rewards-referrals' ), array( array( __( 'What does Reserved mean?', 'rewards-referrals' ), __( 'Points are temporarily held while the associated order is being processed. They are not available for another redemption.', 'rewards-referrals' ) ), array( __( 'What does Released mean?', 'rewards-referrals' ), __( 'The hold was removed, usually because payment or an order flow did not complete. The points become available again.', 'rewards-referrals' ) ), array( __( 'Where do I resolve an order issue?', 'rewards-referrals' ), __( 'Open the linked WooCommerce order. This screen is an audit view; it does not manually change redemption status.', 'rewards-referrals' ) ) ) );
	}

	private static function wallets_page() {
		global $wpdb;
		$page = max( 1, absint( isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : 1 ) );
		$customer_id = absint( isset( $_GET['customer'] ) ? $_GET['customer'] : 0 );
		$search = isset( $_GET['q'] ) && is_scalar( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$detail = $customer_id && ( ! is_multisite() || is_user_member_of_blog( $customer_id ) ) ? get_userdata( $customer_id ) : false;
		echo '<div class="rr-page-heading"><div><span class="rr-section-kicker">' . esc_html__( 'CUSTOMER BALANCES', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Customer wallets', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'Find customers, review their current balance and ledger history, and make documented adjustments.', 'rewards-referrals' ) . '</p></div></div>';
		echo '<section class="rr-card rr-table-card"><form class="rr-wallet-filter" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::page_for_tab( 'wallets' ) ) . '"><input type="hidden" name="tab" value="wallets"><label class="rr-wallet-search"><span class="screen-reader-text">' . esc_html__( 'Search customers by username, email, name, or ID', 'rewards-referrals' ) . '</span><input class="rr-control" type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name, username, email, or customer ID', 'rewards-referrals' ) . '"></label><button class="rr-button rr-button-primary" type="submit">' . esc_html__( 'Search customers', 'rewards-referrals' ) . '</button>';
			if ( $detail ) { echo '<input type="hidden" name="customer" value="' . esc_attr( $customer_id ) . '">'; }
		echo '</form>';
		if ( $detail ) {
			$balance = RR_Ledger::balances( $customer_id );
			$ledger = RR_Database::table( 'ledger' );
			$transactions = $wpdb->get_results( $wpdb->prepare( "SELECT id,amount,transaction_type,state,order_id,rule_version,created_at,meta FROM %i WHERE customer_id = %d ORDER BY id DESC LIMIT 50", $ledger, $customer_id ) );
			echo '<div class="rr-wallet-profile"><div><span class="rr-section-kicker">' . esc_html__( 'CUSTOMER WALLET', 'rewards-referrals' ) . '</span><h3>' . esc_html( $detail->display_name ?: $detail->user_login ) . '</h3><p>' . esc_html( $detail->user_email ) . ' · #' . esc_html( $customer_id ) . '</p></div><a class="rr-button rr-button-light" href="' . esc_url( self::tab_url( 'wallets' ) ) . '" data-rr-nav>' . esc_html__( 'Back to customer list', 'rewards-referrals' ) . '</a></div>';
			echo '<div class="rr-wallet-metrics"><article><span>' . esc_html__( 'Available', 'rewards-referrals' ) . '</span><strong>' . esc_html( number_format_i18n( $balance['available'] ) ) . '</strong></article><article><span>' . esc_html__( 'Pending', 'rewards-referrals' ) . '</span><strong>' . esc_html( number_format_i18n( $balance['pending'] ) ) . '</strong></article><article><span>' . esc_html__( 'Reserved', 'rewards-referrals' ) . '</span><strong>' . esc_html( number_format_i18n( $balance['reserved'] ) ) . '</strong></article><article><span>' . esc_html__( 'Debt', 'rewards-referrals' ) . '</span><strong>' . esc_html( number_format_i18n( $balance['debt'] ) ) . '</strong></article></div>';
			echo '<h3 class="rr-wallet-history-title">' . esc_html__( 'Recent wallet activity', 'rewards-referrals' ) . '</h3><div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Transaction', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Activity', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Points', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'State', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Order', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Reason', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Date', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
			if ( $transactions ) {
				foreach ( $transactions as $transaction ) {
					$meta = json_decode( (string) $transaction->meta, true );
					$reason = isset( $meta['reason'] ) ? sanitize_text_field( $meta['reason'] ) : '—';
					$order = $transaction->order_id ? wc_get_order( absint( $transaction->order_id ) ) : false;
					$order_link = $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $transaction->order_id ) . '</a>' : '—';
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
					echo '<tr><td><span class="rr-id">#' . esc_html( $transaction->id ) . '</span></td><td>' . esc_html( ucwords( str_replace( '_', ' ', $transaction->transaction_type ) ) ) . '</td><td><span class="rr-points ' . ( (int) $transaction->amount < 0 ? 'is-negative' : '' ) . '">' . ( (int) $transaction->amount > 0 ? '+' : '' ) . esc_html( number_format_i18n( (int) $transaction->amount ) ) . '</span></td><td>' . esc_html( ucfirst( $transaction->state ) ) . '</td><td>' . $order_link . '</td><td>' . esc_html( $reason ) . '</td><td>' . esc_html( self::local_date( $transaction->created_at, true ) ) . '</td></tr>';
				}
			} else { echo '<tr><td colspan="7">' . esc_html__( 'No wallet activity has been recorded yet.', 'rewards-referrals' ) . '</td></tr>'; }
			echo '</tbody></table></div>';
			echo '<section class="rr-card rr-adjust-card rr-spaced"><h3>' . esc_html__( 'Adjust this wallet', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Use a positive value to add points or a negative value to deduct them. A reason is required and recorded.', 'rewards-referrals' ) . '</p><form class="rr-adjust-form rr-wallet-adjust-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="br_adjust_points"><input type="hidden" name="customer_id" value="' . esc_attr( $customer_id ) . '"><input type="hidden" name="redirect_tab" value="wallets"><input type="hidden" name="request_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
			wp_nonce_field( 'br_adjust_points' );
			echo '<label>' . esc_html__( 'Signed points', 'rewards-referrals' ) . '<input class="rr-control" type="number" name="amount" required></label><label class="rr-reason-field">' . esc_html__( 'Reason for adjustment', 'rewards-referrals' ) . '<input class="rr-control" type="text" name="reason" required maxlength="200"></label><button class="rr-button rr-button-primary" type="submit">' . esc_html__( 'Record adjustment', 'rewards-referrals' ) . '</button></form></section>';
		} else {
			$after = absint( isset( $_GET['after_customer'] ) && is_scalar( $_GET['after_customer'] ) ? $_GET['after_customer'] : 0 );
			$more = false; $users = array();
			if ( $search ) {
				$valid = mb_strlen( $search ) <= 80 && ( ctype_digit( $search ) || mb_strlen( $search ) >= 3 );
				$matches = $valid && RR_Customer_Search::allowed( get_current_user_id() ) ? RR_Customer_Search::lookup( $search ) : array();
				if ( ! is_wp_error( $matches ) ) { foreach ( $matches as $match ) { $user = get_userdata( $match['id'] ); if ( $user ) { $users[] = $user; } } }
			} else {
				if ( is_multisite() ) {
					$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT u.ID FROM %i u WHERE u.ID>%d AND EXISTS (SELECT 1 FROM %i um WHERE um.user_id=u.ID AND um.meta_key=%s) ORDER BY u.ID LIMIT 21', $wpdb->users, $after, $wpdb->usermeta, $wpdb->prefix . 'capabilities' ) );
				} else {
					$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID>%d ORDER BY ID LIMIT 21', $wpdb->users, $after ) );
				}
				$more = count( $ids ) > 20;
				foreach ( array_slice( $ids, 0, 20 ) as $id ) { $user = get_userdata( $id ); if ( $user ) { $users[] = $user; } }
			}
			echo '<p class="rr-results-summary">' . esc_html__( 'Browse 20 customers at a time, or enter an ID or the first 3–80 characters of a name, username or email. Prefix searches return up to 10 matches; refine the prefix to narrow results.', 'rewards-referrals' ) . '</p><div class="rr-table-scroll"><table class="rr-table"><thead><tr><th>' . esc_html__( 'Customer', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Available', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Pending', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Reserved', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Debt', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Wallet', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
			if ( $users ) {
				$all_balances = RR_Ledger::balances_for_customers( wp_list_pluck( $users, 'ID' ) );
				foreach ( $users as $user ) {
					$balance = $all_balances[ $user->ID ];
					$url = self::tab_url( 'wallets', array( 'customer' => $user->ID ) );
					echo '<tr><td><strong>' . esc_html( $user->display_name ?: $user->user_login ) . '</strong><small>' . esc_html( $user->user_email ) . ' · #' . esc_html( $user->ID ) . '</small></td><td>' . esc_html( number_format_i18n( $balance['available'] ) ) . '</td><td>' . esc_html( number_format_i18n( $balance['pending'] ) ) . '</td><td>' . esc_html( number_format_i18n( $balance['reserved'] ) ) . '</td><td>' . esc_html( number_format_i18n( $balance['debt'] ) ) . '</td><td><a data-rr-nav href="' . esc_url( $url ) . '">' . esc_html__( 'View wallet', 'rewards-referrals' ) . '</a></td></tr>';
				}
			} else { echo '<tr><td colspan="6">' . esc_html__( 'No customers match this search.', 'rewards-referrals' ) . '</td></tr>'; }
			echo '</tbody></table></div>';
			echo '<nav aria-label="' . esc_attr__( 'Customer pages', 'rewards-referrals' ) . '"><a data-rr-nav href="' . esc_url( self::tab_url( 'wallets' ) ) . '">' . esc_html__( 'Start over', 'rewards-referrals' ) . '</a> ';
			if ( $more && $users ) { $last = end( $users ); echo '<a class="rr-button rr-button-light" data-rr-nav href="' . esc_url( self::tab_url( 'wallets', array( 'after_customer' => $last->ID ) ) ) . '">' . esc_html__( 'More customers', 'rewards-referrals' ) . '</a>'; }
			echo '</nav>';
		}
		echo '</section>';
		if ( ! $detail ) { self::faq( __( 'Customer wallets help', 'rewards-referrals' ), array( array( __( 'Will this work with a large customer base?', 'rewards-referrals' ), __( 'Yes. Wallets are queried in pages of 20 and balances are loaded for only that page. Use the search field to narrow by name, username, email, or ID.', 'rewards-referrals' ) ), array( __( 'What is the difference between available and reserved?', 'rewards-referrals' ), __( 'Available points can be redeemed. Reserved points are held for an in-progress redemption until the order confirms or releases them.', 'rewards-referrals' ) ) ) ); }
	}

	private static function catalog_page() {
		$search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page = max( 1, absint( isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : 1 ) );
		$category_id = absint( isset( $_GET['category'] ) ? $_GET['category'] : 0 );
		$type = isset( $_GET['product_type'] ) && is_scalar( $_GET['product_type'] ) ? sanitize_key( wp_unslash( $_GET['product_type'] ) ) : '';
		$status = isset( $_GET['product_status'] ) && is_scalar( $_GET['product_status'] ) ? sanitize_key( wp_unslash( $_GET['product_status'] ) ) : '';
		$allowed_types = array( 'simple', 'variable', 'variation', 'grouped', 'external' );
		$allowed_statuses = array( 'publish', 'draft', 'private' );
		$args = array( 'limit' => 20, 'page' => $page, 'paginate' => true, 'return' => 'objects', 'orderby' => 'name', 'order' => 'ASC', 'status' => in_array( $status, $allowed_statuses, true ) ? $status : array( 'publish', 'draft', 'private' ) );
		if ( $search ) { $args['s'] = $search; }
		if ( $type && in_array( $type, $allowed_types, true ) ) { $args['type'] = $type; }
		if ( $category_id ) {
			$term = get_term( $category_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) { $args['category'] = array( $term->slug ); }
		}
		$result = null;
		if ( ctype_digit( $search ) && absint( $search ) ) {
			$product = wc_get_product( absint( $search ) );
			if ( $product ) { $result = (object) array( 'products' => array( $product ), 'total' => 1, 'max_num_pages' => 1 ); }
		}
		if ( ! $result && $search ) {
			$sku_args = $args;
			unset( $sku_args['s'] );
			$sku_args['sku'] = $search;
			$sku_result = wc_get_products( $sku_args );
			if ( is_object( $sku_result ) && ! empty( $sku_result->products ) ) { $result = $sku_result; }
		}
		if ( ! $result ) { $result = wc_get_products( $args ); }
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : array();
		$total = is_object( $result ) && isset( $result->total ) ? absint( $result->total ) : count( $products );
		$pages = is_object( $result ) && isset( $result->max_num_pages ) ? max( 1, absint( $result->max_num_pages ) ) : max( 1, (int) ceil( $total / 20 ) );
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200, 'orderby' => 'name' ) );
		$settings = RR_Rules::get();
		$redemption_open = 'yes' === ( $settings['redemption_enabled'] ?? 'no' );
		$default_reward_mode = $settings['reward_default_mode'] ?? 'disabled';
		echo '<div class="rr-page-heading"><div><span class="rr-section-kicker">' . esc_html__( 'WOOCOMMERCE PRODUCTS', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Reward catalog', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'Search a page of products at a time, set point prices, and manage availability.', 'rewards-referrals' ) . '</p></div><span class="rr-version-pill">' . esc_html( number_format_i18n( $total ) ) . ' ' . esc_html__( 'products found', 'rewards-referrals' ) . '</span></div>';
		if ( isset( $_GET['catalog_updated'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Selected product reward settings were updated.', 'rewards-referrals' ) . '</p></div>'; }
		if ( ! $redemption_open ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
			echo '<section class="rr-catalog-readiness"><span>' . self::icon( 'info' ) . '</span><div><strong>' . esc_html__( 'Customer reward catalog is not live yet', 'rewards-referrals' ) . '</strong><p>' . esc_html__( 'Your point-priced products are saved, but customers cannot see or redeem them until Point redemption is enabled in Program settings.', 'rewards-referrals' ) . '</p></div><a class="rr-button rr-button-primary" href="' . esc_url( self::tab_url( 'settings' ) ) . '">' . esc_html__( 'Open Program settings', 'rewards-referrals' ) . '</a></section>';
		}
		if ( 'disabled' !== $default_reward_mode ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
			echo '<section class="rr-catalog-readiness"><span>' . self::icon( 'info' ) . '</span><div><strong>' . esc_html__( 'Your global reward default is active', 'rewards-referrals' ) . '</strong><p>' . esc_html__( 'Products marked Inherit follow the Default product redemption mode in Program settings. Set that default to Cash only to keep the customer catalog opt-in, then enable only the products you want to offer as rewards.', 'rewards-referrals' ) . '</p></div><a class="rr-button rr-button-primary" href="' . esc_url( self::tab_url( 'settings' ) ) . '">' . esc_html__( 'Review global default', 'rewards-referrals' ) . '</a></section>';
		}
		echo '<section class="rr-card rr-catalog-card"><p class="rr-workflow-note">' . esc_html__( 'Large catalog workflow: filter first, review one server-side page, then apply a bulk change only to the products selected on that page. Choose “Individual variations” when variation-level pricing is required.', 'rewards-referrals' ) . '</p><form class="rr-catalog-filter" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::page_for_tab( 'catalog' ) ) . '"><input type="hidden" name="tab" value="catalog"><label class="rr-catalog-search"><span class="screen-reader-text">' . esc_html__( 'Search products by name, SKU, or product ID', 'rewards-referrals' ) . '</span><input class="rr-control" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Product name, SKU, or ID', 'rewards-referrals' ) . '"></label><label><span class="screen-reader-text">' . esc_html__( 'Product category', 'rewards-referrals' ) . '</span><select class="rr-control" name="category"><option value="0">' . esc_html__( 'All categories', 'rewards-referrals' ) . '</option>';
		if ( is_array( $categories ) ) { foreach ( $categories as $term ) { echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( $category_id, $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>'; } }
		echo '</select></label><label><span class="screen-reader-text">' . esc_html__( 'Product type', 'rewards-referrals' ) . '</span><select class="rr-control" name="product_type"><option value="">' . esc_html__( 'All types', 'rewards-referrals' ) . '</option>';
		foreach ( array( 'simple' => __( 'Simple', 'rewards-referrals' ), 'variable' => __( 'Variable', 'rewards-referrals' ), 'variation' => __( 'Individual variations', 'rewards-referrals' ), 'grouped' => __( 'Grouped', 'rewards-referrals' ), 'external' => __( 'External', 'rewards-referrals' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $type, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label><label><span class="screen-reader-text">' . esc_html__( 'Product status', 'rewards-referrals' ) . '</span><select class="rr-control" name="product_status"><option value="">' . esc_html__( 'All statuses', 'rewards-referrals' ) . '</option><option value="publish" ' . selected( $status, 'publish', false ) . '>' . esc_html__( 'Published', 'rewards-referrals' ) . '</option><option value="draft" ' . selected( $status, 'draft', false ) . '>' . esc_html__( 'Draft', 'rewards-referrals' ) . '</option><option value="private" ' . selected( $status, 'private', false ) . '>' . esc_html__( 'Private', 'rewards-referrals' ) . '</option></select></label><button class="rr-button rr-button-primary" type="submit">' . esc_html__( 'Search products', 'rewards-referrals' ) . '</button></form>';
		echo '<form class="rr-catalog-bulk" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rr_catalog_bulk">';
		wp_nonce_field( 'rr_catalog_bulk' );
		$context = self::catalog_context( array( 's' => $search, 'category' => $category_id, 'product_type' => $type, 'product_status' => $status, 'paged' => $page ) );
		foreach ( $context as $key => $value ) { echo '<input type="hidden" name="catalog_context[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">'; }
		echo '<div class="rr-catalog-toolbar"><label><span class="screen-reader-text">' . esc_html__( 'Bulk action', 'rewards-referrals' ) . '</span><select class="rr-control" name="bulk_action"><option value="enable">' . esc_html__( 'Enable selected as points rewards', 'rewards-referrals' ) . '</option><option value="disable">' . esc_html__( 'Disable selected rewards', 'rewards-referrals' ) . '</option><option value="cash_or_points">' . esc_html__( 'Enable cash or points choice', 'rewards-referrals' ) . '</option><option value="set_price">' . esc_html__( 'Set selected point prices', 'rewards-referrals' ) . '</option><option value="reset">' . esc_html__( 'Reset selected overrides', 'rewards-referrals' ) . '</option></select></label><label class="rr-bulk-price"><span class="screen-reader-text">' . esc_html__( 'Points price', 'rewards-referrals' ) . '</span><input class="rr-control" type="number" min="1" max="1000000000" step="1" name="points_price" placeholder="' . esc_attr__( 'Points price', 'rewards-referrals' ) . '"></label><button class="rr-button rr-button-light" type="submit">' . esc_html__( 'Apply to selected', 'rewards-referrals' ) . '</button></div><div class="rr-table-scroll"><table class="rr-table rr-catalog-table"><thead><tr><th><input id="rr-select-all" type="checkbox" aria-label="' . esc_attr__( 'Select all products on this page', 'rewards-referrals' ) . '"></th><th>' . esc_html__( 'Product', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'SKU', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Cash price', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Points price', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Availability', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Stock', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Actions', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		if ( $products ) {
			foreach ( $products as $product ) {
				$mode = $product->get_meta( '_rr_reward_mode' );
				$point_price = RR_WooCommerce::points_price( $product );
				$edit_url = get_edit_post_link( $product->get_parent_id() ?: $product->get_id() );
				$stock_label = $product->is_in_stock() ? __( 'In stock', 'rewards-referrals' ) : __( 'Out of stock', 'rewards-referrals' );
				/* translators: %s is the product name. */
				echo '<tr><td><input type="checkbox" name="product_ids[]" value="' . esc_attr( $product->get_id() ) . '" aria-label="' . esc_attr( sprintf( __( 'Select %s', 'rewards-referrals' ), $product->get_name() ) ) . '"></td><td><strong>' . esc_html( $product->get_name() ) . '</strong><small>#' . esc_html( $product->get_id() ) . ' · ' . esc_html( wc_get_product_category_list( $product->get_id(), ', ', '', '' ) ? wp_strip_all_tags( wc_get_product_category_list( $product->get_id(), ', ', '', '' ) ) : __( 'Uncategorized', 'rewards-referrals' ) ) . '</small></td><td>' . esc_html( $product->get_sku() ?: '—' ) . '</td><td>' . wp_kses_post( $product->get_price_html() ?: '—' ) . '</td><td>' . ( $point_price ? esc_html( number_format_i18n( $point_price ) ) : '—' ) . '</td><td><span class="rr-type">' . esc_html( ucwords( str_replace( '_', ' ', $mode ?: 'inherit' ) ) ) . '</span></td><td>' . esc_html( $stock_label ) . '</td><td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit product', 'rewards-referrals' ) . '</a></td></tr>';
			}
		} else { echo '<tr><td colspan="8">' . esc_html__( 'No products match these filters.', 'rewards-referrals' ) . '</td></tr>'; }
		echo '</tbody></table></div></form>';
		self::pagination( $page, $pages, array( 'page' => self::page_for_tab( 'catalog' ), 'tab' => 'catalog', 's' => $search, 'category' => $category_id, 'product_type' => $type, 'product_status' => $status ), __( 'Product catalog pages', 'rewards-referrals' ) );
		echo '</section>';
		self::faq( __( 'Reward catalog help', 'rewards-referrals' ), array( array( __( 'Can I use this with thousands of products?', 'rewards-referrals' ), __( 'Yes. Search, filters, and pagination are performed by WooCommerce; only the current page of products is rendered. Bulk actions are limited to 100 selected products for a bounded, reviewable update.', 'rewards-referrals' ) ), array( __( 'How do variations work?', 'rewards-referrals' ), __( 'Use the product type filter “Individual variations” to set an exception on a specific variation. Otherwise, edit the parent product when the same reward policy applies across the product.', 'rewards-referrals' ) ), array( __( 'What does Inherit mean?', 'rewards-referrals' ), __( 'Inherit has no local redemption choice and follows Default product redemption mode in Program settings. For an opt-in catalog, keep that default as Cash only, then enable only selected products. Reset removes a local override without deleting the WooCommerce product.', 'rewards-referrals' ) ) ) );
	}

	/** Preserve only validated catalog filters; this never accepts a return URL or page slug. */
	private static function catalog_context( $input ) {
		$input = is_array( $input ) ? $input : array();
		$context = array();
		if ( isset( $input['s'] ) && is_scalar( $input['s'] ) ) {
			$search = sanitize_text_field( (string) $input['s'] );
			if ( '' !== $search ) { $context['s'] = $search; }
		}
		foreach ( array( 'category' => 0, 'paged' => 1 ) as $key => $minimum ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? filter_var( (string) $input[ $key ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => $minimum ) ) ) : false;
			if ( false !== $value && $value > $minimum ) { $context[ $key ] = $value; }
		}
		foreach ( array( 'product_type' => array( 'simple', 'variable', 'variation', 'grouped', 'external' ), 'product_status' => array( 'publish', 'draft', 'private' ) ) as $key => $allowed ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_key( (string) $input[ $key ] ) : '';
			if ( in_array( $value, $allowed, true ) ) { $context[ $key ] = $value; }
		}
		return $context;
	}

	public static function catalog_bulk() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'rr_catalog_bulk' );
		$ids = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_slice( array_unique( array_map( 'absint', wp_unslash( $_POST['product_ids'] ) ) ), 0, 100 ) : array();
		$action = isset( $_POST['bulk_action'] ) && is_scalar( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$raw_price = isset( $_POST['points_price'] ) && is_scalar( $_POST['points_price'] ) ? wp_unslash( $_POST['points_price'] ) : '';
		$price = preg_match( '/^\d+$/', (string) $raw_price ) ? min( 1000000000, absint( $raw_price ) ) : 0;
		if ( ! in_array( $action, array( 'enable', 'cash_or_points', 'disable', 'set_price', 'reset' ), true ) ) { wp_die( esc_html__( 'Choose a valid catalog action.', 'rewards-referrals' ) ); }
		if ( in_array( $action, array( 'enable', 'cash_or_points', 'set_price' ), true ) && ! $price ) { wp_die( esc_html__( 'Enter a positive whole points price before applying this action.', 'rewards-referrals' ), '', array( 'response' => 400, 'back_link' => true ) ); }
		if ( ! $ids ) { wp_die( esc_html__( 'Select at least one product.', 'rewards-referrals' ), '', array( 'response' => 400, 'back_link' => true ) ); }
		global $wpdb;
		$completed = 0;
		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! current_user_can( 'edit_product', $product_id ) ) { continue; }
			if ( 'reset' === $action ) { foreach ( array( '_rr_reward_mode', '_rr_points_price', '_rr_cash_price', '_rr_earning_mode', '_rr_points_per_unit', '_rr_fixed_points', '_rr_exclude_commission', '_rr_commission_rate', '_rr_reward_policy_mode', '_rr_reward_start_date', '_rr_reward_end_date', '_rr_reward_roles', '_rr_reward_min_balance', '_rr_reward_limit_customer', '_rr_reward_limit_daily', '_rr_reward_limit_monthly' ) as $key ) { $product->delete_meta_data( $key ); } }
			elseif ( 'set_price' !== $action ) { $product->update_meta_data( '_rr_reward_mode', 'enable' === $action ? 'points_only' : ( 'cash_or_points' === $action ? 'cash_or_points' : 'disabled' ) ); }
			if ( in_array( $action, array( 'enable', 'cash_or_points', 'set_price' ), true ) ) { $product->update_meta_data( '_rr_points_price', $price ); }
			try { $saved_id = $product->save(); }
			catch ( Throwable $error ) {
				/* translators: 1: product ID, 2: number of fully saved and audited products. */
				wp_die( esc_html( sprintf( __( 'Processing stopped at product #%1$d after %2$d products were saved and audited. This product may already have changed before a save integration failed. Refresh the catalog and review it before retrying; earlier changes were not rolled back.', 'rewards-referrals' ), $product_id, $completed ) ), '', array( 'response' => 503, 'back_link' => true ) );
			}
			if ( ! $saved_id ) {
				/* translators: 1: product ID, 2: number of fully saved and audited products. */
				wp_die( esc_html( sprintf( __( 'Processing stopped because product #%1$d could not be confirmed saved. %2$d products were already saved and audited. Refresh the catalog before retrying; earlier changes were not rolled back.', 'rewards-referrals' ), $product_id, $completed ) ), '', array( 'response' => 503, 'back_link' => true ) );
			}
			$audit_failed = false;
			try { self::audit( 'catalog_product_updated', $product_id, array( 'action' => $action, 'points_price' => $price ) ); $audit_failed = (bool) $wpdb->last_error; }
			catch ( Throwable $error ) { $audit_failed = true; }
			if ( $audit_failed ) {
				/* translators: 1: product ID, 2: number of earlier fully saved and audited products. */
				wp_die( esc_html( sprintf( __( 'Product #%1$d was saved, but its audit record could not be written. Processing stopped; %2$d earlier products were saved and audited. Changes were not rolled back. Review the product and restore database health before retrying.', 'rewards-referrals' ), $product_id, $completed ) ), '', array( 'response' => 503, 'back_link' => true ) );
			}
			++$completed;
		}
		$context = self::catalog_context( isset( $_POST['catalog_context'] ) && is_array( $_POST['catalog_context'] ) ? wp_unslash( $_POST['catalog_context'] ) : array() );
		wp_safe_redirect( self::tab_url( 'catalog', array_merge( array_map( 'rawurlencode', $context ), array( 'catalog_updated' => '1' ) ) ) );
		exit;
	}

	private static function stats() {
		$cached = get_transient( 'rr_dashboard_stats' );
		if ( is_array( $cached ) ) { return $cached; }
		global $wpdb;
		$ledger = RR_Database::table( 'ledger' );
		$referrals = RR_Database::table( 'referrals' );
		$stats = array(
			'liability' => max( 0, (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM $ledger WHERE state IN ('available','reserved','spent','adjusted','expired')" ) ),
			'awarded' => max( 0, (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM $ledger WHERE transaction_type IN ('purchase_points','referral_commission') AND amount > 0" ) ),
			'referrals' => max( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $referrals WHERE approved = 1" ) ),
			'pending' => max( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $referrals WHERE approved = 0" ) ),
		);
		set_transient( 'rr_dashboard_stats', $stats, 30 );
		return $stats;
	}

	private static function recent_ledger( $limit ) {
		global $wpdb;
		$table = RR_Database::table( 'ledger' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT customer_id,amount,transaction_type,created_at,order_id FROM %i ORDER BY id DESC LIMIT %d", $table, absint( $limit ) ) );
		if ( ! $rows ) { self::empty_state( 'gift', __( 'Your ledger is ready', 'rewards-referrals' ), __( 'Points activity will appear here as customers earn rewards.', 'rewards-referrals' ) ); return; }
		echo '<div class="rr-activity-list">';
		foreach ( $rows as $row ) {
			$positive = (int) $row->amount >= 0;
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
			echo '<div class="rr-activity-row"><span class="rr-activity-icon ' . ( $positive ? 'is-positive' : 'is-negative' ) . '">' . self::icon( 'gift' ) . '</span><div class="rr-activity-copy"><strong>' . esc_html( ucwords( str_replace( '_', ' ', $row->transaction_type ) ) ) . '</strong><span>' . self::customer_cell( $row->customer_id ) . ( $row->order_id ? ' · ' . esc_html__( 'Order', 'rewards-referrals' ) . ' #' . esc_html( $row->order_id ) : '' ) . '</span></div><strong class="rr-activity-amount ' . ( $positive ? 'is-positive' : 'is-negative' ) . '">' . ( $positive ? '+' : '' ) . esc_html( number_format_i18n( (int) $row->amount ) ) . '</strong><time>' . esc_html( self::local_date( $row->created_at ) ) . '</time></div>';
		}
		echo '</div>';
	}

	private static function customer_cell( $customer_id ) {
		$user = get_userdata( absint( $customer_id ) );
		/* translators: %d is the customer ID. */
		if ( ! $user ) { return '<span class="rr-muted">' . esc_html( sprintf( __( 'Customer #%d', 'rewards-referrals' ), absint( $customer_id ) ) ) . '</span>'; }
		return '<a class="rr-customer-link" href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ?: $user->user_login ) . '</a><small>' . esc_html( $user->user_email ) . '</small>';
	}

	/** Render a deliberately bounded pager; never emit thousands of page links. */
	private static function pagination( $page, $pages, $query, $label ) {
		$page = max( 1, absint( $page ) );
		$pages = max( 1, absint( $pages ) );
		if ( $pages <= 1 ) { return; }
		$numbers = array_unique( array_filter( array_merge( array( 1, 2, $pages - 1, $pages ), range( max( 1, $page - 2 ), min( $pages, $page + 2 ) ) ) ) );
		sort( $numbers, SORT_NUMERIC );
		echo '<nav class="rr-pagination" aria-label="' . esc_attr( $label ) . '">';
		if ( $page > 1 ) { $previous = $query; $previous['paged'] = $page - 1; echo '<a data-rr-nav class="rr-page-control" href="' . esc_url( add_query_arg( $previous, admin_url( 'admin.php' ) ) ) . '">← ' . esc_html__( 'Previous', 'rewards-referrals' ) . '</a>'; }
		$last = 0;
		foreach ( $numbers as $number ) {
			if ( $last && $number > $last + 1 ) { echo '<span class="rr-page-gap" aria-hidden="true">…</span>'; }
			$url_query = $query; $url_query['paged'] = $number;
			echo '<a data-rr-nav class="' . ( $number === $page ? 'is-current' : '' ) . '" href="' . esc_url( add_query_arg( $url_query, admin_url( 'admin.php' ) ) ) . '" ' . ( $number === $page ? 'aria-current="page"' : '' ) . '>' . esc_html( $number ) . '</a>';
			$last = $number;
		}
		if ( $page < $pages ) { $next = $query; $next['paged'] = $page + 1; echo '<a data-rr-nav class="rr-page-control" href="' . esc_url( add_query_arg( $next, admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Next', 'rewards-referrals' ) . ' →</a>'; }
		echo '</nav>';
	}

	private static function faq( $title, $items ) {
		if ( ! is_array( $items ) || ! $items ) { return; }
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '<section class="rr-faq" aria-label="' . esc_attr( $title ) . '"><div class="rr-faq-heading"><span>' . self::icon( 'info' ) . '</span><div><span class="rr-section-kicker">' . esc_html__( 'GUIDANCE', 'rewards-referrals' ) . '</span><h3>' . esc_html( $title ) . '</h3><p>' . esc_html__( 'Short answers for the decisions on this screen.', 'rewards-referrals' ) . '</p></div></div><div class="rr-faq-list">';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || count( $item ) < 2 ) { continue; }
			echo '<details><summary>' . esc_html( $item[0] ) . '</summary><p>' . esc_html( $item[1] ) . '</p></details>';
		}
		echo '</div></section>';
	}

	private static function metric( $icon, $title, $value, $caption, $tone ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '<article class="rr-metric rr-tone-' . esc_attr( $tone ) . '"><div class="rr-metric-top"><span>' . esc_html( $title ) . '</span><span class="rr-metric-icon">' . self::icon( $icon ) . '</span></div><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $caption ) . '</small></article>';
	}

	private static function toggle( $name, $label, $description, $settings ) {
		echo '<label class="rr-toggle-row"><input type="checkbox" name="' . esc_attr( $name ) . '" value="yes" ' . checked( $settings[ $name ], 'yes', false ) . '><span class="rr-toggle-ui" aria-hidden="true"></span><span class="rr-toggle-copy"><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $description ) . '</small></span></label>';
	}

	private static function field_number( $name, $label, $description, $settings, $min, $max, $step, $suffix = '' ) {
		echo '<div class="rr-field"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><div class="rr-input-wrap"><input class="rr-control" type="number" step="' . esc_attr( $step ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" value="' . esc_attr( $settings[ $name ] ) . '" required>' . ( $suffix ? '<span>' . esc_html( $suffix ) . '</span>' : '' ) . '</div><small>' . esc_html( $description ) . '</small></div>';
	}

	private static function empty_state( $icon, $title, $description ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo '<div class="rr-empty-state"><span>' . self::icon( $icon ) . '</span><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $description ) . '</p></div>';
	}

	private static function icon( $name ) {
		$paths = array(
			'sparkles' => 'M12 2l1.7 6.3L20 10l-6.3 1.7L12 18l-1.7-6.3L4 10l6.3-1.7L12 2Zm7 12 .9 2.1L22 17l-2.1.9L19 20l-.9-2.1L16 17l2.1-.9L19 14ZM5 14l1.2 2.8L9 18l-2.8 1.2L5 22l-1.2-2.8L1 18l2.8-1.2L5 14Z',
			'gift' => 'M20 7h-2.2c.3-.5.5-1 .5-1.6A3.4 3.4 0 0 0 15 2c-1.3 0-2.4.9-3 2.1C11.4 2.9 10.3 2 9 2a3.4 3.4 0 0 0-3.3 3.4c0 .6.2 1.1.5 1.6H4a2 2 0 0 0-2 2v3h9V9h2v3h9V9a2 2 0 0 0-2-2ZM9 4a1.4 1.4 0 0 1 1.4 1.4c0 .6-.4 1.1-.8 1.6H9A1.4 1.4 0 0 1 9 4Zm6 3h-.6c-.4-.5-.8-1-.8-1.6A1.4 1.4 0 1 1 15 7Zm-2 7H2v4a2 2 0 0 0 2 2h7v-6Zm2 0v6h7a2 2 0 0 0 2-2v-4h-9Z',
			'users' => 'M16 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM8 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-6 1.7-6 3.8V21h12v-2.2C14 16.7 11.3 15 8 15Zm8-2c-.5 0-1 .1-1.5.2 1.5 1 2.5 2.4 2.5 4.3V21h5v-2.2c0-3.1-2.7-5.8-6-5.8Z',
			'clock' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 5v4.6l3.2 1.9-1 1.7-4.2-2.5V7h2Z',
			'wallet' => 'M4 4h16a2 2 0 0 1 2 2v2h-6a4 4 0 0 0 0 8h6v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm12 6h7v4h-7a2 2 0 0 1 0-4Zm1.5 1.2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6Z',
			'link' => 'M10.6 13.4a1 1 0 0 1 0-1.4l3-3a3 3 0 0 1 4.2 4.2l-1.7 1.7a3 3 0 0 1-4.2 0 1 1 0 1 1 1.4-1.4 1 1 0 0 0 1.4 0l1.7-1.7a1 1 0 0 0-1.4-1.4l-3 3a1 1 0 0 1-1.4 0Zm2.8-2.8a1 1 0 0 1 0 1.4l-3 3a3 3 0 0 1-4.2-4.2l1.7-1.7a3 3 0 0 1 4.2 0 1 1 0 0 1-1.4 1.4 1 1 0 0 0-1.4 0l-1.7 1.7a1 1 0 0 0 1.4 1.4l3-3a1 1 0 0 1 1.4 0Z',
			'info' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-6h2v6Zm0-8h-2V7h2v2Z',
			'shield' => 'M12 2 4 5v6c0 5.2 3.4 9.7 8 11 4.6-1.3 8-5.8 8-11V5l-8-3Zm-1 14-3-3 1.4-1.4L11 13.2l4.6-4.6L17 10l-6 6Z',
			'check' => 'M9 16.2 4.8 12 3.4 13.4 9 19l12-12-1.4-1.4L9 16.2Z',
			'plus' => 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z',
			'settings' => 'M19.4 13a7.8 7.8 0 0 0 0-2l2-1.5-2-3.4-2.3.9a8 8 0 0 0-1.7-1L15 3h-4l-.4 3a8 8 0 0 0-1.7 1l-2.3-.9-2 3.4 2 1.5a7.8 7.8 0 0 0 0 2l-2 1.5 2 3.4 2.3-.9a8 8 0 0 0 1.7 1L11 21h4l.4-3a8 8 0 0 0 1.7-1l2.3.9 2-3.4-2-1.5ZM13 15a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z',
		);
		if ( isset( $paths[ $name ] ) ) { $path = $paths[ $name ]; }
		elseif ( is_string( $name ) && preg_match( '/^M[0-9 .a-zA-Z,\\-]+$/', $name ) ) { return '<svg class="rr-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" d="' . esc_attr( $name ) . '"/></svg>'; }
		else { $path = $paths['sparkles']; }
		return '<svg class="rr-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="' . esc_attr( $path ) . '"/></svg>';
	}

	private static function format_rate( $rate ) {
		// Choose precision before localization: decimal separators can be commas or multibyte characters.
		$canonical = number_format( (float) $rate, 2, '.', '' );
		$decimals = strlen( rtrim( substr( $canonical, strpos( $canonical, '.' ) + 1 ), '0' ) );
		return number_format_i18n( (float) $rate, $decimals );
	}
	private static function duration_label( $settings ) {
		if ( 'first_order' === $settings['commission_duration'] ) { return __( 'First order', 'rewards-referrals' ); }
		/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
		if ( 'first_n' === $settings['commission_duration'] ) { return sprintf( __( 'First %d orders', 'rewards-referrals' ), absint( $settings['first_n'] ) ); }
		/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
		if ( 'days_after_registration' === $settings['commission_duration'] ) { return sprintf( __( '%d days', 'rewards-referrals' ), absint( $settings['duration_days'] ) ); }
		return __( 'Lifetime', 'rewards-referrals' );
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'br_save_settings' );
		$old = RR_Rules::get();
		$new = RR_Rules::sanitize( wp_unslash( $_POST ), $old );
		$result = RR_Operations::persist_settings( $new );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( self::tab_url( 'settings', array( 'updated' => '1' ) ) );
		exit;
	}

	private static function bounded_decimal( $key, $min, $max, $fallback ) {
		$posted = self::post_value( $key );
		$value = is_numeric( $posted ) ? (float) $posted : (float) $fallback;
		return (string) max( $min, min( $max, $value ) );
	}

	private static function bounded_int( $key, $min, $max, $fallback ) {
		$posted = self::post_value( $key );
		$value = preg_match( '/^\d+$/', $posted ) ? absint( $posted ) : absint( $fallback );
		return (string) max( $min, min( $max, $value ) );
	}

	private static function post_value( $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) { return ''; }
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	public static function adjust_points() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'br_adjust_points' );
		$customer_raw = self::post_value( 'customer_id' );
		$customer = preg_match( '/^\d+$/', $customer_raw ) ? absint( $customer_raw ) : 0;
		$amount_raw = self::post_value( 'amount' );
		$amount_value = filter_var( $amount_raw, FILTER_VALIDATE_INT );
		$amount = false === $amount_value ? 0 : $amount_value;
		$reason = trim( self::post_value( 'reason' ) );
		$request_id = self::post_value( 'request_id' );
		$result = new WP_Error( 'rr_adjustment_invalid', __( 'Choose a valid customer, non-zero whole points, and an adjustment reason.', 'rewards-referrals' ) );
		if ( $customer && get_userdata( $customer ) && $amount && '' !== $reason && preg_match( '/^[a-f0-9-]{36}$/i', $request_id ) ) {
			$result = RR_Ledger::add( array( 'customer_id' => $customer, 'amount' => $amount, 'type' => 'manual_adjustment', 'key' => 'admin-adjust:' . strtolower( $request_id ), 'meta' => array( 'actor' => get_current_user_id(), 'reason' => wp_html_excerpt( $reason, 200, '' ) ) ) );
			if ( ! is_wp_error( $result ) ) { self::audit( 'points_adjusted', $customer, array( 'amount' => $amount ) ); }
		}
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Adjustment could not be recorded', 'rewards-referrals' ), array( 'response' => 400, 'back_link' => true ) ); }
		$redirect_tab = in_array( self::post_value( 'redirect_tab' ), array( 'ledger', 'wallets' ), true ) ? self::post_value( 'redirect_tab' ) : 'ledger';
		$redirect_args = array( 'page' => self::page_for_tab( $redirect_tab ), 'tab' => $redirect_tab );
		if ( 'wallets' === $redirect_tab && $customer ) { $redirect_args['customer'] = $customer; }
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function approve_referral() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'rewards-referrals' ), '', array( 'response' => 403 ) ); }
		$id_raw = self::post_value( 'referral_id' );
		$id = preg_match( '/^\d+$/', $id_raw ) ? absint( $id_raw ) : 0;
		check_admin_referer( 'br_approve_referral_' . $id );
		$result = self::approve_referral_record( $id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 503, 'back_link' => true ) ); }
		wp_safe_redirect( self::tab_url( 'referrals' ) );
		exit;
	}


	/** Approve an existing edge without rewriting its attribution or historical records. */
	public static function approve_referral_record( $id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'rr_referral_permission', __( 'Permission denied.', 'rewards-referrals' ) ); }
		if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || ! absint( $id ) ) { return new WP_Error( 'rr_referral_invalid', __( 'Choose a valid referral relationship.', 'rewards-referrals' ) ); }
		$id = absint( $id );
		global $wpdb;
		$table = RR_Database::table( 'referrals' );
		// Match the relationship service lock so reassignment cannot race approval.
		$lock_name = 'rr_referrals_' . substr( md5( $table ), 0, 20 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock_name ) ) ) { return new WP_Error( 'rr_referral_busy', __( 'Referral relationships are being updated. Please retry.', 'rewards-referrals' ) ); }
		$open = false;
		try {
			foreach ( array( $table, RR_Database::table( 'audit' ) ) as $table_name ) {
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table_name ) );
				if ( 'InnoDB' !== $engine ) { return new WP_Error( 'rr_referral_storage', __( 'Referral storage could not be verified. No approval was saved.', 'rewards-referrals' ) ); }
			}
			$peek = $wpdb->get_row( $wpdb->prepare( 'SELECT customer_id,referrer_id FROM %i WHERE id=%d', $table, $id ) );
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_referral_read', __( 'The relationship could not be read. Please retry.', 'rewards-referrals' ) ); }
			if ( ! $peek ) { return new WP_Error( 'rr_referral_missing', __( 'This relationship no longer exists. Refresh the referral list.', 'rewards-referrals' ) ); }
			$started = RR_Ledger::begin_wallet_transaction( array( (int) $peek->customer_id, (int) $peek->referrer_id ), false );
			if ( is_wp_error( $started ) ) { return $started; }
			$open = true;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,approved FROM %i WHERE id=%d FOR UPDATE', $table, $id ) );
			if ( $wpdb->last_error || ! $row ) { return new WP_Error( 'rr_referral_read', __( 'The relationship could not be locked. Please retry.', 'rewards-referrals' ) ); }
			if ( 1 === (int) $row->approved ) { return true; }
			$changed = $wpdb->update( $table, array( 'approved' => 1 ), array( 'id' => $id, 'approved' => 0 ), array( '%d' ), array( '%d', '%d' ) );
			if ( 1 !== $changed ) { return new WP_Error( 'rr_referral_write', __( 'The approval could not be saved. Please retry.', 'rewards-referrals' ) ); }
			self::audit( 'referral_approved', $id );
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_referral_audit', __( 'The approval audit could not be saved. No approval was saved.', 'rewards-referrals' ) ); }
			$finished = RR_Ledger::finish_wallet_transaction( true );
			$open = false;
			if ( is_wp_error( $finished ) ) { return $finished; }
		} catch ( Throwable $error ) { return new WP_Error( 'rr_referral_exception', __( 'The approval could not be safely saved.', 'rewards-referrals' ) ); }
		finally {
			if ( $open ) { RR_Ledger::finish_wallet_transaction( false ); }
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
		delete_transient( 'rr_dashboard_stats' );
		return true;
	}

	public static function audit( $action, $subject_id = 0, $details = array() ) {
		global $wpdb;
		$wpdb->insert( RR_Database::table( 'audit' ), array( 'actor_id' => get_current_user_id(), 'action' => sanitize_key( $action ), 'subject_id' => absint( $subject_id ), 'details' => wp_json_encode( $details ), 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%s', '%d', '%s', '%s' ) );
	}
}
