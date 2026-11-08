<?php
/**
 * Early Renewal handler (via checkout or one-click modal).
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Cart_Early_Renewal
 */
class ULX_Cart_Early_Renewal {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_setup_early_renewal' ), 20 );
	}

	/**
	 * Handle `?subscription_renewal_early=<id>` request.
	 */
	public static function maybe_setup_early_renewal() {
		if ( empty( $_GET['subscription_renewal_early'] ) || empty( $_GET['ulx_nonce'] ) ) {
			return;
		}
		$subscription_id = absint( $_GET['subscription_renewal_early'] );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['ulx_nonce'] ) ), 'ulx-renew-early-' . $subscription_id ) ) {
			wc_add_notice( __( 'Security check failed for early renewal.', 'ulixes-subscriptions' ), 'error' );
			return;
		}

		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription || ! ulx_can_user_renew_early( $subscription ) ) {
			wc_add_notice( __( 'This subscription cannot be renewed early.', 'ulixes-subscriptions' ), 'error' );
			return;
		}

		// Create a pending renewal order marked as early renewal and redirect to pay for it (or process via gateway if modal).
		$renewal_order = ulx_create_renewal_order( $subscription );
		if ( is_wp_error( $renewal_order ) ) {
			wc_add_notice( $renewal_order->get_error_message(), 'error' );
			return;
		}

		$renewal_order->update_meta_data( '_subscription_renewal_early', $subscription->get_id() );
		$renewal_order->save();

		// If modal early renewal is requested and subscription is automatic, trigger gateway charge immediately.
		if ( ! empty( $_GET['process_early_renewal'] ) && ! $subscription->is_manual() && ulx_option_is_yes( 'enable_early_renewal_via_modal' ) ) {
			ULX_Payment_Gateways::trigger_gateway_renewal_payment_hook( $renewal_order );
			$renewal_order = wc_get_order( $renewal_order->get_id() );
			if ( $renewal_order && $renewal_order->is_paid() ) {
				wc_add_notice( __( 'Your early renewal payment was processed successfully!', 'ulixes-subscriptions' ), 'success' );
			} else {
				wc_add_notice( __( 'We could not process your early renewal automatically. Please complete payment below.', 'ulixes-subscriptions' ), 'error' );
				wp_safe_redirect( $renewal_order->get_checkout_payment_url() );
				exit;
			}
			wp_safe_redirect( $subscription->get_view_order_url() );
			exit;
		}

		wc_add_notice( __( 'Complete payment below to renew your subscription early.', 'ulixes-subscriptions' ), 'notice' );
		wp_safe_redirect( $renewal_order->get_checkout_payment_url() );
		exit;
	}
}

