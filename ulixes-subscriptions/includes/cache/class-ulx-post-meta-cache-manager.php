<?php
/**
 * Post Meta & Subscription Cache Manager.
 *
 * Hooks into metadata updates and deletions (both CPT and HPOS) to automatically
 * clear and rebuild transient caches whenever a subscription's status, total,
 * or billing schedules change.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Cache
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class Ulx_Post_Meta_Cache_Manager
 */
class Ulx_Post_Meta_Cache_Manager {

	/**
	 * Monitored meta keys.
	 *
	 * @var array
	 */
	protected static $monitored_keys = array(
		// Schedules.
		'_schedule_next_payment',
		'_schedule_end',
		'_schedule_trial_end',
		'_schedule_start',
		'_schedule_payment_retry',
		'_schedule_cancelled',
		// Billing settings.
		'_billing_period',
		'_billing_interval',
		// Status & payment.
		'_subscription_status',
		'_status',
		'_requires_manual_renewal',
		// Totals.
		'_order_total',
		'_order_tax',
		'_order_shipping',
	);

	/**
	 * Monitored HPOS order properties.
	 *
	 * @var array
	 */
	protected static $monitored_props = array(
		'status',
		'total',
		'tax_total',
		'shipping_total',
		'date_created',
		'date_modified',
	);

	/**
	 * Init hooks.
	 */
	public static function init() {
		// CPT metadata hooks.
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 4 );

		// HPOS hooks.
		add_action( 'woocommerce_order_object_updated_props', array( __CLASS__, 'on_hpos_props_updated' ), 10, 2 );
		add_action( 'ulx_update_subscription', array( __CLASS__, 'on_subscription_updated' ), 10, 2 );
		add_action( 'ulx_subscription_status_updated', array( __CLASS__, 'on_status_updated' ), 10, 3 );
		add_action( 'ulx_subscription_date_updated', array( __CLASS__, 'on_date_updated' ), 10, 3 );
	}

	/**
	 * Detect relevant meta changes in standard WordPress postmeta.
	 *
	 * @param int|array $meta_ids   Meta ID or IDs.
	 * @param int       $object_id  Post / Order ID.
	 * @param string    $meta_key   Meta key changed.
	 * @param mixed     $meta_value New meta value.
	 */
	public static function on_meta_change( $meta_ids, $object_id, $meta_key, $meta_value = null ) {
		if ( ! in_array( $meta_key, self::$monitored_keys, true ) ) {
			return;
		}

		$post_type = get_post_type( $object_id );
		if ( 'shop_subscription' !== $post_type && ! ulx_is_subscription( $object_id ) ) {
			return;
		}

		self::clear_subscription_transients( $object_id );
	}

	/**
	 * Detect changes on HPOS order/subscription objects.
	 *
	 * @param WC_Order $order         Order or subscription.
	 * @param array    $updated_props Array of updated property keys.
	 */
	public static function on_hpos_props_updated( $order, $updated_props ) {
		if ( ! ( $order instanceof WC_Order ) || ! ulx_is_subscription( $order ) ) {
			return;
		}

		$intersect = array_intersect( (array) $updated_props, self::$monitored_props );
		if ( ! empty( $intersect ) ) {
			self::clear_subscription_transients( $order->get_id() );
		}
	}

	/**
	 * Handle subscription object save/update.
	 *
	 * @param int              $subscription_id Subscription ID.
	 * @param ULX_Subscription $subscription    Subscription object.
	 */
	public static function on_subscription_updated( $subscription_id, $subscription = null ) {
		self::clear_subscription_transients( $subscription_id );
	}

	/**
	 * Handle subscription status update.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $new_status   New status.
	 * @param string           $old_status   Old status.
	 */
	public static function on_status_updated( $subscription, $new_status, $old_status ) {
		if ( $subscription ) {
			self::clear_subscription_transients( $subscription->get_id() );
		}
	}

	/**
	 * Handle subscription date update.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $date_type    Date type.
	 * @param string           $datetime     New date string.
	 */
	public static function on_date_updated( $subscription, $date_type, $datetime ) {
		if ( $subscription ) {
			self::clear_subscription_transients( $subscription->get_id() );
		}
	}

	/**
	 * Clear all transient caches for a given subscription and invalidate user subscription lists.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function clear_subscription_transients( $subscription_id ) {
		$subscription_id = absint( $subscription_id );
		if ( ! $subscription_id ) {
			return;
		}

		// Delete cached subscription data.
		delete_transient( 'ulx_sub_' . $subscription_id . '_summary' );
		delete_transient( 'ulx_sub_' . $subscription_id . '_dates' );
		delete_transient( 'ulx_sub_' . $subscription_id . '_totals' );

		// Flush object caches.
		wp_cache_delete( $subscription_id, 'post_meta' );
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $subscription_id );
		}

		// Invalidate user subscription list caches.
		$user_id = 0;
		$subscription = ulx_get_subscription( $subscription_id );
		if ( $subscription ) {
			$user_id = $subscription->get_customer_id();
		}

		if ( $user_id > 0 ) {
			delete_transient( 'ulx_user_subs_' . $user_id );
			wp_cache_delete( 'ulx_user_subs_' . $user_id, 'ulx_subscriptions' );
		}

		do_action( 'ulx_subscription_cache_cleared', $subscription_id, $user_id );
	}

	/**
	 * Get cached summary for a subscription, or generate and cache it.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array
	 */
	public static function get_cached_subscription_summary( $subscription_id ) {
		$transient_key = 'ulx_sub_' . absint( $subscription_id ) . '_summary';
		$cached        = get_transient( $transient_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return array();
		}

		$summary = array(
			'id'               => $subscription->get_id(),
			'status'           => $subscription->get_status(),
			'user_id'          => $subscription->get_customer_id(),
			'total'            => $subscription->get_total(),
			'billing_period'   => $subscription->get_billing_period(),
			'billing_interval' => $subscription->get_billing_interval(),
			'next_payment'     => $subscription->get_date( 'next_payment' ),
			'end_date'         => $subscription->get_date( 'end' ),
			'is_manual'        => $subscription->is_manual(),
		);

		set_transient( $transient_key, $summary, DAY_IN_SECONDS );
		return $summary;
	}
}

