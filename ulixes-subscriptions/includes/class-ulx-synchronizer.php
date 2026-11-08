<?php
/**
 * State Synchronizer for Dual-Storage (CPT & HPOS).
 *
 * Ensures data consistency across WordPress Custom Post Types (wp_posts, wp_postmeta)
 * and WooCommerce High-Performance Order Storage flat tables (wc_orders, wc_orders_meta)
 * when orders or subscriptions are updated or when database modes switch.
 * Matches WooCommerce Subscriptions' WCS_Synchroniser architecture.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Synchronizer
 */
class ULX_Synchronizer {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'ulx_update_subscription', array( __CLASS__, 'sync_subscription_data' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'sync_order_relationships' ), 10, 2 );
	}

	/**
	 * Synchronize subscription post meta and order meta when updated.
	 *
	 * @param int              $subscription_id Subscription ID.
	 * @param ULX_Subscription $subscription    Subscription object.
	 */
	public static function sync_subscription_data( $subscription_id, $subscription = null ) {
		if ( ! $subscription ) {
			$subscription = ulx_get_subscription( $subscription_id );
		}
		if ( ! $subscription ) {
			return;
		}

		// Invalidate WordPress post meta caches so fresh data is always queried.
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $subscription_id );
		}
		wp_cache_delete( $subscription_id, 'post_meta' );

		// If HPOS sync is active, backfill key postmeta for third party plugins relying on wp_postmeta.
		if ( ulx_is_hpos_enabled() ) {
			$critical_keys = array(
				'_billing_period'          => $subscription->get_billing_period(),
				'_billing_interval'        => $subscription->get_billing_interval(),
				'_schedule_next_payment'   => $subscription->get_date( 'next_payment' ),
				'_schedule_end'            => $subscription->get_date( 'end' ),
				'_schedule_trial_end'      => $subscription->get_date( 'trial_end' ),
				'_subscription_status'     => $subscription->get_status(),
				'_requires_manual_renewal' => $subscription->is_manual() ? 'true' : 'false',
			);
			foreach ( $critical_keys as $k => $v ) {
				update_post_meta( $subscription_id, $k, $v );
			}
		}
	}

	/**
	 * Synchronize order-subscription relation metadata between flat tables and postmeta.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public static function sync_order_relationships( $order_id, $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || $order instanceof ULX_Subscription ) {
			return;
		}

		if ( ulx_is_hpos_enabled() ) {
			// Backfill relation meta keys for backwards compatibility.
			$renewal = $order->get_meta( '_subscription_renewal', true );
			if ( $renewal ) {
				update_post_meta( $order_id, '_subscription_renewal', $renewal );
			}
			$switch = $order->get_meta( '_subscription_switch', true );
			if ( $switch ) {
				update_post_meta( $order_id, '_subscription_switch', $switch );
			}
		}
	}
}

