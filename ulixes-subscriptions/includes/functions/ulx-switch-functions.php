<?php
/**
 * Switch (Upgrade / Downgrade / Crossgrade) helper functions.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Can a subscription item be switched by the user?
 *
 * @param ULX_Subscription      $subscription Subscription.
 * @param WC_Order_Item_Product $item         Order item.
 * @return bool
 */
function ulx_can_item_be_switched_by_user( $subscription, $item ) {
	$subscription = ulx_get_subscription( $subscription );
	if ( ! $subscription || ! ( $item instanceof WC_Order_Item_Product ) ) {
		return false;
	}
	if ( ! $subscription->has_status( array( 'active', 'on-hold' ) ) ) {
		return false;
	}

	$product = $item->get_product();
	if ( ! $product ) {
		return false;
	}

	$is_variable = $product->is_type( array( 'subscription_variation', 'variable-subscription', 'variation' ) ) || $item->get_variation_id() > 0 || $product->get_parent_id() > 0;
	if ( $is_variable && 'no' !== ulx_get_option( 'allow_switching_variable', 'yes' ) ) {
		return (bool) apply_filters( 'ulx_can_item_be_switched_by_user', true, $item, $subscription );
	}

	if ( 'no' !== ulx_get_option( 'allow_switching_grouped', 'yes' ) ) {
		// Check if product belongs to any grouped product.
		$parent_grouped = ulx_get_grouped_parent_id( $product->get_id() );
		if ( $parent_grouped ) {
			return (bool) apply_filters( 'ulx_can_item_be_switched_by_user', true, $item, $subscription );
		}
	}

	return (bool) apply_filters( 'ulx_can_item_be_switched_by_user', false, $item, $subscription );
}

/**
 * Find a grouped product containing a given child product ID.
 *
 * @param int $product_id Product ID.
 * @return int 0 if none.
 */
function ulx_get_grouped_parent_id( $product_id ) {
	global $wpdb;
	$like   = '%"' . absint( $product_id ) . '"%';
	$like2  = '%:' . absint( $product_id ) . ';%';
	$parent = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_children' AND (meta_value LIKE %s OR meta_value LIKE %s) LIMIT 1",
			$like,
			$like2
		)
	);
	return absint( $parent );
}

/**
 * Get the switch URL for a subscription line item.
 *
 * @param int              $item_id      Order item ID.
 * @param WC_Order_Item    $item         Order item.
 * @param ULX_Subscription $subscription Subscription.
 * @return string
 */
function ulx_get_switch_url( $item_id, $item, $subscription ) {
	$product = $item->get_product();
	if ( ! $product ) {
		return '';
	}
	$parent_id = $product->get_parent_id() ? $product->get_parent_id() : ulx_get_grouped_parent_id( $product->get_id() );
	$target_id = $parent_id ? $parent_id : $product->get_id();
	$permalink = get_permalink( $target_id );

	$url = add_query_arg(
		array(
			'switch-subscription' => $subscription->get_id(),
			'item'                => $item_id,
		),
		$permalink
	);
	return wp_nonce_url( $url, 'ulx_switch_request', '_ulxnonce' );
}
