<?php
/**
 * Cart handler for Resubscribing to an expired, cancelled or pending-cancel subscription.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Cart_Resubscribe
 */
class ULX_Cart_Resubscribe {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_setup_resubscribe_via_cart' ), 20 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 10, 3 );
		add_filter( 'ulx_product_trial_length', array( __CLASS__, 'remove_trial_on_resubscribe' ), 10, 2 );
		add_filter( 'ulx_product_sign_up_fee', array( __CLASS__, 'remove_sign_up_fee_on_resubscribe' ), 10, 2 );
	}

	/**
	 * Handle `?resubscribe=<subscription_id>` request.
	 */
	public static function maybe_setup_resubscribe_via_cart() {
		if ( empty( $_GET['resubscribe'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}
		$subscription_id = absint( $_GET['resubscribe'] );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $subscription_id ) ) {
			wc_add_notice( __( 'There was an error with your resubscribe request. Please try again.', 'ulixes-subscriptions' ), 'error' );
			return;
		}

		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription || ! ulx_can_user_resubscribe_to( $subscription ) ) {
			wc_add_notice( __( 'That subscription cannot be resubscribed to.', 'ulixes-subscriptions' ), 'error' );
			return;
		}

		WC()->cart->empty_cart();

		foreach ( $subscription->get_items() as $line_item ) {
			$product_id   = $line_item->get_product_id();
			$variation_id = $line_item->get_variation_id();
			$quantity     = $line_item->get_quantity();
			$variations   = array();

			if ( $variation_id ) {
				$variation_product = wc_get_product( $variation_id );
				if ( $variation_product ) {
					$variations = $variation_product->get_variation_attributes();
				}
			}

			$cart_item_data = array(
				'subscription_resubscribe' => array(
					'subscription_id' => $subscription->get_id(),
				),
			);

			WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations, $cart_item_data );
		}

		wc_add_notice( __( 'Renew your subscription by completing checkout below.', 'ulixes-subscriptions' ), 'success' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Restore resubscribe session data.
	 *
	 * @param array  $cart_item_data Cart item.
	 * @param array  $values         Session values.
	 * @param string $key            Key.
	 * @return array
	 */
	public static function get_cart_item_from_session( $cart_item_data, $values, $key ) {
		if ( isset( $values['subscription_resubscribe'] ) ) {
			$cart_item_data['subscription_resubscribe'] = $values['subscription_resubscribe'];
		}
		return $cart_item_data;
	}

	/**
	 * Customers resubscribing do not get a second free trial.
	 *
	 * @param int        $trial_length Trial length.
	 * @param WC_Product $product      Product.
	 * @return int
	 */
	public static function remove_trial_on_resubscribe( $trial_length, $product ) {
		if ( ulx_cart_contains_resubscribe() ) {
			return 0;
		}
		return $trial_length;
	}

	/**
	 * Customers resubscribing do not pay the sign-up fee a second time.
	 *
	 * @param float      $fee     Fee.
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function remove_sign_up_fee_on_resubscribe( $fee, $product ) {
		if ( ulx_cart_contains_resubscribe() ) {
			return 0;
		}
		return $fee;
	}
}

