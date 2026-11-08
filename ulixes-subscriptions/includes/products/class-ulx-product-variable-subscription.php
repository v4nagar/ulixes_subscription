<?php
/**
 * Variable subscription product.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Product_Variable_Subscription
 */
class ULX_Product_Variable_Subscription extends WC_Product_Variable {

	/**
	 * Type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'variable-subscription';
	}

	/**
	 * Behave as variable for core.
	 *
	 * @param string|array $type Type.
	 * @return bool
	 */
	public function is_type( $type ) {
		if ( 'variable' === $type || ( is_array( $type ) && in_array( 'variable', $type, true ) ) ) {
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
		$text = $this->is_purchasable() ? __( 'Select options', 'ulixes-subscriptions' ) : __( 'Read more', 'ulixes-subscriptions' );
		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
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
	 * Price HTML: "From: $10 / month" using the cheapest variation (per day).
	 *
	 * @param string $price Unused.
	 * @return string
	 */
	public function get_price_html( $price = '' ) {
		$children = $this->get_visible_children();
		if ( empty( $children ) ) {
			return apply_filters( 'woocommerce_variable_empty_price_html', '', $this );
		}

		$min_variation = ulx_get_min_price_variation( $this );
		if ( ! $min_variation ) {
			return parent::get_price_html();
		}

		// Do all variations share the same billing schedule and price?
		$same = true;
		$ref  = null;
		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			$sig = implode(
				'|',
				array(
					$child->get_price(),
					ulx_get_product_period( $child ),
					ulx_get_product_interval( $child ),
					ulx_get_product_length( $child ),
					ulx_get_product_trial_length( $child ),
					ulx_get_product_sign_up_fee( $child ),
				)
			);
			if ( null === $ref ) {
				$ref = $sig;
			} elseif ( $ref !== $sig ) {
				$same = false;
				break;
			}
		}

		$display_price = wc_get_price_to_display( $min_variation );
		$price_html    = wc_price( $display_price ) . $this->get_price_suffix();
		if ( $min_variation->is_on_sale() ) {
			$price_html = wc_format_sale_price( wc_get_price_to_display( $min_variation, array( 'price' => $min_variation->get_regular_price() ) ), $display_price ) . $this->get_price_suffix();
		}

		$string = ulx_get_product_price_string( $min_variation, array( 'price' => $price_html ) );
		if ( ! $same ) {
			/* translators: %s: price string */
			$string = sprintf( _x( '<span class="from">From: </span>%s', 'min price', 'ulixes-subscriptions' ), $string );
		}
		return apply_filters( 'woocommerce_variable_subscription_price_html', apply_filters( 'woocommerce_get_price_html', $string, $this ), $this );
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
	 * Sold individually when limited.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public function get_sold_individually( $context = 'view' ) {
		if ( 'view' === $context && 'no' !== ulx_get_product_limit( $this ) ) {
			return true;
		}
		return parent::get_sold_individually( $context );
	}

	/**
	 * Add subscription data to each variation for the frontend JS.
	 *
	 * @param WC_Product $variation Variation.
	 * @return array|false
	 */
	public function get_available_variation( $variation ) {
		$data = parent::get_available_variation( $variation );
		if ( is_array( $data ) && is_object( $variation ) ) {
			$data['is_subscription']       = true;
			$data['subscription_period']   = ulx_get_product_period( $variation );
			$data['subscription_interval'] = ulx_get_product_interval( $variation );
			$data['subscription_length']   = ulx_get_product_length( $variation );
			$data['trial_length']          = ulx_get_product_trial_length( $variation );
			$data['sign_up_fee']           = ulx_get_product_sign_up_fee( $variation );
		}
		return $data;
	}

	/**
	 * Sync variation prices and cache the min/max variation ids.
	 *
	 * @param WC_Product|int $product Product.
	 * @param bool           $save    Save.
	 * @return WC_Product
	 */
	public static function sync( $product, $save = true ) {
		$product = parent::sync( $product, false );
		if ( is_object( $product ) ) {
			$product->delete_meta_data( '_min_price_variation_id' );
			$min = ulx_get_min_price_variation( $product );
			if ( $min ) {
				$product->update_meta_data( '_min_price_variation_id', $min->get_id() );
			}
			if ( $save ) {
				$product->save();
			}
		}
		return $product;
	}
}

