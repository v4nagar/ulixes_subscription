<?php
/**
 * Renewal and Resubscribe helper functions.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Create a renewal order from a subscription.
 *
 * @param ULX_Subscription|int $subscription Subscription.
 * @return WC_Order|WP_Error
 */
function ulx_create_renewal_order( $subscription ) {
	$subscription = ulx_get_subscription( $subscription );
	if ( ! $subscription ) {
		return new WP_Error( 'ulx_invalid_subscription', __( 'Invalid subscription.', 'ulixes-subscriptions' ) );
	}

	$renewal_order = ulx_create_order_from_subscription( $subscription, 'renewal_order' );
	if ( is_wp_error( $renewal_order ) ) {
		return $renewal_order;
	}

	ulx_add_order_subscription_relation( $renewal_order, $subscription, 'renewal' );
	do_action( 'ulx_renewal_order_created', $renewal_order, $subscription );

	return apply_filters( 'ulx_new_renewal_order', $renewal_order, $subscription );
}

/**
 * Create an order cloned from a subscription (for renewal or resubscribe).
 *
 * @param ULX_Subscription $subscription Subscription.
 * @param string           $type         'renewal_order' | 'resubscribe_order'.
 * @return WC_Order|WP_Error
 */
function ulx_create_order_from_subscription( $subscription, $type = 'renewal_order' ) {
	$subscription = ulx_get_subscription( $subscription );
	if ( ! $subscription ) {
		return new WP_Error( 'ulx_invalid_subscription', __( 'Invalid subscription.', 'ulixes-subscriptions' ) );
	}

	$new_order = wc_create_order(
		array(
			'customer_id'   => $subscription->get_user_id(),
			'customer_note' => $subscription->get_customer_note(),
			'created_via'   => 'subscription',
		)
	);
	if ( is_wp_error( $new_order ) ) {
		return $new_order;
	}

	ulx_copy_order_address( $subscription, $new_order );
	ulx_copy_order_meta( $subscription, $new_order, $type );
	ulx_copy_order_items( $subscription, $new_order );

	$new_order->set_Total( $subscription->get_total() );
	$new_order->set_shipping_total( $subscription->get_shipping_total() );
	$new_order->set_shipping_tax( $subscription->get_shipping_tax() );
	$new_order->set_discount_total( $subscription->get_discount_total() );
	$new_order->set_discount_tax( $subscription->get_discount_tax() );
	$new_order->set_cart_tax( $subscription->get_cart_tax() );
	$new_order->save();
	$new_order->calculate_totals( false );
	$new_order->save();

	return $new_order;
}

/**
 *Can a user resubscribe to a subscription?
 *
 * @param ULX_Subscription|int $subscription Subscription.
 * @param int                  $user_id      User ID.
 * @return bool
 */
function ulx_can_user_resubscribe_to( $subscription, $user_id = 0 ) {
	$subscription = ulx_get_subscription( $subscription );
	if ( ! $subscription ) {
		return false;
	}
	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}
	if ( (int) $subscription->get_user_id() !== (int) $user_id ) {
		return false;
	}
	if ( ! $subscription->has_status( array( 'pending-cancel', 'cancelled', 'expired' ) ) ) {
		return false;
	}
	// If already resubscribed via an existing order, block duplicate resubscribe.
	$resubscribe_orders = $subscription->get_related_orders( 'all', 'resubscribe' );
	foreach ( $resubscribe_orders as $ro ) {
		if ( ! $ro->has_status( array( 'cancelled', 'failed', 'trash' ) ) ) {
			return false;
		}
	}
	$items = $subscription->get_items();
	if ( empty( $items ) ) {
		return false;
	}
	foreach ( $items as $item ) {
		$product = $item->get_product();
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return false;
		}
		$limit = ulx_get_product_limit( $product );
		if ( 'any' === $limit ) {
			return false;
		}
		if ( 'active' === $limit && ulx_user_has_subscription( $user_id, $product->get_id(), array( 'active', 'pending-cancel', 'on-hold' ) ) ) {
			return false;
		}
	}
	return (bool) apply_filters( 'ulx_can_user_resubscribe_to_subscription', true, $subscription, $user_id );
}

/**
 * URL to add a resubscribe to cart.
 *
 * @param ULX_Subscription $subscription Subscription.
 * @return string
 */
function ulx_get_users_resubscribe_link( $subscription ) {
	$subscription = ulx_get_subscription( $subscription );
	if ( ! $subscription ) {
		return '';
	}
	$url = add_query_arg(
		array(
			'resubscribe' => $subscription->get_id(),
		),
		wc_get_page_permalink( 'myaccount' )
	);
	return wp_nonce_url( $url, $subscription->get_id() );
}

/**
 * Can a subscription be renewed early by the user?
 *
 * @param ULX_Subscription|int $subscription Subscription.
 * @param int                  $user_id      User ID.
 * @return bool
 */
function ulx_can_user_renew_early( $subscription, $user_id = 0 ) {
	if ( ! ulx_option_is_yes( 'enable_early_renewal' ) ) {
		return false;
	}
	$subscription = ulx_get_subscription( $subscription );
	if ( ! $subscription ) {
		return false;
	}
	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}
	if ( (int) $subscription->get_user_id() !== (int) $user_id ) {
		return false;
	}
	if ( ! $subscription->has_status( 'active' ) ) {
		return false;
	}
	if ( $subscription->get_time( 'trial_end' ) > time() ) {
		return false;
	}
	if ( ! $subscription->get_time( 'next_payment' ) ) {
		return false;
	}
	if ( $subscription->get_total() <= 0 ) {
		return false;
	}
	// Synced subscriptions cannot be renewed early as it would shift the sync schedule or double-bill.
	foreach ( $subscription->get_items() as $item ) {
		$product = $item->get_product();
		if ( $product && ULX_Sync::is_product_synced( $product ) ) {
			return false;
		}
	}
	return (bool) apply_filters( 'ulx_can_user_renew_early', true, $subscription, $user_id );
}

/**
 * Early renewal URL.
 *
 * @param ULX_Subscription $subscription Subscription.
 * @return string
 */
function ulx_get_early_renewal_url( $subscription ) {
	$subscription = ulx_get_subscription( $subscription );
	if ( ! $subscription ) {
		return '';
	}
	$url = add_query_arg(
		array(
			'subscription_renewal_early' => $subscription->get_id(),
			'subscription_renewal'       => 'true',
		),
		get_permalink( wc_get_page_id( 'myaccount' ) )
	);
	return wp_nonce_url( $url, 'ulx-renew-early-' . $subscription->get_id(), 'ulx_nonce' );
}

/**
 * Pay URL for a manual/failed renewal order.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function ulx_get_early_or_manual_renewal_pay_url( $order ) {
	return $order->get_checkout_payment_url();
}
