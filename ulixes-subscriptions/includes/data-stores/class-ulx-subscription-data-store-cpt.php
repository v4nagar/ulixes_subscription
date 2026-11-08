<?php
/**
 * Subscription data store (posts / CPT storage).
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Subscription_Data_Store_CPT
 */
class ULX_Subscription_Data_Store_CPT extends WC_Order_Data_Store_CPT {

	/**
	 * Create a subscription without triggering 'woocommerce_new_order'.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public function create( &$subscription ) {
		if ( '' === $subscription->get_order_key() ) {
			$subscription->set_order_key( wc_generate_order_key() );
		}
		Abstract_WC_Order_Data_Store_CPT::create( $subscription );
		do_action( 'ulx_new_subscription', $subscription->get_id(), $subscription );
	}

	/**
	 * Update a subscription without triggering order hooks.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public function update( &$subscription ) {
		Abstract_WC_Order_Data_Store_CPT::update( $subscription );
		do_action( 'ulx_update_subscription', $subscription->get_id(), $subscription );
	}

	/**
	 * Count subscriptions by status.
	 *
	 * @return array status => count
	 */
	public function get_subscriptions_count_by_status() {
		global $wpdb;
		$rows   = $wpdb->get_results( "SELECT post_status, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' GROUP BY post_status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row['post_status'] ] = (int) $row['cnt'];
		}
		return $counts;
	}
}
