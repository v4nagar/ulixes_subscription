<?php
/**
 * Cart helper functions for subscriptions and recurring carts.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Does the current cart contain any subscription products (or renewal/resubscribe)?
 *
 * @return bool
 */
function ulx_cart_contains_subscription() {
	if ( empty( WC()->cart ) || WC()->cart->is_empty() ) {
		return false;
	}
	if ( ulx_cart_contains_renewal() ) {
		return true;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! empty( $item['data'] ) && ulx_is_subscription_product( $item['data'] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Does the cart contain a subscription renewal item?
 *
 * @param string $role 'parent' or 'child' (default 'child').
 * @return array|false Cart item or false.
 */
function ulx_cart_contains_renewal( $role = 'child' ) {
	if ( ! empty( WC()->cart ) && ! WC()->cart->is_empty() ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['subscription_renewal'] ) && ( empty( $role ) || $role === $item['subscription_renewal']['role'] ) ) {
				return $item;
			}
		}
	}
	return false;
}

/**
 * Does the cart contain an early renewal?
 *
 * @return array|false
 */
function ulx_cart_contains_early_renewal() {
	$item = ulx_cart_contains_renewal();
	if ( $item && ! empty( $item['subscription_renewal']['subscription_renewal_early'] ) ) {
		return $item;
	}
	return false;
}

/**
 * Does the cart contain a resubscribe?
 *
 * @param WC_Cart|null $cart Cart.
 * @return array|false
 */
function ulx_cart_contains_resubscribe( $cart = null ) {
	if ( null === $cart ) {
		$cart = isset( WC()->cart ) ? WC()->cart : null;
	}
	if ( ! empty( $cart ) && ! $cart->is_empty() ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['subscription_resubscribe'] ) ) {
				return $item;
			}
		}
	}
	return false;
}

/**
 * Does the cart contain a failed renewal order payment?
 *
 * @return array|false
 */
function ulx_cart_contains_failed_renewal_order_payment() {
	$item = ulx_cart_contains_renewal();
	if ( $item && ! empty( $item['subscription_renewal']['renewal_order_id'] ) ) {
		$order = wc_get_order( $item['subscription_renewal']['renewal_order_id'] );
		if ( $order && $order->has_status( 'failed' ) ) {
			return $item;
		}
	}
	return false;
}

/**
 * Does the cart contain switches?
 *
 * @param string $switch_type 'any' | 'upgraded' | 'downgraded' | 'crossgraded'.
 * @return array|false Array of switch cart items or false.
 */
function ulx_cart_contains_switches( $switch_type = 'any' ) {
	if ( empty( WC()->cart ) || WC()->cart->is_empty() ) {
		return false;
	}
	$switches = array();
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		if ( isset( $item['subscription_switch'] ) ) {
			if ( 'any' === $switch_type || ( isset( $item['subscription_switch']['upgraded_or_downgraded'] ) && $switch_type === $item['subscription_switch']['upgraded_or_downgraded'] ) ) {
				$switches[ $key ] = $item['subscription_switch'];
			}
		}
	}
	return empty( $switches ) ? false : $switches;
}

/**
 * Build a unique recurring cart key for a cart item based on its billing schedule, trial, length and sync date.
 *
 * @param array $cart_item      Cart item.
 * @param int   $renewal_time   Next payment timestamp.
 * @return string
 */
function ulx_get_Recurring_cart_key( $cart_item, $renewal_time = 0 ) {
	$product  = $cart_item['data'];
	$id       = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];
	$period   = ulx_get_product_period( $product );
	$interval = ulx_get_product_interval( $product );
	$length   = ulx_get_product_length( $product );
	$trial_l  = ulx_get_product_trial_length( $product );
	$trial_p  = ulx_get_product_trial_period( $product );

	if ( ! $renewal_time ) {
		$first = ulx_get_product_first_renewal_payment_date( $product );
		$renewal_time = $first ? ulx_date_to_time( $first ) : 0;
	}

	$key = $renewal_time ? gmdate( 'Y_m_d_', $renewal_time ) : '0_';
	if ( $interval > 1 ) {
		$key .= $interval . '_';
	}
	$key .= $period . 'ly';

	if ( $trial_l > 0 ) {
		$key .= sprintf( '_after_a_%d_%s_trial', $trial_l, $trial_p );
	}
	if ( $length > 0 ) {
		$key .= sprintf( '_for_%d_%ss', $length, $period );
	}
	if ( ! ulx_option_is_yes( 'multiple_purchase' ) ) {
		// Group all items with identical billing schedule together.
	}
	if ( isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
		$key .= '_switch_' . absint( $cart_item['subscription_switch']['subscription_id'] );
	}
	if ( isset( $cart_item['subscription_resubscribe']['subscription_id'] ) ) {
		$key .= '_resubscribe_' . absint( $cart_item['subscription_resubscribe']['subscription_id'] );
	}

	return apply_filters( 'ulx_recurring_cart_key', $key, $cart_item );
}

/**
 * Format a recurring cart's total with its schedule.
 *
 * @param string  $total_html     Formatted total.
 * @param WC_Cart $recurring_cart Recurring cart.
 * @return string
 */
function ulx_cart_price_string( $total_html, $recurring_cart ) {
	return ulx_get_formatted_price_string(
		array(
			'recurring_amount'      => $total_html,
			'subscription_period'   => getattr_or_prop( $recurring_cart, 'subscription_period', 'month' ),
			'subscription_interval' => getattr_or_prop( $recurring_cart, 'subscription_period_interval', 1 ),
			'subscription_length'   => getattr_or_prop( $recurring_cart, 'subscription_length', 0 ),
			'trial_length'          => 0,
		)
	);
}

/**
 * Helper to read dynamic property on WC_Cart.
 *
 * @param object $obj     Object.
 * @param string $prop    Prop.
 * @param mixed  $default Default.
 * @return mixed
 */
function getattr_or_prop( $obj, $prop, $default = null ) {
	return isset( $obj->$prop ) ? $obj->$prop : $default;
}
