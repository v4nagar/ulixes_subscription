<?php
/**
 * Cart handler for paying manual / failed renewal orders through the cart & checkout.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Cart_Renewal
 */
class ULX_Cart_Renewal {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_setup_cart_renewal' ), 20 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_renewal_cart_item_price' ), 20 );
	}

	/**
	 * Detect a renewal payment request and load the renewal order items into the cart if requested.
	 */
	public static function maybe_setup_cart_renewal() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( empty( $_GET['pay_for_order'] ) || empty( $_GET['key'] ) ) {
			return;
		}
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! ulx_order_contains_renewal( $order ) ) {
			return;
		}
		// Standard WooCommerce order-pay handles paying the renewal order directly;
		// when completed, ULX_Renewal_Order::maybe_record_subscription_payment activates the subscription.
		// phpcs:enable
	}

	/**
	 * Restore `subscription_renewal` cart item data from session.
	 *
	 * @param array  $cart_item_data Cart item.
	 * @param array  $values         Session values.
	 * @param string $key            Cart item key.
	 * @return array
	 */
	public static function get_cart_item_from_session( $cart_item_data, $values, $key ) {
		if ( isset( $values['subscription_renewal'] ) ) {
			$cart_item_data['subscription_renewal'] = $values['subscription_renewal'];
		}
		return $cart_item_data;
	}

	/**
	 * Ensure renewal cart items use the subscription's recurring line item price (ignoring sign-up fees & trials).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function set_renewal_cart_item_price( $cart ) {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( isset( $item['subscription_renewal']['custom_line_item_price'] ) && isset( $item['data'] ) ) {
				$item['data']->set_price( $item['subscription_renewal']['custom_line_item_price'] );
			}
		}
	}
}

