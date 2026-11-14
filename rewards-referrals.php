<?php
/**
 * Plugin Name: Rewards & Referrals
 * Plugin URI: https://www.linkedin.com/in/abdul-hameed-website-developer/
 * Description: Customer loyalty points and direct referral rewards for WooCommerce.
 * Version: 0.6.0-rc.9
 * Author: Abdul Hameed
 * Author URI: https://www.linkedin.com/in/abdul-hameed-website-developer/
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 10.8
 * WC tested up to: 11.3
 * Domain Path: /languages
 * Text Domain: rewards-referrals
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
defined( 'ABSPATH' ) || exit;
add_action( 'init', static function () { load_plugin_textdomain( 'rewards-referrals', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); }, 0 );

// Freemius uses this branch to deactivate the other edition before loading it.
if ( function_exists( 'rr_fs' ) ) {
	rr_fs()->set_basename( false, __FILE__ );
} else {
	if ( ! function_exists( 'rr_fs' ) ) {
		function rr_fs() {
			global $rr_fs;
			if ( ! isset( $rr_fs ) ) {
				require_once __DIR__ . '/vendor/freemius/start.php';
				$rr_fs = fs_dynamic_init( array(
					'id' => '44712',
					'slug' => 'rewards-referrals',
					'premium_slug' => 'rewards-referrals-premium',
					'type' => 'plugin',
					'public_key' => 'pk_4c03c47e008ad9e6f37fb4e71f467',
					'is_premium' => false,
					'premium_suffix' => 'Pro',
					'has_premium_version' => true,
					'has_paid_plans' => true,
					'has_addons' => false,
					'is_org_compliant' => true,
					'enable_anonymous' => true,
					'menu' => array( 'slug' => 'rewards-referrals', 'contact' => false, 'support' => false ),
				) );
			}
			return $rr_fs;
		}
	}
	rr_fs();
	require_once __DIR__ . '/includes/uninstall-data.php';
	rr_fs()->add_action( 'after_uninstall', 'rr_uninstall_data' );
	do_action( 'rr_fs_loaded' );

define( 'REWARDS_REFERRALS_VERSION', '0.6.0-rc.9' );
define( 'REWARDS_REFERRALS_FILE', __FILE__ );
define( 'REWARDS_REFERRALS_DIR', plugin_dir_path( __FILE__ ) );
define( 'REWARDS_REFERRALS_URL', plugin_dir_url( __FILE__ ) );
require_once REWARDS_REFERRALS_DIR . 'includes/class-br-database.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-wallet-balances.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-wallet-rebuild.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-recovery.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-legacy-lot-migration.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-background.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-outbox.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-commission-index.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-br-ledger.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-br-rules.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-br-referrals.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-br-woocommerce.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-br-frontend.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-br-admin.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-operations.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-integrations.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-commercial.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-evidence.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-customer-search.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-customer-records.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-catalog.php';
require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-program-check.php';

register_activation_hook( __FILE__, array( 'RR_Database', 'activate' ) );
register_deactivation_hook( __FILE__, static function () {
	global $wp_rewrite;
	foreach ( array( 'rr_accounting_maintenance', 'rr_mature_pending_points', 'rr_expire_credit_lots', 'rr_send_notification', 'rr_expire_redemption_reservation', 'rr_catalog_worker', 'rr_catalog_continue', 'rr_commission_index', 'rr_retry_order_rewards', 'rr_customer_index', 'rr_outbox_worker', 'rr_background_dispatch', 'rr_wallet_rebuild', 'rr_wallet_rebuild_poll', 'rr_retry_refund_rewards', 'rr_retry_cancel_rewards', 'rr_recovery_worker', 'rr_legacy_lot_migration', 'rr_legacy_lot_poll', 'rr_referral_index', 'rr_expire_customer_lots', 'rr_mature_customer_points', 'rr_retry_confirm_rewards', 'rr_retry_release_rewards' ) as $hook ) {
		wp_unschedule_hook( $hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( $hook, null, 'rewards-referrals' ); }
	}
	if ( $wp_rewrite instanceof WP_Rewrite ) {
		$wp_rewrite->endpoints = array_values( array_filter( $wp_rewrite->endpoints, static function ( $endpoint ) { return 'rewards' !== ( $endpoint[1] ?? '' ); } ) );
	}
	flush_rewrite_rules();
} );

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', static function () {
	RR_Database::maybe_upgrade();
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Rewards & Referrals requires WooCommerce to be active.', 'rewards-referrals' ) . '</p></div>';
			}
		} );
		return;
	}
	RR_Background::init();
	RR_Referrals::init();
	RR_Ledger::init();
	RR_WooCommerce::init();
	RR_Frontend::init();
	RR_Operations::init();
	RR_Outbox::init();
	RR_Wallet_Rebuild::init();
	RR_Legacy_Lot_Migration::init();
	RR_Recovery::init();
	RR_Evidence::init();
	RR_Program_Check::init();
	RR_Customer_Search::init();
	RR_Customer_Records::init();
	RR_Catalog::init();
	RR_Commission_Index::init();
	RR_Integrations::init();
	if ( is_admin() ) {
		RR_Admin::init();
		RR_Commercial::init();
	}
} );

} // Only load the active Free or Pro edition once.
