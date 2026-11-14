<?php
defined( 'ABSPATH' ) || exit;

final class RR_WooCommerce {
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'award_order' ), 20 );
		add_action( 'rr_retry_refund_rewards', array( __CLASS__, 'reverse_refund' ), 10, 2 );
		add_action( 'rr_retry_cancel_rewards', array( __CLASS__, 'cancel_order_rewards' ) );
		add_action( 'rr_retry_confirm_rewards', array( __CLASS__, 'retry_order_confirmation' ) );
		add_action( 'rr_retry_release_rewards', array( __CLASS__, 'release_order_redemption' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'award_order' ), 20 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'reserve_checkout_redemption' ), 1, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'reserve_store_api_redemption' ), 1 );
		// Run after the Store API has finished its order/cart bookkeeping so a
		// completed zero-total cart cannot be written back into the session.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'clear_completed_store_cart' ), 999 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'confirm_order_redemption' ), 5 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'confirm_order_redemption' ), 5 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release_order_redemption' ), 5 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'validate_reward_payment_retry' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'cancel_order_rewards' ), 5 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'reverse_refund' ), 20, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_reward_product_prices' ), 20 );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_redemption_choice' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_redemption_data' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_redemption_data' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_redemption' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item_redemption_data' ), 10, 4 );
		add_action( 'rr_expire_redemption_reservation', array( 'RR_Ledger', 'expire_order_reservation' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_reward_data' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ) );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( __CLASS__, 'store_api_cart_data' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'validate_store_api_reward' ), 10, 2 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'rotate_checkout_generation' ), 20 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'cart_reward_summary' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'cart_reward_summary' ) );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'reward_price_html' ), 20, 2 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'cancellation_order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_rr_retry_rewards_cancellation', array( __CLASS__, 'retry_cancellation' ) );
		add_action( 'woocommerce_order_action_rr_retry_rewards_accounting', array( __CLASS__, 'retry_order_accounting' ) );
	}

	/** Invalidate only this order after a rolled-back CRUD save; object caches are not transactional. */
	private static function fresh_order( $order_id, $previous = null ) {
		$order_id = absint( $order_id );
		if ( $previous instanceof WC_Abstract_Order && is_callable( array( $previous, 'delete_meta_cache' ) ) ) { $previous->delete_meta_cache(); }
		if ( class_exists( '\\Automattic\\WooCommerce\\Caches\\OrderCache' ) ) { wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id ); }
		$store = WC_Data_Store::load( 'order' );
		// WC_Data_Store explicitly delegates store-specific methods through __call().
		if ( method_exists( $store->get_current_class_name(), 'clear_cached_data' ) ) { $store->__call( 'clear_cached_data', array( array( $order_id ) ) ); }
		// Also covers synchronized CPT metadata without invalidating unrelated customer/store caches.
		wp_cache_delete( $order_id, 'posts' ); wp_cache_delete( $order_id, 'post_meta' );
		$order = wc_get_order( $order_id );
		if ( $order ) { $order->read_meta_data( true ); }
		return $order;
	}

	public static function award_order( $order_id ) {
		$result = RR_Rules::with_snapshot( static function () use ( $order_id ) { return self::award_order_verified( $order_id ); } );
		if ( is_wp_error( $result ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && $order->get_customer_id() && $order->has_status( array( 'processing', 'completed' ) ) ) {
				RR_Background::schedule( 'rr_retry_order_rewards', 60, array( absint( $order_id ) ) );
				$order->add_order_note( $result->get_error_message() );
			}
		}
		return $result;
	}

	private static function award_order_verified( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_customer_id() && $order->has_status( array( 'processing','completed' ) ) ) { RR_Recovery::paid( $order_id ); }
		$settings = RR_Rules::get();
		$statuses = 'processing' === ( $settings['award_statuses'] ?? 'completed' ) ? array( 'processing', 'completed' ) : array( 'completed' );
		if ( ! $order || ! $order->get_customer_id() || ! in_array( $order->get_status(), $statuses, true ) ) { return; }
		if ( $order->get_currency() !== get_woocommerce_currency() ) {
			$order->add_order_note( __( 'Purchase earning is paused because this order currency differs from the current store currency. No points were awarded. Review a supported historical currency/rate policy before retrying; automatic currency conversion is not configured.', 'rewards-referrals' ) );
			return;
		}
		$storage = RR_Database::verify_order_storage();
		if ( is_wp_error( $storage ) ) { $order->add_order_note( $storage->get_error_message() ); RR_Background::schedule( 'rr_retry_order_rewards', 60, array( absint( $order_id ) ) ); return; }
		global $wpdb;
		$customer_id = (int) $order->get_customer_id();
		// Do not finalize a buyer-only award while bounded legacy referral attribution is unresolved.
		$pending_identity = RR_Referrals::pending_identity( $customer_id );
		if ( is_wp_error( $pending_identity ) || $pending_identity['present'] ) {
			RR_Background::schedule( 'rr_referral_index', 5 );
			RR_Background::schedule( 'rr_retry_order_rewards', 60, array( absint( $order_id ) ) );
			return;
		}
		$relationship = $wpdb->get_row( $wpdb->prepare( 'SELECT id,referrer_id FROM %i WHERE customer_id=%d AND approved=1', RR_Database::table( 'referrals' ), $customer_id ) );
		if ( $wpdb->last_error ) { RR_Background::schedule( 'rr_retry_order_rewards', 60, array( absint( $order_id ) ) ); return; }
		if ( $relationship && ! RR_Commission_Index::ready() ) { RR_Background::schedule( 'rr_retry_order_rewards', 60, array( absint( $order_id ) ) ); return; }
		$wallets = array( $customer_id );
		if ( $relationship ) { $wallets[] = (int) $relationship->referrer_id; }
		$locked = RR_Ledger::begin_wallet_transaction( $wallets );
		if ( is_wp_error( $locked ) ) { if ( in_array( $locked->get_error_code(), array( 'rr_wallet_rebuild_pending', 'rr_legacy_lot_maintenance' ), true ) ) { RR_Background::schedule( 'rr_retry_order_rewards', 60, array( absint( $order_id ) ) ); } else { $order->add_order_note( __( 'Rewards accounting could not acquire its wallet lock and needs review.', 'rewards-referrals' ) ); } return; }
		$transaction_open = true;
		try {
			// Use a current shared policy read after locking, so settings/version cannot mix.
			$policy = RR_Rules::refresh_snapshot();
			if ( is_wp_error( $policy ) ) { throw new RuntimeException( $policy->get_error_code() ); }
			$settings = RR_Rules::get();
			$statuses = 'processing' === $settings['award_statuses'] ? array( 'processing', 'completed' ) : array( 'completed' );
			// Re-read after taking the wallet lock: cancellation may have won the race.
			$order = self::fresh_order( $order_id, $order );
			if ( ! $order || 'yes' === $order->get_meta( '_rr_rewards_cancelled' ) || ! in_array( $order->get_status(), $statuses, true ) ) { RR_Ledger::finish_wallet_transaction( true ); return; }
			// The wallet lock serializes rule caps and both credits; completed credits are immutable.
			$existing_purchase = RR_Ledger::transaction_id_by_key( 'order:' . $order_id . ':purchase' );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Existing purchase award could not be read.' ); }
			if ( $existing_purchase || 'yes' === $order->get_meta( '_rr_rewards_award_complete' ) ) { RR_Ledger::finish_wallet_transaction( true ); return; }
			$breakdown = RR_Rules::purchase_breakdown( $order );
			if ( is_wp_error( $breakdown ) ) { throw new RuntimeException( $breakdown->get_error_code() ); }
			$breakdown['price_decimals'] = wc_get_price_decimals();
			$breakdown['currency'] = $order->get_currency();
			$breakdown['include_tax'] = $settings['include_tax'] ?? 'no';
			$breakdown['expiration_days'] = min( 36500, absint( $settings['expiration_days'] ?? 0 ) );
			$delay_days = absint( $settings['availability_delay_days'] ?? 0 );
			$award_state = $delay_days ? 'pending' : 'available';
			$available_at = $delay_days ? gmdate( 'Y-m-d H:i:s', time() + $delay_days * DAY_IN_SECONDS ) : null;
			$points = (int) $breakdown['points'];
			$eligible_items = $breakdown['eligible_items_minor'];
			$eligible_basis = $breakdown['eligible_basis_minor'];
			if ( $points > 0 ) {
				$purchase_id = RR_Ledger::add( array( 'customer_id' => $customer_id, 'amount' => $points, 'type' => 'purchase_points', 'state' => $award_state, 'available_at' => $available_at, 'order_id' => $order_id, 'key' => 'order:' . $order_id . ':purchase', 'meta' => $breakdown ) );
				if ( is_wp_error( $purchase_id ) ) { throw new RuntimeException( $purchase_id->get_error_message() ); }
				$order->update_meta_data( '_br_points_awarded', $points );
				$order->update_meta_data( '_rr_rewards_purchase_transaction_id', absint( $purchase_id ) );
			}
			$order->update_meta_data( '_rr_rewards_eligible_items_minor', wp_json_encode( $eligible_items ) );
			$order->update_meta_data( '_rr_rewards_eligible_basis_minor', $eligible_basis );
			$order->update_meta_data( '_rr_rewards_item_points', wp_json_encode( $breakdown['item_points'] ) );
			$order->update_meta_data( '_rr_rewards_purchase_snapshot', wp_json_encode( $breakdown ) );
			if ( $relationship && $points > 0 ) {
				$commission = RR_Rules::commission_breakdown( $points, (int) $relationship->referrer_id, $customer_id, $order, (int) $relationship->id );
				if ( is_wp_error( $commission ) ) { throw new RuntimeException( $commission->get_error_code() ); }
				$commission['price_decimals'] = $breakdown['price_decimals'];
				$commission['currency'] = $order->get_currency();
				$commission['include_tax'] = $settings['include_tax'] ?? 'no';
				$commission['expiration_days'] = min( 36500, absint( $settings['expiration_days'] ?? 0 ) );
				if ( $commission['points'] > 0 ) {
					$commission_id = RR_Ledger::add( array( 'customer_id' => (int) $relationship->referrer_id, 'amount' => $commission['points'], 'type' => 'referral_commission', 'state' => $award_state, 'available_at' => $available_at, 'order_id' => $order_id, 'referral_id' => (int) $relationship->id, 'key' => 'order:' . $order_id . ':referral:' . (int) $relationship->referrer_id, 'meta' => $commission + array( 'qualifying_points' => $points ) ) );
					if ( is_wp_error( $commission_id ) ) { throw new RuntimeException( $commission_id->get_error_message() ); }
					$order->update_meta_data( '_br_referral_commission', $commission['points'] );
					$order->update_meta_data( '_rr_rewards_commission_transaction_id', absint( $commission_id ) );
					$order->update_meta_data( '_rr_rewards_commission_snapshot', wp_json_encode( $commission ) );
				}
			}
			$order->update_meta_data( '_rr_rewards_award_complete', 'yes' );
			$order->save();
			// Late completion / deferred attribution must account for refunds that already exist.
			$refund = self::latest_order_refund( $order_id );
			if ( $refund ) { self::reverse_purchase_refund_locked( $order_id, $refund->get_id(), $order ); }
			$committed = RR_Ledger::finish_wallet_transaction( true );
			$transaction_open = false;
			if ( is_wp_error( $committed ) ) { throw new RuntimeException( $committed->get_error_message() ); }
			do_action( 'rr_order_rewards_awarded', $order, $points );
			do_action( 'br_order_rewards_awarded', $order, $points );
		} catch ( Throwable $error ) {
			if ( $transaction_open ) { RR_Ledger::finish_wallet_transaction( false ); }
			$order = self::fresh_order( $order_id, $order ); if ( ! $order ) { return; }
			RR_Background::schedule( 'rr_retry_order_rewards', 60, array( absint( $order_id ) ) );
			$order->add_order_note( __( 'Rewards accounting needs review. No partial wallet credit was committed. The store can use the Retry rewards accounting order action after resolving the issue.', 'rewards-referrals' ) );
		}
	}

	public static function reverse_refund( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund instanceof WC_Order_Refund || (int) $refund->get_parent_id() !== (int) $order_id ) { return; }
		global $wpdb; $buyer = RR_Recovery::buyer( $order );
		if ( ! $buyer ) {
			// Unknown ownership must not guess a wallet or lose the one-time refund callback.
			if ( $wpdb->last_error ) { RR_Background::schedule( 'rr_retry_refund_rewards', 60, array( absint( $order_id ), absint( $refund_id ) ) ); }
			return;
		}
		$commission_wallet = RR_Ledger::positive_order_transaction( $order_id, 'referral_commission' );
		if ( $wpdb->last_error ) { RR_Background::schedule( 'rr_retry_refund_rewards', 60, array( absint( $order_id ), absint( $refund_id ) ) ); $order->add_order_note( __( 'Refund rewards ownership could not be read. No points changed; repair the database and retry rewards accounting.', 'rewards-referrals' ) ); return; }
		$hold = RR_Recovery::record( 'refund', $order_id, $refund_id, array( $buyer, $commission_wallet ? (int) $commission_wallet->customer_id : $buyer ) );
		if ( is_wp_error( $hold ) ) { RR_Background::schedule( 'rr_retry_refund_rewards', 60, array( absint( $order_id ), absint( $refund_id ) ) ); $order->add_order_note( __( 'Refund rewards recovery could not be recorded. Review accounting before further redemption.', 'rewards-referrals' ) ); return; }
		$storage = RR_Database::verify_order_storage(); if ( is_wp_error( $storage ) ) { $order->add_order_note( $storage->get_error_message() ); RR_Background::schedule( 'rr_retry_refund_rewards', 60, array( absint( $order_id ), absint( $refund_id ) ) ); return; }
		RR_Recovery::run( array( $buyer, $commission_wallet ? (int) $commission_wallet->customer_id : $buyer ), static function () use ( $order_id, $refund_id, $order, $commission_wallet, $buyer ) {
		global $wpdb;
		$locked = RR_Ledger::begin_wallet_transaction( array( $buyer, $commission_wallet ? (int) $commission_wallet->customer_id : $buyer ) );
		/* translators: %s is a rewards accounting error message. */
		if ( is_wp_error( $locked ) ) { if ( in_array( $locked->get_error_code(), array( 'rr_wallet_rebuild_pending', 'rr_legacy_lot_maintenance' ), true ) ) { RR_Background::schedule( 'rr_retry_refund_rewards', 60, array( absint( $order_id ), absint( $refund_id ) ) ); } else { $order->add_order_note( sprintf( __( 'Rewards refund accounting needs review: %s', 'rewards-referrals' ), $locked->get_error_message() ) ); } return; }
		try {
			$order = self::recovery_order_locked( $order_id ); if ( is_wp_error( $order ) ) { throw new RuntimeException( 'Refund order could not be re-read.' ); }
			if ( 'yes' === $order->get_meta( '_br_reward_order' ) ) {
				$reservation_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d FOR UPDATE', RR_Database::table( 'redemptions' ), $order_id ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Refund reservation status could not be read.' ); }
				if ( $order->has_status( 'refunded' ) && 'reserved' === $reservation_status ) {
					$restored = RR_Ledger::release_redemption( $order_id, 'order_fully_refunded_before_confirmation' );
					if ( ! is_wp_error( $restored ) ) { $order->delete_meta_data( '_rr_confirmation_waiting' ); $order->save(); }
				} else { $restored = RR_Ledger::restore_redemption_refund( $order_id, $refund_id ); }
				if ( is_wp_error( $restored ) ) { throw new RuntimeException( $restored->get_error_message() ); }
				self::update_refunded_usage( $order, $refund_id );
			}
			self::reverse_purchase_refund_locked( $order_id, $refund_id, $order );
			if ( $order->has_status( 'refunded' ) ) { RR_Recovery::complete( 'confirm', $order_id ); RR_Recovery::complete( 'release', $order_id ); }
			RR_Recovery::complete( 'refund', $order_id, $refund_id );
			RR_Recovery::finish_reversal_review( $order_id );
			$committed = RR_Ledger::finish_wallet_transaction( true );
			/* translators: %s is a rewards accounting error message. */
			if ( is_wp_error( $committed ) ) { $order = self::fresh_order( $order_id, $order ); if ( ! $order ) { return; } $order->add_order_note( sprintf( __( 'Rewards refund accounting needs review: %s', 'rewards-referrals' ), $committed->get_error_message() ) ); }
		} catch ( Throwable $error ) {
			RR_Ledger::finish_wallet_transaction( false );
			$order = self::fresh_order( $order_id, $order ); if ( ! $order ) { return; }
			$order->add_order_note( __( 'Rewards refund accounting needs review because an unexpected error occurred.', 'rewards-referrals' ) );
		}
		} );
	}

	private static function reverse_purchase_refund_locked( $order_id, $refund_id, $order ) {
		global $wpdb;
		$purchase = RR_Ledger::positive_order_transaction( $order_id, 'purchase_points' );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Purchase reward source could not be read.' ); }
		$purchase_id = absint( $order->get_meta( '_rr_rewards_purchase_transaction_id' ) );
		if ( ! $purchase_id && $purchase ) { $purchase_id = absint( $purchase->id ); }
		if ( ! $purchase_id ) { $purchase_id = RR_Ledger::transaction_id_by_key( 'order:' . $order_id . ':purchase' ); }
		$original_points = (int) $order->get_meta( '_br_points_awarded' );
		if ( ! $original_points && $purchase ) { $original_points = (int) $purchase->amount; }
		if ( ! $purchase_id || $original_points < 1 ) { return; }

		$eligible_items = json_decode( (string) $order->get_meta( '_rr_rewards_eligible_items_minor' ), true );
		if ( ! is_array( $eligible_items ) || ! $eligible_items ) {
			// Legacy orders predate snapshots; derive the best available eligible item set.
			$eligible_items = RR_Rules::eligible_items_minor( $order );
		}
		$eligible_basis = absint( $order->get_meta( '_rr_rewards_eligible_basis_minor' ) );
		if ( ! $eligible_basis ) { $eligible_basis = array_sum( array_map( 'absint', $eligible_items ) ); }
		if ( ! $eligible_basis ) { return; }

		$refunds = self::order_refunds_with_current( $order, $refund_id );
		$purchase_snapshot = json_decode( (string) $order->get_meta( '_rr_rewards_purchase_snapshot' ), true );
		$refunded_basis = self::cumulative_eligible_refund_minor( $order, $eligible_items, $eligible_basis, $refunds, $purchase_snapshot['price_decimals'] ?? null );
		$purchase_allocations = json_decode( (string) $order->get_meta( '_rr_rewards_item_points' ), true );
		$purchase_target = is_array( $purchase_allocations ) && $purchase_allocations ? self::allocated_refund_target( $order, $eligible_items, $purchase_allocations, $original_points, is_array( $purchase_snapshot ) ? $purchase_snapshot : array(), $refunds ) : self::proportional_reversal( $original_points, $eligible_basis, $refunded_basis );
		$purchase_reversed = RR_Ledger::reversed_amount( $purchase_id );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Prior purchase reversal total could not be read.' ); }
		$purchase_delta = max( 0, $purchase_target - $purchase_reversed );
		if ( $purchase_delta > 0 ) {
			$reversal = RR_Ledger::add( array(
				'customer_id' => RR_Recovery::buyer( $order ), 'amount' => -$purchase_delta, 'type' => 'refund_reversal', 'state' => $purchase && 'pending' === $purchase->state ? 'pending' : 'available', 'available_at' => $purchase && 'pending' === $purchase->state ? $purchase->available_at : null,
				'order_id' => $order_id, 'reference_id' => $purchase_id, 'key' => 'refund:' . absint( $refund_id ) . ':purchase',
				'meta' => array( 'refund_id' => absint( $refund_id ), 'source' => 'purchase', 'cumulative_eligible_refund_minor' => $refunded_basis ),
			) );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Accounting exceptions are caught internally; WooCommerce serializes route errors and escapes checkout notices at the output boundary.
			if ( is_wp_error( $reversal ) ) { throw new RuntimeException( $reversal->get_error_message() ); }
		}

		$commission_tx = RR_Ledger::positive_order_transaction( $order_id, 'referral_commission' );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Referral reward source could not be read.' ); }
		$commission_id = absint( $order->get_meta( '_rr_rewards_commission_transaction_id' ) );
		if ( ! $commission_id && $commission_tx ) { $commission_id = absint( $commission_tx->id ); }
		$commission_points = (int) $order->get_meta( '_br_referral_commission' );
		if ( ! $commission_points && $commission_tx ) { $commission_points = (int) $commission_tx->amount; }
		if ( $commission_id && $commission_points > 0 && $commission_tx ) {
			$commission_snapshot = json_decode( (string) $order->get_meta( '_rr_rewards_commission_snapshot' ), true );
			$commission_target = is_array( $commission_snapshot ) && ! empty( $commission_snapshot['item_points'] ) ? self::allocated_refund_target( $order, $commission_snapshot['eligible_items_minor'], $commission_snapshot['item_points'], $commission_points, $commission_snapshot, $refunds ) : self::proportional_reversal( $commission_points, $eligible_basis, $refunded_basis );
			$commission_reversed = RR_Ledger::reversed_amount( $commission_id );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Prior referral reversal total could not be read.' ); }
			$commission_delta = max( 0, $commission_target - $commission_reversed );
			if ( $commission_delta > 0 ) {
				$reversal = RR_Ledger::add( array(
					'customer_id' => (int) $commission_tx->customer_id, 'amount' => -$commission_delta, 'type' => 'refund_reversal', 'state' => 'pending' === $commission_tx->state ? 'pending' : 'available', 'available_at' => 'pending' === $commission_tx->state ? $commission_tx->available_at : null,
					'order_id' => $order_id, 'referral_id' => absint( $commission_tx->referral_id ), 'reference_id' => $commission_id,
					'key' => 'refund:' . absint( $refund_id ) . ':commission',
					'meta' => array( 'refund_id' => absint( $refund_id ), 'source' => 'commission', 'cumulative_eligible_refund_minor' => $refunded_basis ),
				) );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Accounting exceptions are caught internally; WooCommerce serializes route errors and escapes checkout notices at the output boundary.
				if ( is_wp_error( $reversal ) ) { throw new RuntimeException( $reversal->get_error_message() ); }
			}
		}
	}

	private static function order_refunds_with_current( $order, $refund_id = 0 ) {
		$refunds = (array) $order->get_refunds();
		$found = false;
		foreach ( $refunds as $refund ) { if ( absint( $refund->get_id() ) === absint( $refund_id ) ) { $found = true; break; } }
		if ( $refund_id && ! $found ) { $current = wc_get_order( absint( $refund_id ) ); if ( $current instanceof WC_Order_Refund && absint( $current->get_parent_id() ) === absint( $order->get_id() ) ) { $refunds[] = $current; } }
		return $refunds;
	}

	private static function allocated_refund_target( $order, $eligible_items, $item_points, $maximum, $snapshot = array(), $refunds = null ) {
		$decimals = isset( $snapshot['price_decimals'] ) ? max( 0, min( 8, (int) $snapshot['price_decimals'] ) ) : wc_get_price_decimals();
		$refunded = array();
		$unallocated = 0;
		$shipping_refunded = 0;
		foreach ( null === $refunds ? $order->get_refunds() : $refunds as $refund ) {
			$shipping_refunded += abs( RR_Rules::to_scaled_integer( $refund->get_shipping_total(), $decimals ) );
			$items = $refund->get_items( 'line_item' );
			if ( ! $items && ( $refund->get_items( 'shipping' ) || $refund->get_items( 'fee' ) ) ) { continue; }
			if ( ! $items ) { $unallocated += abs( RR_Rules::to_scaled_integer( $refund->get_amount(), $decimals ) ); continue; }
			foreach ( $items as $item ) {
				$id = (string) absint( $item->get_meta( '_refunded_item_id', true ) );
				if ( ! isset( $eligible_items[ $id ] ) ) { continue; }
				$refund_value = abs( RR_Rules::to_scaled_integer( $item->get_total(), $decimals ) );
				if ( 'yes' === ( $snapshot['include_tax'] ?? 'no' ) ) { $refund_value += abs( RR_Rules::to_scaled_integer( $item->get_total_tax(), $decimals ) ); }
				$refunded[ $id ] = ( $refunded[ $id ] ?? 0 ) + $refund_value;
			}
		}
		$target = 0;
		$order_total = abs( RR_Rules::to_scaled_integer( $order->get_total(), $decimals ) );
		foreach ( $item_points as $id => $points ) {
			$basis = absint( $eligible_items[ $id ] ?? 0 );
			$refund_basis = absint( $refunded[ $id ] ?? 0 );
			if ( $unallocated && $order_total ) { $refund_basis += RR_Rules::ratio( $basis, min( $unallocated, $order_total ), $order_total, 'floor', 1000000000000000 ); }
			$target += self::proportional_reversal( $points, $basis, $refund_basis );
		}
		if ( ! empty( $snapshot['shipping_points'] ) && ! empty( $snapshot['shipping_basis_minor'] ) ) {
			if ( $unallocated && $order_total ) { $shipping_refunded += RR_Rules::ratio( absint( $snapshot['shipping_basis_minor'] ), min( $unallocated, $order_total ), $order_total, 'floor', 1000000000000000 ); }
			$target += self::proportional_reversal( $snapshot['shipping_points'], $snapshot['shipping_basis_minor'], $shipping_refunded );
		}
		return min( absint( $maximum ), $target );
	}

	private static function cumulative_eligible_refund_minor( WC_Order $order, $eligible_items, $eligible_basis, $refunds = null, $decimals = null ) {
		$decimals = null === $decimals ? wc_get_price_decimals() : max( 0, min( 8, (int) $decimals ) );
		$refunded = 0;
		$cash_only_refunds = 0;
		foreach ( null === $refunds ? $order->get_refunds() : $refunds as $refund ) {
			$items = $refund->get_items( 'line_item' );
			if ( $items ) {
				foreach ( $items as $refunded_item ) {
					$parent_item_id = absint( $refunded_item->get_meta( '_refunded_item_id' ) );
					if ( ! isset( $eligible_items[ (string) $parent_item_id ] ) ) { continue; }
					$refunded += abs( RR_Rules::to_scaled_integer( $refunded_item->get_total(), $decimals ) );
				}
			} elseif ( ! $refund->get_items( 'shipping' ) && ! $refund->get_items( 'fee' ) ) {
				$cash_only_refunds += abs( RR_Rules::to_scaled_integer( $refund->get_amount(), $decimals ) );
			}
		}
		if ( $cash_only_refunds > 0 ) {
			$order_total = abs( RR_Rules::to_scaled_integer( $order->get_total(), $decimals ) );
			if ( $order_total > 0 ) { $refunded += RR_Rules::ratio( $eligible_basis, min( $cash_only_refunds, $order_total ), $order_total, 'floor', 1000000000000000 ); }
		}
		return min( absint( $eligible_basis ), max( 0, $refunded ) );
	}

	private static function proportional_reversal( $original_points, $eligible_basis, $refunded_basis ) {
		$original_points = absint( $original_points );
		$eligible_basis = absint( $eligible_basis );
		$refunded_basis = min( $eligible_basis, absint( $refunded_basis ) );
		if ( ! $original_points || ! $eligible_basis || ! $refunded_basis ) { return 0; }
		if ( $refunded_basis >= $eligible_basis ) { return $original_points; }
		return RR_Rules::ratio( $original_points, min( $refunded_basis, $eligible_basis ), $eligible_basis, 'floor', $original_points );
	}

	private static function policy_fields( $product = null, $loop = null ) {
		$variation = null !== $loop;
		$prefix = $variation ? '_rr_variation' : '';
		$key = '_rr_reward_policy_mode';
		$name = $variation ? $prefix . $key . '[' . $loop . ']' : $key;
		woocommerce_wp_select( array( 'id' => $name, 'name' => $name, 'value' => $product ? $product->get_meta( $key ) : null, 'label' => __( 'Reward eligibility policy', 'rewards-referrals' ), 'options' => array( 'inherit' => __( 'Inherit parent / no extra limits', 'rewards-referrals' ), 'custom' => __( 'Use the limits below', 'rewards-referrals' ) ), 'description' => __( 'Variation inheritance uses the entire parent policy, sharing its quantity limits across all child variations. Custom variation policy has independent limits.', 'rewards-referrals' ) ) );
		foreach ( array(
			'_rr_reward_start_date' => array( 'date', __( 'Reward starts (UTC)', 'rewards-referrals' ) ),
			'_rr_reward_end_date' => array( 'date', __( 'Reward ends (UTC)', 'rewards-referrals' ) ),
			'_rr_reward_roles' => array( 'text', __( 'Eligible role names (comma separated)', 'rewards-referrals' ) ),
			'_rr_reward_min_balance' => array( 'number', __( 'Minimum available wallet before redemption', 'rewards-referrals' ) ),
			'_rr_reward_limit_customer' => array( 'number', __( 'Customer lifetime unit limit (0 = none)', 'rewards-referrals' ) ),
			'_rr_reward_limit_daily' => array( 'number', __( 'Daily unit limit per customer (UTC)', 'rewards-referrals' ) ),
			'_rr_reward_limit_monthly' => array( 'number', __( 'Monthly unit limit per customer (UTC)', 'rewards-referrals' ) ),
		) as $key => $field ) {
			$name = $variation ? $prefix . $key . '[' . $loop . ']' : $key;
			$args = array( 'id' => $name, 'name' => $name, 'label' => $field[1], 'type' => $field[0] );
			if ( $product ) { $args['value'] = $product->get_meta( $key ); }
			if ( 'number' === $field[0] ) { $args['custom_attributes'] = array( 'min' => '0', 'max' => '1000000000', 'step' => '1' ); }
			woocommerce_wp_text_input( $args );
		}
	}

	private static function save_policy_fields( $product, $loop = null ) {
		$values = array();
		foreach ( array( '_rr_reward_policy_mode', '_rr_reward_start_date', '_rr_reward_end_date', '_rr_reward_roles', '_rr_reward_min_balance', '_rr_reward_limit_customer', '_rr_reward_limit_daily', '_rr_reward_limit_monthly' ) as $key ) {
			$post_key = null !== $loop ? '_rr_variation' . $key : $key;
			$raw = null !== $loop ? ( $_POST[ $post_key ][ $loop ] ?? '' ) : ( $_POST[ $post_key ] ?? '' );
			$raw = is_scalar( $raw ) ? trim( wp_unslash( (string) $raw ) ) : '';
			if ( '_rr_reward_policy_mode' === $key ) { $values[ $key ] = 'custom' === $raw ? 'custom' : 'inherit'; }
			elseif ( in_array( $key, array( '_rr_reward_start_date', '_rr_reward_end_date' ), true ) ) { $values[ $key ] = self::valid_policy_date( $raw ) ? $raw : $product->get_meta( $key ); }
			elseif ( '_rr_reward_roles' === $key ) { $roles = array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) ); $values[ $key ] = array_diff( $roles, array_keys( wp_roles()->roles ) ) ? $product->get_meta( $key ) : implode( ',', array_values( $roles ) ); }
			else { $value = RR_Ledger::validated_integer( '' === $raw ? '0' : $raw, 0, 1000000000 ); $values[ $key ] = null === $value ? $product->get_meta( $key ) : $value; }
		}
		if ( $values['_rr_reward_start_date'] && $values['_rr_reward_end_date'] && $values['_rr_reward_end_date'] < $values['_rr_reward_start_date'] ) { $values['_rr_reward_end_date'] = $values['_rr_reward_start_date']; }
		foreach ( $values as $key => $value ) { $product->update_meta_data( $key, $value ); }
	}

	private static function valid_policy_date( $value ) {
		if ( '' === $value ) { return true; }
		$date = is_string( $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) ) : false;
		return $date && $date->format( 'Y-m-d' ) === $value;
	}

	public static function reward_policy( $product ) {
		if ( ! $product ) { return array(); }
		$product = RR_Rules::policy_product( $product );
		if ( is_wp_error( $product ) ) { return array( '_error' => $product->get_error_message() ); }
		if ( 'custom' !== $product->get_meta( '_rr_reward_policy_mode' ) ) {
			return $product->get_parent_id() ? self::reward_policy( wc_get_product( $product->get_parent_id() ) ) : array();
		}
		$policy = array( 'scope_id' => $product->get_id(), 'start_date' => (string) $product->get_meta( '_rr_reward_start_date' ), 'end_date' => (string) $product->get_meta( '_rr_reward_end_date' ), 'roles' => array_filter( array_map( 'sanitize_key', explode( ',', (string) $product->get_meta( '_rr_reward_roles' ) ) ) ) );
		foreach ( array( 'min_balance', 'limit_customer', 'limit_daily', 'limit_monthly' ) as $key ) { $policy[ $key ] = min( 1000000000, absint( $product->get_meta( '_rr_reward_' . $key ) ) ); }
		return $policy;
	}

	public static function reward_policy_error( $product, $customer_id, $quantity = 1, $exclude_order = 0, $check_limits = true ) {
		$policy = self::reward_policy( $product );
		if ( ! empty( $policy['_error'] ) ) { return $policy['_error']; }
		if ( ! $policy ) { return ''; }
		$today = gmdate( 'Y-m-d' );
		if ( ! self::valid_policy_date( $policy['start_date'] ) || ! self::valid_policy_date( $policy['end_date'] ) || ( $policy['start_date'] && $today < $policy['start_date'] ) || ( $policy['end_date'] && $today > $policy['end_date'] ) ) { return __( 'This reward is outside its availability dates.', 'rewards-referrals' ); }
		$user = $customer_id ? get_userdata( $customer_id ) : false;
		if ( $policy['roles'] && ( ! $user || ! array_intersect( $policy['roles'], (array) $user->roles ) ) ) { return __( 'This reward is not available for your customer role.', 'rewards-referrals' ); }
		if ( $policy['min_balance'] && ( ! $customer_id || RR_Ledger::balances( $customer_id )['available'] < $policy['min_balance'] ) ) { return __( 'Your wallet does not meet this reward’s minimum balance requirement.', 'rewards-referrals' ); }
		if ( ! $check_limits || ( ! $policy['limit_customer'] && ! $policy['limit_daily'] && ! $policy['limit_monthly'] ) ) { return ''; }
		global $wpdb;
		$items = RR_Database::table( 'redemption_items' );
		$redemptions = RR_Database::table( 'redemptions' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(GREATEST(i.quantity-i.refunded_quantity,0)),0) AS lifetime, COALESCE(SUM(CASE WHEN i.created_at >= %s THEN GREATEST(i.quantity-i.refunded_quantity,0) ELSE 0 END),0) AS daily, COALESCE(SUM(CASE WHEN i.created_at >= %s THEN GREATEST(i.quantity-i.refunded_quantity,0) ELSE 0 END),0) AS monthly FROM $items i INNER JOIN $redemptions r ON r.order_id=i.order_id WHERE i.customer_id=%d AND i.policy_scope_id=%d AND i.order_id<>%d AND r.status IN ('reserved','confirmed','partially_refunded')", $today . ' 00:00:00', gmdate( 'Y-m-01 00:00:00' ), absint( $customer_id ), $policy['scope_id'], absint( $exclude_order ) ) );
		if ( ! $row || $wpdb->last_error ) { return __( 'Reward limits could not be safely checked. Please retry later.', 'rewards-referrals' ); }
		foreach ( array( 'customer' => 'lifetime', 'daily' => 'daily', 'monthly' => 'monthly' ) as $limit => $column ) { if ( $policy[ 'limit_' . $limit ] && (int) $row->$column + $quantity > $policy[ 'limit_' . $limit ] ) { return __( 'This reward exceeds your customer, daily, or monthly redemption limit.', 'rewards-referrals' ); } }
		return '';
	}

	private static function update_refunded_usage( $order, $refund_id ) {
		global $wpdb;
		$table = RR_Database::table( 'redemption_items' );
		$quantities = array();
		foreach ( self::order_refunds_with_current( $order, $refund_id ) as $refund ) { foreach ( $refund->get_items( 'line_item' ) as $item ) { $id = absint( $item->get_meta( '_refunded_item_id', true ) ); $quantities[ $id ] = ( $quantities[ $id ] ?? 0 ) + absint( $item->get_quantity() ); } }
		foreach ( $quantities as $id => $quantity ) {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET refunded_quantity=LEAST(quantity,%d) WHERE order_id=%d AND item_id=%d", $table, $quantity, $order->get_id(), $id ) );
			if ( false === $result ) { throw new RuntimeException( 'Reward usage refund could not be recorded.' ); }
		}
	}

	/** Index a bounded batch of legacy reward orders before enforcing a newly configured limit. */
	private static function backfill_customer_usage( $customer_id ) {
		$started = RR_Ledger::begin_wallet_transaction( $customer_id );
		if ( is_wp_error( $started ) ) { return $started; }
		try {
			$result = self::backfill_customer_usage_locked( $customer_id );
			$commit = ! is_wp_error( $result ) || 'rr_usage_indexing' === $result->get_error_code();
			$finished = RR_Ledger::finish_wallet_transaction( $commit );
			return is_wp_error( $finished ) ? $finished : $result;
		} catch ( Throwable $error ) { RR_Ledger::finish_wallet_transaction( false ); return new WP_Error( 'rr_usage_exception', __( 'Earlier reward history could not be safely indexed.', 'rewards-referrals' ) ); }
	}

	private static function backfill_customer_usage_locked( $customer_id ) {
		global $wpdb;
		$table = RR_Database::table( 'redemption_items' );
		$redemptions = RR_Database::table( 'redemptions' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.order_id,r.meta,r.created_at FROM $redemptions r WHERE r.customer_id=%d AND r.status IN ('reserved','confirmed','partially_refunded') AND NOT EXISTS (SELECT 1 FROM $table i WHERE i.order_id=r.order_id) ORDER BY r.id ASC LIMIT 100", $customer_id ) );
		if ( $wpdb->last_error || ! is_array( $rows ) ) { return new WP_Error( 'rr_usage_read', __( 'Earlier reward history could not be read safely. Please retry.', 'rewards-referrals' ) ); }
		foreach ( $rows as $row ) {
			$order = wc_get_order( $row->order_id );
			$meta = json_decode( (string) $row->meta, true );
			if ( ! $order || empty( $meta['items'] ) || ! is_array( $meta['items'] ) ) { return new WP_Error( 'rr_usage_legacy', __( 'An earlier reward order needs accounting review before this reward limit can be safely checked.', 'rewards-referrals' ) ); }
			foreach ( $meta['items'] as $item_id => $item_data ) {
				$item = $order->get_item( absint( $item_id ) );
				if ( $item && ! $item instanceof WC_Order_Item_Product ) { return new WP_Error( 'rr_usage_legacy_product', __( 'An earlier reward product could not be identified for limit accounting.', 'rewards-referrals' ) ); }
				$ids = $meta['products'][ $item_id ] ?? array();
				if ( ! $ids && $item ) { $ids = array( 'product_id' => $item->get_variation_id() ?: $item->get_product_id(), 'parent_id' => $item->get_variation_id() ? $item->get_product_id() : 0 ); }
				if ( empty( $ids['product_id'] ) ) { return new WP_Error( 'rr_usage_legacy_product', __( 'An earlier reward product could not be identified for limit accounting.', 'rewards-referrals' ) ); }
				$policy_scope = $ids['policy_scope_id'] ?? null;
				if ( null === $policy_scope ) {
					$policy = self::reward_policy( $item ? $item->get_product() : false );
					if ( ! empty( $policy['_error'] ) ) { return new WP_Error( 'rr_product_policy_read', $policy['_error'] ); }
					$policy_scope = $policy['scope_id'] ?? 0;
				}
				$written = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (order_id,customer_id,product_id,parent_id,policy_scope_id,item_id,quantity,refunded_quantity,created_at) VALUES (%d,%d,%d,%d,%d,%d,%d,0,%s) ON DUPLICATE KEY UPDATE order_id=VALUES(order_id)", $table, $row->order_id, $customer_id, absint( $ids['product_id'] ), absint( $ids['parent_id'] ?? 0 ), absint( $policy_scope ), absint( $item_id ), absint( $item_data['quantity'] ?? 0 ), $row->created_at ) );
				if ( false === $written ) { return new WP_Error( 'rr_usage_write', __( 'Earlier reward quantity accounting could not be indexed.', 'rewards-referrals' ) ); }
			}
			self::update_refunded_usage( $order, 0 );
		}
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $redemptions r WHERE r.customer_id=%d AND r.status IN ('reserved','confirmed','partially_refunded') AND NOT EXISTS (SELECT 1 FROM $table i WHERE i.order_id=r.order_id)", $customer_id ) );
		if ( $wpdb->last_error || null === RR_Ledger::validated_integer( $remaining, 0, PHP_INT_MAX ) ) { return new WP_Error( 'rr_usage_read', __( 'Earlier reward history could not be read safely. Please retry.', 'rewards-referrals' ) ); }
		return $remaining ? new WP_Error( 'rr_usage_indexing', __( 'Earlier reward history is being indexed. Please retry checkout shortly.', 'rewards-referrals' ) ) : true;
	}

	public static function product_fields() {
		wp_nonce_field( 'rr_save_product_rewards', '_rr_rewards_nonce' );
		echo '<div class="options_group"><h4 style="padding-left:12px">' . esc_html__( 'Rewards & Referrals', 'rewards-referrals' ) . '</h4>';
		woocommerce_wp_checkbox( array( 'id' => '_br_exclude_rewards', 'label' => __( 'Exclude from purchase rewards', 'rewards-referrals' ), 'description' => __( 'Do not include this product in purchase points calculations.', 'rewards-referrals' ) ) );
		woocommerce_wp_select( array( 'id' => '_rr_reward_mode', 'label' => __( 'Redemption mode', 'rewards-referrals' ), 'description' => __( 'Choose whether this product can be purchased using points.', 'rewards-referrals' ), 'options' => array( 'inherit' => __( 'Use store default', 'rewards-referrals' ), 'disabled' => __( 'Cash purchase only', 'rewards-referrals' ), 'points_only' => __( 'Points only', 'rewards-referrals' ), 'cash_or_points' => __( 'Cash or points (customer choice)', 'rewards-referrals' ), 'points_plus_cash' => __( 'Points plus a cash contribution', 'rewards-referrals' ) ) ) );
		woocommerce_wp_text_input( array( 'id' => '_rr_points_price', 'label' => __( 'Points price', 'rewards-referrals' ), 'type' => 'number', 'custom_attributes' => array( 'min' => '1', 'step' => '1' ), 'description' => __( 'Whole points charged per unit when the customer selects a points reward.', 'rewards-referrals' ) ) );
		woocommerce_wp_text_input( array( 'id' => '_rr_cash_price', 'label' => __( 'Cash contribution per reward item', 'rewards-referrals' ), 'type' => 'number', 'custom_attributes' => array( 'min' => '0', 'step' => '0.01' ), 'description' => __( 'Only for points plus cash. Native WooCommerce taxes and coupons apply to this cash portion.', 'rewards-referrals' ) ) );
		woocommerce_wp_select( array( 'id' => '_rr_earning_mode', 'label' => __( 'Purchase points rate', 'rewards-referrals' ), 'description' => __( 'Product-level override for purchase earning.', 'rewards-referrals' ), 'options' => array( 'inherit' => __( 'Use global rate', 'rewards-referrals' ), 'excluded' => __( 'Do not earn points', 'rewards-referrals' ), 'custom' => __( 'Custom rate', 'rewards-referrals' ), 'fixed' => __( 'Fixed points per item', 'rewards-referrals' ) ) ) );
		woocommerce_wp_text_input( array( 'id' => '_rr_points_per_unit', 'label' => __( 'Custom points per currency unit', 'rewards-referrals' ), 'type' => 'number', 'custom_attributes' => array( 'min' => '0.01', 'step' => '0.01' ) ) );
		woocommerce_wp_text_input( array( 'id' => '_rr_fixed_points', 'label' => __( 'Fixed points per item', 'rewards-referrals' ), 'type' => 'number', 'custom_attributes' => array( 'min' => '0', 'max' => '1000000000', 'step' => '1' ) ) );
		woocommerce_wp_checkbox( array( 'id' => '_rr_exclude_commission', 'label' => __( 'Exclude referral commission', 'rewards-referrals' ), 'description' => __( 'Purchase points can still be earned, but this product does not fund direct referral commission.', 'rewards-referrals' ) ) );
		woocommerce_wp_text_input( array( 'id' => '_rr_commission_rate', 'label' => __( 'Referral percentage override', 'rewards-referrals' ), 'type' => 'number', 'custom_attributes' => array( 'min' => '0', 'max' => '100', 'step' => '0.01' ), 'description' => __( 'Leave empty to use the referral program rate.', 'rewards-referrals' ) ) );
		self::policy_fields();
		echo '</div>';
	}

	public static function save_product_fields( $product ) {
		if ( ! current_user_can( 'edit_product', $product->get_id() ) || ! isset( $_POST['_rr_rewards_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rr_rewards_nonce'] ) ), 'rr_save_product_rewards' ) ) { return; }
		$product->update_meta_data( '_br_exclude_rewards', isset( $_POST['_br_exclude_rewards'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_rr_reward_mode', self::posted_choice( '_rr_reward_mode', array( 'inherit', 'disabled', 'points_only', 'cash_or_points', 'points_plus_cash' ), 'inherit' ) );
		$product->update_meta_data( '_rr_points_price', self::posted_uint( '_rr_points_price', 0, 1000000000 ) );
		$product->update_meta_data( '_rr_cash_price', self::posted_decimal( '_rr_cash_price', 0, 1000000000 ) );
		$product->update_meta_data( '_rr_earning_mode', self::posted_choice( '_rr_earning_mode', array( 'inherit', 'excluded', 'custom', 'fixed' ), 'inherit' ) );
		$product->update_meta_data( '_rr_points_per_unit', self::posted_decimal( '_rr_points_per_unit', 0, 1000000 ) );
		$product->update_meta_data( '_rr_fixed_points', self::posted_uint( '_rr_fixed_points', 0, 1000000000 ) );
		$product->update_meta_data( '_rr_exclude_commission', isset( $_POST['_rr_exclude_commission'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_rr_commission_rate', self::posted_decimal( '_rr_commission_rate', 0, 100 ) );
		self::save_policy_fields( $product );
	}

	private static function posted_choice( $key, $allowed, $fallback ) {
		$value = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function posted_uint( $key, $min, $max ) {
		$value = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		return preg_match( '/^\d+$/', (string) $value ) ? min( $max, max( $min, absint( $value ) ) ) : $min;
	}

	private static function posted_decimal( $key, $min, $max ) {
		$value = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? wc_format_decimal( wp_unslash( $_POST[ $key ] ), 6 ) : '';
		if ( '' === $value || ! is_numeric( $value ) ) { return ''; }
		return (string) max( $min, min( $max, (float) $value ) );
	}

	public static function variation_fields( $loop, $variation_data, $variation ) {
		$variation = is_a( $variation, 'WC_Product' ) ? $variation : wc_get_product( $variation->ID ?? 0 );
		if ( ! $variation ) { return; }
		echo '<input type="hidden" name="_rr_variation_rewards_nonce[' . esc_attr( $loop ) . ']" value="' . esc_attr( wp_create_nonce( 'rr_save_product_rewards' ) ) . '">';
		foreach ( array( '_rr_reward_mode' => array( 'label' => __( 'Reward mode', 'rewards-referrals' ), 'options' => array( 'inherit' => __( 'Inherit parent', 'rewards-referrals' ), 'disabled' => __( 'Cash only', 'rewards-referrals' ), 'points_only' => __( 'Points only', 'rewards-referrals' ), 'cash_or_points' => __( 'Cash or points', 'rewards-referrals' ), 'points_plus_cash' => __( 'Points plus cash', 'rewards-referrals' ) ) ), '_rr_earning_mode' => array( 'label' => __( 'Purchase earning', 'rewards-referrals' ), 'options' => array( 'inherit' => __( 'Inherit parent/global', 'rewards-referrals' ), 'excluded' => __( 'Exclude', 'rewards-referrals' ), 'custom' => __( 'Custom rate', 'rewards-referrals' ), 'fixed' => __( 'Fixed points per item', 'rewards-referrals' ) ) ) ) as $key => $field ) {
			woocommerce_wp_select( array( 'id' => '_rr_variation' . $key . '[' . $loop . ']', 'name' => '_rr_variation' . $key . '[' . $loop . ']', 'value' => $variation->get_meta( $key ), 'label' => $field['label'], 'options' => $field['options'] ) );
		}
		foreach ( array( '_rr_points_price' => __( 'Points price', 'rewards-referrals' ), '_rr_points_per_unit' => __( 'Custom earning rate', 'rewards-referrals' ), '_rr_fixed_points' => __( 'Fixed points per item', 'rewards-referrals' ), '_rr_commission_rate' => __( 'Referral percentage override', 'rewards-referrals' ), '_rr_cash_price' => __( 'Cash contribution per reward item', 'rewards-referrals' ) ) as $key => $label ) {
			woocommerce_wp_text_input( array( 'id' => '_rr_variation' . $key . '[' . $loop . ']', 'name' => '_rr_variation' . $key . '[' . $loop . ']', 'value' => $variation->get_meta( $key ), 'label' => $label, 'type' => 'number', 'custom_attributes' => array( 'min' => '0', 'step' => in_array( $key, array( '_rr_points_per_unit', '_rr_commission_rate', '_rr_cash_price' ), true ) ? '0.01' : '1' ) ) );
		}
		self::policy_fields( $variation, $loop );
	}

	public static function save_variation_fields( $variation_id, $loop ) {
		$nonce = isset( $_POST['_rr_variation_rewards_nonce'][ $loop ] ) && is_scalar( $_POST['_rr_variation_rewards_nonce'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_rr_variation_rewards_nonce'][ $loop ] ) ) : '';
		if ( ! current_user_can( 'edit_product', $variation_id ) || ! wp_verify_nonce( $nonce, 'rr_save_product_rewards' ) ) { return; }
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) { return; }
		foreach ( array( '_rr_reward_mode' => array( 'inherit', 'disabled', 'points_only', 'cash_or_points', 'points_plus_cash' ), '_rr_earning_mode' => array( 'inherit', 'excluded', 'custom', 'fixed' ) ) as $key => $allowed ) {
			$post_key = '_rr_variation' . $key;
			$value = isset( $_POST[ $post_key ][ $loop ] ) && is_scalar( $_POST[ $post_key ][ $loop ] ) ? sanitize_key( wp_unslash( $_POST[ $post_key ][ $loop ] ) ) : 'inherit';
			$variation->update_meta_data( $key, in_array( $value, $allowed, true ) ? $value : 'inherit' );
		}
		foreach ( array( '_rr_points_price' => 1000000000, '_rr_fixed_points' => 1000000000, '_rr_points_per_unit' => 1000000, '_rr_commission_rate' => 100, '_rr_cash_price' => 1000000000 ) as $key => $max ) {
			$post_key = '_rr_variation' . $key;
			$raw = isset( $_POST[ $post_key ][ $loop ] ) && is_scalar( $_POST[ $post_key ][ $loop ] ) ? wp_unslash( $_POST[ $post_key ][ $loop ] ) : '';
			$integer = in_array( $key, array( '_rr_points_price', '_rr_fixed_points' ), true );
			$value = $integer ? ( preg_match( '/^\d{1,10}$/', (string) $raw ) ? min( $max, absint( $raw ) ) : 0 ) : ( is_numeric( $raw ) ? wc_format_decimal( max( 0, min( $max, (float) $raw ) ), 6 ) : '' );
			$variation->update_meta_data( $key, $value );
		}
		self::save_policy_fields( $variation, $loop );
		$variation->save();
	}

	public static function reward_mode( $product ) {
		$result = RR_Rules::with_snapshot( static function () use ( $product ) { return self::reward_mode_verified( $product ); } );
		return is_wp_error( $result ) ? 'disabled' : $result;
	}

	private static function reward_mode_verified( $product ) {
		if ( ! $product || 'yes' !== RR_Rules::get()['redemption_enabled'] || ! $product->is_type( array( 'simple', 'variation', 'variable' ) ) ) { return 'disabled'; }
		$product = RR_Rules::policy_product( $product );
		if ( is_wp_error( $product ) ) { return 'disabled'; }
		$mode = $product->get_meta( '_rr_reward_mode' );
		if ( 'inherit' === $mode || ! in_array( $mode, array( 'disabled', 'points_only', 'cash_or_points', 'points_plus_cash' ), true ) ) {
			$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : false;
			$mode = $parent ? self::reward_mode( $parent ) : ( RR_Rules::get()['reward_default_mode'] ?? 'disabled' );
		}
		return in_array( $mode, array( 'disabled', 'points_only', 'cash_or_points', 'points_plus_cash' ), true ) ? $mode : 'disabled';
	}

	public static function points_price( $product ) {
		if ( ! $product ) { return 0; }
		$product = RR_Rules::policy_product( $product );
		if ( is_wp_error( $product ) ) { return 0; }
		$raw = $product->get_meta( '_rr_points_price' );
		if ( ! is_scalar( $raw ) || ! preg_match( '/^\d{0,10}$/D', (string) $raw ) ) { return 0; }
		$price = (int) $raw;
		if ( 0 === $price && $product->get_parent_id() ) { return self::points_price( wc_get_product( $product->get_parent_id() ) ); }
		return $price <= 1000000000 ? $price : 0;
	}

	public static function cash_price( $product ) {
		if ( ! $product ) { return '0'; }
		$product = RR_Rules::policy_product( $product );
		if ( is_wp_error( $product ) ) { return '0'; }
		$price = $product->get_meta( '_rr_cash_price' );
		if ( '' === $price && $product->get_parent_id() ) { return self::cash_price( wc_get_product( $product->get_parent_id() ) ); }
		return is_numeric( $price ) && $price > 0 ? wc_format_decimal( min( 1000000000, (float) $price ), wc_get_price_decimals() ) : '0';
	}

	public static function variation_reward_data( $data, $parent, $variation ) {
		$data['rr_reward_mode'] = self::reward_mode( $variation );
		$data['rr_points_price'] = self::points_price( $variation );
		$data['rr_cash_price'] = self::cash_price( $variation );
		$data['rr_cash_label'] = wp_strip_all_tags( wc_price( self::cash_price( $variation ) ) );
		return $data;
	}

	public static function reward_price_html( $html, $product ) {
		if ( is_admin() || 'points_only' !== self::reward_mode( $product ) || ! self::points_price( $product ) ) { return $html; }
		/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
		return '<span class="rr-points-price">' . esc_html( sprintf( __( '%s points', 'rewards-referrals' ), number_format_i18n( self::points_price( $product ) ) ) ) . '</span>';
	}

	public static function frontend_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
		wp_enqueue_style( 'rr-product', REWARDS_REFERRALS_URL . 'assets/product.css', array(), REWARDS_REFERRALS_VERSION );
		wp_enqueue_script( 'rr-product', REWARDS_REFERRALS_URL . 'assets/product.js', array( 'jquery' ), REWARDS_REFERRALS_VERSION, true );
		wp_localize_script( 'rr-product', 'RRProductConfig', array(
			/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
			'pointsOnly' => __( 'This reward costs %s points.', 'rewards-referrals' ),
			/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
			'cashOrPoints' => __( 'Use %s points for this item instead of paying cash', 'rewards-referrals' ),
			/* translators: %cash is the formatted cash contribution; %points is the number of points. */
			'pointsPlusCash' => __( 'Pay %cash plus %points points per item.', 'rewards-referrals' ),
		) );
	}

	public static function render_redemption_choice() {
		global $product;
		if ( ! $product ) { return; }
		$mode = self::reward_mode( $product );
		$price = self::points_price( $product );
		if ( 'disabled' === $mode && ! $product->is_type( 'variable' ) ) { return; }
		if ( in_array( $mode, array( 'points_only', 'cash_or_points', 'points_plus_cash' ), true ) || $product->is_type( 'variable' ) ) {
			/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
			$label = 'points_only' === $mode ? sprintf( __( 'This reward costs %s points.', 'rewards-referrals' ), number_format_i18n( $price ) ) : sprintf( __( 'Use %s points for this item instead of paying cash', 'rewards-referrals' ), number_format_i18n( $price ) );
			/* translators: %cash is the formatted cash contribution; %points is the number of points. */
			if ( 'points_plus_cash' === $mode ) { $label = str_replace( array( '%cash', '%points' ), array( wp_strip_all_tags( wc_price( self::cash_price( $product ) ) ), number_format_i18n( $price ) ), __( 'Pay %cash plus %points points per item.', 'rewards-referrals' ) ); }
			echo '<label class="rr-product-redeem-choice" data-rr-product-reward data-mode="' . esc_attr( $mode ) . '" data-default-mode="' . esc_attr( $mode ) . '" data-variable="' . esc_attr( $product->is_type( 'variable' ) ? '1' : '0' ) . '" data-cash="' . esc_attr( wp_strip_all_tags( wc_price( self::cash_price( $product ) ) ) ) . '" data-points="' . esc_attr( $price ) . '"><input type="checkbox" name="rr_redeem_with_points" value="1" ' . checked( 'points_only' === $mode, true, false ) . '><span data-rr-points-label>' . esc_html( $label ) . '</span></label>';
			/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
			if ( is_user_logged_in() ) { echo '<p class="rr-product-balance">' . esc_html( sprintf( __( 'Your wallet: %s available points. Shipping and any applicable taxes remain payable in cash.', 'rewards-referrals' ), number_format_i18n( RR_Ledger::balances( get_current_user_id() )['available'] ) ) ) . '</p>'; }
			else { echo '<p class="rr-product-balance"><a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'Sign in to redeem points', 'rewards-referrals' ) . '</a></p>'; }
		}
	}

	private static function requested_points() {
		return isset( $_REQUEST['rr_redeem_with_points'] ) && is_scalar( $_REQUEST['rr_redeem_with_points'] ) && '1' === (string) wp_unslash( $_REQUEST['rr_redeem_with_points'] );
	}

	/** Always load the persisted product; cart/session point values are display hints only. */
	public static function cart_item_reward( $item ) {
		$result = RR_Rules::with_snapshot( static function () use ( $item ) { return self::cart_item_reward_verified( $item ); } );
		if ( is_wp_error( $result ) ) {
			return array( 'selected' => ! empty( $item['rr_redeem_with_points'] ), 'mode' => 'disabled', 'product' => false, 'each' => 0, 'cash_each' => '0', 'points' => 0, 'error' => __( 'Rewards policy is temporarily unavailable. Please retry before placing your order.', 'rewards-referrals' ) );
		}
		return $result;
	}

	private static function cart_item_reward_verified( $item ) {
		$product_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : absint( $item['product_id'] ?? 0 );
		$product = $product_id ? wc_get_product( $product_id ) : false;
		$mode = self::reward_mode( $product );
		$requested = ! empty( $item['rr_redeem_with_points'] );
		$selected = in_array( $mode, array( 'points_only', 'points_plus_cash' ), true ) || ( 'cash_or_points' === $mode && $requested );
		$quantity = $item['quantity'] ?? 0;
		$each = $selected ? self::points_price( $product ) : 0;
		$cash_each = $selected && 'points_plus_cash' === $mode ? self::cash_price( $product ) : '0';
		$error = '';
		if ( $requested && 'disabled' === $mode ) { $error = __( 'A selected reward is no longer available for points. Remove it and choose a current reward.', 'rewards-referrals' ); }
		if ( $selected && 'points_plus_cash' === $mode && (float) $cash_each <= 0 ) { $error = __( 'This reward has an invalid cash contribution. Please contact the store.', 'rewards-referrals' ); }
		if ( $selected && ( ! $each || ! is_numeric( $quantity ) || (float) $quantity !== (float) absint( $quantity ) || $quantity < 1 || $quantity > 1000000 ) ) { $error = __( 'A reward has an invalid points price or quantity.', 'rewards-referrals' ); }
		if ( $selected && ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->has_enough_stock( $quantity ) ) ) { $error = __( 'A selected reward is not available in the requested quantity.', 'rewards-referrals' ); }
		if ( $selected && ! $error ) { $error = self::reward_policy_error( $product, isset( $item['customer_id'] ) ? absint( $item['customer_id'] ) : get_current_user_id(), absint( $quantity ), 0, false ); }
		return array( 'selected' => $selected, 'mode' => $mode, 'product' => $product, 'each' => $each, 'cash_each' => $cash_each, 'points' => $selected && ! $error ? $each * absint( $quantity ) : 0, 'error' => $error );
	}

	public static function cart_required_points( $cart = null ) {
		$cart = $cart ?: ( WC()->cart ?? null );
		$total = 0;
		if ( $cart ) { foreach ( $cart->get_cart() as $item ) { $total += self::cart_item_reward( $item )['points']; } }
		return $total;
	}

	private static function cart_scope_quantity( $product ) {
		$policy = self::reward_policy( $product );
		if ( ! empty( $policy['_error'] ) ) { return PHP_INT_MAX; }
		if ( ! $policy || ! WC()->cart ) { return 0; }
		$total = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$reward = self::cart_item_reward( $item );
			$other = self::reward_policy( $reward['product'] );
			if ( ! empty( $reward['error'] ) || ! empty( $other['_error'] ) ) { return PHP_INT_MAX; }
			if ( $reward['selected'] && $other && $other['scope_id'] === $policy['scope_id'] ) { $total += absint( $item['quantity'] ); }
		}
		return $total;
	}

	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		$reward = self::cart_item_reward( array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => $quantity, 'rr_redeem_with_points' => self::requested_points() ) );
		if ( $reward['error'] ) { wc_add_notice( $reward['error'], 'error' ); return false; }
		if ( ! $reward['selected'] ) { return $passed; }
		if ( ! is_user_logged_in() ) { wc_add_notice( __( 'Sign in to redeem rewards with points.', 'rewards-referrals' ), 'error' ); return false; }
		$policy_error = self::reward_policy_error( $reward['product'], get_current_user_id(), self::cart_scope_quantity( $reward['product'] ) + absint( $quantity ) );
		if ( $policy_error ) { wc_add_notice( $policy_error, 'error' ); return false; }
		$required = self::cart_required_points() + $reward['points'];
		if ( RR_Ledger::balances( get_current_user_id() )['available'] < $required ) { wc_add_notice( __( 'You do not have enough available points for the reward items in your cart.', 'rewards-referrals' ), 'error' ); return false; }
		return $passed;
	}

	public static function store_api_cart_data( $data, $request ) {
		$extensions = $request->get_param( 'extensions' );
		$value = is_array( $extensions ) ? ( $extensions['rewards-referrals']['redeem_with_points'] ?? false ) : false;
		// Preserve intent before WooCommerce resolves parent + attributes to a child.
		// Prices, product eligibility and balance are validated against that child below.
		if ( in_array( $value, array( true, 1, '1' ), true ) ) { $data['cart_item_data']['rr_redeem_with_points'] = true; }
		return $data;
	}

	/** Validate the product AFTER WooCommerce resolves and sanitizes variation selection. */
	public static function validate_store_api_reward( $product, $request ) {
		$selected = ! empty( $request['cart_item_data']['rr_redeem_with_points'] );
		$reward = self::cart_item_reward( array( 'product_id' => $product->get_parent_id() ?: $product->get_id(), 'variation_id' => $product->get_parent_id() ? $product->get_id() : 0, 'quantity' => $request['quantity'] ?? 1, 'rr_redeem_with_points' => $selected ) );
		if ( $reward['error'] ) { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rr_reward_unavailable', esc_html( $reward['error'] ), 400 ); }
		if ( ! $reward['selected'] ) { return; }
		if ( ! is_user_logged_in() ) { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rr_login_required', esc_html__( 'Sign in to redeem rewards with points.', 'rewards-referrals' ), 403 ); }
		$policy_error = self::reward_policy_error( $product, get_current_user_id(), self::cart_scope_quantity( $product ) + absint( $request['quantity'] ?? 1 ) );
		if ( $policy_error ) { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rr_reward_limit', esc_html( $policy_error ), 400 ); }
		if ( RR_Ledger::balances( get_current_user_id() )['available'] < self::cart_required_points() + $reward['points'] ) { throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rr_reward_unavailable', esc_html__( 'There are not enough available points for this reward.', 'rewards-referrals' ), 400 ); }
	}

	public static function add_cart_redemption_data( $data, $product_id, $variation_id, $quantity ) {
		// Seed this before a Store API checkout can be submitted concurrently.
		self::checkout_generation();
		$product = wc_get_product( $variation_id ?: $product_id );
		$mode = self::reward_mode( $product );
		// Ignore arbitrary point prices supplied through custom cart-item data.
		unset( $data['rr_points_each'] );
		if ( in_array( $mode, array( 'points_only', 'points_plus_cash' ), true ) || ( 'cash_or_points' === $mode && ( ! empty( $data['rr_redeem_with_points'] ) || self::requested_points() ) ) ) {
			$data['rr_redeem_with_points'] = true;
			$data['rr_points_each'] = self::points_price( $product );
		} else { unset( $data['rr_redeem_with_points'] ); }
		return $data;
	}

	public static function set_reward_product_prices( $cart ) {
		if ( ! $cart ) { return; }
		foreach ( $cart->get_cart() as $key => $item ) {
			$reward = self::cart_item_reward( $item );
			if ( ! isset( $item['data'] ) || ! $reward['product'] ) { continue; }
			if ( $reward['selected'] && ! $reward['error'] ) {
				$cart->cart_contents[ $key ]['rr_points_each'] = $reward['each'];
				$cart->cart_contents[ $key ]['rr_redeem_with_points'] = true;
				$cart->cart_contents[ $key ]['rr_price_applied'] = true;
				$item['data']->set_price( $reward['cash_each'] );
			} elseif ( ! empty( $item['rr_price_applied'] ) ) {
				$item['data']->set_price( $reward['product']->get_price() );
				unset( $cart->cart_contents[ $key ]['rr_price_applied'] );
			}
		}
	}

	public static function display_cart_redemption_data( $data, $item ) {
		$reward = self::cart_item_reward( $item );
		/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
		if ( $reward['selected'] && ! $reward['error'] ) { $data[] = array( 'key' => __( 'Rewards payment', 'rewards-referrals' ), 'value' => sprintf( __( '%s points per item', 'rewards-referrals' ), number_format_i18n( $reward['each'] ) ) ); }
		return $data;
	}

	public static function validate_cart_redemption() {
		if ( ! WC()->cart ) { return; }
		$required = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$reward = self::cart_item_reward( $item );
			if ( $reward['error'] ) { wc_add_notice( $reward['error'], 'error' ); }
			if ( $reward['selected'] && $reward['product'] ) { $error = self::reward_policy_error( $reward['product'], get_current_user_id(), self::cart_scope_quantity( $reward['product'] ) ); if ( $error ) { wc_add_notice( $error, 'error' ); } }
			$required += $reward['points'];
		}
		if ( $required && ( ! is_user_logged_in() || RR_Ledger::balances( get_current_user_id() )['available'] < $required ) ) { wc_add_notice( __( 'Your available points are not sufficient for the reward items in your cart.', 'rewards-referrals' ), 'error' ); }
	}

	public static function save_order_item_redemption_data( $item, $cart_item_key, $values, $order ) {
		$reward = self::cart_item_reward( $values );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Accounting exceptions are caught internally; WooCommerce serializes route errors and escapes checkout notices at the output boundary.
		if ( $reward['error'] ) { throw new Exception( $reward['error'] ); }
		if ( $reward['selected'] ) {
			$item->add_meta_data( '_rr_rewards_points_each', $reward['each'], true );
			$item->add_meta_data( '_rr_rewards_mode', $reward['mode'], true );
			$item->add_meta_data( '_rr_rewards_cash_each', $reward['cash_each'], true );
		}
	}

	public static function cart_reward_summary() {
		if ( ! WC()->cart || ! is_user_logged_in() ) { return; }
		$required = self::cart_required_points();
		$available = RR_Ledger::balances( get_current_user_id() )['available'];
		/* translators: 1: Available wallet points. 2: Points used by this cart. */
		echo '<tr class="rr-cart-reward-summary"><th>' . esc_html__( 'Your rewards', 'rewards-referrals' ) . '</th><td>' . esc_html( sprintf( __( '%1$s points available · %2$s points applied', 'rewards-referrals' ), number_format_i18n( $available ), number_format_i18n( $required ) ) ) . '<small style="display:block">' . esc_html__( 'Shipping, taxes, and cash items are charged through the selected payment method.', 'rewards-referrals' ) . '</small></td></tr>';
	}

	public static function reserve_checkout_redemption( $order_id, $posted_data, $order ) {
		self::reserve_order_points( $order );
		if ( $order instanceof WC_Order && absint( $order->get_meta( '_rr_points_reserved' ) ) ) {
			add_filter( 'woocommerce_checkout_no_payment_needed_redirect', static function ( $url ) use ( $order ) {
				$completed = wc_get_order( $order->get_id() );
				if ( $completed && $completed->is_paid() && 0.0 === (float) $completed->get_total() && WC()->cart ) {
					// Classic checkout calls wc_empty_cart(false), retaining a saved cart
					// until the thank-you page. Remove the consumed reward cart now so
					// another request cannot restore it before that redirect completes.
					WC()->cart->empty_cart( true );
					self::rotate_checkout_generation();
				}
				return $url;
			} );
		}
	}

	public static function reserve_store_api_redemption( $order ) {
		try { self::reserve_order_points( $order ); }
		catch ( Throwable $error ) {
			// Store API expects RouteException for a customer-actionable conflict.
			// A generic Exception is surfaced as an unknown HTTP 500 error.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce JSON-encodes RouteException messages; HTML escaping here would double-encode customer notices.
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rr_redemption_conflict', sanitize_text_field( $error->getMessage() ), 409 );
		}
	}

	public static function clear_completed_store_cart( $order ) {
		// The Store API does not consistently clear a zero-total cart before a
		// follow-up request. Retaining it would let a later hybrid checkout carry
		// an already-completed reward line. Cash orders retain their cart until
		// WooCommerce finishes payment; only no-payment orders are cleared here.
		if ( $order && absint( $order->get_meta( '_rr_points_reserved' ) ) && ! $order->needs_payment() && function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
			self::rotate_checkout_generation();
		}
	}

	/** A Store API/classic checkout retry must not create a second order that spends the same cart twice. */
	private static function checkout_generation( $rotate = false ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) { return ''; }
		$generation = $rotate ? '' : (string) WC()->session->get( 'rr_checkout_generation' );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $generation ) ) { $generation = wp_generate_uuid4(); WC()->session->set( 'rr_checkout_generation', $generation ); }
		return $generation;
	}

	/** Start every materially new cart with a fresh idempotency generation. */
	public static function rotate_checkout_generation() { self::checkout_generation( true ); }

	private static function checkout_key( $customer_id ) {
		$is_rest_checkout = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$is_classic_checkout = wp_doing_ajax() && ( ( isset( $_REQUEST['wc-ajax'] ) && 'checkout' === sanitize_key( wp_unslash( $_REQUEST['wc-ajax'] ) ) ) || ( isset( $_REQUEST['action'] ) && 'woocommerce_checkout' === sanitize_key( wp_unslash( $_REQUEST['action'] ) ) ) );
		if ( ! $is_rest_checkout && ! $is_classic_checkout || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) { return ''; }
		$generation = self::checkout_generation();
		return $generation ? hash_hmac( 'sha256', absint( $customer_id ) . '|' . $generation, wp_salt( 'auth' ) ) : '';
	}

	private static function reserve_order_points( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { return; }
		global $wpdb;
		$customer_id = (int) $order->get_customer_id();
		$has_reward = false;
		$needs_usage_index = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item WooCommerce line_item factory subtype. */
			if ( ! absint( $item->get_meta( '_rr_rewards_points_each', true ) ) ) { continue; }
			$has_reward = true;
			$policy = self::reward_policy( $item->get_product() );
			if ( ! empty( $policy['_error'] ) ) { throw new Exception( esc_html( $policy['_error'] ) ); }
			if ( $policy && ( $policy['limit_customer'] || $policy['limit_daily'] || $policy['limit_monthly'] ) ) { $needs_usage_index = true; }
		}
		if ( ! $has_reward ) { return; }
		$storage = RR_Database::verify_order_storage(); if ( is_wp_error( $storage ) ) { throw new Exception( esc_html( $storage->get_error_message() ) ); }
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Accounting exceptions are caught internally; WooCommerce serializes route errors and escapes checkout notices at the output boundary.
		if ( $needs_usage_index ) { $indexed = self::backfill_customer_usage( $customer_id ); if ( is_wp_error( $indexed ) ) { throw new Exception( $indexed->get_error_message() ); } }
		$started = RR_Ledger::begin_wallet_transaction( $customer_id );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Accounting exceptions are caught internally; WooCommerce serializes route errors and escapes checkout notices at the output boundary.
		if ( is_wp_error( $started ) ) { throw new Exception( $started->get_error_message() ); }
		$open = true;
		try {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT status,points,meta FROM %i WHERE order_id=%d FOR UPDATE', RR_Database::table( 'redemptions' ), $order->get_id() ) );
			$points = 0; $items_meta = array(); $products_meta = array(); $scopes = array();
			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				/** @var WC_Order_Item_Product $item WooCommerce line_item factory subtype. */
				$each = absint( $item->get_meta( '_rr_rewards_points_each', true ) );
				if ( ! $each ) { continue; }
				$quantity = $item->get_quantity();
				if ( (float) $quantity !== (float) absint( $quantity ) || $quantity < 1 || $quantity > 1000000 ) { throw new Exception( __( 'Reward quantities must be positive whole numbers.', 'rewards-referrals' ) ); }
				$quantity = absint( $quantity );
				$product = $item->get_product();
				if ( ! $existing ) {
					$reward = self::cart_item_reward( array( 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'quantity' => $quantity, 'rr_redeem_with_points' => true, 'customer_id' => $customer_id ) );
					if ( $reward['error'] || ! $reward['selected'] || $reward['each'] !== $each ) { throw new Exception( $reward['error'] ?: __( 'A reward changed or is unavailable. Refresh your cart before trying again.', 'rewards-referrals' ) ); }
					$saved_mode = (string) $item->get_meta( '_rr_rewards_mode', true );
					if ( $saved_mode && $saved_mode !== $reward['mode'] ) { throw new Exception( __( 'The reward purchase mode changed. Refresh your cart before trying again.', 'rewards-referrals' ) ); }
					$expected_subtotal = 'points_plus_cash' === $reward['mode'] ? RR_Rules::to_scaled_integer( $reward['cash_each'], wc_get_price_decimals() ) * $quantity : 0;
					$actual_subtotal = RR_Rules::to_scaled_integer( $item->get_subtotal(), wc_get_price_decimals() );
					$actual_total = RR_Rules::to_scaled_integer( $item->get_total(), wc_get_price_decimals() );
					if ( $expected_subtotal !== $actual_subtotal || $actual_total < 0 || $actual_total > $actual_subtotal ) { throw new Exception( __( 'The reward cash contribution changed. Refresh your cart before trying again.', 'rewards-referrals' ) ); }
				}
				if ( ! $product ) { throw new Exception( __( 'A reward product is unavailable.', 'rewards-referrals' ) ); }
				$points += $each * $quantity;
				$items_meta[ (string) absint( $item_id ) ] = array( 'points_each' => $each, 'quantity' => $quantity );
				$products_meta[ (string) absint( $item_id ) ] = array( 'product_id' => $product->get_id(), 'parent_id' => $product->get_parent_id(), 'quantity' => $quantity );
				$policy = self::reward_policy( $product );
				if ( ! empty( $policy['_error'] ) ) { throw new Exception( esc_html( $policy['_error'] ) ); }
				if ( $policy ) { $scope_id = $policy['scope_id']; $scopes[ $scope_id ] = array( 'product' => $product, 'quantity' => ( $scopes[ $scope_id ]['quantity'] ?? 0 ) + $quantity ); }
			}
			if ( ! $existing && $scopes ) {
				foreach ( $scopes as $scope ) { $error = self::reward_policy_error( $scope['product'], $customer_id, $scope['quantity'], $order->get_id() ); if ( $error ) { throw new Exception( $error ); } }
			}
			$meta = array( 'items' => $items_meta, 'products' => $products_meta );
			$checkout_key = self::checkout_key( $customer_id );
			if ( $checkout_key ) { $meta['checkout_key'] = $checkout_key; $order->update_meta_data( '_rr_checkout_key', $checkout_key ); }
			if ( $existing ) {
				$previous_meta = json_decode( (string) $existing->meta, true );
				if ( ! is_array( $previous_meta ) || ( $previous_meta['items'] ?? array() ) != $items_meta || ( isset( $previous_meta['products'] ) && $previous_meta['products'] != $products_meta ) ) { throw new Exception( __( 'The reserved reward items changed. Please contact the store for accounting review.', 'rewards-referrals' ) ); }
				$meta = $previous_meta;
				if ( $checkout_key && empty( $meta['checkout_key'] ) ) { $meta['checkout_key'] = $checkout_key; }
			}
			$order->update_meta_data( '_br_reward_order', 'yes' ); $order->update_meta_data( '_rr_points_reserved', $points ); $order->save();
			$result = RR_Ledger::reserve_redemption( $customer_id, $order->get_id(), $points, $meta );
			if ( is_wp_error( $result ) ) { throw new Exception( $result->get_error_message() ); }
			$table = RR_Database::table( 'redemption_items' );
			foreach ( $products_meta as $item_id => $product_data ) {
				$policy = self::reward_policy( wc_get_product( $product_data['product_id'] ) );
				if ( ! empty( $policy['_error'] ) ) { throw new Exception( esc_html( $policy['_error'] ) ); }
				$written = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (order_id,customer_id,product_id,parent_id,policy_scope_id,item_id,quantity,refunded_quantity,created_at) VALUES (%d,%d,%d,%d,%d,%d,%d,0,%s) ON DUPLICATE KEY UPDATE order_id=VALUES(order_id)", $table, $order->get_id(), $customer_id, $product_data['product_id'], $product_data['parent_id'], absint( $policy['scope_id'] ?? 0 ), absint( $item_id ), $product_data['quantity'], current_time( 'mysql', true ) ) );
				if ( false === $written ) { throw new Exception( __( 'Reward quantity accounting could not be saved.', 'rewards-referrals' ) ); }
			}
			$committed = RR_Ledger::finish_wallet_transaction( true ); $open = false;
			if ( is_wp_error( $committed ) ) { throw new Exception( $committed->get_error_message() ); }
			/* translators: %s or %d is the localized number of points, orders, or days described in this message. */
			if ( ! $existing ) { $order->add_order_note( sprintf( __( '%s points reserved for reward redemption.', 'rewards-referrals' ), number_format_i18n( $points ) ) ); }
		} catch ( Throwable $error ) {
			if ( $open ) { RR_Ledger::finish_wallet_transaction( false ); }
			$current = self::fresh_order( $order->get_id(), $order );
			$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d', RR_Database::table( 'redemptions' ), $order->get_id() ) );
			if ( $current && ! $current->is_paid() && ! in_array( $status, array( 'confirmed', 'partially_refunded', 'refunded', 'cancelled' ), true ) ) {
				$current->update_status( 'failed', __( 'Reward redemption could not be reserved: ', 'rewards-referrals' ) . $error->getMessage() );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Accounting exceptions are caught internally; WooCommerce serializes route errors and escapes checkout notices at the output boundary.
			throw new Exception( $error->getMessage() );
		}
		if ( function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( time() + 1800, 'rr_expire_redemption_reservation', array( $order->get_id() ), 'rewards-referrals', true ); }
		else { wp_schedule_single_event( time() + 1800, 'rr_expire_redemption_reservation', array( $order->get_id() ) ); }
	}

	private static function latest_order_refund( $order_id ) {
		global $wpdb;
		$rows = wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => absint( $order_id ), 'limit' => 1, 'orderby' => 'ID', 'order' => 'DESC' ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Existing WooCommerce refunds could not be read.' ); }
		return $rows ? reset( $rows ) : false;
	}
	private static function retryable_accounting_error( $result ) {
		return is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'rr_wallet_rebuild_pending', 'rr_legacy_lot_maintenance', 'rr_wallet_lock', 'rr_wallet_commit', 'rr_wallet_transaction', 'rr_order_storage' ), true );
	}
	public static function confirm_order_redemption( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return false; }
		return RR_Recovery::run( array( RR_Recovery::buyer( $order ) ), static function () use ( $order_id ) { return self::confirm_order_redemption_inner( $order_id ); }, array( RR_Recovery::key( 'confirm', $order_id ) ) );
	}
	private static function confirm_order_redemption_inner( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! absint( $order->get_meta( '_rr_points_reserved' ) ) ) { return true; }
		global $wpdb;
		$reservation = $wpdb->get_row( $wpdb->prepare( 'SELECT status,meta FROM %i WHERE order_id=%d', RR_Database::table( 'redemptions' ), $order_id ) );
		if ( $reservation && in_array( $reservation->status, array( 'confirmed', 'partially_refunded', 'refunded' ), true ) ) { return true; }
		$meta = $reservation ? json_decode( (string) $reservation->meta, true ) : array();
		$actual_items = array(); $actual_products = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			/** @var WC_Order_Item_Product $item WooCommerce line_item factory subtype. */
			$each = absint( $item->get_meta( '_rr_rewards_points_each', true ) ); if ( $each ) { $actual_items[ (string) $item_id ] = array( 'points_each' => $each, 'quantity' => absint( $item->get_quantity() ) ); $actual_products[ (string) $item_id ] = array( 'product_id' => $item->get_variation_id() ?: $item->get_product_id(), 'parent_id' => $item->get_variation_id() ? $item->get_product_id() : 0, 'quantity' => absint( $item->get_quantity() ) ); } }
		if ( ! $reservation || ( $meta['items'] ?? array() ) != $actual_items || ( isset( $meta['products'] ) && $meta['products'] != $actual_products ) ) { $order->delete_meta_data( '_rr_confirmation_waiting' ); $order->update_status( 'on-hold', __( 'Reward items changed after reservation; fulfillment is paused for accounting review.', 'rewards-referrals' ) ); return false; }
		$started = RR_Database::verify_order_storage();
		if ( ! is_wp_error( $started ) ) { $started = RR_Ledger::begin_wallet_transaction( RR_Recovery::buyer( $order ) ); }
		$result = $started;
		if ( ! is_wp_error( $started ) ) {
			try {
				$order = self::recovery_order_locked( $order_id );
				if ( is_wp_error( $order ) ) { throw new RuntimeException( 'Confirmation order could not be re-read.' ); }
				if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) || 'yes' === $order->get_meta( '_rr_rewards_cancelled' ) ) { RR_Ledger::finish_wallet_transaction( true ); return false; }
				$result = RR_Ledger::confirm_redemption( $order_id );
				if ( ! is_wp_error( $result ) ) {
					$refund = self::latest_order_refund( $order_id );
					if ( $refund ) { $result = RR_Ledger::restore_redemption_refund( $order_id, $refund->get_id() ); self::update_refunded_usage( $order, $refund->get_id() ); }
				}
				$finished = RR_Ledger::finish_wallet_transaction( ! is_wp_error( $result ) );
				if ( is_wp_error( $finished ) ) { $result = $finished; }
			} catch ( Throwable $error ) { RR_Ledger::finish_wallet_transaction( false ); $result = new WP_Error( 'rr_confirmation_review' ); }
		}
		if ( is_wp_error( $result ) ) {
			self::pause_order_confirmation( $order_id, self::retryable_accounting_error( $result ) );
			return false;
		}
		return true;
	}
	/** The recovery intent, original paid target and paused status form one recoverable checkpoint. */
	private static function pause_order_confirmation( $order_id, $retry ) {
		$order = self::fresh_order( $order_id ); if ( ! $order ) { return; }
		$buyer = RR_Recovery::buyer( $order );
		$storage = RR_Database::verify_order_storage();
		$start = is_wp_error( $storage ) ? $storage : RR_Ledger::begin_wallet_transaction( $buyer, false );
		$open = ! is_wp_error( $start );
		try {
			if ( $open ) { $order = self::recovery_order_locked( $order_id ); if ( is_wp_error( $order ) ) { throw new RuntimeException( 'Confirmation checkpoint order unavailable.' ); } }
			// A concurrent cancellation/refund or explicit merchant status change must win over this retry.
			$target = $order->get_meta( '_rr_confirmation_waiting' );
			if ( ! in_array( $target, array( 'processing', 'completed' ), true ) ) { $target = $order->get_status(); }
			if ( ! $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) || 'yes' === $order->get_meta( '_rr_rewards_cancelled' ) ) { if ( $open ) { RR_Ledger::finish_wallet_transaction( true ); } return; }
			if ( $retry && in_array( $target, array( 'processing', 'completed' ), true ) && $order->get_date_paid() ) {
				$order->update_meta_data( '_rr_confirmation_waiting', $target );
				$recorded = RR_Recovery::record( 'confirm', $order_id, 0, array( $buyer ) );
				if ( is_wp_error( $recorded ) ) { throw new RuntimeException( 'Paid confirmation intent could not be saved.' ); }
				RR_Background::schedule( 'rr_retry_confirm_rewards', 60, array( absint( $order_id ) ) );
			} else { $order->delete_meta_data( '_rr_confirmation_waiting' ); }
			$order->update_status( 'on-hold', __( 'Reward points could not be confirmed; fulfillment is paused while accounting recovers or the store reviews this order.', 'rewards-referrals' ) );
			if ( $open ) { $finished = RR_Ledger::finish_wallet_transaction( true ); $open = false; if ( is_wp_error( $finished ) ) { throw new RuntimeException( 'Paid confirmation checkpoint commit failed.' ); } }
		} catch ( Throwable $error ) {
			if ( $open ) { RR_Ledger::finish_wallet_transaction( false ); }
			$order = self::fresh_order( $order_id, $order );
			if ( $order && $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) { if ( $retry && in_array( $target ?? '', array( 'processing', 'completed' ), true ) && $order->get_date_paid() ) { $order->update_meta_data( '_rr_confirmation_waiting', $target ); } $order->update_status( 'on-hold', __( 'Paid reward recovery could not be durably recorded. Keep fulfillment paused and use Retry rewards accounting after repairing the database.', 'rewards-referrals' ) ); }
		}
	}

	/** Lock the authoritative WooCommerce row before a recovery status check/update. */
	private static function recovery_order_locked( $order_id ) {
		$storage = RR_Database::verify_order_storage(); if ( is_wp_error( $storage ) ) { return $storage; }
		global $wpdb;
		$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$table = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
		$column = $hpos ? 'id' : 'ID';
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE %i=%d FOR UPDATE', $column, $table, $column, $order_id ) );
		if ( ! $found || $wpdb->last_error ) { return new WP_Error( 'rr_recovery_order_missing' ); }
		$order = self::fresh_order( $order_id );
		return $order ?: new WP_Error( 'rr_recovery_order_missing' );
	}
	/** Financial confirmation is a durable phase; fulfillment and intent completion commit together afterward. */
	public static function retry_order_confirmation( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order ) { return; }
		$target = $order->get_meta( '_rr_confirmation_waiting' );
		if ( in_array( $target, array( 'processing', 'completed' ), true ) && $order->has_status( 'on-hold' ) && $order->get_date_paid() && 'yes' !== $order->get_meta( '_rr_rewards_cancelled' ) ) {
			if ( ! self::confirm_order_redemption( $order_id ) ) { return; }
		}
		$buyer = RR_Recovery::buyer( $order );
		$started = RR_Ledger::begin_wallet_transaction( $buyer, false ); if ( is_wp_error( $started ) ) { RR_Background::schedule( 'rr_retry_confirm_rewards', 60, array( absint( $order_id ) ) ); return; }
		try {
			$order = self::recovery_order_locked( $order_id ); if ( is_wp_error( $order ) ) { RR_Ledger::finish_wallet_transaction( false ); return; }
			global $wpdb; $status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE order_id=%d FOR UPDATE', RR_Database::table( 'redemptions' ), $order_id ) );
			if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) || 'yes' === $order->get_meta( '_rr_rewards_cancelled' ) ) {
				// Native terminal callbacks own financial reversal/release; this stale paid retry must retire.
				$order->delete_meta_data( '_rr_confirmation_waiting' ); $order->save(); RR_Recovery::complete( 'confirm', $order_id );
			} elseif ( $order->has_status( array( 'processing', 'completed' ) ) && in_array( $status, array( 'confirmed', 'partially_refunded', 'refunded' ), true ) ) {
				$order->delete_meta_data( '_rr_confirmation_waiting' ); $order->save(); RR_Recovery::complete( 'confirm', $order_id );
				RR_Background::schedule( 'rr_retry_order_rewards', 5, array( absint( $order_id ) ) );
			} elseif ( $order->has_status( 'on-hold' ) && $order->get_date_paid() && in_array( $target, array( 'processing', 'completed' ), true ) && $order->get_meta( '_rr_confirmation_waiting' ) === $target && in_array( $status, array( 'confirmed', 'partially_refunded' ), true ) ) {
				$other_hold = RR_Recovery::run( array( $buyer ), static function () use ( $buyer ) { return RR_Recovery::held( $buyer ); }, array( RR_Recovery::key( 'confirm', $order_id ) ) );
				if ( $other_hold ) { RR_Ledger::finish_wallet_transaction( false ); return; }
				// Keep the hold visible to nested earning callbacks until this status + checkpoint commit.
				$order->delete_meta_data( '_rr_confirmation_waiting' );
				$order->update_status( $target, __( 'Reward accounting recovered; the original paid order status was restored.', 'rewards-referrals' ) );
				RR_Recovery::complete( 'confirm', $order_id );
				RR_Background::schedule( 'rr_retry_order_rewards', 5, array( absint( $order_id ) ) );
			}
			$finished = RR_Ledger::finish_wallet_transaction( true );
			if ( is_wp_error( $finished ) ) { self::fresh_order( $order_id, $order ); }
			if ( ! is_wp_error( $finished ) && $order->has_status( array( 'processing', 'completed' ) ) ) { self::award_order( $order_id ); }
		} catch ( Throwable $error ) { if ( RR_Ledger::transaction_open() ) { RR_Ledger::finish_wallet_transaction( false ); } self::fresh_order( $order_id, $order ); }
	}

	/** Core order-pay checks error notices before invoking the chosen gateway. */
	public static function validate_reward_payment_retry( $order ) {
		if ( ! $order instanceof WC_Order || ! absint( $order->get_meta( '_rr_points_reserved' ) ) ) { return; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status,customer_id FROM %i WHERE order_id=%d', RR_Database::table( 'redemptions' ), $order->get_id() ) );
		if ( ! $row || $wpdb->last_error || ! in_array( $row->status, array( 'reserved', 'confirmed', 'partially_refunded' ), true ) || (int) $row->customer_id !== (int) $order->get_customer_id() ) {
			wc_add_notice( __( 'This reward reservation has closed or needs review. No new payment was submitted. Please return to the shop and start a new checkout, or contact the store about this order.', 'rewards-referrals' ), 'error' );
		}
	}

	public static function release_order_redemption( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order || ! absint( $order->get_meta( '_rr_points_reserved' ) ) ) { return; }
		$buyer = RR_Recovery::buyer( $order ); if ( ! $buyer ) { return; }
		RR_Recovery::run( array( $buyer ), static function () use ( $order_id, $buyer ) {
			$storage = RR_Database::verify_order_storage();
			$started = is_wp_error( $storage ) ? $storage : RR_Ledger::begin_wallet_transaction( $buyer, false );
			$result = $started;
			if ( ! is_wp_error( $started ) ) {
				try {
					$order = self::recovery_order_locked( $order_id );
					if ( is_wp_error( $order ) ) { RR_Ledger::finish_wallet_transaction( false ); return; }
					if ( ! $order->has_status( 'failed' ) ) { RR_Recovery::complete( 'release', $order_id ); RR_Ledger::finish_wallet_transaction( true ); return; }
					$result = RR_Ledger::release_redemption( $order_id, 'order_failed' );
					if ( ! is_wp_error( $result ) ) { $order->delete_meta_data( '_rr_confirmation_waiting' ); $order->save(); RR_Recovery::complete( 'release', $order_id ); RR_Recovery::complete( 'confirm', $order_id ); }
					$finished = RR_Ledger::finish_wallet_transaction( ! is_wp_error( $result ) ); if ( is_wp_error( $finished ) ) { $result = $finished; }
				} catch ( Throwable $error ) { RR_Ledger::finish_wallet_transaction( false ); $result = new WP_Error( 'rr_release_review' ); }
			}
			if ( is_wp_error( $result ) ) {
				$order = self::fresh_order( $order_id ); if ( ! $order || ! $order->has_status( 'failed' ) ) { return; }
				if ( self::retryable_accounting_error( $result ) ) { RR_Recovery::record( 'release', $order_id, 0, array( $buyer ) ); RR_Background::schedule( 'rr_retry_release_rewards', 60, array( absint( $order_id ) ) ); }
				$order->add_order_note( __( 'Reward reservation could not be released. Confirmed points require cancellation or an itemized WooCommerce refund; review rewards accounting before retrying payment.', 'rewards-referrals' ) );
			}
		}, array( RR_Recovery::key( 'release', $order_id ), RR_Recovery::key( 'confirm', $order_id ) ) );
	}

	/** Cancellation is a terminal rewards event. Cash refunds remain WooCommerce's responsibility. */
	public static function cancel_order_rewards( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->has_status( 'cancelled' ) ) { return; }
		global $wpdb; $buyer = RR_Recovery::buyer( $order );
		if ( ! $buyer ) {
			if ( $wpdb->last_error ) { RR_Background::schedule( 'rr_retry_cancel_rewards', 60, array( absint( $order_id ) ) ); }
			return;
		}
		$commission = RR_Ledger::positive_order_transaction( $order_id, 'referral_commission' );
		if ( $wpdb->last_error ) { RR_Background::schedule( 'rr_retry_cancel_rewards', 60, array( absint( $order_id ) ) ); $order->add_order_note( __( 'Cancellation rewards ownership could not be read. No points changed; repair the database and retry rewards cancellation.', 'rewards-referrals' ) ); return; }
		$ids = array( $buyer );
		if ( $commission ) { $ids[] = (int) $commission->customer_id; }
		$hold = RR_Recovery::record( 'cancel', $order_id, 0, $ids );
		if ( is_wp_error( $hold ) ) { RR_Background::schedule( 'rr_retry_cancel_rewards', 60, array( absint( $order_id ) ) ); $order->add_order_note( __( 'Cancellation recovery could not be recorded. Review accounting before further redemption.', 'rewards-referrals' ) ); return; }
		$storage = RR_Database::verify_order_storage(); if ( is_wp_error( $storage ) ) { $order->add_order_note( $storage->get_error_message() ); RR_Background::schedule( 'rr_retry_cancel_rewards', 60, array( absint( $order_id ) ) ); return; }
		RR_Recovery::run( $ids, static function () use ( $order_id, $order, $ids ) {
		global $wpdb;
		$started = RR_Ledger::begin_wallet_transaction( $ids );
		if ( is_wp_error( $started ) ) { if ( in_array( $started->get_error_code(), array( 'rr_wallet_rebuild_pending', 'rr_legacy_lot_maintenance' ), true ) ) { RR_Background::schedule( 'rr_retry_cancel_rewards', 60, array( absint( $order_id ) ) ); } else { $order->add_order_note( __( 'Cancellation rewards accounting could not acquire its lock. Review and retry rewards cancellation.', 'rewards-referrals' ) ); } return; }
		$open = true;
		try {
			$order = self::recovery_order_locked( $order_id ); if ( is_wp_error( $order ) ) { throw new RuntimeException( 'Cancellation order could not be re-read.' ); }
			if ( ! $order->has_status( 'cancelled' ) ) { RR_Recovery::complete( 'cancel', $order_id ); RR_Ledger::finish_wallet_transaction( true ); return; }
			$restored = RR_Ledger::cancel_redemption( $order_id );
			if ( is_wp_error( $restored ) ) { throw new RuntimeException( 'Cancellation points restoration failed.' ); }
			$purchase = RR_Ledger::positive_order_transaction( $order_id, 'purchase_points' );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Cancellation purchase source could not be read.' ); }
			$commission = RR_Ledger::positive_order_transaction( $order_id, 'referral_commission' );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Cancellation referral source could not be read.' ); }
			foreach ( array( 'purchase_points' => $purchase, 'referral_commission' => $commission ) as $source_type => $source ) {
				if ( ! $source ) { continue; }
				$reversed = RR_Ledger::reversed_amount( (int) $source->id );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Cancellation prior reversal total could not be read.' ); }
				$delta = max( 0, (int) $source->amount - $reversed );
				if ( ! $delta ) { continue; }
				$result = RR_Ledger::add( array( 'customer_id' => (int) $source->customer_id, 'amount' => -$delta, 'type' => 'refund_reversal', 'state' => 'pending' === $source->state ? 'pending' : 'available', 'available_at' => 'pending' === $source->state ? $source->available_at : null, 'order_id' => (int) $order_id, 'reference_id' => (int) $source->id, 'key' => 'cancel:order:' . $order_id . ':credit:' . $source->id, 'meta' => array( 'reason' => 'order_cancelled', 'source' => $source_type ) ) );
				if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Cancellation credit reversal failed.' ); }
			}
			$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET refunded_quantity=quantity WHERE order_id=%d', RR_Database::table( 'redemption_items' ), $order_id ) );
			if ( false === $result ) { throw new RuntimeException( 'Cancellation usage update failed.' ); }
			$order->update_meta_data( '_rr_rewards_cancelled', 'yes' );
			$order->save();
			RR_Recovery::complete( 'confirm', $order_id ); RR_Recovery::complete( 'release', $order_id );
			RR_Recovery::complete( 'cancel', $order_id );
			RR_Recovery::finish_reversal_review( $order_id, true );
			$finished = RR_Ledger::finish_wallet_transaction( true );
			$open = false;
			if ( is_wp_error( $finished ) ) { throw new RuntimeException( 'Cancellation commit failed.' ); }
			if ( 'yes' !== $order->get_meta( '_rr_rewards_cancellation_noted' ) ) {
				$order->update_meta_data( '_rr_rewards_cancellation_noted', 'yes' );
				$order->add_order_note( __( 'Cancellation rewards accounting completed: unreturned reward points were restored and remaining purchase/referral earnings reversed. Cash was not refunded by this action; use WooCommerce refunds for any paid cash balance. Cancelled orders are final for rewards; create a new order if the customer purchases again.', 'rewards-referrals' ) );
				$order->save();
			}
		} catch ( Throwable $error ) {
			if ( $open ) { RR_Ledger::finish_wallet_transaction( false ); }
			$order = self::fresh_order( $order_id, $order ); if ( ! $order ) { return; }
			$order->add_order_note( __( 'Cancellation rewards accounting needs review. No partial cancellation wallet changes were committed. Retry rewards cancellation after resolving the accounting issue.', 'rewards-referrals' ) );
		}
		} );
	}

	public static function cancellation_order_action( $actions, $order ) {
		if ( $order instanceof WC_Order && current_user_can( 'manage_woocommerce' ) && $order->has_status( array( 'processing', 'completed', 'failed', 'on-hold' ) ) ) { $actions['rr_retry_rewards_accounting'] = __( 'Retry rewards accounting', 'rewards-referrals' ); }
		if ( $order instanceof WC_Order && $order->has_status( 'cancelled' ) && current_user_can( 'manage_woocommerce' ) ) { $actions['rr_retry_rewards_cancellation'] = __( 'Retry rewards cancellation accounting', 'rewards-referrals' ); }
		return $actions;
	}

	public static function retry_order_accounting( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$order = wc_get_order( $order->get_id() ); if ( ! $order ) { return; }
		if ( $order->has_status( 'failed' ) ) { self::release_order_redemption( $order->get_id() ); }
		elseif ( $order->has_status( 'on-hold' ) && $order->get_meta( '_rr_confirmation_waiting' ) ) { self::retry_order_confirmation( $order->get_id() ); }
		elseif ( $order->has_status( array( 'processing', 'completed' ) ) && self::confirm_order_redemption( $order->get_id() ) ) { self::award_order( $order->get_id() ); }
	}

	public static function retry_cancellation( $order ) {
		if ( $order instanceof WC_Order && current_user_can( 'manage_woocommerce' ) ) { self::cancel_order_rewards( $order->get_id() ); }
	}
}
