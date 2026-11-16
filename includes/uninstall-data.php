<?php
defined( 'ABSPATH' ) || exit;

function rr_uninstall_data() {
// Retain accounting history unless the store explicitly selected deletion beforehand.
if ( 'yes' !== get_option( 'rr_delete_on_uninstall', 'no' ) ) { return; }
global $wpdb;
foreach ( array( 'referrals', 'ledger', 'rules', 'audit', 'wallet_locks', 'redemptions', 'referral_codes', 'referral_history', 'point_wallets', 'point_lots', 'point_allocations', 'redemption_items', 'wallet_balances', 'catalog', 'catalog_queue', 'credit_facts', 'commission_totals', 'customer_lookup', 'request_limits', 'notification_outbox', 'wallet_rebuilds', 'recovery_events', 'legacy_lot_migrations' ) as $suffix ) {
	$table = $wpdb->prefix . 'br_' . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
}
foreach ( array( 'br_settings', 'br_schema_version', 'br_rules_version', 'rr_campaigns', 'rr_notifications', 'rr_delete_on_uninstall', 'rr_notification_failures', 'rr_catalog_state', 'rr_commission_index_state', 'rr_customer_index_after', 'rr_customer_index_complete', 'rr_legacy_after_customer', 'rr_referral_index_cursor', 'rr_referral_index_ready', 'rr_referral_pending_after', 'rr_maturity_after_customer', 'rr_expiry_after_customer', 'rr_outbox_floor', 'rr_outbox_retention_after', 'rr_schema_error', 'rr_schema_retry_after', 'rr_wallet_identity_state', 'rr_reservation_sweep_cursor' ) as $key ) { delete_option( $key ); }
do {
	$notification_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT 500", $wpdb->options, $wpdb->esc_like( 'rr_notified_' ) . '%' ) );
	foreach ( $notification_options as $notification_option ) { delete_option( $notification_option ); }
} while ( count( $notification_options ) === 500 );
// Legacy referral metadata is global on multisite and can still be needed by another blog.
if ( ! is_multisite() ) {
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_br_referral_code','_br_referrer_id','_rr_pending_referral')" );
}
// Account proof belongs to this site; another blog can still retain its own wallet history.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key=%s', $wpdb->usermeta, $wpdb->prefix . 'rr_wallet_identity_token' ) );
// Exact plugin-owned keys only. Native post metadata deletion also invalidates
// product and legacy-order metadata caches without purging unrelated site data.
$metadata_keys = array(
	'_br_exclude_rewards', '_br_points_awarded', '_br_referral_commission', '_br_reward_order',
	'_rr_cash_price', '_rr_checkout_key', '_rr_commission_rate', '_rr_earning_mode',
	'_rr_exclude_commission', '_rr_fixed_points', '_rr_points_per_unit', '_rr_points_price', '_rr_points_reserved',
	'_rr_reward_end_date', '_rr_reward_limit_customer', '_rr_reward_limit_daily', '_rr_reward_limit_monthly',
	'_rr_reward_min_balance', '_rr_reward_mode', '_rr_reward_policy_mode', '_rr_reward_roles', '_rr_reward_start_date',
	'_rr_confirmation_waiting', '_rr_rewards_award_complete', '_rr_rewards_cancellation_noted', '_rr_rewards_cancelled', '_rr_rewards_cash_each',
	'_rr_rewards_commission_snapshot', '_rr_rewards_commission_transaction_id', '_rr_rewards_eligible_basis_minor',
	'_rr_rewards_eligible_items_minor', '_rr_rewards_item_points', '_rr_rewards_mode', '_rr_rewards_points_each',
	'_rr_rewards_purchase_snapshot', '_rr_rewards_purchase_transaction_id',
);
foreach ( $metadata_keys as $metadata_key ) { delete_post_meta_by_key( $metadata_key ); }
// WooCommerce can retain both HPOS and legacy copies. Remove plugin annotations
// from each existing metadata store, but never delete the underlying orders/items.
foreach ( array( 'wc_orders_meta', 'woocommerce_order_itemmeta' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { continue; }
	foreach ( $metadata_keys as $metadata_key ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key=%s', $table, $metadata_key ) );
	}
}
foreach ( array( 'rr_accounting_maintenance', 'rr_mature_pending_points', 'rr_expire_credit_lots', 'rr_send_notification', 'rr_expire_redemption_reservation', 'rr_catalog_worker', 'rr_catalog_continue', 'rr_commission_index', 'rr_retry_order_rewards', 'rr_customer_index', 'rr_outbox_worker', 'rr_background_dispatch', 'rr_wallet_rebuild', 'rr_wallet_rebuild_poll', 'rr_retry_refund_rewards', 'rr_retry_cancel_rewards', 'rr_recovery_worker', 'rr_legacy_lot_migration', 'rr_legacy_lot_poll', 'rr_referral_index', 'rr_expire_customer_lots', 'rr_mature_customer_points', 'rr_retry_confirm_rewards', 'rr_retry_release_rewards' ) as $hook ) {
	wp_unschedule_hook( $hook );
	if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( $hook, null, 'rewards-referrals' ); }
}
delete_transient( 'rr_dashboard_stats' );

}
