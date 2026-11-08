<?php
/**
 * Deprecated procedural functions.
 *
 * Provides backward compatibility wrappers for legacy `ulixes_` procedural functions.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Deprecated
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( ! function_exists( 'ulixes_get_subscription' ) ) {
	/**
	 * Get subscription by ID.
	 *
	 * @param int|WC_Order $subscription Subscription ID or order.
	 * @return ULX_Subscription|false
	 */
	function ulixes_get_subscription( $subscription ) {
		if ( function_exists( 'wc_deprecated_function' ) ) {
			wc_deprecated_function( __FUNCTION__, '1.0.0', 'ulx_get_subscription' );
		}
		return ulx_get_subscription( $subscription );
	}
}

if ( ! function_exists( 'ulixes_is_subscription' ) ) {
	/**
	 * Check if an ID or order is a subscription.
	 *
	 * @param int|WC_Order $order Order or ID.
	 * @return bool
	 */
	function ulixes_is_subscription( $order ) {
		if ( function_exists( 'wc_deprecated_function' ) ) {
			wc_deprecated_function( __FUNCTION__, '1.0.0', 'ulx_is_subscription' );
		}
		return ulx_is_subscription( $order );
	}
}

if ( ! function_exists( 'ulixes_create_renewal_order' ) ) {
	/**
	 * Create a renewal order from a subscription.
	 *
	 * @param ULX_Subscription|int $subscription Subscription.
	 * @return WC_Order|WP_Error
	 */
	function ulixes_create_renewal_order( $subscription ) {
		if ( function_exists( 'wc_deprecated_function' ) ) {
			wc_deprecated_function( __FUNCTION__, '1.0.0', 'ulx_create_renewal_order' );
		}
		return ulx_create_renewal_order( $subscription );
	}
}

