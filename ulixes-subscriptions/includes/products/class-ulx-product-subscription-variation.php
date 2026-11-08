<?php
/**
 * Subscription variation.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Product_Subscription_Variation
 */
class ULX_Product_Subscription_Variation extends WC_Product_Variation {

	/**
	 * Type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'subscription_variation';
	}

	/**
	 * Behave as variation for core.
	 *
	 * @param string|array $type Type.
	 * @return bool
	 */
	public function is_type( $type ) {
		if ( 'variation' === $type || ( is_array( $type ) && in_array( 'variation', $type, true ) ) ) {
			return true;
		}
		return parent::is_type( $type );
	}

	/**
	 * Price HTML.
	 *
	 * @param string $deprecated Unused.
	 * @return string
	 */
	public function get_price_html( $deprecated = '' ) {
		$price = parent::get_price_html();
		if ( '' === $this->get_price() ) {
			return $price;
		}
		return apply_filters( 'ulx_product_price_html', ulx_get_product_price_string( $this, array( 'price' => $price ) ), $this );
	}

	/**
	 * Add to cart text.
	 *
	 * @return string
	 */
	public function add_to_cart_text() {
		return apply_filters( 'woocommerce_product_add_to_cart_text', $this->is_purchasable() && $this->is_in_stock() ? ulx_get_option( 'add_to_cart_button_text', __( 'Sign up now', 'ulixes-subscriptions' ) ) : __( 'Read more', 'ulixes-subscriptions' ), $this );
	}

	/**
	 * Single add to cart text.
	 *
	 * @return string
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', ulx_get_option( 'add_to_cart_button_text', __( 'Sign up now', 'ulixes-subscriptions' ) ), $this );
	}

	/**
	 * Purchasable respecting limits.
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		return ULX_Limiter::is_purchasable( parent::is_purchasable(), $this );
	}

	/**
	 * Sign-up fee.
	 *
	 * @return float
	 */
	public function get_sign_up_fee() {
		return ulx_get_product_sign_up_fee( $this );
	}
}

