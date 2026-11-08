<?php
/**
 * Template helper functions for frontend and My Account rendering.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Get available customer actions on a subscription for the My Account view.
 *
 * @param ULX_Subscription $subscription Subscription.
 * @param int              $user_id      User ID.
 * @return array
 */
function ulx_get_all_user_actions_for_subscription( $subscription, $user_id ) {
	$actions = array();
	if ( (int) $subscription->get_user_id() !== (int) $user_id ) {
		return $actions;
	}

	$current_status = $subscription->get_status();

	if ( $subscription->can_be_updated_to( 'on-hold' ) && ulx_can_user_put_subscription_on_hold( $subscription ) ) {
		$actions['suspend'] = array(
			'url'  => ulx_get_users_change_status_link( $subscription->get_id(), 'on-hold', $current_status ),
			'name' => __( 'Suspend', 'ulixes-subscriptions' ),
		);
	}

	if ( $subscription->can_be_updated_to( 'active' ) && ! $subscription->needs_payment() ) {
		$actions['reactivate'] = array(
			'url'  => ulx_get_users_change_status_link( $subscription->get_id(), 'active', $current_status ),
			'name' => __( 'Reactivate', 'ulixes-subscriptions' ),
		);
	}

	if ( ulx_can_user_renew_early( $subscription, $user_id ) ) {
		$actions['subscription_renewal_early'] = array(
			'url'  => ulx_get_early_renewal_url( $subscription ),
			'name' => __( 'Renew now', 'ulixes-subscriptions' ),
		);
	}

	if ( ulx_can_user_resubscribe_to( $subscription, $user_id ) ) {
		$actions['resubscribe'] = array(
			'url'  => ulx_get_users_resubscribe_link( $subscription ),
			'name' => __( 'Resubscribe', 'ulixes-subscriptions' ),
		);
	}

	// Change payment method action.
	if ( ! $subscription->has_status( ULX_Subscription::get_ended_statuses() ) && $subscription->get_total() > 0 && $subscription->payment_method_supports( 'subscription_payment_method_change_customer' ) ) {
		$actions['change_payment_method'] = array(
			'url'  => wp_nonce_url( add_query_arg( array( 'change_payment_method' => $subscription->get_id() ), $subscription->get_checkout_payment_url() ) ),
			'name' => $subscription->is_manual() ? __( 'Add payment', 'ulixes-subscriptions' ) : __( 'Change payment', 'ulixes-subscriptions' ),
		);
	}

	// Change address action.
	if ( $subscription->has_status( array( 'active', 'on-hold' ) ) && $subscription->needs_shipping_address() ) {
		$actions['change_address'] = array(
			'url'  => wp_nonce_url( add_query_arg( array( 'subscription' => $subscription->get_id() ), wc_get_endpoint_url( 'edit-address', 'shipping', wc_get_page_permalink( 'myaccount' ) ) ), 'ulx_edit_address' ),
			'name' => __( 'Change address', 'ulixes-subscriptions' ),
		);
	}

	// Cancel action: if prepaid time remains, move to pending-cancel; otherwise cancel immediately.
	$next_payment = $subscription->get_time( 'next_payment' );
	if ( $subscription->can_be_updated_to( 'cancelled' ) && ! $subscription->has_status( array( 'cancelled', 'expired', 'switched' ) ) ) {
		if ( $subscription->has_status( 'pending-cancel' ) ) {
			// Already pending cancel; only allow immediate cancel if admin or filter permits.
		} elseif ( $subscription->can_be_updated_to( 'pending-cancel' ) && ( $next_payment > time() || $subscription->get_time( 'trial_end' ) > time() || $subscription->get_time( 'end' ) > time() ) ) {
			$actions['cancel'] = array(
				'url'  => ulx_get_users_change_status_link( $subscription->get_id(), 'cancelled', $current_status ),
				'name' => __( 'Cancel', 'ulixes-subscriptions' ),
			);
		} else {
			$actions['cancel'] = array(
				'url'  => ulx_get_users_change_status_link( $subscription->get_id(), 'cancelled', $current_status ),
				'name' => __( 'Cancel', 'ulixes-subscriptions' ),
			);
		}
	}

	return apply_filters( 'ulx_view_subscription_actions', $actions, $subscription, $user_id );
}

/**
 * URL for a customer to change their subscription status.
 *
 * @param int    $subscription_id Subscription ID.
 * @param string $status          New status.
 * @param string $current_status  Current status.
 * @return string
 */
function ulx_get_users_change_status_link( $subscription_id, $status, $current_status = '' ) {
	if ( '' === $current_status ) {
		$sub = ulx_get_subscription( $subscription_id );
		$current_status = $sub ? $sub->get_status() : '';
	}
	$action_link = add_query_arg(
		array(
			'subscription_id'        => $subscription_id,
			'change_subscription_to' => $status,
		),
		wc_get_endpoint_url( 'view-subscription', $subscription_id, wc_get_page_permalink( 'myaccount' ) )
	);
	return wp_nonce_url( $action_link, $subscription_id . $current_status );
}
