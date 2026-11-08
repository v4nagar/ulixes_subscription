<?php
/**
 * Order helper functions for subscriptions and related orders.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Meta key for a relationship type.
 *
 * @param string $relation_type renewal|resubscribe|switch.
 * @return string
 */
function ulx_get_relation_meta_key( $relation_type ) {
	$map = array(
		'renewal'     => '_subscription_renewal',
		'resubscribe' => '_subscription_resubscribe',
		'switch'      => '_subscription_switch',
	);
	return isset( $map[ $relation_type ] ) ? $map[ $relation_type ] : '_subscription_' . $relation_type;
}

/**
 * Get related order IDs for a subscription.
 *
 * @param int    $subscription_id Subscription ID.
 * @param string $relation_type   renewal|resubscribe|switch.
 * @return int[]
 */
function ulx_get_related_order_ids( $subscription_id, $relation_type = 'renewal' ) {
	$subscription_id = absint( $subscription_id );
	if ( ! $subscription_id ) {
		return array();
	}
	$pre = apply_filters( 'ulx_pre_get_related_order_ids', null, $subscription_id, $relation_type );
	if ( null !== $pre && is_array( $pre ) ) {
		return array_map( 'absint', $pre );
	}
	$meta_key = ulx_get_relation_meta_key( $relation_type );
	$ids      = wc_get_orders(
		array(
			'type'       => 'shop_order',
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => 'any',
			'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => (string) $subscription_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return array_map( 'absint', (array) $ids );
}

/**
 * Link an order to a subscription for a given relation type.
 *
 * @param WC_Order|int         $order        Order.
 * @param ULX_Subscription|int $subscription Subscription.
 * @param string               $relation_type renewal|resubscribe|switch.
 */
function ulx_add_order_subscription_relation( $order, $subscription, $relation_type = 'renewal' ) {
	$order = is_object( $order ) ? $order : wc_get_order( $order );
	$sub_id = is_object( $subscription ) ? $subscription->get_id() : absint( $subscription );
	if ( ! $order || ! $sub_id ) {
		return;
	}
	$meta_key = ulx_get_relation_meta_key( $relation_type );
	$existing = $order->get_meta( $meta_key, false );
	$found    = false;
	foreach ( $existing as $meta ) {
		if ( (int) $meta->value === (int) $sub_id ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		$order->add_meta_data( $meta_key, $sub_id, false );
		$order->save_meta_data();
		do_action( 'ulx_order_subscription_relation_added', $order->get_id(), $sub_id, $relation_type );
	}
}

/**
 * Check if an order contains any subscriptions of given types.
 *
 * @param WC_Order|int $order      Order.
 * @param string|array $order_type parent|renewal|resubscribe|switch|any.
 * @return bool
 */
function ulx_order_contains_subscription( $order, $order_type = array( 'parent', 'resubscribe', 'switch' ) ) {
	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order || $order instanceof ULX_Subscription ) {
		return false;
	}
	$subs = ulx_get_subscriptions_for_order( $order, array( 'order_type' => $order_type ) );
	return ! empty( $subs );
}

/**
 * Check if an order is a renewal order.
 *
 * @param WC_Order|int $order Order.
 * @return bool
 */
function ulx_order_contains_renewal( $order ) {
	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order || $order instanceof ULX_Subscription ) {
		return false;
	}
	return (bool) $order->get_meta( '_subscription_renewal', true );
}

/**
 * Check if an order is an early renewal.
 *
 * @param WC_Order|int $order Order.
 * @return bool
 */
function ulx_order_contains_early_renewal( $order ) {
	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}
	return $order && (bool) $order->get_meta( '_subscription_renewal_early', true );
}

/**
 * Check if an order is a resubscribe order.
 *
 * @param WC_Order|int $order Order.
 * @return bool
 */
function ulx_order_contains_resubscribe( $order ) {
	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}
	return $order && (bool) $order->get_meta( '_subscription_resubscribe', true );
}

/**
 * Check if an order is a switch order.
 *
 * @param WC_Order|int $order Order.
 * @return bool
 */
function ulx_order_contains_switch( $order ) {
	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}
	return $order && (bool) $order->get_meta( '_subscription_switch', true );
}

/**
 * Get all subscriptions linked to an order.
 *
 * @param WC_Order|int $order Order.
 * @param array        $args  Args: order_type (parent|renewal|resubscribe|switch|any).
 * @return ULX_Subscription[]
 */
function ulx_get_subscriptions_for_order( $order, $args = array() ) {
	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order ) {
		return array();
	}
	if ( $order instanceof ULX_Subscription ) {
		return array( $order->get_id() => $order );
	}

	$args        = wp_parse_args( $args, array( 'order_type' => array( 'parent', 'renewal', 'switch' ) ) );
	$order_types = (array) $args['order_type'];
	if ( in_array( 'any', $order_types, true ) ) {
		$order_types = array( 'parent', 'renewal', 'resubscribe', 'switch' );
	}

	$subscriptions = array();

	if ( in_array( 'parent', $order_types, true ) ) {
		$subs = ulx_get_subscriptions(
			array(
				'order_id'               => $order->get_id(),
				'subscriptions_per_page' => -1,
				'subscription_status'    => array( 'any' ),
			)
		);
		foreach ( $subs as $id => $sub ) {
			$subscriptions[ $id ] = $sub;
		}
	}

	foreach ( array( 'renewal', 'resubscribe', 'switch' ) as $rel ) {
		if ( ! in_array( $rel, $order_types, true ) ) {
			continue;
		}
		$metas = $order->get_meta( ulx_get_relation_meta_key( $rel ), false );
		foreach ( $metas as $meta ) {
			$sub = ulx_get_subscription( absint( $meta->value ) );
			if ( $sub ) {
				$subscriptions[ $sub->get_id() ] = $sub;
			}
		}
	}

	return apply_filters( 'ulx_subscriptions_for_order', $subscriptions, $order->get_id(), $args );
}

/**
 * Get subscriptions for a renewal order.
 *
 * @param WC_Order|int $order Order.
 * @return ULX_Subscription[]
 */
function ulx_get_subscriptions_for_renewal_order( $order ) {
	return ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'renewal' ) );
}

/**
 * Get subscriptions for a resubscribe order.
 *
 * @param WC_Order|int $order Order.
 * @return ULX_Subscription[]
 */
function ulx_get_subscriptions_for_resubscribe_order( $order ) {
	return ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'resubscribe' ) );
}

/**
 * Get subscriptions for a switch order.
 *
 * @param WC_Order|int $order Order.
 * @return ULX_Subscription[]
 */
function ulx_get_subscriptions_for_switch_order( $order ) {
	return ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'switch' ) );
}
