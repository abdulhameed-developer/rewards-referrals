<?php
/**
 * Merchant setup checks and non-persisted purchase examples.
 *
 * @package RewardsReferrals
 */

defined( 'ABSPATH' ) || exit;

final class RR_Program_Check {

	public static function init() {
		add_action( 'wp_ajax_rr_preview_product_search', array( __CLASS__, 'search_request' ) );
		add_action( 'wp_ajax_rr_preview_purchase', array( __CLASS__, 'preview_request' ) );
	}

	private static function link( $tab ) {
		return add_query_arg( array( 'page' => RR_Admin::page_for_tab( $tab ), 'tab' => $tab ), admin_url( 'admin.php' ) );
	}

	/** Small indexed probes only. No repair, queue dispatch, catalogue scan or wallet writes. */
	public static function checks() {
		$result = RR_Rules::with_snapshot( static function () { return self::checks_verified(); } );
		return is_wp_error( $result ) ? array( 'policy' => array( 'status' => 'unknown', 'title' => __( 'Saved program policy', 'rewards-referrals' ), 'message' => $result->get_error_message(), 'tab' => 'tools' ) ) : $result;
	}

	private static function checks_verified() {
		global $wpdb;
		$settings = RR_Rules::get();
		$rows = array();
		$rows['earning'] = array(
			'status' => 'yes' === $settings['purchase_enabled'] ? 'info' : 'attention',
			'title' => __( 'Purchase earning', 'rewards-referrals' ),
			'message' => 'yes' === $settings['purchase_enabled'] ? __( 'Purchase earning is enabled. Use the example below to check product overrides, rounding and customer limits.', 'rewards-referrals' ) : __( 'Purchase earning is paused. Enable it in Program settings if purchases should earn points.', 'rewards-referrals' ),
			'tab' => 'settings',
		);
		$rows['referrals'] = array(
			'status' => 'info',
			'title' => __( 'Direct referrals', 'rewards-referrals' ),
			'message' => 'yes' === $settings['commission_enabled'] ? __( 'Only the immediate approved referrer can earn commission. Duration, eligibility and caps still apply; the purchase example does not promise a referral award.', 'rewards-referrals' ) : __( 'Referral commission is paused. Purchase points can still operate independently.', 'rewards-referrals' ),
			'tab' => 'settings',
		);
		$rows['redemption'] = array(
			'status' => 'yes' === $settings['redemption_enabled'] ? 'pass' : 'attention',
			'title' => __( 'Point redemption', 'rewards-referrals' ),
			'message' => 'yes' === $settings['redemption_enabled'] ? __( 'Point redemption is enabled. Individual product eligibility and the customer’s available balance are checked again at checkout.', 'rewards-referrals' ) : __( 'The customer rewards catalogue is closed because Point redemption is disabled. Select and price your rewards before enabling it.', 'rewards-referrals' ),
			'tab' => 'settings',
		);
		$rows['inheritance'] = array(
			'status' => 'disabled' === $settings['reward_default_mode'] ? 'pass' : 'info',
			'title' => __( 'Which products become rewards?', 'rewards-referrals' ),
			'message' => 'disabled' === $settings['reward_default_mode'] ? __( 'The global default is Cash only. Only products or variations explicitly given a reward mode and positive points price can appear.', 'rewards-referrals' ) : __( 'Products marked Inherit use the global redemption mode. A positive points price is still required. Choose Cash only as the global default for a selected-products-only catalogue.', 'rewards-referrals' ),
			'tab' => 'catalog',
		);
		// Read the existing generation without RR_Catalog::state(), which can initialize it.
		$state = get_option( 'rr_catalog_state', array() );
		$candidate = null;
		$pending = null;
		$read_failed = false;
		$valid_state = is_array( $state ) && ! empty( $state['generation'] ) && is_string( $state['generation'] );
		if ( $valid_state ) {
			$candidate = $wpdb->get_var( $wpdb->prepare( 'SELECT product_id FROM %i WHERE generation=%s LIMIT 1', RR_Database::table( 'catalog' ), $state['generation'] ) );
			$read_failed = (bool) $wpdb->last_error;
			$pending = $wpdb->get_var( $wpdb->prepare( 'SELECT product_id FROM %i LIMIT 1', RR_Database::table( 'catalog_queue' ) ) );
			$read_failed = $read_failed || (bool) $wpdb->last_error;
		}
		if ( $read_failed || ! $valid_state ) {
			$status = 'unknown';
			$message = __( 'Catalogue readiness could not be read. Check database health and retry; this is not evidence of an empty catalogue.', 'rewards-referrals' );
		} elseif ( empty( $state['complete'] ) || null !== $pending ) {
			$status = 'attention';
			$message = __( 'The reward index is refreshing. Product changes may not all be visible yet. Check background processing in Tools & diagnostics.', 'rewards-referrals' );
		} elseif ( null === $candidate ) {
			$status = 'attention';
			$message = __( 'No eligible reward is indexed. Select a published, visible, in-stock product or variation, choose its reward mode and set a positive points price. Points plus cash also needs a positive cash contribution.', 'rewards-referrals' );
		} else {
			$status = 'pass';
			$message = __( 'At least one reward is indexed and no product refresh is queued. Checkout revalidates current stock, pricing, eligibility and customer balance.', 'rewards-referrals' );
		}
		$rows['catalogue'] = array( 'status' => $status, 'title' => __( 'Customer reward catalogue', 'rewards-referrals' ), 'message' => $message, 'tab' => 'catalog' );
		$next = wp_next_scheduled( 'rr_accounting_maintenance' );
		$due = ! $next || $next < time() - HOUR_IN_SECONDS;
		$rows['scheduler'] = array(
			'status' => $due ? 'attention' : 'info',
			'title' => __( 'Background processing', 'rewards-referrals' ),
			'message' => $due ? __( 'Maintenance is missing or more than one hour overdue. Check the site scheduler before relying on delayed points, expiry and recovery jobs.', 'rewards-referrals' ) : __( 'A maintenance event is scheduled. Scheduling alone does not prove it runs; verify the site cron service when WordPress cron is disabled.', 'rewards-referrals' ),
			'tab' => 'tools',
		);
		$rows['expiry'] = array(
			'status' => 'info',
			'title' => __( 'Availability, expiry and refunds', 'rewards-referrals' ),
			'message' => __( 'Review the pending period and expiry policy before launch. Refunds reverse the original beneficiary’s rewards; spending before a refund can create debt. Settings changes do not rewrite earlier awards.', 'rewards-referrals' ),
			'tab' => 'settings',
		);
		return $rows;
	}

	/** Return a bounded result using WooCommerce's product data store, never all products. */
	public static function products( $query ) {
		global $wpdb;
		if ( ! is_string( $query ) || mb_strlen( $query ) > 80 || ( mb_strlen( $query ) < 3 && ! ctype_digit( $query ) ) ) {
			return new WP_Error( 'rr_preview_search', __( 'Enter a product ID, or 3–80 characters of a product name or SKU.', 'rewards-referrals' ) );
		}
		if ( ctype_digit( $query ) ) {
			$id = RR_Ledger::validated_integer( $query, 1, PHP_INT_MAX );
			$ids = $id ? array( $id ) : array();
		} else {
			$store = WC_Data_Store::load( 'product' );
			// The WooCommerce facade delegates product-specific APIs to the selected store.
			$ids = $store->__call( 'search_products', array( $query, '', true, false, 11 ) );
		}
		if ( $wpdb->last_error ) {
			return new WP_Error( 'rr_preview_storage', __( 'Product search is temporarily unavailable. Please retry.', 'rewards-referrals' ) );
		}
		$rows = array();
		foreach ( array_slice( (array) $ids, 0, 11 ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) || 'publish' !== $product->get_status() ) {
				continue;
			}
			$rows[] = array( 'id' => $product->get_id(), 'label' => $product->get_name() . ' · #' . $product->get_id() );
			if ( 10 === count( $rows ) ) { break; }
		}
		return $rows;
	}

	/**
	 * Evaluate one hypothetical cash-purchase line through the production rule engine.
	 * No save(), checkout, award, stock reduction, reservation or notification occurs.
	 *
	 * @param array $input Product/customer and explicitly entered line amounts.
	 * @return array|WP_Error Explained result, or an error rather than a plausible zero.
	 */
	public static function preview( $input ) {
		return RR_Rules::with_snapshot( static function () use ( $input ) { return self::preview_verified( $input ); } );
	}

	private static function preview_verified( $input ) {
		if ( ! is_array( $input ) ) { return new WP_Error( 'rr_preview_input', __( 'Enter a valid purchase example.', 'rewards-referrals' ) ); }
		$product_id = RR_Ledger::validated_integer( $input['product_id'] ?? null, 1, PHP_INT_MAX );
		$quantity = RR_Ledger::validated_integer( $input['quantity'] ?? null, 1, 1000 );
		$customer_value = $input['customer_id'] ?? '0';
		$customer = RR_Ledger::validated_integer( '' === $customer_value ? '0' : $customer_value, 0, PHP_INT_MAX );
		$product = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) || 'publish' !== $product->get_status() || ! $quantity || null === $customer ) {
			return new WP_Error( 'rr_preview_product', __( 'Choose a published simple product or individual variation and a quantity from 1 to 1,000.', 'rewards-referrals' ) );
		}
		if ( $customer && ( ! get_userdata( $customer ) || ( is_multisite() && ! is_user_member_of_blog( $customer ) ) ) ) {
			return new WP_Error( 'rr_preview_customer', __( 'Choose a customer who belongs to this site.', 'rewards-referrals' ) );
		}
		$amounts = array();
		$decimals = wc_get_price_decimals();
		if ( $decimals < 0 || $decimals > 8 ) { return new WP_Error( 'rr_preview_amount', __( 'This preview supports currency precision from zero to eight decimal places.', 'rewards-referrals' ) ); }
		foreach ( array( 'subtotal', 'total', 'tax', 'shipping' ) as $key ) {
			$value = $input[ $key ] ?? '0';
			if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,9}(?:\.\d{1,8})?$/D', (string) $value ) || strlen( explode( '.', (string) $value )[0] ) > 15 - $decimals || ( str_contains( (string) $value, '.' ) && strlen( explode( '.', (string) $value )[1] ) > $decimals ) ) {
				return new WP_Error( 'rr_preview_amount', __( 'Use non-negative amounts with the store’s currency precision and a dot as the decimal separator.', 'rewards-referrals' ) );
			}
			$amounts[ $key ] = RR_Rules::to_scaled_integer( $value, $decimals );
		}
		if ( $amounts['total'] > $amounts['subtotal'] ) {
			return new WP_Error( 'rr_preview_discount', __( 'The amount after discount cannot exceed the amount before discount.', 'rewards-referrals' ) );
		}
		$payment = $input['payment_method'] ?? '';
		if ( ! is_string( $payment ) || strlen( $payment ) > 80 || ( '' !== $payment && ! preg_match( '/^[a-z0-9_-]+$/D', $payment ) ) ) {
			return new WP_Error( 'rr_preview_payment', __( 'Choose a valid payment method.', 'rewards-referrals' ) );
		}
		$order = new WC_Order();
		$order->set_currency( get_woocommerce_currency() );
		$order->set_customer_id( $customer );
		$order->set_payment_method( $payment );
		$order->set_shipping_total( $input['shipping'] ?? '0' );
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $quantity );
		$item->set_subtotal( $input['subtotal'] ?? '0' );
		$item->set_total( $input['total'] ?? '0' );
		$item->set_taxes( array( 'total' => array( $input['tax'] ?? '0' ), 'subtotal' => array( $input['tax'] ?? '0' ) ) );
		$order->add_item( $item );
		// A single unsaved line has ID 0. Never assign a real order/item ID or persist it.
		$result = RR_Rules::purchase_breakdown( $order );
		if ( is_wp_error( $result ) ) { return $result; }
		$settings = RR_Rules::get();
		$result['product_label'] = $product->get_name();
		$result['currency'] = get_woocommerce_currency();
		$result['rounding'] = $settings['rounding'];
		$result['customer_context'] = $customer ? 'existing' : 'none';
		$result['quantity'] = $quantity;
		$result['explanation'] = self::reason( $result['excluded_reason'], empty( $result['eligible_items_minor'] ) );
		return $result;
	}

	public static function reason( $code, $no_items = false ) {
		$reasons = array(
			'purchase_disabled' => __( 'Purchase earning is disabled.', 'rewards-referrals' ),
			'outside_earning_dates' => __( 'Today is outside the saved earning date window (UTC).', 'rewards-referrals' ),
			'reward_order_excluded' => __( 'The saved policy excludes earning on reward orders.', 'rewards-referrals' ),
			'customer_role_excluded' => __( 'The customer’s role is excluded by the saved eligibility rules.', 'rewards-referrals' ),
			'payment_method_excluded' => __( 'This payment method is excluded or has not been selected.', 'rewards-referrals' ),
			'currency_excluded' => __( 'The store currency is excluded by the saved earning rules.', 'rewards-referrals' ),
			'coupon_excluded' => __( 'The order’s coupons are excluded by the saved policy.', 'rewards-referrals' ),
			'below_purchase_threshold' => __( 'The eligible amount is below the minimum purchase threshold.', 'rewards-referrals' ),
		);
		if ( isset( $reasons[ $code ] ) ) { return $reasons[ $code ]; }
		if ( $code ) { return __( 'The saved rules exclude this purchase.', 'rewards-referrals' ); }
		return $no_items ? __( 'No eligible positive purchase amount remains. Check the product or category earning exclusions and entered amounts.', 'rewards-referrals' ) : __( 'The saved earning rules were applied, including rounding, product overrides and applicable limits. Zero points can result from rounding or an exhausted limit.', 'rewards-referrals' );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_REQUEST['nonce'] ) || ! is_string( $_REQUEST['nonce'] ) || ! check_ajax_referer( 'rr_admin_route', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied or session expired.', 'rewards-referrals' ) ), 403 );
		}
		if ( ! RR_Customer_Search::allowed( get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Wait a minute and retry.', 'rewards-referrals' ) ), 429 );
		}
	}

	public static function search_request() {
		self::authorize();
		$query = isset( $_POST['q'] ) && is_string( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
		$result = self::products( $query );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 'rr_preview_storage' === $result->get_error_code() ? 503 : 400 ); }
		wp_send_json_success( array( 'products' => $result ) );
	}

	public static function preview_request() {
		self::authorize();
		$input = array();
		foreach ( array( 'product_id', 'quantity', 'customer_id', 'subtotal', 'total', 'tax', 'shipping', 'payment_method' ) as $key ) {
			// Preserve malformed syntax for the strict field validators; stripping markup
			// first could silently turn an invalid payment context into an empty one.
			$input[ $key ] = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		}
		try { $result = self::preview( $input ); }
		catch ( Exception $exception ) { $result = new WP_Error( 'rr_preview_storage', __( 'The example could not be evaluated. Check the product and database health, then retry.', 'rewards-referrals' ) ); }
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), str_starts_with( $result->get_error_code(), 'rr_preview_' ) && 'rr_preview_storage' !== $result->get_error_code() ? 400 : 503 ); }
		/* translators: 1: purchase points, 2: eligible amount, 3: currency, 4: explanation. */
		$result['message'] = sprintf( __( '%1$s purchase points. Eligible amount: %2$s %3$s. %4$s', 'rewards-referrals' ), number_format_i18n( $result['points'] ), number_format_i18n( $result['eligible_basis_minor'] / ( 10 ** wc_get_price_decimals() ), wc_get_price_decimals() ), $result['currency'], $result['explanation'] );
		$rounding = array( 'floor' => __( 'Round down', 'rewards-referrals' ), 'nearest' => __( 'Round to nearest', 'rewards-referrals' ), 'ceil' => __( 'Round up', 'rewards-referrals' ) );
		/* translators: 1: saved rule version, 2: rounding method. */
		$result['message'] .= ' ' . sprintf( __( 'Rules version %1$s. Rounding: %2$s.', 'rewards-referrals' ), $result['rule_version'], $rounding[ $result['rounding'] ] );
		/* translators: 1: product name, 2: quantity. */
		$result['message'] .= ' ' . sprintf( __( 'Product: %1$s. Quantity: %2$s.', 'rewards-referrals' ), $result['product_label'], number_format_i18n( $result['quantity'] ) );
		$result['message'] .= ' ' . ( 'existing' === $result['customer_context'] ? __( 'Current customer roles and earning limits were considered.', 'rewards-referrals' ) : __( 'No customer selected: customer roles and history limits were not checked.', 'rewards-referrals' ) );
		if ( ! empty( $result['campaign_id'] ) ) { $result['message'] .= ' ' . __( 'A currently eligible campaign multiplier was applied.', 'rewards-referrals' ); }
		wp_send_json_success( $result );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		echo '<div class="rr-page-heading"><div><span class="rr-section-kicker">' . esc_html__( 'SETUP & CONFIDENCE', 'rewards-referrals' ) . '</span><h2>' . esc_html__( 'Program check', 'rewards-referrals' ) . '</h2><p>' . esc_html__( 'See what needs attention, then try a purchase example before using your program. These checks do not change settings or customer rewards.', 'rewards-referrals' ) . '</p></div></div><section class="rr-card rr-setting-card"><h3>' . esc_html__( 'Review your program', 'rewards-referrals' ) . '</h3><ul class="rr-readiness-list">';
		$labels = array( 'pass' => __( 'Checked', 'rewards-referrals' ), 'attention' => __( 'Review', 'rewards-referrals' ), 'unknown' => __( 'Unavailable', 'rewards-referrals' ), 'info' => __( 'Understand', 'rewards-referrals' ) );
		$actions = array( 'settings' => __( 'Review settings', 'rewards-referrals' ), 'catalog' => __( 'Review catalogue', 'rewards-referrals' ), 'tools' => __( 'Open diagnostics', 'rewards-referrals' ) );
		foreach ( self::checks() as $row ) {
			echo '<li><span class="rr-check-state rr-check-' . esc_attr( $row['status'] ) . '">' . esc_html( $labels[ $row['status'] ] ) . '</span><div><h4>' . esc_html( $row['title'] ) . '</h4><p>' . esc_html( $row['message'] ) . '</p></div><a data-rr-nav class="rr-button rr-button-light" href="' . esc_url( self::link( $row['tab'] ) ) . '">' . esc_html( $actions[ $row['tab'] ] ) . '</a></li>';
		}
		echo '</ul><p class="rr-help">' . esc_html__( 'This is a configuration check, not a payment, delivery or financial-audit certification. Complete a controlled checkout and refund on a separate test site before launch.', 'rewards-referrals' ) . '</p></section>';
		self::preview_form();
	}

	private static function preview_form() {
		echo '<section class="rr-card rr-setting-card rr-spaced"><h3>' . esc_html__( 'Try a purchase without creating an order', 'rewards-referrals' ) . '</h3><p>' . esc_html__( 'Uses saved rules and today’s date for one cash-purchase product line. Amounts are totals for the whole line, not per item. Enter the same tax amount for before and after discount; use an existing order preview for other tax or coupon cases.', 'rewards-referrals' ) . '</p><form class="rr-purchase-example"><div class="rr-example-search"><label>' . esc_html__( 'Find a product or variation', 'rewards-referrals' ) . '<input class="rr-control" type="search" name="product_query" maxlength="80" placeholder="' . esc_attr__( 'Name, SKU or product ID', 'rewards-referrals' ) . '"></label><button type="button" class="rr-button rr-button-light" data-rr-product-search>' . esc_html__( 'Search products', 'rewards-referrals' ) . '</button></div><p data-rr-product-status role="status"></p><div class="rr-example-products" data-rr-product-results></div><input type="hidden" name="product_id" value=""><p data-rr-product-selected></p><div class="rr-example-fields">';
		$fields = array( 'quantity' => array( __( 'Quantity', 'rewards-referrals' ), '1', '1', '1000' ), 'subtotal' => array( __( 'Line amount before discount', 'rewards-referrals' ), '8.99', '0', '999999999' ), 'total' => array( __( 'Line amount after discount', 'rewards-referrals' ), '8.99', '0', '999999999' ), 'tax' => array( __( 'Line tax amount', 'rewards-referrals' ), '0', '0', '999999999' ), 'shipping' => array( __( 'Shipping before tax', 'rewards-referrals' ), '0', '0', '999999999' ) );
		foreach ( $fields as $key => $field ) {
			$default = in_array( $key, array( 'subtotal', 'total' ), true ) ? wc_format_decimal( '8.99', wc_get_price_decimals() ) : $field[1];
			echo '<label>' . esc_html( $field[0] ) . '<input class="rr-control" type="number" required name="' . esc_attr( $key ) . '" value="' . esc_attr( $default ) . '" min="' . esc_attr( $field[2] ) . '" max="' . esc_attr( $field[3] ) . '" step="' . esc_attr( 'quantity' === $key ? '1' : (string) ( 1 / ( 10 ** wc_get_price_decimals() ) ) ) . '"></label>';
		}
		echo '<label>' . esc_html__( 'Payment method context', 'rewards-referrals' ) . '<select class="rr-control" name="payment_method"><option value="">' . esc_html__( 'Not specified', 'rewards-referrals' ) . '</option>';
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) { echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $gateway->get_method_title() ) . '</option>'; }
		echo '</select></label></div><div class="rr-adjust-form rr-example-customer"><label>' . esc_html__( 'Existing customer ID (optional)', 'rewards-referrals' ) . '<input class="rr-control" type="number" min="0" name="customer_id" value="0"></label></div><p class="rr-help">' . esc_html__( 'Choose an existing customer to include their current earning limits and role campaigns. Without a customer, those checks are omitted. This example does not calculate referral commission, validate coupons or availability, reserve points, send messages or test a payment gateway.', 'rewards-referrals' ) . '</p><button type="submit" class="rr-button rr-button-primary">' . esc_html__( 'Preview purchase points', 'rewards-referrals' ) . '</button><output class="rr-example-output" aria-live="polite" aria-atomic="true"></output></form></section>';
	}
}
