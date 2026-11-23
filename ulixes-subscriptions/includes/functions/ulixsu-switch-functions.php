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
 * @param ULIXSU_Subscription      $subscription Subscription.
 * @param WC_Order_Item_Product $item         Order item.
 * @return bool
 */
function ulixsu_can_item_be_switched_by_user( $subscription, $item ) {
	$subscription = ulixsu_get_subscription( $subscription );
	if ( ! $subscription || ! ( $item instanceof WC_Order_Item_Product ) ) {
		return false;
	}
	// A guest customer ID (0) is not proof of ownership.
	if ( ! is_user_logged_in() || ! $subscription->get_user_id() || (int) $subscription->get_user_id() !== get_current_user_id() ) {
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
	if ( $is_variable && 'no' !== ulixsu_get_option( 'allow_switching_variable', 'yes' ) ) {
		return (bool) apply_filters( 'ulixsu_can_item_be_switched_by_user', true, $item, $subscription );
	}

	if ( 'no' !== ulixsu_get_option( 'allow_switching_grouped', 'yes' ) ) {
		// Check if product belongs to any grouped product.
		$parent_grouped = ulixsu_get_grouped_parent_id( $product->get_id() );
		if ( $parent_grouped ) {
			return (bool) apply_filters( 'ulixsu_can_item_be_switched_by_user', true, $item, $subscription );
		}
	}

	if ( 'no' !== ulixsu_get_option( 'allow_switching_simple', 'yes' ) || ! ulixsu_option_is_yes( 'multiple_purchase' ) ) {
		return (bool) apply_filters( 'ulixsu_can_item_be_switched_by_user', true, $item, $subscription );
	}

	return (bool) apply_filters( 'ulixsu_can_item_be_switched_by_user', false, $item, $subscription );
}

/**
 * Find a grouped product containing a given child product ID.
 *
 * @param int $product_id Product ID.
 * @return int 0 if none.
 */
function ulixsu_get_grouped_parent_id( $product_id ) {
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
 * @param ULIXSU_Subscription $subscription Subscription.
 * @return string
 */
function ulixsu_get_switch_url( $item_id, $item, $subscription ) {
	$product = $item->get_product();
	if ( ! $product ) {
		return '';
	}
	$parent_id = $product->get_parent_id() ? $product->get_parent_id() : ulixsu_get_grouped_parent_id( $product->get_id() );
	$target_id = $parent_id ? $parent_id : $product->get_id();
	$permalink = get_permalink( $target_id );

	$url = add_query_arg(
		array(
			'switch-subscription' => $subscription->get_id(),
			'item'                => $item_id,
		),
		$permalink
	);
	return wp_nonce_url( $url, 'ulixsu_switch_request', '_ulixsunonce' );
}
