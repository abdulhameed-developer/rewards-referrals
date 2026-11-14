<?php
/** Validated prospective earning policies. Amounts and reward calculations use whole integers. */
defined( 'ABSPATH' ) || exit;

final class RR_Rules {
	public const MAX_POINTS = 1000000000;

	public static function fields() {
		return array(
			'existing_customer_policy' => array( 'group' => 'Referral attribution', 'type' => 'select', 'label' => 'Existing accounts with no referrer', 'options' => array( 'new_only' => 'Assign a referrer only during registration', 'unassigned' => 'Allow an unassigned existing account to join on sign-in' ), 'default' => 'new_only' ),
			'fixed_order_points' => array( 'group' => 'Purchase earning', 'type' => 'number', 'label' => 'Fixed points per eligible order', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'amount_basis' => array( 'group' => 'Purchase earning', 'type' => 'select', 'label' => 'Eligible product amount', 'options' => array( 'post_discount' => 'After discounts', 'pre_discount' => 'Before discounts' ), 'default' => 'post_discount' ),
			'include_tax' => array( 'group' => 'Purchase earning', 'type' => 'select', 'label' => 'Include eligible product tax', 'options' => array( 'no' => 'No', 'yes' => 'Yes' ), 'default' => 'no' ),
			'include_shipping' => array( 'group' => 'Purchase earning', 'type' => 'select', 'label' => 'Include shipping at global rate', 'options' => array( 'no' => 'No', 'yes' => 'Yes' ), 'default' => 'no' ),
			'rounding' => array( 'group' => 'Purchase rewards', 'type' => 'select', 'label' => 'Earned points rounding', 'description' => 'At 1 point per currency unit, 8.99 earns 8 with Round down and 9 with Round to nearest or Round up. Nearest rounds .50 upward. Applies to each eligible order line (including its quantity), multiplier results, and referral commissions. Saved changes affect future awards only.', 'options' => array( 'floor' => 'Round down (floor)', 'nearest' => 'Round to nearest (half up)', 'ceil' => 'Round up (ceiling)' ), 'default' => 'floor' ),
			'multiplier' => array( 'group' => 'Purchase earning', 'type' => 'number', 'label' => 'Base purchase multiplier', 'min' => '0.01', 'max' => '100', 'step' => '0.01', 'default' => '1' ),
			'category_rates' => array( 'group' => 'Product policies', 'type' => 'text', 'label' => 'Category earning rates', 'description' => 'Category ID:points per currency unit, separated by commas. Example 12:2,24:1.5. Product overrides win; the lowest matching category ID wins.', 'default' => '' ),
			'excluded_products' => array( 'group' => 'Product policies', 'type' => 'text', 'label' => 'Excluded product or variation IDs', 'description' => 'Comma-separated IDs. Parent exclusions apply to variations.', 'default' => '' ),
			'excluded_categories' => array( 'group' => 'Product policies', 'type' => 'text', 'label' => 'Excluded category IDs', 'description' => 'Comma-separated category IDs.', 'default' => '' ),
			'minimum_purchase' => array( 'group' => 'Purchase limits', 'type' => 'number', 'label' => 'Minimum eligible purchase amount', 'min' => 0, 'max' => 1000000000, 'step' => '0.01', 'default' => '0' ),
			'minimum_order_points' => array( 'group' => 'Purchase limits', 'type' => 'number', 'label' => 'Minimum award on an eligible order', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'maximum_order_points' => array( 'group' => 'Purchase limits', 'type' => 'number', 'label' => 'Maximum purchase points per order (0 = unlimited)', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'customer_lifetime_cap' => array( 'group' => 'Customer limits', 'type' => 'number', 'label' => 'Lifetime purchase-point cap per customer (0 = unlimited)', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'customer_daily_cap' => array( 'group' => 'Customer limits', 'type' => 'number', 'label' => 'Daily purchase-point cap per customer (UTC, 0 = unlimited)', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'customer_weekly_cap' => array( 'group' => 'Customer limits', 'type' => 'number', 'label' => 'Weekly purchase-point cap per customer (UTC Monday, 0 = unlimited)', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'customer_monthly_cap' => array( 'group' => 'Customer limits', 'type' => 'number', 'label' => 'Monthly purchase-point cap per customer (UTC, 0 = unlimited)', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'eligible_roles' => array( 'group' => 'Eligibility', 'type' => 'text', 'label' => 'Eligible customer roles', 'description' => 'Comma-separated role slugs; empty allows all registered customers.', 'default' => '' ),
			'eligible_payments' => array( 'group' => 'Eligibility', 'type' => 'text', 'label' => 'Eligible payment method IDs', 'description' => 'Comma-separated WooCommerce gateway IDs; empty allows all.', 'default' => '' ),
			'eligible_currencies' => array( 'group' => 'Eligibility', 'type' => 'text', 'label' => 'Eligible currency codes', 'description' => 'Comma-separated codes, for example EUR,USD. Empty uses every order currency at the configured rate.', 'default' => '' ),
			'coupon_policy' => array( 'group' => 'Eligibility', 'type' => 'select', 'label' => 'Coupon eligibility', 'options' => array( 'allow' => 'Allow coupons', 'exclude_any' => 'No earning on coupon orders', 'exclude_list' => 'Exclude listed coupon codes' ), 'default' => 'allow' ),
			'excluded_coupons' => array( 'group' => 'Eligibility', 'type' => 'text', 'label' => 'Excluded coupon codes', 'description' => 'Comma-separated codes; used when listed-code exclusion is selected.', 'default' => '' ),
			'earning_start_date' => array( 'group' => 'Eligibility', 'type' => 'date', 'label' => 'Purchase earning starts (UTC, optional)', 'default' => '' ),
			'earning_end_date' => array( 'group' => 'Eligibility', 'type' => 'date', 'label' => 'Purchase earning ends (UTC, optional)', 'default' => '' ),
			'award_statuses' => array( 'group' => 'Availability', 'type' => 'select', 'label' => 'Award purchase points when order reaches', 'options' => array( 'completed' => 'Completed', 'processing' => 'Processing or completed' ), 'default' => 'completed' ),
			'availability_delay_days' => array( 'group' => 'Availability', 'type' => 'number', 'label' => 'Delay before earned points become available (days)', 'min' => 0, 'max' => 365, 'step' => 1, 'default' => '0' ),
			'expiration_days' => array( 'group' => 'Availability', 'type' => 'number', 'label' => 'Expiry after points become available (days, 0 = never)', 'min' => 0, 'max' => 36500, 'step' => 1, 'default' => '0' ),
			'commission_mode' => array( 'group' => 'Direct commission', 'type' => 'select', 'label' => 'Commission calculation', 'options' => array( 'buyer_points_percentage' => 'Percentage of buyer-earned points', 'fixed_order' => 'Fixed points per qualifying order', 'eligible_value_percentage' => 'Percentage of eligible order value converted at global earning rate' ), 'default' => 'buyer_points_percentage' ),
			'commission_fixed_points' => array( 'group' => 'Direct commission', 'type' => 'number', 'label' => 'Fixed commission per qualifying order', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'commission_minimum_purchase' => array( 'group' => 'Direct commission', 'type' => 'number', 'label' => 'Minimum eligible amount for commission', 'min' => 0, 'max' => 1000000000, 'step' => '0.01', 'default' => '0' ),
			'commission_maximum_order' => array( 'group' => 'Direct commission', 'type' => 'number', 'label' => 'Maximum commission points per order (0 = unlimited)', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'commission_relationship_cap' => array( 'group' => 'Direct commission', 'type' => 'number', 'label' => 'Total commission cap per referral relationship (0 = unlimited)', 'min' => 0, 'max' => self::MAX_POINTS, 'step' => 1, 'default' => '0' ),
			'commission_volume_cap' => array( 'group' => 'Direct commission', 'type' => 'number', 'label' => 'Eligible purchase volume cap per referral (0 = unlimited)', 'min' => 0, 'max' => 1000000000, 'step' => '0.01', 'default' => '0' ),
			'commission_start_date' => array( 'group' => 'Direct commission', 'type' => 'date', 'label' => 'Commission period starts (UTC, optional)', 'default' => '' ),
			'commission_end_date' => array( 'group' => 'Direct commission', 'type' => 'date', 'label' => 'Commission period ends (UTC, optional)', 'default' => '' ),
			'commission_category_rates' => array( 'group' => 'Direct commission', 'type' => 'text', 'label' => 'Category commission percentage overrides', 'description' => 'Category ID:percentage, comma-separated. Product overrides win; lowest matching category ID wins.', 'default' => '' ),
			'commission_role_rates' => array( 'group' => 'Direct commission', 'type' => 'text', 'label' => 'Referrer role commission percentages', 'description' => 'Role:percentage, comma-separated. First alphabetically matching role wins before product and category overrides.', 'default' => '' ),
			'commission_migration' => array( 'group' => 'Direct commission', 'type' => 'select', 'label' => 'When a commission policy changes', 'options' => array( 'count_history' => 'Count earlier qualifying activity toward new limits', 'new_version' => 'Start limits again for the new rule version' ), 'default' => 'count_history' ),
			'earn_cash_items_in_reward_orders' => array( 'group' => 'Redemption', 'type' => 'select', 'label' => 'Earn on paid product lines in mixed reward orders', 'options' => array( 'yes' => 'Yes, paid eligible product lines only', 'no' => 'No earning on any reward order' ), 'default' => 'no' ),
			'reward_default_mode' => array( 'group' => 'Redemption', 'type' => 'select', 'label' => 'Default product redemption mode', 'options' => array( 'disabled' => 'Cash only', 'points_only' => 'Points only (requires points price)', 'cash_or_points' => 'Cash or points (requires points price)', 'points_plus_cash' => 'Points plus a configured cash contribution' ), 'default' => 'disabled' ),
		);
	}

	public static function defaults() {
		$defaults = array( 'purchase_enabled' => 'yes', 'points_per_unit' => '1', 'commission_enabled' => 'yes', 'commission_rate' => '25', 'commission_duration' => 'lifetime', 'first_n' => '1', 'duration_days' => '90', 'cookie_days' => '30', 'attribution' => 'last', 'approval' => 'automatic', 'redemption_enabled' => 'no', 'earn_on_rewards' => 'no', 'expiration_days' => '0' );
		foreach ( self::fields() as $key => $field ) { $defaults[ $key ] = $field['default']; }
		return $defaults;
	}

	/** Reject malformed values by retaining the previous valid value. Returns a complete settings array. */
	public static function sanitize( $input, $fallback = null ) {
		$base = wp_parse_args( is_array( $fallback ) ? $fallback : array(), self::defaults() );
		$input = is_array( $input ) ? $input : array();
		foreach ( array( 'purchase_enabled', 'commission_enabled', 'redemption_enabled', 'earn_on_rewards' ) as $key ) { if ( isset( $input[ $key ] ) && in_array( $input[ $key ], array( 'yes', 'no' ), true ) ) { $base[ $key ] = $input[ $key ]; } }
		foreach ( array( 'points_per_unit' => array( 0, 1000000 ), 'commission_rate' => array( 0, 100 ), 'first_n' => array( 1, 1000000 ), 'duration_days' => array( 1, 36500 ), 'cookie_days' => array( 1, 365 ) ) as $key => $bounds ) { $pattern = in_array( $key, array( 'first_n', 'duration_days', 'cookie_days' ), true ) ? '/^\d+$/D' : '/^\d+(?:\.\d{1,6})?$/D'; if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) && preg_match( $pattern, (string) $input[ $key ] ) && strlen( (string) $input[ $key ] ) < 24 && (float) $input[ $key ] >= $bounds[0] && (float) $input[ $key ] <= $bounds[1] ) { $base[ $key ] = (string) $input[ $key ]; } }
		foreach ( array( 'commission_duration' => array( 'lifetime', 'first_order', 'first_n', 'first_item', 'first_n_items', 'days_after_registration', 'days_after_first_purchase', 'fixed_dates' ), 'attribution' => array( 'first', 'last' ), 'approval' => array( 'automatic', 'manual' ) ) as $key => $allowed ) { if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) { $base[ $key ] = $input[ $key ]; } }
		foreach ( self::fields() as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) { continue; }
			$value = trim( (string) $input[ $key ] );
			if ( 'select' === $field['type'] ) { if ( isset( $field['options'][ $value ] ) ) { $base[ $key ] = $value; } }
			elseif ( 'number' === $field['type'] ) {
				if ( preg_match( '/^\d+(?:\.\d{1,6})?$/D', $value ) && strlen( $value ) < 24 && (float) $value >= (float) $field['min'] && (float) $value <= (float) $field['max'] && ( '1' !== (string) $field['step'] || ! str_contains( $value, '.' ) ) ) { $base[ $key ] = $value; }
			} elseif ( 'date' === $field['type'] ) { if ( '' === $value || self::valid_date( $value ) ) { $base[ $key ] = $value; } }
			elseif ( in_array( $key, array( 'category_rates', 'commission_category_rates', 'commission_role_rates' ), true ) ) { $base[ $key ] = self::sanitize_rate_map( $value, 'commission_role_rates' === $key, 'category_rates' === $key ? 1000000 : 100 ); }
			else { $base[ $key ] = substr( preg_replace( '/[^a-zA-Z0-9_@.,\-\s]/', '', $value ), 0, 2000 ); }
		}
		if ( 'subtotal' === $base['amount_basis'] ) { $base['amount_basis'] = 'post_discount'; }
		foreach ( array( 'earning', 'commission' ) as $prefix ) {
			if ( $base[ $prefix . '_start_date' ] && $base[ $prefix . '_end_date' ] && $base[ $prefix . '_end_date' ] < $base[ $prefix . '_start_date' ] ) {
				$base[ $prefix . '_start_date' ] = $fallback[ $prefix . '_start_date' ] ?? '';
				$base[ $prefix . '_end_date' ] = $fallback[ $prefix . '_end_date' ] ?? '';
			}
		}
		return $base;
	}

	/** The context lives only for a checked evaluation and is always restored in finally. */
	private static $policy_snapshot = null;

	private static function policy_error() {
		return new WP_Error( 'rr_policy_unavailable', __( 'Saved rewards policy could not be verified. No estimate or new reward was produced. Check storage and retry.', 'rewards-referrals' ) );
	}

	/** Validate stored semantics rather than silently replacing corrupt policy with defaults. */
	private static function valid_settings( $value ) {
		if ( ! is_array( $value ) || count( $value ) > 1000 ) { return false; }
		foreach ( $value as $key => $item ) { if ( ! is_string( $key ) || ! is_scalar( $item ) ) { return false; } }
		// These original settings have always been seeded; later optional fields may be absent.
		foreach ( array( 'purchase_enabled', 'points_per_unit', 'commission_enabled', 'commission_rate', 'commission_duration', 'first_n', 'duration_days', 'cookie_days', 'attribution', 'approval', 'redemption_enabled', 'earn_on_rewards' ) as $key ) {
			if ( ! array_key_exists( $key, $value ) ) { return false; }
		}
		$clean = self::sanitize( $value, self::defaults() );
		foreach ( array_intersect_key( $value, self::defaults() ) as $key => $item ) {
			if ( trim( (string) $item ) !== (string) $clean[ $key ] ) { return false; }
		}
		return true;
	}

	/** Match core option filter ordering, only after persisted policy has been verified. */
	private static function filtered_policy_option( $name, $value, $default ) {
		$pre = apply_filters( 'pre_option_' . $name, false, $name, $default );
		$pre = apply_filters( 'pre_option', $pre, $name, $default );
		return false !== $pre ? $pre : apply_filters( 'option_' . $name, $value, $name );
	}

	/**
	 * Read settings and version together, without poisoned option/absence caches.
	 * Activation seeds both rows. Their absence is never a financial default.
	 * Existing option filters remain supported after the stored source validates.
	 *
	 * @return array|WP_Error Verified policy and version, or an unavailable policy.
	 */
	public static function checked_snapshot() {
		global $wpdb;
		if ( RR_Ledger::transaction_open() ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,option_value FROM %i WHERE option_name IN (%s,%s) ORDER BY option_name LOCK IN SHARE MODE', $wpdb->options, 'br_settings', 'br_rules_version' ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,option_value FROM %i WHERE option_name IN (%s,%s) ORDER BY option_name', $wpdb->options, 'br_settings', 'br_rules_version' ), ARRAY_A );
		}
		if ( $wpdb->last_error || ! is_array( $rows ) || 2 !== count( $rows ) ) { return self::policy_error(); }
		$stored = array_column( $rows, 'option_value', 'option_name' );
		if ( ! isset( $stored['br_settings'], $stored['br_rules_version'] ) || ! is_string( $stored['br_settings'] ) || strlen( $stored['br_settings'] ) > 262144 || ! is_serialized( $stored['br_settings'] ) ) { return self::policy_error(); }
		// No stored object may be instantiated, including objects nested in an array.
		$settings = @unserialize( $stored['br_settings'], array( 'allowed_classes' => false, 'max_depth' => 32 ) );
		$version = RR_Ledger::validated_integer( $stored['br_rules_version'], 1, PHP_INT_MAX );
		if ( ! self::valid_settings( $settings ) || null === $version ) { return self::policy_error(); }
		$settings = self::filtered_policy_option( 'br_settings', $settings, array() );
		$version = self::filtered_policy_option( 'br_rules_version', $stored['br_rules_version'], 1 );
		$version = RR_Ledger::validated_integer( $version, 1, PHP_INT_MAX );
		if ( ! self::valid_settings( $settings ) || null === $version ) { return self::policy_error(); }
		$settings = self::sanitize( $settings, self::defaults() );
		return array( 'settings' => $settings, 'version' => $version );
	}

	/** Execute with one coherent policy; nested calculations reuse it. */
	public static function with_snapshot( callable $callback ) {
		if ( null !== self::$policy_snapshot ) { $result = $callback(); return self::$policy_snapshot['product_error'] ?? $result; }
		$snapshot = self::checked_snapshot();
		if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		self::$policy_snapshot = $snapshot;
		try { $result = $callback(); return self::$policy_snapshot['product_error'] ?? $result; }
		finally { self::$policy_snapshot = null; }
	}

	/** Reuse a verified dependent policy, including an absent option or error, only in this evaluation. */
	public static function policy_component( $name, callable $reader ) {
		if ( null === self::$policy_snapshot ) { return $reader(); }
		if ( ! isset( self::$policy_snapshot['components'] ) ) { self::$policy_snapshot['components'] = array(); }
		if ( ! array_key_exists( $name, self::$policy_snapshot['components'] ) ) { self::$policy_snapshot['components'][ $name ] = $reader(); }
		return self::$policy_snapshot['components'][ $name ];
	}

	/** Refresh after the award acquires its wallet lock, before final policy decisions. */
	public static function refresh_snapshot() {
		if ( null === self::$policy_snapshot ) { return self::policy_error(); }
		$snapshot = self::checked_snapshot();
		if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		self::$policy_snapshot = $snapshot;
		return true;
	}

	/** Authoritative version for writes; preserve the existing scalar display getter. */
	public static function checked_version() {
		if ( null !== self::$policy_snapshot ) { return self::$policy_snapshot['version']; }
		$snapshot = self::checked_snapshot();
		return is_wp_error( $snapshot ) ? $snapshot : $snapshot['version'];
	}

	public static function version() { return null !== self::$policy_snapshot ? self::$policy_snapshot['version'] : absint( get_option( 'br_rules_version', 1 ) ); }
	public static function get() { return null !== self::$policy_snapshot ? self::$policy_snapshot['settings'] : self::sanitize( wp_parse_args( get_option( 'br_settings', array() ), self::defaults() ), self::defaults() ); }
	private static function valid_date( $value ) { return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) && false !== ( $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) ) ) && $date->format( 'Y-m-d' ) === $value; }
	private static function list_values( $value ) { return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', strtolower( (string) $value ) ) ), 'strlen' ) ) ); }
	private static function rate_map( $value ) { $out = array(); foreach ( explode( ',', substr( (string) $value, 0, 4000 ) ) as $part ) { $pair = array_map( 'trim', explode( ':', $part, 2 ) ); if ( 2 === count( $pair ) && '' !== $pair[0] && preg_match( '/^\d+(?:\.\d{1,6})?$/D', $pair[1] ) ) { $out[ strtolower( $pair[0] ) ] = $pair[1]; } } ksort( $out, SORT_NATURAL ); return $out; }
	private static function sanitize_rate_map( $value, $roles, $max ) { $out = array(); foreach ( self::rate_map( $value ) as $key => $rate ) { if ( ( $roles ? preg_match( '/^[a-z0-9_-]+$/D', $key ) : ctype_digit( (string) $key ) && (int) $key > 0 ) && (float) $rate <= $max ) { $out[] = $key . ':' . $rate; } if ( count( $out ) >= 100 ) { break; } } return implode( ',', $out ); }
	private static function active_dates( $settings, $prefix, $timestamp ) { $date = gmdate( 'Y-m-d', $timestamp ); return ( ! $settings[ $prefix . '_start_date' ] || $date >= $settings[ $prefix . '_start_date' ] ) && ( ! $settings[ $prefix . '_end_date' ] || $date <= $settings[ $prefix . '_end_date' ] ); }
	private static function now() { return time(); }

	/** Fraction-safe multiply/divide with a conservative one-billion-point ceiling. */
	public static function ratio( $a, $b, $divisor, $rounding = 'floor', $ceiling = self::MAX_POINTS ) {
		if ( ! is_int( $a ) || ! is_int( $b ) || ! is_int( $divisor ) || $a < 0 || $b < 0 || $divisor < 1 || $a > 1000000000000000 || $b > 1000000000000000 || $divisor > 1000000000000000 ) { return 0; }
		$q = 0; $r = 0; $aq = intdiv( $a, $divisor ); $ar = $a % $divisor;
		foreach ( str_split( decbin( $b ) ) as $bit ) {
			$q *= 2; $r *= 2;
			if ( $r >= $divisor ) { ++$q; $r -= $divisor; }
			if ( '1' === $bit ) { $q += $aq; $r += $ar; if ( $r >= $divisor ) { ++$q; $r -= $divisor; } }
			if ( $q >= $ceiling ) { return $ceiling; }
		}
		if ( 'ceil' === $rounding && $r > 0 || 'nearest' === $rounding && $r >= intdiv( $divisor, 2 ) + $divisor % 2 ) { ++$q; }
		return min( $ceiling, $q );
	}

	public static function to_scaled_integer( $value, $precision ) {
		$precision = max( 0, min( 8, absint( $precision ) ) );
		if ( ! is_scalar( $value ) || strlen( (string) $value ) > 32 ) { return 0; }
		$normalized = wc_format_decimal( (string) $value, $precision );
		if ( ! preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/D', $normalized, $matches ) || strlen( $matches[2] ) > 15 - $precision ) { return 0; }
		$scale = 10 ** $precision;
		$fraction = isset( $matches[3] ) ? substr( str_pad( $matches[3], $precision, '0' ), 0, $precision ) : '';
		$minor = (int) $matches[2] * $scale + ( $precision ? (int) $fraction : 0 );
		return '-' === $matches[1] ? -$minor : $minor;
	}

	/** Read persisted policy metadata on a clone; never erase an editor's unsaved changes. */
	public static function policy_product( $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! $product->get_id() ) { return $product; }
		$verified = self::policy_component( 'product_meta_' . $product->get_id(), static function () use ( $product ) {
			global $wpdb;
			$copy = clone $product;
			$wpdb->last_error = '';
			try { $copy->read_meta_data( true ); }
			catch ( Throwable $error ) { return new WP_Error( 'rr_product_policy_read', __( 'Product rewards policy is temporarily unavailable. Please retry.', 'rewards-referrals' ) ); }
			if ( $wpdb->last_error ) { return new WP_Error( 'rr_product_policy_read', __( 'Product rewards policy is temporarily unavailable. Please retry.', 'rewards-referrals' ) ); }
			return $copy;
		} );
		if ( is_wp_error( $verified ) && null !== self::$policy_snapshot ) { self::$policy_snapshot['product_error'] = $verified; }
		return $verified;
	}

	/** Variation override, then parent override. Empty/inherit values continue to the parent. */
	public static function product_setting( $product, $key, $fallback = '' ) {
		if ( ! $product ) { return $fallback; }
		$product = self::policy_product( $product );
		if ( is_wp_error( $product ) ) { return null !== self::$policy_snapshot ? $fallback : $product; }
		$value = $product->get_meta( $key, true );
		if ( '' !== $value && 'inherit' !== $value ) { return $value; }
		if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) { $parent = wc_get_product( $product->get_parent_id() ); return $parent ? self::product_setting( $parent, $key, $fallback ) : $fallback; }
		return $fallback;
	}

	private static function product_categories( $product ) { $parent_id = method_exists( $product, 'get_parent_id' ) ? $product->get_parent_id() : 0; $target = $parent_id ? wc_get_product( $parent_id ) : $product; return $target && method_exists( $target, 'get_category_ids' ) ? array_map( 'absint', $target->get_category_ids() ) : array(); }
	private static function category_rate( $product, $map, $fallback ) { $categories = self::product_categories( $product ); foreach ( $map as $id => $rate ) { if ( in_array( absint( $id ), $categories, true ) ) { return $rate; } } return $fallback; }
	/** @param WC_Product|false $product Product resolved from an order line, or false when deleted. */
	private static function eligible_product( $product, $settings ) {
		if ( ! $product || 'yes' === self::product_setting( $product, '_br_exclude_rewards', 'no' ) || 'excluded' === self::product_setting( $product, '_rr_earning_mode', 'inherit' ) ) { return false; }
		$excluded = array_map( 'absint', self::list_values( $settings['excluded_products'] ) );
		if ( method_exists( $product, 'get_id' ) && ( in_array( $product->get_id(), $excluded, true ) || in_array( $product->get_parent_id(), $excluded, true ) ) ) { return false; }
		return ! array_intersect( self::product_categories( $product ), array_map( 'absint', self::list_values( $settings['excluded_categories'] ) ) );
	}

	private static function order_eligibility( $order, $settings ) {
		if ( 'yes' !== $settings['purchase_enabled'] ) { return 'purchase_disabled'; }
		if ( ! self::active_dates( $settings, 'earning', self::now() ) ) { return 'outside_earning_dates'; }
		if ( method_exists( $order, 'get_meta' ) && 'yes' === $order->get_meta( '_br_reward_order' ) && 'yes' !== $settings['earn_cash_items_in_reward_orders'] ) { return 'reward_order_excluded'; }
		if ( method_exists( $order, 'get_customer_id' ) && $order->get_customer_id() ) {
			$user = get_userdata( $order->get_customer_id() );
			$roles = self::list_values( $settings['eligible_roles'] );
			if ( $roles && ( ! $user || ! array_intersect( $roles, (array) $user->roles ) ) ) { return 'customer_role_excluded'; }
		}
		$payments = self::list_values( $settings['eligible_payments'] );
		if ( $payments && ( ! method_exists( $order, 'get_payment_method' ) || ! in_array( strtolower( $order->get_payment_method() ), $payments, true ) ) ) { return 'payment_method_excluded'; }
		$currencies = self::list_values( $settings['eligible_currencies'] );
		if ( $currencies && ( ! method_exists( $order, 'get_currency' ) || ! in_array( strtolower( $order->get_currency() ), $currencies, true ) ) ) { return 'currency_excluded'; }
		$coupons = method_exists( $order, 'get_coupon_codes' ) ? array_map( 'strtolower', $order->get_coupon_codes() ) : array();
		if ( $coupons && ( 'exclude_any' === $settings['coupon_policy'] || 'exclude_list' === $settings['coupon_policy'] && array_intersect( $coupons, self::list_values( $settings['excluded_coupons'] ) ) ) ) { return 'coupon_excluded'; }
		return '';
	}

	public static function eligible_items_minor( WC_Order $order, $settings = null ) {
		$settings = is_array( $settings ) ? self::sanitize( $settings, self::get() ) : self::get();
		$items = array(); $decimals = wc_get_price_decimals();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item WooCommerce line_item factory subtype. */
			$product = $item->get_product();
			if ( ! self::eligible_product( $product, $settings ) ) { continue; }
			if ( method_exists( $item, 'get_meta' ) && absint( $item->get_meta( '_rr_rewards_points_each', true ) ) > 0 ) {
				$cash_mode = $item->get_meta( '_rr_rewards_mode', true );
				if ( 'points_plus_cash' !== $cash_mode || 'yes' !== $settings['earn_cash_items_in_reward_orders'] ) { continue; }
			}
			$before_discount = 'pre_discount' === $settings['amount_basis'];
			$value = $before_discount && method_exists( $item, 'get_subtotal' ) ? $item->get_subtotal() : $item->get_total();
			$minor = self::to_scaled_integer( $value, $decimals );
			if ( 'yes' === $settings['include_tax'] ) { $tax = $before_discount && method_exists( $item, 'get_subtotal_tax' ) ? $item->get_subtotal_tax() : ( method_exists( $item, 'get_total_tax' ) ? $item->get_total_tax() : 0 ); $minor += self::to_scaled_integer( $tax, $decimals ); }
			if ( $minor > 0 ) { $items[ (string) $item->get_id() ] = $minor; }
		}
		return $items;
	}

	public static function points_for_eligible_items( $items, $rate, $rounding = 'floor' ) { $basis = min( 1000000000000000, array_sum( array_map( 'absint', (array) $items ) ) ); return self::ratio( (int) $basis, max( 0, self::to_scaled_integer( $rate, 6 ) ), ( 10 ** wc_get_price_decimals() ) * 1000000, $rounding ); }
	public static function purchase_points( WC_Order $order ) { $result = self::purchase_breakdown( $order ); return is_wp_error( $result ) ? $result : $result['points']; }

	/** Snapshot this result on the order; future rule changes never recalculate an existing credit. */
	public static function purchase_breakdown( WC_Order $order, $settings = null ) {
		return self::with_snapshot( static function () use ( $order, $settings ) { return self::purchase_breakdown_verified( $order, $settings ); } );
	}

	private static function purchase_breakdown_verified( WC_Order $order, $settings = null ) {
		$settings = is_array( $settings ) ? self::sanitize( $settings, self::get() ) : self::get();
		$basis = self::eligible_items_minor( $order, $settings );
		$result = array( 'points' => 0, 'eligible_items_minor' => $basis, 'eligible_basis_minor' => array_sum( $basis ), 'item_points' => array(), 'shipping_points' => 0, 'shipping_basis_minor' => 0, 'include_tax' => 'yes' === $settings['include_tax'], 'excluded_reason' => self::order_eligibility( $order, $settings ), 'rule_version' => self::version(), 'campaign_id' => '', 'qualifying_item_quantity' => 0 );
		if ( $result['excluded_reason'] || ! $basis ) { return $result; }
		if ( $result['eligible_basis_minor'] < self::to_scaled_integer( $settings['minimum_purchase'], wc_get_price_decimals() ) ) { $result['excluded_reason'] = 'below_purchase_threshold'; return $result; }
		$rates = self::rate_map( $settings['category_rates'] );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item WooCommerce line_item factory subtype. */
			$id = (string) $item->get_id(); if ( ! isset( $basis[ $id ] ) ) { continue; }
			$product = $item->get_product(); $mode = self::product_setting( $product, '_rr_earning_mode', 'inherit' );
			$quantity = method_exists( $item, 'get_quantity' ) ? max( 0, absint( $item->get_quantity() ) ) : 1;
			$result['qualifying_item_quantity'] += $quantity;
			if ( 'fixed' === $mode ) { $points = self::ratio( min( self::MAX_POINTS, absint( self::product_setting( $product, '_rr_fixed_points', 0 ) ) ), $quantity, 1 ); }
			else { $rate = 'custom' === $mode ? self::product_setting( $product, '_rr_points_per_unit', '0' ) : self::category_rate( $product, $rates, $settings['points_per_unit'] ); $points = self::points_for_eligible_items( array( $basis[ $id ] ), $rate, $settings['rounding'] ); }
			$result['item_points'][ $id ] = $points;
		}
		$points = min( self::MAX_POINTS, array_sum( $result['item_points'] ) );
		$allocation_weights = $result['item_points'];
		if ( 'yes' === $settings['include_shipping'] && method_exists( $order, 'get_shipping_total' ) ) {
			$result['shipping_basis_minor'] = max( 0, self::to_scaled_integer( $order->get_shipping_total(), wc_get_price_decimals() ) );
			$shipping_points = self::points_for_eligible_items( array( $result['shipping_basis_minor'] ), $settings['points_per_unit'], $settings['rounding'] );
			$points += $shipping_points;
			$allocation_weights['shipping'] = $shipping_points;
		}
		$multiplier = $settings['multiplier'];
		$campaign = apply_filters( 'rr_purchase_campaign', array( 'multiplier' => $multiplier, 'campaign_id' => '' ), $order );
		if ( is_wp_error( $campaign ) ) { return $campaign; }
		$multiplier = $campaign['multiplier'];
		$result['campaign_id'] = $campaign['campaign_id'];
		$points = self::ratio( (int) min( self::MAX_POINTS, $points ), max( 0, self::to_scaled_integer( $multiplier, 6 ) ), 1000000, $settings['rounding'] );
		$points = min( self::MAX_POINTS, $points + absint( $settings['fixed_order_points'] ) );
		$points = max( $points, absint( $settings['minimum_order_points'] ) );
		if ( absint( $settings['maximum_order_points'] ) ) { $points = min( $points, absint( $settings['maximum_order_points'] ) ); }
		if ( method_exists( $order, 'get_customer_id' ) && $order->get_customer_id() ) { $points = self::apply_customer_caps( $points, $order, $settings ); }
		if ( is_wp_error( $points ) ) { return $points; }
		$result['points'] = $points;
		$allocation = self::allocate( $points, $allocation_weights, $basis );
		$result['shipping_points'] = absint( $allocation['shipping'] ?? 0 ); unset( $allocation['shipping'] );
		$result['item_points'] = $allocation;
		return $result;
	}

	private static function allocate( $points, $weights, $fallback ) { if ( ! array_sum( $weights ) ) { $weights = $fallback; } $total = array_sum( $weights ); $allocated = array(); $remainder = $points; $ids = array_keys( $weights ); $last = array_pop( $ids ); foreach ( $weights as $id => $weight ) { $share = $id === $last ? $remainder : self::ratio( $points, (int) $weight, max( 1, (int) $total ) ); $allocated[ $id ] = $share; $remainder -= $share; } return $allocated; }

	private static function apply_customer_caps( $points, $order, $settings ) {
		global $wpdb; $table = RR_Database::table( 'ledger' );
		$periods = array( 'lifetime' => '', 'daily' => gmdate( 'Y-m-d 00:00:00' ), 'weekly' => gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week UTC' ) ), 'monthly' => gmdate( 'Y-m-01 00:00:00' ) );
		foreach ( $periods as $period => $start ) {
			$cap = absint( $settings[ 'customer_' . $period . '_cap' ] ); if ( ! $cap ) { continue; }
			$date = $start ? $wpdb->prepare( ' AND c.created_at >= %s', $start ) : '';
			$awarded = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(GREATEST(0,c.amount + COALESCE((SELECT SUM(r.amount) FROM $table r WHERE r.reference_id=c.id AND r.transaction_type='refund_reversal' AND r.amount<0),0))),0) FROM $table c WHERE c.customer_id=%d AND c.transaction_type='purchase_points' AND c.amount>0 AND c.state <> 'reversed' AND (%d=0 OR c.order_id <> %d)$date", $order->get_customer_id(), $order->get_id(), $order->get_id() ) );
			if ( $wpdb->last_error || null === RR_Ledger::validated_integer( (string) $awarded, 0, PHP_INT_MAX ) ) { return new WP_Error( 'rr_customer_cap_read', __( 'Customer reward limits could not be checked safely. No points were awarded. Please retry after the database is available.', 'rewards-referrals' ) ); }
			$points = min( $points, max( 0, $cap - (int) $awarded ) );
		}
		return $points;
	}

	public static function commission_points( $purchase_points, $referrer_id, $customer_id, $order, $relationship_id ) { $result = self::commission_breakdown( $purchase_points, $referrer_id, $customer_id, $order, $relationship_id ); return is_wp_error( $result ) ? $result : $result['points']; }
	public static function commission_breakdown( $purchase_points, $referrer_id, $customer_id, $order, $relationship_id ) {
		return self::with_snapshot( static function () use ( $purchase_points, $referrer_id, $customer_id, $order, $relationship_id ) { return self::commission_breakdown_verified( $purchase_points, $referrer_id, $customer_id, $order, $relationship_id ); } );
	}

	private static function commission_breakdown_verified( $purchase_points, $referrer_id, $customer_id, $order, $relationship_id ) {
		$settings = self::get(); $result = array( 'points' => 0, 'eligible_basis_minor' => 0, 'eligible_items_minor' => array(), 'item_points' => array(), 'include_tax' => 'yes' === $settings['include_tax'], 'qualifying_item_quantity' => 0, 'excluded_reason' => '', 'rule_version' => self::version() );
		if ( 'yes' !== $settings['commission_enabled'] || $referrer_id < 1 || $referrer_id === $customer_id || $purchase_points < 1 || ! self::active_dates( $settings, 'commission', self::now() ) ) { $result['excluded_reason'] = 'commission_ineligible'; return $result; }
		$purchase = self::purchase_breakdown( $order, $settings );
		if ( is_wp_error( $purchase ) ) { return $purchase; }
		if ( $purchase['excluded_reason'] || ! $purchase['points'] ) { $result['excluded_reason'] = 'purchase_ineligible'; return $result; }
		$basis = $purchase['eligible_items_minor']; $product_points = max( 0, min( self::MAX_POINTS, (int) $purchase_points ) - absint( $purchase['shipping_points'] ) ); $buyer_item_points = self::allocate( $product_points, $purchase['item_points'], $basis ); $categories = self::rate_map( $settings['commission_category_rates'] );
		$role_rates = self::rate_map( $settings['commission_role_rates'] ); $referrer = get_userdata( $referrer_id ); $role_rate = null;
		foreach ( $role_rates as $role => $rate ) { if ( $referrer && in_array( $role, (array) $referrer->roles, true ) ) { $role_rate = $rate; break; } }
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$id = (string) $item->get_id(); if ( ! isset( $basis[ $id ] ) || 'yes' === self::product_setting( $item->get_product(), '_rr_exclude_commission', 'no' ) ) { continue; }
			$quantity = method_exists( $item, 'get_quantity' ) ? max( 1, absint( $item->get_quantity() ) ) : 1;
			$items[ $id ] = array( 'quantity' => $quantity, 'basis' => $basis[ $id ], 'points' => absint( $buyer_item_points[ $id ] ?? 0 ), 'product' => $item->get_product() );
			$result['eligible_basis_minor'] += $basis[ $id ]; $result['qualifying_item_quantity'] += $quantity;
		}
		if ( ! $items || $result['eligible_basis_minor'] < self::to_scaled_integer( $settings['commission_minimum_purchase'], wc_get_price_decimals() ) ) { $result['excluded_reason'] = 'commission_threshold'; return $result; }
		$prior = self::prior_commissions( $relationship_id, $order->get_id(), $settings );
		if ( is_wp_error( $prior ) ) { return $prior; }
		$duration = $settings['commission_duration'];
		if ( 'fixed_dates' === $duration && ( ! $settings['commission_start_date'] || ! $settings['commission_end_date'] ) ) { $result['excluded_reason'] = 'commission_dates_required'; return $result; }
		if ( 'first_order' === $duration && $prior['orders'] > 0 || 'first_n' === $duration && $prior['orders'] >= max( 1, absint( $settings['first_n'] ) ) ) { $result['excluded_reason'] = 'commission_order_limit'; return $result; }
		if ( in_array( $duration, array( 'days_after_registration', 'days_after_first_purchase' ), true ) ) {
			$user = get_userdata( $customer_id ); $start = 'days_after_registration' === $duration ? ( $user ? strtotime( $user->user_registered . ' UTC' ) : 0 ) : self::first_qualifying_purchase_at( $customer_id, $order->get_id(), $settings );
			if ( is_wp_error( $start ) ) { return $start; }
			if ( ! $start && 'days_after_first_purchase' === $duration ) { $start = self::now(); }
			if ( ! $start || self::now() >= $start + max( 1, absint( $settings['duration_days'] ) ) * DAY_IN_SECONDS ) { $result['excluded_reason'] = 'commission_duration_expired'; return $result; }
		}
		$item_budget = self::MAX_POINTS;
		if ( in_array( $duration, array( 'first_item', 'first_n_items' ), true ) ) { $item_budget = max( 0, ( 'first_item' === $duration ? 1 : max( 1, absint( $settings['first_n'] ) ) ) - $prior['items'] ); }
		$volume_cap = self::to_scaled_integer( $settings['commission_volume_cap'], wc_get_price_decimals() );
		$volume_budget = $volume_cap ? max( 0, $volume_cap - $prior['volume'] ) : 1000000000000000;
		$awarded_basis = 0; $awarded_quantity = 0; $weighted_numerator = 0; $commission_weights = array();
		foreach ( $items as $item_id => $item ) {
			$quantity = min( $item['quantity'], $item_budget ); if ( $quantity < 1 || $volume_budget < 1 ) { continue; }
			$item_budget -= $quantity;
			$item_basis = min( $volume_budget, self::ratio( (int) $item['basis'], $quantity, $item['quantity'], 'floor', 1000000000000000 ) );
			$item_points = self::ratio( $item['points'], $item_basis, max( 1, $item['basis'] ) );
			$rate = self::product_setting( $item['product'], '_rr_commission_rate', '' );
			if ( '' === $rate ) { $rate = self::category_rate( $item['product'], $categories, null !== $role_rate ? $role_rate : $settings['commission_rate'] ); }
			$rate_scaled = min( 10000, max( 0, self::to_scaled_integer( $rate, 2 ) ) );
			if ( 'eligible_value_percentage' === $settings['commission_mode'] ) {
				$combined_rate = self::ratio( max( 0, self::to_scaled_integer( $settings['points_per_unit'], 6 ) ), $rate_scaled, 10000, 'floor', 1000000000000 );
				$contribution = self::ratio( (int) $item_basis, $combined_rate, ( 10 ** wc_get_price_decimals() ) * 1000000, $settings['rounding'] ) * 10000;
			} else { $contribution = $item_points * $rate_scaled; }
			$weighted_numerator = min( self::MAX_POINTS * 10000, $weighted_numerator + $contribution );
			$commission_weights[ $item_id ] = 'fixed_order' === $settings['commission_mode'] ? $item_basis : $contribution;
			$result['eligible_items_minor'][ $item_id ] = $item_basis;
			$awarded_basis += $item_basis; $awarded_quantity += $quantity; $volume_budget -= $item_basis;
		}
		$points = 'fixed_order' === $settings['commission_mode'] && $awarded_basis > 0 ? absint( $settings['commission_fixed_points'] ) : self::ratio( (int) $weighted_numerator, 1, 10000, $settings['rounding'] );
		if ( absint( $settings['commission_maximum_order'] ) ) { $points = min( $points, absint( $settings['commission_maximum_order'] ) ); }
		if ( absint( $settings['commission_relationship_cap'] ) ) { $points = min( $points, max( 0, absint( $settings['commission_relationship_cap'] ) - $prior['points'] ) ); }
		$result['points'] = min( self::MAX_POINTS, $points ); $result['eligible_basis_minor'] = $awarded_basis; $result['qualifying_item_quantity'] = $awarded_quantity; $result['item_points'] = self::allocate( $result['points'], $commission_weights, $result['eligible_items_minor'] );
		return $result;
	}

	private static function first_qualifying_purchase_at( $customer_id, $order_id, $settings ) { return RR_Commission_Index::first_purchase( $customer_id, $order_id, $settings ); }
	private static function prior_commissions( $relationship_id, $order_id, $settings ) { return RR_Commission_Index::prior( $relationship_id, $order_id, $settings ); }
}
