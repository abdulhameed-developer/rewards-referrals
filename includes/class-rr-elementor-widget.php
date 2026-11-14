<?php
defined( 'ABSPATH' ) || exit;

/** Loaded only after Elementor registers its widget API. */
final class RR_Elementor_Widget extends \Elementor\Widget_Base {
	public function get_name() { return 'rr-rewards-referrals'; }
	public function get_title() { return __( 'Rewards & Referrals', 'rewards-referrals' ); }
	public function get_icon() { return 'eicon-star'; }
	public function get_categories() { return array( 'general' ); }
	public function get_keywords() { return array( 'rewards', 'loyalty', 'wallet', 'referrals', 'points', 'woocommerce' ); }
	public function get_style_depends() { return array( 'rr-storefront' ); }
	public function get_script_depends() { return array( 'rr-storefront' ); }
	protected function register_controls() {
		$options = array();
		foreach ( RR_Integrations::components() as $key => $component ) { $options[ $key ] = $component[0]; }
		$this->start_controls_section( 'rr_content', array( 'label' => __( 'Rewards component', 'rewards-referrals' ) ) );
		$this->add_control( 'component', array( 'label' => __( 'Display', 'rewards-referrals' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => $options, 'default' => 'wallet' ) );
		$this->end_controls_section();
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
	protected function render() { echo RR_Integrations::render( $this->get_settings_for_display() ); }
}
