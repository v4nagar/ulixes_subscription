<?php
/**
 * Subscriber & Inactive Subscriber Role Synchronization.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULIXSU_User_Roles
 */
class ULIXSU_User_Roles {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'ulixsu_subscription_status_active', array( __CLASS__, 'on_subscription_active' ), 10, 1 );
		add_action( 'ulixsu_subscription_status_on-hold', array( __CLASS__, 'on_subscription_inactive' ), 10, 1 );
		add_action( 'ulixsu_subscription_status_cancelled', array( __CLASS__, 'on_subscription_inactive' ), 10, 1 );
		add_action( 'ulixsu_subscription_status_expired', array( __CLASS__, 'on_subscription_inactive' ), 10, 1 );
	}

	/**
	 * Grant active subscriber role.
	 *
	 * @param ULIXSU_Subscription $subscription Subscription.
	 */
	public static function on_subscription_active( $subscription ) {
		ulixsu_make_user_active( $subscription->get_user_id() );
	}

	/**
	 * Transition to inactive role if no other active subscriptions exist.
	 *
	 * @param ULIXSU_Subscription $subscription Subscription.
	 */
	public static function on_subscription_inactive( $subscription ) {
		ulixsu_maybe_make_user_inactive( $subscription->get_user_id() );
	}
}

