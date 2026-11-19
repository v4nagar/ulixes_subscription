<?php
/**
 * Deprecated procedural functions.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Deprecated
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deprecated wrapper: get subscription by ID.
 *
 * @param int|WC_Order $subscription Subscription ID or order.
 * @return ULIXSU_Subscription|false
 */
function ulixsu_deprecated_get_subscription( $subscription ) {
	if ( function_exists( 'wc_deprecated_function' ) ) {
		wc_deprecated_function( __FUNCTION__, '1.0.0', 'ulixsu_get_subscription' );
	}
	return ulixsu_get_subscription( $subscription );
}

/**
 * Deprecated wrapper: check if an ID or order is a subscription.
 *
 * @param int|WC_Order $order Order or ID.
 * @return bool
 */
function ulixsu_deprecated_is_subscription( $order ) {
	if ( function_exists( 'wc_deprecated_function' ) ) {
		wc_deprecated_function( __FUNCTION__, '1.0.0', 'ulixsu_is_subscription' );
	}
	return ulixsu_is_subscription( $order );
}

/**
 * Deprecated wrapper: create a renewal order from a subscription.
 *
 * @param ULIXSU_Subscription|int $subscription Subscription.
 * @return WC_Order|WP_Error
 */
function ulixsu_deprecated_create_renewal_order( $subscription ) {
	if ( function_exists( 'wc_deprecated_function' ) ) {
		wc_deprecated_function( __FUNCTION__, '1.0.0', 'ulixsu_create_renewal_order' );
	}
	return ulixsu_create_renewal_order( $subscription );
}
