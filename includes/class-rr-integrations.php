<?php
defined( 'ABSPATH' ) || exit;

final class RR_Integrations {
	public static function components() {
		return array(
			'wallet' => array( __( 'Customer wallet', 'rewards-referrals' ), 'wallet_shortcode' ),
			'referrals' => array( __( 'Referral dashboard', 'rewards-referrals' ), 'referral_shortcode' ),
			'link' => array( __( 'Referral link', 'rewards-referrals' ), 'link_shortcode' ),
			'catalog' => array( __( 'Rewards catalog', 'rewards-referrals' ), 'catalog_shortcode' ),
			'history' => array( __( 'Points history', 'rewards-referrals' ), 'history_shortcode' ),
			'redemptions' => array( __( 'Redemption history', 'rewards-referrals' ), 'redemptions_shortcode' ),
			'summary' => array( __( 'Rewards summary', 'rewards-referrals' ), 'summary_shortcode' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_elementor' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
	}

	public static function register_assets() {
		wp_register_style( 'rr-storefront', REWARDS_REFERRALS_URL . 'assets/storefront.css', array(), REWARDS_REFERRALS_VERSION );
		wp_register_script( 'rr-storefront', REWARDS_REFERRALS_URL . 'assets/storefront.js', array(), REWARDS_REFERRALS_VERSION, true );
	}

	public static function render( $attributes = array() ) {
		$component = isset( $attributes['component'] ) && is_string( $attributes['component'] ) ? $attributes['component'] : 'wallet';
		$components = self::components();
		if ( ! isset( $components[ $component ] ) ) { return ''; }
		return '<div class="rr-storefront">' . call_user_func( array( 'RR_Frontend', $components[ $component ][1] ) ) . '</div>';
	}

	public static function register_block() {
		wp_register_script( 'rr-editor-block', REWARDS_REFERRALS_URL . 'assets/editor-block.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ), REWARDS_REFERRALS_VERSION, true );
		$options = array();
		foreach ( self::components() as $key => $component ) { $options[] = array( 'value' => $key, 'label' => $component[0] ); }
		wp_localize_script( 'rr-editor-block', 'RREditorConfig', array( 'components' => $options ) );
		register_block_type( 'rewards-referrals/component', array(
			'api_version' => 3,
			'editor_script' => 'rr-editor-block',
			'render_callback' => array( __CLASS__, 'render' ),
			'attributes' => array( 'component' => array( 'type' => 'string', 'default' => 'wallet', 'enum' => array_keys( self::components() ) ) ),
			'supports' => array( 'html' => false ),
		) );
	}

	public static function register_elementor( $manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) { return; }
		require_once REWARDS_REFERRALS_DIR . 'includes/class-rr-elementor-widget.php';
		$manager->register( new RR_Elementor_Widget() );
	}
}
