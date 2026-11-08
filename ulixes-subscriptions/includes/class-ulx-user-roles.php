<?php
/**
 * Subscriber & Inactive Subscriber Role Synchronization.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_User_Roles
 */
class ULX_User_Roles {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'ulx_subscription_status_active', array( __CLASS__, 'on_subscription_active' ), 10, 1 );
		add_action( 'ulx_subscription_status_on-hold', array( __CLASS__, 'on_subscription_inactive' ), 10, 1 );
		add_action( 'ulx_subscription_status_cancelled', array( __CLASS__, 'on_subscription_inactive' ), 10, 1 );
		add_action( 'ulx_subscription_status_expired', array( __CLASS__, 'on_subscription_inactive' ), 10, 1 );
	}

	/**
	 * Grant active subscriber role.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public static function on_subscription_active( $subscription ) {
		ulx_make_user_active( $subscription->get_user_id() );
	}

	/**
	 * Transition to inactive role if no other active subscriptions exist.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public static function on_subscription_inactive( $subscription ) {
		ulx_maybe_make_user_inactive( $subscription->get_user_id() );
	}
}

