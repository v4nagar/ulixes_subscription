<?php
/**
 * Many-To-One Object Data Cache Manager.
 *
 * Caches the relationship between one Subscription ID and its many Renewal/Switch Order IDs
 * in transients and memory, bypassing heavy database SQL JOINs or slow meta queries on the
 * "My Account" pages and subscription admin views.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Cache
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class Ulx_Object_Data_Cache_Manager_Many_To_One
 */
class Ulx_Object_Data_Cache_Manager_Many_To_One {

	/**
	 * In-memory runtime cache for the current request.
	 *
	 * @var array
	 */
	protected static $runtime_cache = array();

	/**
	 * Transient prefix.
	 */
	const TRANSIENT_PREFIX = 'ulx_rel_';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'ulx_order_subscription_relation_added', array( __CLASS__, 'on_relation_added' ), 10, 3 );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'on_order_deleted' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_order_deleted' ), 10, 1 );
		add_action( 'ulx_subscription_cache_cleared', array( __CLASS__, 'clear_cache_for_subscription' ), 10, 1 );
		add_filter( 'ulx_pre_get_related_order_ids', array( __CLASS__, 'filter_get_related_order_ids' ), 10, 3 );
	}

	/**
	 * Generate transient cache key.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $relation_type   Relationship type (e.g. 'renewal', 'switch', 'resubscribe').
	 * @return string
	 */
	public static function get_cache_key( $subscription_id, $relation_type = 'renewal' ) {
		return self::TRANSIENT_PREFIX . sanitize_key( $relation_type ) . '_' . absint( $subscription_id );
	}

	/**
	 * Retrieve related order IDs for a subscription from cache, or query and store.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $relation_type   Relationship type ('renewal', 'switch', 'resubscribe').
	 * @return int[]
	 */
	public static function get_cached_related_order_ids( $subscription_id, $relation_type = 'renewal' ) {
		$subscription_id = absint( $subscription_id );
		$relation_type   = sanitize_key( $relation_type );

		if ( ! $subscription_id ) {
			return array();
		}

		// 1. Check runtime memory cache.
		if ( isset( self::$runtime_cache[ $relation_type ][ $subscription_id ] ) ) {
			return self::$runtime_cache[ $relation_type ][ $subscription_id ];
		}

		// 2. Check transient cache.
		$cache_key = self::get_cache_key( $subscription_id, $relation_type );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			self::$runtime_cache[ $relation_type ][ $subscription_id ] = $cached;
			return $cached;
		}

		// 3. Cache miss: execute HPOS & CPT compatible order lookup.
		$meta_key  = ulx_get_relation_meta_key( $relation_type );
		$order_ids = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => -1,
				'return'     => 'ids',
				'status'     => 'any',
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (string) $subscription_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$order_ids = array_map( 'absint', (array) $order_ids );

		// 4. Save to caches.
		self::set_cached_related_order_ids( $subscription_id, $order_ids, $relation_type );

		return $order_ids;
	}

	/**
	 * Save related order IDs into transient and runtime memory.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param array  $order_ids       Array of order IDs.
	 * @param string $relation_type   Relationship type.
	 * @param int    $expiration      Cache duration in seconds.
	 */
	public static function set_cached_related_order_ids( $subscription_id, $order_ids, $relation_type = 'renewal', $expiration = DAY_IN_SECONDS ) {
		$subscription_id = absint( $subscription_id );
		$relation_type   = sanitize_key( $relation_type );
		$cache_key       = self::get_cache_key( $subscription_id, $relation_type );

		$order_ids = array_unique( array_map( 'absint', (array) $order_ids ) );
		rsort( $order_ids );

		self::$runtime_cache[ $relation_type ][ $subscription_id ] = $order_ids;
		set_transient( $cache_key, $order_ids, $expiration );
	}

	/**
	 * Incrementally add a new order relationship to the cached array without running a slow query.
	 *
	 * @param int    $order_id        New Order ID.
	 * @param int    $subscription_id Subscription ID.
	 * @param string $relation_type   Relationship type.
	 */
	public static function on_relation_added( $order_id, $subscription_id, $relation_type = 'renewal' ) {
		$subscription_id = absint( $subscription_id );
		$order_id        = absint( $order_id );
		$relation_type   = sanitize_key( $relation_type );

		if ( ! $subscription_id || ! $order_id ) {
			return;
		}

		$existing = self::get_cached_related_order_ids( $subscription_id, $relation_type );
		if ( ! in_array( $order_id, $existing, true ) ) {
			array_unshift( $existing, $order_id );
			self::set_cached_related_order_ids( $subscription_id, $existing, $relation_type );
		}
	}

	/**
	 * When an order is deleted, remove it from cached subscription relationships.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order_deleted( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof WC_Order ) || $order instanceof ULX_Subscription ) {
			return;
		}

		foreach ( array( 'renewal', 'switch', 'resubscribe' ) as $rel ) {
			$meta_key = ulx_get_relation_meta_key( $rel );
			$sub_ids  = (array) $order->get_meta( $meta_key, false );
			foreach ( $sub_ids as $sub_id_meta ) {
				$sub_id = is_object( $sub_id_meta ) ? absint( $sub_id_meta->value ) : absint( $sub_id_meta );
				if ( $sub_id > 0 ) {
					$cached = self::get_cached_related_order_ids( $sub_id, $rel );
					$filtered = array_diff( $cached, array( $order_id ) );
					self::set_cached_related_order_ids( $sub_id, $filtered, $rel );
				}
			}
		}
	}

	/**
	 * Clear all related order relationship transients for a given subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function clear_cache_for_subscription( $subscription_id ) {
		$subscription_id = absint( $subscription_id );
		if ( ! $subscription_id ) {
			return;
		}

		foreach ( array( 'renewal', 'switch', 'resubscribe' ) as $rel ) {
			delete_transient( self::get_cache_key( $subscription_id, $rel ) );
			unset( self::$runtime_cache[ $rel ][ $subscription_id ] );
		}
	}

	/**
	 * Hook into `ulx_pre_get_related_order_ids` to intercept order functions and serve from cache.
	 *
	 * @param mixed  $pre             Existing pre-filtered value.
	 * @param int    $subscription_id Subscription ID.
	 * @param string $relation_type   Relationship type.
	 * @return array
	 */
	public static function filter_get_related_order_ids( $pre, $subscription_id, $relation_type ) {
		return self::get_cached_related_order_ids( $subscription_id, $relation_type );
	}

	/**
	 * Clear all many-to-one transients across the entire store (used by debug tools).
	 *
	 * @return int Number of transients deleted.
	 */
	public static function clear_all_caches() {
		global $wpdb;

		self::$runtime_cache = array();

		// Delete both standard and timeout transients for ulx_rel_ and ulx_sub_.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_ulx_rel_%',
				'_transient_timeout_ulx_rel_%'
			)
		);

		return (int) $count;
	}
}

