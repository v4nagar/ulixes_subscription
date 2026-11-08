<?php
/**
 * Payment Gateways integration for automatic & manual renewals.
 *
 * Bridges standard WooCommerce Subscriptions gateway hooks (`woocommerce_scheduled_subscription_payment_{gateway_id}`)
 * and Ulixes hooks (`ulx_scheduled_subscription_payment_{gateway_id}`) so any third-party gateway built for
 * WooCommerce Subscriptions (Stripe, PayPal, Square, Razorpay, Authorize.Net, WooPayments) works out of the box.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Get a payment gateway instance by ID.
 *
 * @param string $gateway_id Gateway ID.
 * @return WC_Payment_Gateway|false
 */
function ulx_get_payment_gateway( $gateway_id ) {
	if ( empty( $gateway_id ) || ! WC()->payment_gateways() ) {
		return false;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ] : false;
}

/**
 * ULX_Payment_Gateways
 */
class ULX_Payment_Gateways {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_available_payment_gateways' ), 10 );
		add_action( 'ulx_subscription_status_cancelled', array( __CLASS__, 'trigger_gateway_status_Change_hook' ), 10, 1 );
		add_action( 'ulx_subscription_status_on-hold', array( __CLASS__, 'trigger_gateway_status_Change_hook' ), 10, 1 );
		add_action( 'ulx_subscription_status_active', array( __CLASS__, 'trigger_gateway_status_Change_hook' ), 10, 1 );
	}

	/**
	 * Filter available payment gateways on checkout when the cart contains a subscription.
	 *
	 * If manual renewals are NOT accepted, only gateways supporting `subscriptions` are shown.
	 * If multiple subscriptions are in the cart, only gateways supporting `multiple_subscriptions` are shown.
	 *
	 * @param array $available_gateways Gateways.
	 * @return array
	 */
	public static function filter_available_payment_gateways( $available_gateways ) {
		if ( is_admin() || empty( WC()->cart ) ) {
			return $available_gateways;
		}

		$is_change_payment = ULX_Change_Payment_Method::is_request_to_change_payment();
		if ( ! ulx_cart_contains_subscription() && ! $is_change_payment ) {
			return $available_gateways;
		}

		$accept_manual   = 'yes' === ulx_get_option( 'accept_manual_renewals', 'yes' ) || ulx_automatic_payments_disabled();
		$recurring_carts = isset( WC()->cart->recurring_carts ) ? count( WC()->cart->recurring_carts ) : 0;
		$multiple_subs   = $recurring_carts > 1;
		$filtered        = $available_gateways;

		foreach ( $filtered as $gateway_id => $gateway ) {
			if ( $is_change_payment ) {
				if ( ! $gateway->supports( 'subscription_payment_method_change_customer' ) && ! $gateway->supports( 'subscriptions' ) && ! $accept_manual ) {
					unset( $filtered[ $gateway_id ] );
				}
				continue;
			}

			if ( $multiple_subs && ! $gateway->supports( 'multiple_subscriptions' ) && ! $accept_manual ) {
				unset( $filtered[ $gateway_id ] );
				continue;
			}

			if ( ! $accept_manual && ! $gateway->supports( 'subscriptions' ) ) {
				unset( $filtered[ $gateway_id ] );
			}
		}

		// Never strip all gateways if no automatic subscription gateway is configured yet.
		return ! empty( $filtered ) ? $filtered : $available_gateways;
	}

	/**
	 * Trigger the gateway's scheduled renewal payment hook for a renewal order.
	 *
	 * Fires both `ulx_scheduled_subscription_payment_{gateway_id}` and
	 * `woocommerce_scheduled_subscription_payment_{gateway_id}` for 100% compatibility with WC Stripe/PayPal/etc.
	 * Also supports WC Payment Tokens (`WC_Payment_Tokens`) when a gateway stores a `_payment_tokens` or customer token.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 */
	public static function trigger_gateway_renewal_payment_hook( $renewal_order ) {
		if ( ! $renewal_order || ! $renewal_order->needs_payment() ) {
			return;
		}
		$gateway_id = $renewal_order->get_payment_method();
		if ( empty( $gateway_id ) ) {
			return;
		}

		$amount = (float) $renewal_order->get_total();

		do_action( 'ulx_before_scheduled_subscription_payment', $renewal_order );

		// Fire standard WooCommerce Subscriptions hook first if any listener is attached.
		if ( has_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ) ) {
			do_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id, $amount, $renewal_order );
		} else {
			do_action( 'ulx_scheduled_subscription_payment_' . $gateway_id, $amount, $renewal_order );
		}

		do_action( 'ulx_after_scheduled_subscription_payment', $renewal_order );
	}

	/**
	 * Notify the payment gateway when a subscription status changes (e.g. cancelled, suspended, reactivated).
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public static function trigger_gateway_status_Change_hook( $subscription ) {
		if ( $subscription->is_manual() ) {
			return;
		}
		$gateway_id = $subscription->get_payment_method();
		$status     = $subscription->get_status();
		if ( $gateway_id ) {
			do_action( 'woocommerce_subscription_status_' . $status . '_' . $gateway_id, $subscription );
			do_action( 'ulx_subscription_status_' . $status . '_' . $gateway_id, $subscription );
		}
	}

	/**
	 * Get payment meta table mapping for all gateways so admin can view/edit tokens (e.g. Stripe `_stripe_customer_id`, `_stripe_source_id`).
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @return array
	 */
	public static function get_payment_meta( $subscription ) {
		$payment_meta = array(
			'razorpay' => array(
				'post_meta' => array(
					'_razorpay_subscription_id' => array(
						'value' => $subscription->get_meta( '_razorpay_subscription_id', true ),
						'label' => __( 'Razorpay Subscription ID', 'ulixes-subscriptions' ),
					),
					'_razorpay_token'           => array(
						'value' => $subscription->get_meta( '_razorpay_token', true ),
						'label' => __( 'Razorpay Token ID', 'ulixes-subscriptions' ),
					),
					'_razorpay_customer_id'     => array(
						'value' => $subscription->get_meta( '_razorpay_customer_id', true ),
						'label' => __( 'Razorpay Customer ID', 'ulixes-subscriptions' ),
					),
				),
			),
			'stripe'   => array(
				'post_meta' => array(
					'_stripe_customer_id' => array(
						'value' => $subscription->get_meta( '_stripe_customer_id', true ),
						'label' => __( 'Stripe Customer ID', 'ulixes-subscriptions' ),
					),
					'_stripe_source_id'   => array(
						'value' => $subscription->get_meta( '_stripe_source_id', true ),
						'label' => __( 'Stripe Source / PaymentMethod ID', 'ulixes-subscriptions' ),
					),
				),
			),
		);
		$payment_meta = apply_filters( 'woocommerce_subscription_payment_meta', $payment_meta, $subscription );
		return apply_filters( 'ulx_subscription_payment_meta', $payment_meta, $subscription );
	}
}

