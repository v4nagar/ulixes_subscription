<?php
/**
 * Failed Recurring Payment Retry System.
 *
 * Implements WooCommerce Subscriptions' 5-step retry rules engine:
 * - Rule 0: +12 hours
 * - Rule 1: +12 hours (24h after initial failure)
 * - Rule 2: +24 hours (48h after initial failure)
 * - Rule 3: +48 hours (4 days after initial failure)
 * - Rule 4: +72 hours (7 days after initial failure)
 *
 * Each rule specifies the interval, customer/admin notification emails, order status, and subscription status.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Retry_Manager
 */
class ULX_Retry_Manager {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'ulx_subscription_renewal_payment_failed', array( __CLASS__, 'maybe_apply_retry_rule' ), 10, 2 );
		add_action( 'ulx_scheduled_subscription_payment_retry', array( __CLASS__, 'process_scheduled_retry' ), 10, 1 );
		add_action( 'ulx_subscription_renewal_payment_complete', array( __CLASS__, 'clear_retry_data' ), 10, 2 );
	}

	/**
	 * Is the failed-payment retry system enabled?
	 *
	 * @return bool
	 */
	public static function is_retry_enabled() {
		return ulx_option_is_yes( 'enable_retry' ) && ! ulx_automatic_payments_disabled();
	}

	/**
	 * Get the default 5 retry rules (filterable via `ulx_default_retry_rules` and `wcs_default_retry_rules`).
	 *
	 * @return array
	 */
	public static function get_retry_rules() {
		return ULX_Retry_Rules::get_rules();
	}

	/**
	 * When an automatic renewal fails, check if a retry rule applies and schedule the retry.
	 *
	 * @param ULX_Subscription $subscription  Subscription.
	 * @param WC_Order         $renewal_order Failed renewal order.
	 */
	public static function maybe_apply_retry_rule( $subscription, $renewal_order ) {
		if ( ! self::is_retry_enabled() || ! $subscription || ! $renewal_order || $subscription->is_manual() ) {
			return;
		}

		$rules       = self::get_retry_rules();
		$retry_count = (int) $renewal_order->get_meta( '_ulx_retry_count', true );

		if ( ! isset( $rules[ $retry_count ] ) ) {
			// All retries exhausted.
			$subscription->update_dates( array( 'payment_retry' => 0 ) );
			$subscription->add_order_note( __( 'Maximum payment retries reached for renewal order.', 'ulixes-subscriptions' ) );
			ULX_Retry_Table_Maker::record_retry( $renewal_order->get_id(), $subscription->get_id(), 'cancelled', '', array( 'exhausted' => true ) );
			do_action( 'ulx_retry_retries_exhausted', $subscription, $renewal_order );
			return;
		}

		$rule       = $rules[ $retry_count ];
		$retry_time = time() + (int) $rule['retry_after_interval'];
		$retry_date = ulx_date_to_mysql( $retry_time );

		$renewal_order->update_meta_data( '_ulx_retry_count', $retry_count + 1 );
		$renewal_order->update_meta_data( '_ulx_retry_rule', $rule );
		$renewal_order->update_meta_data( '_ulx_retry_date', $retry_date );
		if ( ! empty( $rule['status_to_apply_to_order'] ) && ! $renewal_order->has_status( $rule['status_to_apply_to_order'] ) ) {
			$renewal_order->set_status( $rule['status_to_apply_to_order'] );
		}
		$renewal_order->save();

		// Record retry in custom database table.
		$retry_id = ULX_Retry_Table_Maker::record_retry( $renewal_order->get_id(), $subscription->get_id(), 'pending', $retry_time, $rule );
		if ( $retry_id ) {
			$renewal_order->update_meta_data( '_ulx_current_retry_id', $retry_id );
			$renewal_order->save_meta_data();
		}

		$subscription->update_dates( array( 'payment_retry' => $retry_date ) );
		$subscription->add_order_note(
			sprintf(
				/* translators: 1: retry number, 2: formatted retry date */
				__( 'Payment retry #%1$d scheduled for %2$s.', 'ulixes-subscriptions' ),
				$retry_count + 1,
				ulx_format_date( $retry_time )
			)
		);

		do_action( 'ulx_after_apply_retry_rule', $rule, $renewal_order, $subscription );
	}

	/**
	 * Execute a scheduled payment retry.
	 *
	 * @param int $order_id Renewal order ID.
	 */
	public static function process_scheduled_retry( $order_id ) {
		$renewal_order = wc_get_order( $order_id );
		if ( ! $renewal_order || ! $renewal_order->needs_payment() ) {
			return;
		}
		$subscriptions = ulx_get_subscriptions_for_renewal_order( $renewal_order );
		foreach ( $subscriptions as $subscription ) {
			$subscription->update_dates( array( 'payment_retry' => 0 ) );
		}

		$current_retry_id = (int) $renewal_order->get_meta( '_ulx_current_retry_id', true );
		if ( $current_retry_id > 0 ) {
			ULX_Retry_Table_Maker::update_status( $current_retry_id, 'processing' );
		}

		$renewal_order->add_order_note( __( 'Processing scheduled automatic payment retry.', 'ulixes-subscriptions' ) );
		ULX_Payment_Gateways::trigger_gateway_renewal_payment_hook( $renewal_order );
	}

	/**
	 * Clear retry metadata when a renewal payment succeeds.
	 *
	 * @param ULX_Subscription $subscription  Subscription.
	 * @param WC_Order         $renewal_order Renewal order.
	 */
	public static function clear_retry_data( $subscription, $renewal_order ) {
		if ( $subscription->get_time( 'payment_retry' ) ) {
			$subscription->update_dates( array( 'payment_retry' => 0 ) );
		}
		if ( $renewal_order ) {
			$current_retry_id = (int) $renewal_order->get_meta( '_ulx_current_retry_id', true );
			if ( $current_retry_id > 0 ) {
				ULX_Retry_Table_Maker::update_status( $current_retry_id, 'completed' );
			}
			$renewal_order->delete_meta_data( '_ulx_retry_date' );
			$renewal_order->delete_meta_data( '_ulx_current_retry_id' );
			$renewal_order->save_meta_data();
		}
	}
}

