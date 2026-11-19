<?php
/**
 * Simple subscription product.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULIXSU_Product_Subscription
 */
class ULIXSU_Product_Subscription extends WC_Product_Simple {

	/**
	 * Product type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'subscription';
	}

	/**
	 * Treat as simple too for core compatibility.
	 *
	 * @param string|array $type Type.
	 * @return bool
	 */
	public function is_type( $type ) {
		if ( 'simple' === $type || ( is_array( $type ) && in_array( 'simple', $type, true ) ) ) {
			return true;
		}
		return parent::is_type( $type );
	}

	/**
	 * Add to cart text.
	 *
	 * @return string
	 */
	public function add_to_cart_text() {
		if ( $this->is_purchasable() && $this->is_in_stock() ) {
			$text = ulixsu_get_option( 'add_to_cart_button_text', __( 'Sign up now', 'ulixes-subscriptions' ) );
		} else {
			$text = parent::add_to_cart_text();
		}
		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * Single add to cart text.
	 *
	 * @return string
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', ulixsu_get_option( 'add_to_cart_button_text', __( 'Sign up now', 'ulixes-subscriptions' ) ), $this );
	}

	/**
	 * Price HTML with subscription details.
	 *
	 * @param string $deprecated Unused.
	 * @return string
	 */
	public function get_price_html( $deprecated = '' ) {
		$price = parent::get_price_html();
		if ( '' === $this->get_price() ) {
			return $price;
		}
		return apply_filters( 'ulixsu_product_price_html', ulixsu_get_product_price_string( $this, array( 'price' => $price ) ), $this );
	}

	/**
	 * Purchasable – also respects subscription limits and resubscribe/renewal context.
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		$purchasable = parent::is_purchasable();
		return ULIXSU_Limiter::is_purchasable( $purchasable, $this );
	}

	/**
	 * Sold individually when limited.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public function get_sold_individually( $context = 'view' ) {
		$limit = ulixsu_get_product_limit( $this );
		if ( 'view' === $context && 'no' !== $limit ) {
			return true;
		}
		return parent::get_sold_individually( $context );
	}

	/**
	 * Sign-up fee.
	 *
	 * @return float
	 */
	public function get_sign_up_fee() {
		return ulixsu_get_product_sign_up_fee( $this );
	}

	/**
	 * Period.
	 *
	 * @return string
	 */
	public function get_subscription_period() {
		return ulixsu_get_product_period( $this );
	}
}

