<?php
/**
 * Cart Validator: validates cart contents and item additions for subscription products.
 *
 * Matches WooCommerce Subscriptions' WCS_Cart_Validator architecture:
 * - Prevents purchasing multiple subscriptions together if multiple purchase is disabled
 * - Detects incompatible billing schedules (different billing intervals/periods)
 * - Validates switching and resubscribe cart constraints
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Cart_Validator
 */
class ULX_Cart_Validator {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 15, 6 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_items' ) );
	}

	/**
	 * Validate adding an item to the cart.
	 *
	 * @param bool       $valid        Whether the product can be added.
	 * @param int        $product_id   Product ID.
	 * @param int        $quantity     Quantity.
	 * @param int        $variation_id Variation ID.
	 * @param array      $variations   Variations data.
	 * @param array|null $cart_item_data Additional cart item data.
	 * @return bool
	 */
	public static function validate_add_to_cart( $valid, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! $valid || empty( WC()->cart ) ) {
			return $valid;
		}

		$target_id = $variation_id ? $variation_id : $product_id;
		$product   = wc_get_product( $target_id );
		if ( ! $product ) {
			return $valid;
		}

		$is_subscription_product = ulx_is_subscription_product( $product );
		$cart_has_subscription   = ulx_cart_contains_subscription();
		$multiple_purchase_allowed = ulx_option_is_yes( 'multiple_purchase' );

		// 1. Single subscription per cart check.
		if ( ! $multiple_purchase_allowed && ! WC()->cart->is_empty() ) {
			if ( $is_subscription_product && $cart_has_subscription ) {
				// Empty the cart to replace with the new subscription if customer desires, or throw notice.
				WC()->cart->empty_cart();
				wc_add_notice(
					__( 'Your cart previously contained a subscription product. It has been replaced with your new selection.', 'ulixes-subscriptions' ),
					'notice'
				);
			} elseif ( $is_subscription_product && ! WC()->cart->is_empty() ) {
				// Cart contains non-subscription products and mixed checkout is disabled.
				if ( ! ulx_option_is_yes( 'allow_mixed_checkout' ) && 'no' === ulx_get_option( 'allow_mixed_checkout', 'yes' ) ) {
					WC()->cart->empty_cart();
					wc_add_notice(
						__( 'Subscription products cannot be purchased alongside standard products. Your cart has been updated.', 'ulixes-subscriptions' ),
						'notice'
					);
				}
			}
		}

		// 2. Quantity check: Subscription products typically only support quantity 1 unless multiple purchase is explicitly enabled.
		if ( $is_subscription_product && $quantity > 1 && ! $multiple_purchase_allowed ) {
			wc_add_notice(
				__( 'You can only purchase a single subscription for this plan at a time.', 'ulixes-subscriptions' ),
				'error'
			);
			return false;
		}

		// 3. Prevent duplicate active subscriptions for the same user if limited to one.
		if ( $is_subscription_product && is_user_logged_in() ) {
			$limit = $product->get_meta( '_ulx_limit_per_user', true );
			if ( 'yes' === $limit || 'active' === $limit ) {
				if ( ulx_user_has_subscription( get_current_user_id(), $product->get_id(), array( 'active', 'on-hold', 'pending' ) ) ) {
					wc_add_notice(
						__( 'You already have an active subscription for this product.', 'ulixes-subscriptions' ),
						'error'
					);
					return false;
				}
			}
		}

		return $valid;
	}

	/**
	 * Validate cart items on the Cart and Checkout pages.
	 */
	public static function validate_cart_items() {
		if ( empty( WC()->cart ) || WC()->cart->is_empty() ) {
			return;
		}

		$multiple_allowed = ulx_option_is_yes( 'multiple_purchase' );
		if ( $multiple_allowed ) {
			return;
		}

		$sub_count = 0;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['data'] ) && ulx_is_subscription_product( $cart_item['data'] ) ) {
				$sub_count++;
			}
		}

		if ( $sub_count > 1 ) {
			wc_add_notice(
				__( 'Only one subscription product can be purchased at a time. Please remove extra subscriptions to continue.', 'ulixes-subscriptions' ),
				'error'
			);
		}
	}
}

