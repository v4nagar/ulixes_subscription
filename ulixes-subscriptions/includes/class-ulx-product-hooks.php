<?php
/**
 * Product hooks for subscription product types.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Product_Hooks
 */
class ULX_Product_Hooks {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'product_type_selector', array( __CLASS__, 'add_product_types' ) );
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 10, 4 );
		add_action( 'woocommerce_subscription_add_to_cart', array( __CLASS__, 'subscription_add_to_cart' ), 30 );
		add_action( 'woocommerce_variable-subscription_add_to_cart', array( __CLASS__, 'variable_subscription_add_to_cart' ), 30 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'maybe_sold_individually' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'maybe_protect_deleting_variation' ) );
	}

	/**
	 * Add subscription product types to the product type dropdown.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public static function add_product_types( $types ) {
		$types['subscription']          = __( 'Simple subscription', 'ulixes-subscriptions' );
		$types['variable-subscription'] = __( 'Variable subscription', 'ulixes-subscriptions' );
		return $types;
	}

	/**
	 * Map product types to their class names.
	 *
	 * @param string $classname    Classname.
	 * @param string $product_type Product type.
	 * @param string $post_type    Post type.
	 * @param int    $product_id   Product ID.
	 * @return string
	 */
	public static function product_class( $classname, $product_type, $post_type, $product_id ) {
		if ( 'subscription' === $product_type ) {
			return 'ULX_Product_Subscription';
		}
		if ( 'variable-subscription' === $product_type ) {
			return 'ULX_Product_Variable_Subscription';
		}
		if ( 'product_variation' === $post_type ) {
			$parent_id = wp_get_post_parent_id( $product_id );
			if ( $parent_id ) {
				$terms = get_the_terms( $parent_id, 'product_type' );
				$type  = ! empty( $terms ) && ! is_wp_error( $terms ) ? sanitize_title( current( $terms )->name ) : '';
				if ( 'variable-subscription' === $type ) {
					return 'ULX_Product_Subscription_Variation';
				}
			}
		}
		return $classname;
	}

	/**
	 * Output the simple add-to-cart template for simple subscriptions.
	 */
	public static function subscription_add_to_cart() {
		wc_get_template( 'single-product/add-to-cart/simple.php' );
	}

	/**
	 * Output the variable add-to-cart template for variable subscriptions.
	 */
	public static function variable_subscription_add_to_cart() {
		global $product;
		wp_enqueue_script( 'wc-add-to-cart-variation' );
		$get_variations = count( $product->get_children() ) <= apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product );
		wc_get_template(
			'single-product/add-to-cart/variable.php',
			array(
				'available_variations' => $get_variations ? $product->get_available_variations() : false,
				'attributes'           => $product->get_variation_attributes(),
				'selected_attributes'  => $product->get_default_attributes(),
			)
		);
	}

	/**
	 * Force sold individually when product limit is active.
	 *
	 * @param bool       $individually Sold individually.
	 * @param WC_Product $product      Product.
	 * @return bool
	 */
	public static function maybe_sold_individually( $individually, $product ) {
		if ( ulx_is_subscription_product( $product ) && 'no' !== ulx_get_product_limit( $product ) ) {
			return true;
		}
		return $individually;
	}

	/**
	 * Clean up parent cache if variation is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function maybe_protect_deleting_variation( $post_id ) {
		if ( 'product_variation' === get_post_type( $post_id ) ) {
			$parent_id = wp_get_post_parent_id( $post_id );
			if ( $parent_id ) {
				delete_post_meta( $parent_id, '_min_price_variation_id' );
			}
		}
	}
}
