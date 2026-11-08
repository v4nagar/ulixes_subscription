<?php
/**
 * Customer self-service status changes (Cancel, Suspend, Reactivate) from My Account.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_User_Change_Status
 */
class ULX_User_Change_Status {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_change_users_subscription' ), 100 );
	}

	/**
	 * Handle `?subscription_id=X&change_subscription_to=Y` requests from the customer.
	 */
	public static function maybe_change_users_subscription() {
		if ( empty( $_GET['change_subscription_to'] ) || empty( $_GET['subscription_id'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}

		$subscription_id = absint( $_GET['subscription_id'] );
		$subscription    = ulx_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}

		$new_status = sanitize_text_field( wp_unslash( $_GET['change_subscription_to'] ) );
		$user_id    = get_current_user_id();

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $subscription->get_id() . $subscription->get_status() ) ) {
			wc_add_notice( __( 'Security check failed. Please try again.', 'ulixes-subscriptions' ), 'error' );
			return;
		}

		if ( (int) $subscription->get_user_id() !== (int) $user_id ) {
			wc_add_notice( __( 'That does not appear to be one of your subscriptions.', 'ulixes-subscriptions' ), 'error' );
			return;
		}

		self::change_users_subscription( $subscription, $new_status );

		wp_safe_redirect( $subscription->get_view_order_url() );
		exit;
	}

	/**
	 * Perform a user-initiated status change on a subscription.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $new_status   Target status.
	 * @return bool
	 */
	public static function change_users_subscription( $subscription, $new_status ) {
		$changed = false;

		switch ( $new_status ) {
			case 'cancelled':
				if ( ! $subscription->can_be_updated_to( 'cancelled' ) ) {
					wc_add_notice( __( 'That subscription cannot be cancelled.', 'ulixes-subscriptions' ), 'error' );
					break;
				}
				// If the customer has prepaid time remaining, move to `pending-cancel` first.
				$has_prepaid = $subscription->get_time( 'next_payment' ) > time() || $subscription->get_time( 'trial_end' ) > time();
				if ( $has_prepaid && $subscription->can_be_updated_to( 'pending-cancel' ) ) {
					$subscription->update_status( 'pending-cancel', __( 'Subscription cancelled by customer (pending end of prepaid term).', 'ulixes-subscriptions' ) );
					wc_add_notice( __( 'Your subscription has been cancelled and will remain active until the end of your current prepaid term.', 'ulixes-subscriptions' ), 'success' );
				} else {
					$subscription->update_status( 'cancelled', __( 'Subscription cancelled by customer.', 'ulixes-subscriptions' ) );
					wc_add_notice( __( 'Your subscription has been cancelled.', 'ulixes-subscriptions' ), 'success' );
				}
				$changed = true;
				break;

			case 'on-hold':
				if ( ulx_can_user_put_subscription_on_hold( $subscription ) ) {
					$subscription->update_status( 'on-hold', __( 'Subscription suspended by customer.', 'ulixes-subscriptions' ) );
					wc_add_notice( __( 'Your subscription has been suspended.', 'ulixes-subscriptions' ), 'success' );
					$changed = true;
				} else {
					wc_add_notice( __( 'You can no longer suspend this subscription.', 'ulixes-subscriptions' ), 'error' );
				}
				break;

			case 'active':
				if ( $subscription->can_be_updated_to( 'active' ) && ! $subscription->needs_payment() ) {
					$subscription->update_status( 'active', __( 'Subscription reactivated by customer.', 'ulixes-subscriptions' ) );
					wc_add_notice( __( 'Your subscription has been reactivated.', 'ulixes-subscriptions' ), 'success' );
					$changed = true;
				} else {
					wc_add_notice( __( 'That subscription cannot be reactivated. Please pay any pending renewal orders first.', 'ulixes-subscriptions' ), 'error' );
				}
				break;
		}

		if ( $changed ) {
			do_action( 'ulx_customer_changed_subscription_to_' . $new_status, $subscription );
		}
		return $changed;
	}
}

