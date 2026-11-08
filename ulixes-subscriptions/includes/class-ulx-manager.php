<?php
/**
 * Subscription lifecycle manager.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Manager
 */
class ULX_Manager {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'activate_subscriptions_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'activate_subscriptions_for_order' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'activate_subscriptions_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'failed_subscription_sign_ups_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'cancel_subscriptions_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'cancel_subscriptions_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'put_subscriptions_on_hold_for_order' ), 10, 1 );

		add_action( 'ulx_scheduled_subscription_trial_end', array( __CLASS__, 'trigger_trial_end' ), 10, 1 );
		add_action( 'ulx_scheduled_subscription_payment', array( __CLASS__, 'prepare_renewal' ), 1, 1 );
		add_action( 'ulx_scheduled_subscription_payment', array( __CLASS__, 'maybe_process_gateway_renewal' ), 10, 1 );
		add_action( 'ulx_scheduled_subscription_expiration', array( __CLASS__, 'expire_subscription' ), 10, 1 );
		add_action( 'ulx_scheduled_subscription_end_of_prepaid_term', array( __CLASS__, 'end_of_prepaid_term' ), 10, 1 );

		add_action( 'before_delete_post', array( __CLASS__, 'maybe_trash_or_delete_related' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'maybe_cancel_on_trash' ) );
	}

	/**
	 * Activate all parent/resubscribe subscriptions on an order when the order is paid.
	 *
	 * @param int|WC_Order $order Order.
	 */
	public static function activate_subscriptions_for_order( $order ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order || $order instanceof ULX_Subscription ) {
			return;
		}
		$subscriptions = ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		if ( empty( $subscriptions ) ) {
			return;
		}
		foreach ( $subscriptions as $subscription ) {
			if ( $subscription->has_status( 'active' ) ) {
				continue;
			}
			if ( ! $subscription->can_be_updated_to( 'active' ) ) {
				continue;
			}
			if ( $subscription->needs_payment() ) {
				continue;
			}
			try {
				$subscription->update_status(
					'active',
					sprintf(
						/* translators: %s: order number */
						__( 'Subscription activated by order #%s payment.', 'ulixes-subscriptions' ),
						$order->get_order_number()
					)
				);
			} catch ( Exception $e ) {
				ulx_log( 'Failed to activate subscription #' . $subscription->get_id() . ': ' . $e->getMessage(), 'error' );
			}
		}
	}

	/**
	 * Put subscriptions on hold when parent order is placed on hold.
	 *
	 * @param int|WC_Order $order Order.
	 */
	public static function put_subscriptions_on_hold_for_order( $order ) {
		$subscriptions = ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription->has_status( array( 'on-hold', 'pending' ) ) && $subscription->can_be_updated_to( 'on-hold' ) ) {
				$subscription->update_status( 'on-hold', __( 'Subscription placed on hold by parent order.', 'ulixes-subscriptions' ) );
			}
		}
	}

	/**
	 * Handle failed initial order.
	 *
	 * @param int|WC_Order $order Order.
	 */
	public static function failed_subscription_sign_ups_for_order( $order ) {
		$subscriptions = ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
		foreach ( $subscriptions as $subscription ) {
			if ( $subscription->has_status( 'active' ) && $subscription->can_be_updated_to( 'on-hold' ) ) {
				$subscription->update_status( 'on-hold', __( 'Parent order payment failed.', 'ulixes-subscriptions' ) );
			}
		}
	}

	/**
	 * Cancel subscriptions when parent order is cancelled or fully refunded.
	 *
	 * @param int|WC_Order $order Order.
	 */
	public static function cancel_subscriptions_for_order( $order ) {
		$subscriptions = ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription->has_status( ULX_Subscription::get_ended_statuses() ) && $subscription->can_be_updated_to( 'cancelled' ) ) {
				$subscription->update_status( 'cancelled', __( 'Subscription cancelled because parent order was cancelled or refunded.', 'ulixes-subscriptions' ) );
			}
		}
	}

	/**
	 * Scheduled trial end hook.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function trigger_trial_end( $subscription_id ) {
		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		do_action( 'ulx_subscription_trial_ended', $subscription_id, $subscription );
	}

	/**
	 * Step 1 of scheduled renewal payment (priority 1):
	 * Put the subscription on-hold, calculate & store the next payment date, and create the pending renewal order.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function prepare_renewal( $subscription_id ) {
		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription || $subscription->has_status( ULX_Subscription::get_ended_statuses() ) ) {
			return;
		}

		$order_note   = _x( 'Subscription renewal payment due:', 'used in order note as reason for why subscription status changed', 'ulixes-subscriptions' );
		$next_payment = $subscription->calculate_date( 'next_payment' );
		$subscription->update_dates( array( 'next_payment' => $next_payment ) );

		if ( $subscription->get_total() > 0 && $subscription->can_be_updated_to( 'on-hold' ) ) {
			$subscription->update_status( 'on-hold', $order_note );
		}

		$renewal_order = ulx_create_renewal_order( $subscription );
		if ( is_wp_error( $renewal_order ) ) {
			ulx_log( 'Renewal order creation failed for subscription #' . $subscription_id . ': ' . $renewal_order->get_error_message(), 'error' );
			return;
		}

		if ( 0 == $renewal_order->get_total() ) { // phpcs:ignore
			$renewal_order->payment_complete();
			if ( $subscription->can_be_updated_to( 'active' ) ) {
				$subscription->update_status( 'active', __( 'Free renewal completed.', 'ulixes-subscriptions' ) );
			}
			return;
		}

		if ( $subscription->is_manual() ) {
			do_action( 'ulx_generated_manual_renewal_order', $renewal_order->get_id(), $subscription );
		}
	}

	/**
	 * Step 2 of scheduled renewal payment (priority 10):
	 * Trigger the payment gateway's scheduled subscription payment hook if the subscription is automatic.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function maybe_process_gateway_renewal( $subscription_id ) {
		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription || $subscription->is_manual() ) {
			return;
		}
		$last_order = $subscription->get_last_order( 'all', 'renewal' );
		if ( ! $last_order || ! $last_order->needs_payment() ) {
			return;
		}
		ULX_Payment_Gateways::trigger_gateway_renewal_payment_hook( $last_order );
	}

	/**
	 * Scheduled expiration hook.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function expire_subscription( $subscription_id ) {
		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		if ( $subscription->can_be_updated_to( 'expired' ) ) {
			$subscription->update_status( 'expired', __( 'Subscription reached its end date.', 'ulixes-subscriptions' ) );
		}
	}

	/**
	 * Scheduled end of prepaid term hook (moves `pending-cancel` subscription to `cancelled`).
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function end_of_prepaid_term( $subscription_id ) {
		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
			$subscription->update_status( 'cancelled', __( 'End of prepaid term reached.', 'ulixes-subscriptions' ) );
		}
	}

	/**
	 * Cancel subscription before trashing if still active.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_cancel_on_trash( $post_id ) {
		$sub = ulx_get_subscription( $post_id );
		if ( $sub && ! $sub->has_status( ULX_Subscription::get_ended_statuses() ) && $sub->can_be_updated_to( 'cancelled' ) ) {
			$sub->update_status( 'cancelled', __( 'Subscription cancelled because it was moved to trash.', 'ulixes-subscriptions' ) );
		}
	}

	/**
	 * Unschedule actions when a subscription is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_trash_or_delete_related( $post_id ) {
		$sub = ulx_get_subscription( $post_id );
		if ( $sub ) {
			ULX_Scheduler::unschedule_all_for_subscription( $sub );
		}
	}
}

