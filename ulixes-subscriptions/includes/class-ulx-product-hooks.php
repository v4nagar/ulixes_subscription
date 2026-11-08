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
		add_action( 'init', array( __CLASS__, 'ensure_product_type_terms' ), 20 );
		add_filter( 'product_type_selector', array( __CLASS__, 'add_product_types' ) );
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 10, 4 );
		add_action( 'woocommerce_subscription_add_to_cart', array( __CLASS__, 'subscription_add_to_cart' ), 30 );
		add_action( 'woocommerce_variable-subscription_add_to_cart', array( __CLASS__, 'variable_subscription_add_to_cart' ), 30 );
		add_filter( 'woocommerce_add_to_cart_handler', array( __CLASS__, 'add_to_cart_handler' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'filter_available_variation' ), 20, 3 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'maybe_sold_individually' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'maybe_protect_deleting_variation' ) );
	}

	/**
	 * Ensure `subscription` and `variable-subscription` terms exist in the `product_type` taxonomy.
	 */
	public static function ensure_product_type_terms() {
		if ( ! taxonomy_exists( 'product_type' ) ) {
			return;
		}
		foreach ( array( 'subscription', 'variable-subscription' ) as $type_slug ) {
			if ( ! get_term_by( 'slug', $type_slug, 'product_type' ) ) {
				wp_insert_term( $type_slug, 'product_type' );
			}
		}
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
		if ( 'simple' === $product_type && $product_id ) {
			$sub_price  = get_post_meta( $product_id, '_subscription_price', true );
			$sub_period = get_post_meta( $product_id, '_subscription_period', true );
			if ( '' !== $sub_price && false !== $sub_price && '' !== $sub_period ) {
				return 'ULX_Product_Subscription';
			}
		}
		if ( 'variable' === $product_type && $product_id ) {
			$children = get_posts(
				array(
					'post_parent'    => (int) $product_id,
					'post_type'      => 'product_variation',
					'post_status'    => array( 'publish', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_subscription_price',
							'value'   => '',
							'compare' => '!=',
						),
					),
				)
			);
			if ( ! empty( $children ) ) {
				return 'ULX_Product_Variable_Subscription';
			}
		}
		if ( 'product_variation' === $post_type ) {
			$var_sub_price = get_post_meta( $product_id, '_subscription_price', true );
			$var_period    = get_post_meta( $product_id, '_subscription_period', true );
			if ( '' !== $var_sub_price && false !== $var_sub_price && '' !== $var_period ) {
				return 'ULX_Product_Subscription_Variation';
			}
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
	 * Route `variable-subscription` to WooCommerce's `variable` add-to-cart handler (which parses `variation_id` and attributes)
	 * and `subscription` to `simple`.
	 *
	 * @param string     $handler Handler type.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function add_to_cart_handler( $handler, $product ) {
		if ( 'variable-subscription' === $handler ) {
			return 'variable';
		}
		if ( 'subscription' === $handler ) {
			return 'simple';
		}
		return $handler;
	}

	/**
	 * Customize variation JSON data for subscription variations (button text for upgrades/downgrades and clear status for current plan).
	 *
	 * @param array                $data      Variation data.
	 * @param WC_Product           $product   Parent variable product.
	 * @param WC_Product_Variation $variation Variation product.
	 * @return array
	 */
	public static function filter_available_variation( $data, $product, $variation ) {
		if ( ! is_array( $data ) || ! is_object( $variation ) || ! ulx_is_subscription_product( $variation ) ) {
			return $data;
		}
		$data['is_subscription'] = true;
		$data['ulx_button_text'] = ulx_get_option( 'add_to_cart_button_text', __( 'Sign up now', 'ulixes-subscriptions' ) );

		if ( is_user_logged_in() ) {
			$parent_id    = $product->get_id();
			$variation_id = $variation->get_id();
			$existing_sub = ulx_get_user_subscription_for_product( get_current_user_id(), $parent_id, array( 'active', 'on-hold', 'pending-cancel' ) );
			if ( $existing_sub ) {
				if ( $existing_sub->has_product( $variation_id ) ) {
					$data['is_purchasable']    = false;
					$data['ulx_is_current']    = true;
					$data['ulx_button_text']   = __( 'Current Active Plan', 'ulixes-subscriptions' );
					$data['availability_html'] = '<p class="stock out-of-stock ulx-current-plan-notice">' . esc_html__( 'You already have an active subscription to this plan. Select a different plan above to upgrade or downgrade.', 'ulixes-subscriptions' ) . '</p>';
				} elseif ( $existing_sub->has_status( 'active' ) && 'no' !== ulx_get_option( 'allow_switching_variable', 'yes' ) ) {
					$data['is_purchasable']  = true;
					$data['is_in_stock']     = true;
					$data['ulx_is_switch']   = true;
					$data['ulx_button_text'] = ulx_get_option( 'switch_button_text', __( 'Upgrade or Downgrade', 'ulixes-subscriptions' ) );
				}
			}
		}

		return $data;
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
