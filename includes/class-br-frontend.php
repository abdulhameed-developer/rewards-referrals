<?php
defined( 'ABSPATH' ) || exit;

final class RR_Frontend {
	public static function init() {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_account_rewards_endpoint', array( __CLASS__, 'account' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_personal_pages' ), 0 );
		foreach ( array( 'rewards_wallet' => 'wallet_shortcode', 'rewards_referral_link' => 'link_shortcode', 'rewards_referral_stats' => 'stats_shortcode', 'rewards_referral_dashboard' => 'referral_shortcode', 'rewards_transaction_history' => 'history_shortcode', 'rewards_catalog' => 'catalog_shortcode', 'rewards_redemption_history' => 'redemptions_shortcode', 'rewards_summary' => 'summary_shortcode' ) as $tag => $method ) { add_shortcode( $tag, array( __CLASS__, $method ) ); }
	}
	public static function endpoint() { add_rewrite_endpoint( 'rewards', EP_ROOT | EP_PAGES ); }
	public static function protect_personal_pages() {
		if ( ! is_user_logged_in() ) { return; }
		$post = get_post();
		$personal = function_exists( 'is_account_page' ) && is_account_page();
		if ( $post instanceof WP_Post ) {
			$personal = $personal || has_block( 'rewards-referrals/component', $post );
			foreach ( array( 'rewards_wallet', 'rewards_referral_link', 'rewards_referral_stats', 'rewards_referral_dashboard', 'rewards_transaction_history', 'rewards_catalog', 'rewards_redemption_history', 'rewards_summary' ) as $tag ) {
				if ( has_shortcode( $post->post_content, $tag ) ) { $personal = true; break; }
			}
		}
		if ( $personal ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			nocache_headers();
		}
	}
	public static function menu( $items ) { $logout = $items['customer-logout'] ?? null; unset( $items['customer-logout'] ); $items['rewards'] = __( 'Rewards', 'rewards-referrals' ); if ( $logout ) { $items['customer-logout'] = $logout; } return $items; }
	public static function assets() {
		$post = get_post();
		$needed = function_exists( 'is_account_page' ) && is_account_page();
		if ( $post && ! $needed ) { foreach ( array( 'rewards_wallet', 'rewards_referral_link', 'rewards_referral_stats', 'rewards_referral_dashboard', 'rewards_transaction_history', 'rewards_catalog', 'rewards_redemption_history', 'rewards_summary' ) as $tag ) { if ( has_shortcode( $post->post_content, $tag ) ) { $needed = true; break; } } }
		if ( $needed ) { self::enqueue(); }
	}
	private static function enqueue() {
		if ( is_user_logged_in() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			if ( ! headers_sent() ) { nocache_headers(); }
		}
		wp_enqueue_style( 'rr-storefront', REWARDS_REFERRALS_URL . 'assets/storefront.css', array(), REWARDS_REFERRALS_VERSION );
		wp_enqueue_script( 'rr-storefront', REWARDS_REFERRALS_URL . 'assets/storefront.js', array(), REWARDS_REFERRALS_VERSION, true );
		wp_localize_script( 'rr-storefront', 'RRStorefrontConfig', array( 'copied' => __( 'Referral link copied.', 'rewards-referrals' ), 'copyFailed' => __( 'Select the link and copy it using your device controls.', 'rewards-referrals' ), 'shareTitle' => __( 'Join me and earn rewards', 'rewards-referrals' ) ) );
	}
	public static function account() {
		self::enqueue();
		echo '<div class="rr-storefront"><header class="rr-customer-header"><span class="rr-customer-kicker">' . esc_html__( 'YOUR LOYALTY PROGRAM', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Your rewards', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'Earn on eligible purchases, share your referral link, and choose your next reward.', 'rewards-referrals' ) . '</p></header><nav class="rr-customer-nav" aria-label="' . esc_attr__( 'Your rewards sections', 'rewards-referrals' ) . '"><a href="#rr-your-wallet">' . esc_html__( 'Wallet', 'rewards-referrals' ) . '</a><a href="#rr-your-referrals">' . esc_html__( 'Referrals', 'rewards-referrals' ) . '</a><a href="#rr-customer-catalog">' . esc_html__( 'Rewards catalog', 'rewards-referrals' ) . '</a><a href="#rr-your-activity">' . esc_html__( 'Activity', 'rewards-referrals' ) . '</a></nav>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed markup uses trusted renderers that escape dynamic values at their source; literal control attributes are fixed.
		echo self::render_wallet() . '<div class="rr-customer-grid"><div>' . self::render_link() . '</div><div>' . self::render_stats() . '</div></div>' . RR_Customer_Records::render() . self::catalog_shortcode() . self::render_history() . self::redemptions_shortcode() . '</div>';
	}
	private static function ownership_message() {
		$owner = RR_Wallet_Identity::check( get_current_user_id() );
		return is_wp_error( $owner ) ? '<p class="rr-customer-empty" role="status">' . esc_html( $owner->get_error_message() ) . '</p>' : '';
	}
	private static function signed_in_message() { return '<p class="rr-customer-empty">' . esc_html__( 'Sign in to view your rewards.', 'rewards-referrals' ) . ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'Sign in', 'rewards-referrals' ) . '</a></p>'; }
	private static function render_wallet() {
		self::enqueue();
		if ( ! is_user_logged_in() ) { return self::signed_in_message(); }
		$ownership = self::ownership_message(); if ( $ownership ) { return $ownership; }
		global $wpdb;
		$table = RR_Database::table( 'ledger' );
		$customer_id = get_current_user_id();
		$balance = RR_Ledger::balances( $customer_id );
		if ( ! empty( $balance['unavailable'] ) ) { return '<p class="rr-customer-empty" role="status">' . esc_html__( 'Your wallet is temporarily unavailable. Please try again shortly.', 'rewards-referrals' ) . '</p>'; }
		$earned = $balance['earned'];
		$redeemed = $balance['spent'];
		$out = '<section id="rr-your-wallet" class="rr-customer-wallet"><div class="rr-wallet-available"><span>' . esc_html__( 'Available points', 'rewards-referrals' ) . '</span><strong>' . esc_html( number_format_i18n( $balance['available'] ) ) . '</strong><small>' . esc_html__( 'Ready to use on eligible rewards', 'rewards-referrals' ) . '</small></div><div class="rr-customer-totals">';
		foreach ( array( 'pending' => __( 'Pending', 'rewards-referrals' ), 'reserved' => __( 'Reserved', 'rewards-referrals' ), 'earned' => __( 'Total earned', 'rewards-referrals' ), 'redeemed' => __( 'Redeemed', 'rewards-referrals' ) ) as $key => $label ) { $value = 'earned' === $key ? $earned : ( 'redeemed' === $key ? $redeemed : $balance[ $key ] ); $out .= '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong></div>'; }
		$out .= '</div></section>';
		if ( class_exists( 'RR_Point_Lots' ) ) {
			$expiry = RR_Point_Lots::expiring_summary( $customer_id );
			if ( ! empty( $expiry['points'] ) && ! empty( $expiry['next_expiry'] ) ) {
				/* translators: 1: Point amount. 2: Localized expiration date. */
				$out .= '<p class="rr-customer-note">' . esc_html( sprintf( __( '%1$s available points expire on %2$s.', 'rewards-referrals' ), number_format_i18n( $expiry['points'] ), wp_date( get_option( 'date_format' ), strtotime( $expiry['next_expiry'] . ' UTC' ) ) ) ) . '</p>';
			}
		}
		/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
		if ( $balance['debt'] ) { $out .= '<p class="rr-customer-note">' . esc_html( sprintf( __( 'Future earnings first offset a previous adjustment of %s points.', 'rewards-referrals' ), number_format_i18n( $balance['debt'] ) ) ) . '</p>'; }
		return $out;
	}
	private static function render_link() {
		self::enqueue();
		if ( ! is_user_logged_in() ) { return ''; }
		$ownership = self::ownership_message(); if ( $ownership ) { return $ownership; }
		$code = RR_Referrals::ensure_code( get_current_user_id() );
		if ( ! $code ) { return '<p class="rr-customer-empty">' . esc_html__( 'Your referral link is temporarily unavailable. Please try again later.', 'rewards-referrals' ) . '</p>'; }
		$url = add_query_arg( 'ref', $code, home_url( '/' ) );
		$id = wp_unique_id( 'rr-referral-' );
		return '<section id="rr-your-referrals" class="rr-customer-card"><h3>' . esc_html__( 'Invite friends. Share the rewards.', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Share your personal link. Eligible purchases by your direct referrals can earn you extra points.', 'rewards-referrals' ) . '</p><label class="rr-customer-link-label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Your referral link', 'rewards-referrals' ) . '</label><div class="rr-customer-link"><input id="' . esc_attr( $id ) . '" type="url" readonly value="' . esc_attr( $url ) . '"><button type="button" data-rr-copy="' . esc_attr( $id ) . '">' . esc_html__( 'Copy link', 'rewards-referrals' ) . '</button><button class="rr-customer-secondary" type="button" data-rr-share="' . esc_attr( $id ) . '">' . esc_html__( 'Share', 'rewards-referrals' ) . '</button></div><p class="rr-customer-copy-status" role="status" aria-live="polite"></p><small>' . esc_html__( 'Referral code:', 'rewards-referrals' ) . ' <strong>' . esc_html( $code ) . '</strong></small></section>';
	}
	private static function render_stats() {
		self::enqueue();
		if ( ! is_user_logged_in() ) { return ''; }
		$ownership = self::ownership_message(); if ( $ownership ) { return $ownership; }
		global $wpdb;
		$customer_id = get_current_user_id();
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE referrer_id=%d AND approved=1', RR_Database::table( 'referrals' ), $customer_id ) );
		$ledger = RR_Database::table( 'ledger' );
		$earned = RR_Ledger::balances( $customer_id )['referral_earned'];
		return '<section class="rr-customer-card rr-referral-stat-card"><h3>' . esc_html__( 'Your referral impact', 'rewards-referrals' ) . '</h3><div class="rr-referral-stat"><strong>' . esc_html( number_format_i18n( $count ) ) . '</strong><span>' . esc_html__( 'Direct referrals', 'rewards-referrals' ) . '</span></div><div class="rr-referral-stat"><strong>' . esc_html( number_format_i18n( $earned ) ) . '</strong><span>' . esc_html__( 'Referral points earned', 'rewards-referrals' ) . '</span></div><p>' . esc_html__( 'Only your immediate referrals count toward direct referral rewards.', 'rewards-referrals' ) . '</p></section>';
	}
	/** Public customer explanations never use staff notes, metadata or another customer's identity. */
	public static function activity_description( $type, $state ) {
		$activity = array(
			'purchase_points' => array( __( 'Purchase points', 'rewards-referrals' ), __( 'Points earned on an eligible purchase.', 'rewards-referrals' ) ),
			'referral_commission' => array( __( 'Referral points', 'rewards-referrals' ), __( 'Points earned from an eligible purchase by your direct referral.', 'rewards-referrals' ) ),
			'refund_reversal' => array( __( 'Earned points reversed', 'rewards-referrals' ), __( 'Previously earned points removed after a refund or cancellation. This is separate from points returned for a redeemed reward.', 'rewards-referrals' ) ),
			'redemption_restore' => array( __( 'Reward points returned', 'rewards-referrals' ), __( 'Points spent on a reward returned after a refund or cancellation.', 'rewards-referrals' ) ),
			'redemption_reservation' => array( __( 'Reward points reserved', 'rewards-referrals' ), __( 'Points set aside while a reward order is processed. A later entry records spending or release; do not add every history row to calculate your balance.', 'rewards-referrals' ) ),
			'redemption_debit' => array( __( 'Reward redeemed', 'rewards-referrals' ), __( 'Reserved points spent on a confirmed reward order.', 'rewards-referrals' ) ),
			'reservation_release' => array( __( 'Reserved points released', 'rewards-referrals' ), __( 'A reward reservation was released. The points are no longer held for that order.', 'rewards-referrals' ) ),
			'point_expiration' => array( __( 'Points expired', 'rewards-referrals' ), __( 'Unused points reached their saved expiry date.', 'rewards-referrals' ) ),
			'expiry_refund_offset' => array( __( 'Expired points reconciled', 'rewards-referrals' ), __( 'An earlier expiry was accounted for during a refund so those points are not deducted twice.', 'rewards-referrals' ) ),
			'promotional_bonus' => array( __( 'Bonus points', 'rewards-referrals' ), __( 'Additional points credited to your rewards account.', 'rewards-referrals' ) ),
			'manual_adjustment' => array( __( 'Points adjustment', 'rewards-referrals' ), __( 'The store recorded a correction to your points. Contact the store if you need more information.', 'rewards-referrals' ) ),
			'manual_credit' => array( __( 'Points added', 'rewards-referrals' ), __( 'The store added points to your rewards account.', 'rewards-referrals' ) ),
			'manual_debit' => array( __( 'Points deducted', 'rewards-referrals' ), __( 'The store deducted points from your rewards account.', 'rewards-referrals' ) ),
			'migration_correction' => array( __( 'Historical balance correction', 'rewards-referrals' ), __( 'A correction was recorded while bringing earlier rewards history into this account.', 'rewards-referrals' ) ),
		);
		$states = array( 'pending' => __( 'Pending', 'rewards-referrals' ), 'available' => __( 'Recorded', 'rewards-referrals' ), 'reserved' => __( 'Reserved', 'rewards-referrals' ), 'spent' => __( 'Spent', 'rewards-referrals' ), 'expired' => __( 'Expired', 'rewards-referrals' ), 'reversed' => __( 'Reversed', 'rewards-referrals' ), 'adjusted' => __( 'Adjusted', 'rewards-referrals' ) );
		$details = $activity[ $type ] ?? array( __( 'Points activity', 'rewards-referrals' ), __( 'A change was recorded in your rewards history. Contact the store for help.', 'rewards-referrals' ) );
		return array( 'label' => $details[0], 'explanation' => $details[1], 'state' => $states[ $state ] ?? __( 'Recorded', 'rewards-referrals' ) );
	}

	private static function render_history() {
		self::enqueue();
		if ( ! is_user_logged_in() ) { return ''; }
		$ownership = self::ownership_message(); if ( $ownership ) { return $ownership; }
		global $wpdb;
		$table = RR_Database::table( 'ledger' );
		$customer_id = get_current_user_id();
		$page = max( 1, absint( self::query_value( 'rr_history_page' ) ) );
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d', $table, $customer_id ) );
		$read_error = (bool) $wpdb->last_error || null === RR_Ledger::validated_integer( $total, 0, PHP_INT_MAX );
		$page = min( $page, max( 1, (int) ceil( (int) $total / 20 ) ) );
		$rows = $read_error ? null : $wpdb->get_results( $wpdb->prepare( 'SELECT id,amount,transaction_type,state,order_id,created_at FROM %i WHERE customer_id=%d ORDER BY id DESC LIMIT 20 OFFSET %d', $table, $customer_id, ( $page - 1 ) * 20 ) );
		$read_error = $read_error || (bool) $wpdb->last_error || ! is_array( $rows );
		$out = '<section id="rr-your-activity" class="rr-customer-card rr-customer-history"><h3>' . esc_html__( 'Points activity', 'rewards-referrals' ) . '</h3>';
		if ( $read_error ) { return $out . '<p class="rr-customer-empty" role="status">' . esc_html__( 'Your points history is temporarily unavailable. Please try again shortly.', 'rewards-referrals' ) . '</p></section>'; }
		if ( ! $rows ) { return $out . '<p class="rr-customer-empty">' . esc_html__( 'Your rewards activity will appear here after your first eligible purchase.', 'rewards-referrals' ) . '</p></section>'; }
		$out .= '<div class="rr-customer-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Points activity', 'rewards-referrals' ) . '"><table><thead><tr><th>' . esc_html__( 'Date', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Activity', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'State', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Points', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Order', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$description = self::activity_description( $row->transaction_type, $row->state );
			$order = $row->order_id ? wc_get_order( absint( $row->order_id ) ) : false;
			$order_link = $order && (int) $order->get_customer_id() === $customer_id ? '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '—';
			$out .= '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $row->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( $description['label'] ) . '<small class="rr-activity-explanation">' . esc_html( $description['explanation'] ) . '</small></td><td>' . esc_html( $description['state'] ) . '</td><td class="' . ( $row->amount < 0 ? 'rr-negative' : 'rr-positive' ) . '">' . ( $row->amount > 0 ? '+' : '' ) . esc_html( number_format_i18n( (int) $row->amount ) ) . '</td><td>' . $order_link . '</td></tr>';
		}
		return $out . '</tbody></table></div>' . self::pagination( $page, $total, 20, 'rr_history_page', 'rr-your-activity' ) . '</section>';
	}
	public static function catalog_shortcode( $attributes = array() ) {
		self::enqueue();
		$settings = RR_Rules::get();
		$out = '<section id="rr-customer-catalog" class="rr-customer-catalog"><h3>' . esc_html__( 'Choose your next reward', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Browse available rewards and open a product to choose its options and redeem.', 'rewards-referrals' ) . '</p>';
		if ( 'yes' !== $settings['redemption_enabled'] ) { return $out . '<p class="rr-customer-empty">' . esc_html__( 'Rewards are being prepared and will be available here soon. Your available points remain safely in your wallet.', 'rewards-referrals' ) . '</p></section>'; }
		$category = absint( self::query_value( 'rr_cat' ) ); $sort = self::query_value( 'rr_sort' );
		$listing = RR_Catalog::page( $category, $sort, self::query_value( 'rr_catalog_cursor' ) );
		if ( is_wp_error( $listing ) ) { return $out . '<p class="rr-customer-empty">' . esc_html( $listing->get_error_message() ) . '</p></section>'; }
		if ( $listing['building'] ) { $out .= '<p class="rr-customer-note">' . esc_html__( 'The rewards catalog is being refreshed. More eligible products may appear shortly.', 'rewards-referrals' ) . '</p>'; }
		$products = array();
		$ids = array_map( 'absint', array_column( $listing['rows'], 'product_id' ) );
		if ( $ids ) { _prime_post_caches( $ids, true, true ); }
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( RR_Catalog::eligible( $product ) && $product->is_purchasable() && $product->is_visible() ) { $products[] = $product; }
		}
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 100 ) );
		$out .= '<form class="rr-customer-catalog-filter" method="get"><label><span>' . esc_html__( 'Category', 'rewards-referrals' ) . '</span><select name="rr_cat"><option value="0">' . esc_html__( 'All categories', 'rewards-referrals' ) . '</option>';
		if ( is_array( $terms ) ) { foreach ( $terms as $term ) { $out .= '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( $category, $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>'; } }
		$out .= '</select></label><label><span>' . esc_html__( 'Sort rewards', 'rewards-referrals' ) . '</span><select name="rr_sort">';
		foreach ( array( '' => __( 'Name', 'rewards-referrals' ), 'newest' => __( 'Newest first', 'rewards-referrals' ), 'points_low' => __( 'Points: low to high', 'rewards-referrals' ), 'points_high' => __( 'Points: high to low', 'rewards-referrals' ) ) as $value => $label ) { $out .= '<option value="' . esc_attr( $value ) . '" ' . selected( $sort, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		$out .= '</select></label><button type="submit">' . esc_html__( 'Filter rewards', 'rewards-referrals' ) . '</button></form>';
		if ( ! $products ) { return $out . '<p class="rr-customer-empty">' . esc_html__( 'No rewards match this page. Try another page or filter.', 'rewards-referrals' ) . '</p>' . self::catalog_navigation( $listing['next'] ) . '</section>'; }
		$available = is_user_logged_in() ? RR_Ledger::balances( get_current_user_id() )['available'] : 0;
		$out .= '<div class="rr-customer-reward-grid">';
		foreach ( $products as $product ) {
			$points = RR_WooCommerce::points_price( $product );
			$price_html = 'cash_or_points' === RR_WooCommerce::reward_mode( $product ) ? $product->get_price_html() : '';
			$cash_label = 'points_plus_cash' === RR_WooCommerce::reward_mode( $product ) ? ' + ' . wp_strip_all_tags( wc_price( RR_WooCommerce::cash_price( $product ) ) ) : '';
			$policy_error = is_user_logged_in() ? RR_WooCommerce::reward_policy_error( $product, get_current_user_id(), 1 ) : '';
			$eligible = $product->is_in_stock() && $product->is_purchasable() && is_user_logged_in() && $available >= $points && ! $policy_error;
			/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
			$status = ! $product->is_in_stock() ? __( 'Out of stock', 'rewards-referrals' ) : ( ! is_user_logged_in() ? __( 'Sign in to check your balance', 'rewards-referrals' ) : ( $policy_error ? $policy_error : ( $eligible ? __( 'Your balance covers this reward', 'rewards-referrals' ) : sprintf( __( '%s more points needed', 'rewards-referrals' ), number_format_i18n( max( 0, $points - $available ) ) ) ) ) );
			/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
			$out .= '<article class="rr-customer-reward"><a class="rr-customer-reward-image" href="' . esc_url( $product->get_permalink() ) . '">' . wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ) . '</a><div class="rr-customer-reward-body"><h4><a href="' . esc_url( $product->get_permalink() ) . '">' . esc_html( $product->get_name() ) . '</a></h4><span class="rr-customer-reward-price">' . wp_kses_post( $price_html ) . '</span><strong>' . esc_html( sprintf( __( '%s points', 'rewards-referrals' ), number_format_i18n( $points ) ) ) . esc_html( $cash_label ) . '</strong><small>' . esc_html( $status ) . '</small><a class="rr-customer-reward-action" href="' . esc_url( $product->get_permalink() ) . '">' . esc_html( $product->is_type( 'variable' ) ? __( 'Choose options', 'rewards-referrals' ) : __( 'View reward', 'rewards-referrals' ) ) . '</a></div></article>';
		}
		return $out . '</div>' . self::catalog_navigation( $listing['next'] ) . '</section>';
	}
	private static function catalog_navigation( $next ) {
		$out = '<nav class="rr-customer-pagination" aria-label="' . esc_attr__( 'Reward catalog pages', 'rewards-referrals' ) . '">';
		if ( self::query_value( 'rr_catalog_cursor' ) ) { $out .= '<a href="' . esc_url( remove_query_arg( array( 'rr_catalog_cursor','rr_catalog_page' ) ) . '#rr-customer-catalog' ) . '">' . esc_html__( 'Start over', 'rewards-referrals' ) . '</a>'; }
		if ( $next ) { $out .= '<a href="' . esc_url( add_query_arg( 'rr_catalog_cursor', $next, remove_query_arg( 'rr_catalog_page' ) ) . '#rr-customer-catalog' ) . '">' . esc_html__( 'More rewards', 'rewards-referrals' ) . '</a>'; }
		return $out . '</nav>';
	}

	public static function redemptions_shortcode() {
		self::enqueue();
		if ( ! is_user_logged_in() ) { return ''; }
		$ownership = self::ownership_message(); if ( $ownership ) { return $ownership; }
		global $wpdb;
		$table = RR_Database::table( 'redemptions' );
		$customer_id = get_current_user_id();
		$page = max( 1, absint( self::query_value( 'rr_redemption_page' ) ) );
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d', $table, $customer_id ) );
		$read_error = (bool) $wpdb->last_error || null === RR_Ledger::validated_integer( $total, 0, PHP_INT_MAX );
		$page = min( $page, max( 1, (int) ceil( (int) $total / 10 ) ) );
		$rows = $read_error ? null : $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,points,status,created_at FROM %i WHERE customer_id=%d ORDER BY id DESC LIMIT 10 OFFSET %d', $table, $customer_id, ( $page - 1 ) * 10 ) );
		$read_error = $read_error || (bool) $wpdb->last_error || ! is_array( $rows );
		$out = '<section class="rr-customer-card rr-customer-history" id="rr-redemption-history"><h3>' . esc_html__( 'Your redemption orders', 'rewards-referrals' ) . '</h3>';
		if ( $read_error ) { return $out . '<p class="rr-customer-empty" role="status">' . esc_html__( 'Your redemption history is temporarily unavailable. Please try again shortly.', 'rewards-referrals' ) . '</p></section>'; }
		if ( ! $rows ) { return $out . '<p class="rr-customer-empty">' . esc_html__( 'Your reward orders will appear here when you redeem points.', 'rewards-referrals' ) . '</p></section>'; }
		$out .= '<div class="rr-customer-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Your redemption orders', 'rewards-referrals' ) . '"><table><thead><tr><th>' . esc_html__( 'Order', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Points', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'State', 'rewards-referrals' ) . '</th><th>' . esc_html__( 'Date', 'rewards-referrals' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { $order = wc_get_order( absint( $row->order_id ) ); $link = $order && (int) $order->get_customer_id() === $customer_id ? '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . esc_html( $row->order_id ); $out .= '<tr><td>' . $link . '</td><td>' . esc_html( number_format_i18n( $row->points ) ) . '</td><td>' . esc_html( ucwords( str_replace( '_', ' ', $row->status ) ) ) . '</td><td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $row->created_at . ' UTC' ) ) ) . '</td></tr>'; }
		return $out . '</tbody></table></div>' . self::pagination( $page, $total, 10, 'rr_redemption_page', 'rr-redemption-history' ) . '</section>';
	}
	private static function query_value( $name ) { return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : ''; }
	private static function pagination( $page, $total, $per_page, $parameter, $anchor ) {
		$pages = (int) ceil( $total / $per_page );
		if ( $pages < 2 ) { return ''; }
		$out = '<nav class="rr-customer-pagination" aria-label="' . esc_attr__( 'Rewards pages', 'rewards-referrals' ) . '">';
		if ( $page > 1 ) { $out .= '<a href="' . esc_url( add_query_arg( $parameter, $page - 1 ) . '#' . $anchor ) . '">' . esc_html__( 'Previous', 'rewards-referrals' ) . '</a>'; }
		/* translators: 1: Current page number. 2: Total page count. */
		$out .= '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'rewards-referrals' ), $page, $pages ) ) . '</span>';
		if ( $page < $pages ) { $out .= '<a href="' . esc_url( add_query_arg( $parameter, $page + 1 ) . '#' . $anchor ) . '">' . esc_html__( 'Next', 'rewards-referrals' ) . '</a>'; }
		return $out . '</nav>';
	}
	public static function wallet_shortcode() { return self::render_wallet(); }
	public static function link_shortcode() { return self::render_link(); }
	public static function stats_shortcode() { return self::render_stats(); }
	public static function history_shortcode() { return self::render_history(); }
	public static function referral_shortcode() { return self::render_link() . self::render_stats(); }
	public static function summary_shortcode() { return self::render_wallet() . self::render_stats(); }
}
