<?php
/**
 * Subscription Purchase Limiter (limit to 1 active subscription or 1 subscription of any status per customer).
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Limiter
 */
class ULX_Limiter {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_cart_limit' ), 20, 3 );
	}

	/**
	 * Filter `is_purchasable` for subscription products with a purchase limit.
	 *
	 * @param bool       $purchasable Purchasable.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public static function is_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! is_user_logged_in() ) {
			return $purchasable;
		}
		// Allow resubscribe and switch flows when permitted.
		if ( isset( $_GET['resubscribe'] ) || isset( $_GET['switch-subscription'] ) || ulx_cart_contains_resubscribe() || ulx_cart_contains_switches() || ulx_cart_contains_renewal() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $purchasable;
		}

		$limit = ulx_get_product_limit( $product );
		if ( 'no' === $limit || empty( $limit ) ) {
			return $purchasable;
		}

		$check_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$user_id  = get_current_user_id();

		if ( 'active' === $limit ) {
			if ( ulx_user_has_subscription( $user_id, $check_id, array( 'active', 'on-hold', 'pending-cancel' ) ) ) {
				return false;
			}
		} elseif ( 'any' === $limit ) {
			if ( ulx_user_has_subscription( $user_id, $check_id, 'any' ) ) {
				return false;
			}
		}

		return $purchasable;
	}

	/**
	 * Prevent adding duplicate limited subscription products to the cart.
	 *
	 * @param bool $valid      Valid.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function validate_cart_limit( $valid, $product_id, $quantity ) {
		if ( ! $valid ) {
			return $valid;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! ulx_is_subscription_product( $product ) ) {
			return $valid;
		}
		$limit = ulx_get_product_limit( $product );
		if ( 'no' === $limit || empty( $limit ) ) {
			return $valid;
		}
		if ( ! self::is_purchasable( true, $product ) ) {
			wc_add_notice( __( 'You already have a subscription to this product and cannot purchase another.', 'ulixes-subscriptions' ), 'error' );
			return false;
		}
		return $valid;
	}
}

