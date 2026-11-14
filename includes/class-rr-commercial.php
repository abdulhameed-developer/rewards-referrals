<?php
defined( 'ABSPATH' ) || exit;

/** Shared edition UI. Accounting never depends on licensing. */
final class RR_Commercial {
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'edition_notice' ) );
	}

	public static function edition_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || self::has_pro() ) { return; }
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'rewards-referrals' ) ) { return; }
		foreach ( (array) get_option( 'rr_campaigns', array() ) as $campaign ) {
			if ( is_array( $campaign ) && 'yes' === ( $campaign['enabled'] ?? 'no' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Saved Pro campaigns are paused in this edition. Future purchases use your standard earning rules. Historical awards and refund accounting are preserved.', 'rewards-referrals' ) . '</p></div>';
				return;
			}
		}
	}
	public static function has_pro() {
		return class_exists( 'RR_Pro', false ) && rr_fs()->can_use_premium_code();
	}

	public static function upgrade_url() {
		return rr_fs()->get_upgrade_url();
	}

	public static function card( $feature ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		echo '<section class="rr-card rr-setting-card rr-pro-card"><span class="rr-section-kicker">' . esc_html__( 'REWARDS & REFERRALS PRO', 'rewards-referrals' ) . '</span><h3>' . esc_html( $feature ) . '</h3><p>' . esc_html__( 'Add scheduled earning campaigns, filtered summary reports and custom email templates with Pro. Core rewards, wallets and refund handling remain available in Free.', 'rewards-referrals' ) . '</p><a class="rr-button rr-button-primary" href="' . esc_url( self::upgrade_url() ) . '">' . esc_html__( 'Explore Pro', 'rewards-referrals' ) . '</a><p class="rr-help">' . esc_html__( 'Changing editions does not delete your settings or accounting history. Keep the Pro edition installed to continue using its features with an eligible license.', 'rewards-referrals' ) . '</p></section>';
	}
}
