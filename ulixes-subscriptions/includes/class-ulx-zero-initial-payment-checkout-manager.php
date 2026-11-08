<?php
/**
 * Zero Initial Payment Checkout Manager.
 *
 * Manages checkouts where the initial total is $0.00 (e.g. free trials or 100% off sign-up coupons)
 * while ensuring recurring payment gateways capture tokenization details for future renewals.
 * Matches WooCommerce Subscriptions' WCS_Zero_Initial_Payment_Checkout_Manager.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Zero_Initial_Payment_Checkout_Manager
 */
class ULX_Zero_Initial_Payment_Checkout_Manager {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 15, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways_for_zero_total' ), 20, 1 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'display_zero_total_notice' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'maybe_process_zero_total_order' ), 20, 3 );
	}

	/**
	 * Force `woocommerce_cart_needs_payment` to true when initial total is 0 but future recurring payments exist.
	 *
	 * @param bool    $needs_payment Needs payment.
	 * @param WC_Cart $cart          Cart.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs_payment, $cart ) {
		if ( ! ulx_cart_contains_subscription() ) {
			return $needs_payment;
		}

		// If automatic payments are disabled completely or store forces manual renewals, no payment method is required for $0.
		if ( ulx_automatic_payments_disabled() || 'yes' === ulx_get_option( 'accept_manual_renewals', 'yes' ) && 'no' === ulx_get_option( 'zero_initial_payment_requires_payment', 'no' ) ) {
			// If recurring total > 0 and store requires gateway token, require payment.
			$recurring_total = self::get_recurring_total( $cart );
			if ( $recurring_total > 0 && ulx_option_is_yes( 'zero_initial_payment_requires_payment' ) ) {
				return true;
			}
			return $needs_payment;
		}

		$recurring_total = self::get_recurring_total( $cart );
		if ( 0 == $cart->total && $recurring_total > 0 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			$needs_payment = true;
		}

		return $needs_payment;
	}

	/**
	 * Sum the recurring total across all recurring carts.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return float
	 */
	public static function get_recurring_total( $cart ) {
		$total = 0.0;
		if ( ! empty( $cart->recurring_carts ) ) {
			foreach ( $cart->recurring_carts as $rc ) {
				$total += (float) $rc->total;
			}
		}
		return $total;
	}

	/**
	 * When initial total is $0, only allow payment gateways that support subscriptions or tokenization.
	 *
	 * @param array $available_gateways Available gateways.
	 * @return array
	 */
	public static function filter_gateways_for_zero_total( $available_gateways ) {
		if ( is_admin() || empty( WC()->cart ) || ! ulx_cart_contains_subscription() ) {
			return $available_gateways;
		}

		if ( 0 != WC()->cart->total ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			return $available_gateways;
		}

		$filtered = array();
		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( $gateway->supports( 'subscriptions' ) || $gateway->supports( 'tokenization' ) || $gateway->supports( 'subscription_payment_method_change_customer' ) ) {
				$filtered[ $gateway_id ] = $gateway;
			}
		}

		// If no token gateway is configured, fall back to default gateways so the customer can still checkout.
		return ! empty( $filtered ) ? $filtered : $available_gateways;
	}

	/**
	 * Display an informative banner on the checkout page when $0 is due today.
	 */
	public static function display_zero_total_notice() {
		if ( empty( WC()->cart ) || ! ulx_cart_contains_subscription() ) {
			return;
		}
		if ( 0 == WC()->cart->total && self::get_recurring_total( WC()->cart ) > 0 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			echo '<div class="woocommerce-info ulx-zero-initial-notice">' .
				esc_html__( 'No payment is due today. A payment method is required to set up your subscription for automatic recurring renewals.', 'ulixes-subscriptions' ) .
				'</div>';
		}
	}

	/**
	 * When a zero-total subscription order is placed, mark it as processing/completed so subscriptions activate immediately.
	 *
	 * @param int      $order_id    Order ID.
	 * @param array    $posted_data Form posted data.
	 * @param WC_Order $order       Order.
	 */
	public static function maybe_process_zero_total_order( $order_id, $posted_data, $order = null ) {
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order || 0 != $order->get_total() ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			return;
		}

		$subscriptions = ulx_get_subscriptions_for_order( $order );
		if ( empty( $subscriptions ) ) {
			return;
		}

		// Ensure subscriptions are activated for $0 initial checkout.
		foreach ( $subscriptions as $subscription ) {
			if ( $subscription->can_be_updated_to( 'active' ) ) {
				$subscription->update_status( 'active', __( 'Active subscription created from zero initial payment checkout (free trial or 100% discount).', 'ulixes-subscriptions' ) );
			}
		}
	}
}

