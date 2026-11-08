<?php
/**
 * User role & permission helper functions.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Does a user have a subscription (optionally for a specific product and/or status)?
 *
 * @param int          $user_id    User ID (0 = current user).
 * @param int|string   $product_id Product or variation ID (optional).
 * @param string|array $status     Status or array of statuses (optional, default 'any').
 * @return bool
 */
function ulx_user_has_subscription( $user_id = 0, $product_id = '', $status = 'any' ) {
	$subscriptions = ulx_get_users_subscriptions( $user_id );
	if ( empty( $subscriptions ) ) {
		return false;
	}
	$has = false;
	foreach ( $subscriptions as $subscription ) {
		if ( ! empty( $product_id ) && ! $subscription->has_product( $product_id ) ) {
			continue;
		}
		if ( ! empty( $status ) && 'any' !== $status && ! $subscription->has_status( $status ) ) {
			continue;
		}
		$has = true;
		break;
	}
	return (bool) apply_filters( 'ulx_user_has_subscription', $has, $user_id, $product_id, $status );
}

/**
 * Give the user the active subscriber role (unless they are an admin / shop manager).
 *
 * @param int $user_id User ID.
 */
function ulx_make_user_active( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return;
	}
	$user = get_userdata( $user_id );
	if ( ! $user || user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
		return;
	}
	$subscriber_role = ulx_get_option( 'subscriber_role', 'subscriber' );
	$cancelled_role  = ulx_get_option( 'cancelled_role', 'customer' );
	if ( $cancelled_role && in_array( $cancelled_role, (array) $user->roles, true ) && $cancelled_role !== $subscriber_role ) {
		$user->remove_role( $cancelled_role );
	}
	if ( $subscriber_role && ! in_array( $subscriber_role, (array) $user->roles, true ) ) {
		$user->add_role( $subscriber_role );
	}
	do_action( 'ulx_Activated_user_role', $user_id, $subscriber_role );
}

/**
 * If the user has no remaining active or pending-cancel subscriptions, switch them to the inactive role.
 *
 * @param int $user_id User ID.
 */
function ulx_maybe_make_user_inactive( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return;
	}
	if ( ulx_user_has_subscription( $user_id, '', array( 'active', 'pending-cancel' ) ) ) {
		return;
	}
	$user = get_userdata( $user_id );
	if ( ! $user || user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
		return;
	}
	$subscriber_role = ulx_get_option( 'subscriber_role', 'subscriber' );
	$cancelled_role  = ulx_get_option( 'cancelled_role', 'customer' );
	if ( $subscriber_role && in_array( $subscriber_role, (array) $user->roles, true ) && $subscriber_role !== $cancelled_role ) {
		$user->remove_role( $subscriber_role );
	}
	if ( $cancelled_role && ! in_array( $cancelled_role, (array) $user->roles, true ) ) {
		$user->add_role( $cancelled_role );
	}
	do_action( 'ulx_deactivated_user_role', $user_id, $cancelled_role );
}

/**
 * Check if a user can perform a status change on a subscription.
 *
 * @param ULX_Subscription $subscription Subscription.
 * @param string           $new_status   Target status.
 * @return bool
 */
function ulx_can_user_put_subscription_on_hold( $subscription ) {
	$allow = (int) ulx_get_option( 'allow_customer_suspension', 0 );
	if ( 0 === $allow ) {
		return false;
	}
	if ( -1 !== $allow && $subscription->get_suspension_count() >= $allow ) {
		return false;
	}
	return $subscription->can_be_updated_to( 'on-hold' );
}
