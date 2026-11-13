<?php
/**
 * Subscription Coupons:
 * - `sign_up_fee`          : Fixed Sign Up Fee Discount
 * - `sign_up_fee_percent`  : Sign Up Fee % Discount
 * - `recurring_fee`        : Recurring Product Discount
 * - `recurring_percent`    : Recurring Product % Discount
 *
 * Also supports "Active for X payments" (`_ulx_number_payments`) which automatically removes
 * the recurring coupon from the subscription once X payments have been made.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Coupons
 */
class ULX_Coupons {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_coupon_discount_types', array( __CLASS__, 'add_discount_types' ) );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'validate_subscription_coupon_for_product' ), 10, 4 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( __CLASS__, 'get_discount_amount' ), 10, 5 );
		add_action( 'woocommerce_coupon_options', array( __CLASS__, 'add_coupon_limit_payments_field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save_coupon_limit_payments_field' ), 10, 2 );
		add_action( 'ulx_subscription_payment_complete', array( __CLASS__, 'maybe_remove_limited_payment_coupons' ), 10, 1 );
	}

	/**
	 * Register the 4 subscription coupon types.
	 *
	 * @param array $types Coupon types.
	 * @return array
	 */
	public static function add_discount_types( $types ) {
		return array_merge(
			$types,
			array(
				'sign_up_fee'         => __( 'Sign Up Fee Discount', 'ulixes-subscriptions' ),
				'sign_up_fee_percent' => __( 'Sign Up Fee % Discount', 'ulixes-subscriptions' ),
				'recurring_fee'       => __( 'Recurring Product Discount', 'ulixes-subscriptions' ),
				'recurring_percent'   => __( 'Recurring Product % Discount', 'ulixes-subscriptions' ),
			)
		);
	}

	/**
	 * Validate that subscription coupons only apply to subscription products.
	 *
	 * @param bool       $valid   Valid.
	 * @param WC_Product $product Product.
	 * @param WC_Coupon  $coupon  Coupon.
	 * @param array      $values  Cart item.
	 * @return bool
	 */
	public static function validate_subscription_coupon_for_product( $valid, $product, $coupon, $values ) {
		$type = $coupon->get_discount_type();
		if ( in_array( $type, array( 'sign_up_fee', 'sign_up_fee_percent', 'recurring_fee', 'recurring_percent' ), true ) ) {
			if ( ! ulx_is_subscription_product( $product ) ) {
				return false;
			}
			if ( in_array( $type, array( 'sign_up_fee', 'sign_up_fee_percent' ), true ) && ulx_get_product_sign_up_fee( $product ) <= 0 ) {
				return false;
			}
		}
		return $valid;
	}

	/**
	 * Calculate the discount amount for initial vs recurring carts.
	 *
	 * @param float      $discount           Discount.
	 * @param float      $discounting_amount Amount being discounted.
	 * @param array|null $cart_item          Cart item.
	 * @param bool       $single             Single.
	 * @param WC_Coupon  $coupon             Coupon.
	 * @return float
	 */
	public static function get_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		$type      = $coupon->get_discount_type();
		$calc_type = ULX_Cart::get_calculation_type();

		if ( empty( $cart_item['data'] ) || ! ulx_is_subscription_product( $cart_item['data'] ) ) {
			return $discount;
		}
		$product = $cart_item['data'];
		$amount  = (float) $coupon->get_amount();

		// Recurringcart calculation: only `recurring_fee` and `recurring_percent` apply.
		if ( 'recurring_total' === $calc_type ) {
			if ( 'recurring_fee' === $type ) {
				return min( $amount, $discounting_amount );
			}
			if ( 'recurring_percent' === $type ) {
				return ( $discounting_amount * $amount ) / 100;
			}
			if ( in_array( $type, array( 'sign_up_fee', 'sign_up_fee_percent', 'fixed_cart', 'fixed_product', 'percent' ), true ) ) {
				return 0;
			}
		}

		// Initial cart calculation:
		if ( 'sign_up_fee' === $type ) {
			$fee = ulx_get_product_sign_up_fee( $product );
			return min( $amount, $fee );
		}
		if ( 'sign_up_fee_percent' === $type ) {
			$fee = ulx_get_product_sign_up_fee( $product );
			return ( $fee * $amount ) / 100;
		}
		if ( 'recurring_fee' === $type ) {
			if ( ulx_product_has_trial( $product ) ) {
				return 0;
			}
			$rec = ulx_get_product_price( $product );
			return min( $amount, $rec );
		}
		if ( 'recurring_percent' === $type ) {
			if ( ulx_product_has_trial( $product ) ) {
				return 0;
			}
			$rec = ulx_get_product_price( $product );
			return ( $rec * $amount ) / 100;
		}

		return $discount;
	}

	/**
	 * Admin field on coupon edit screen: "Active for X payments".
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon    Coupon.
	 */
	public static function add_coupon_limit_payments_field( $coupon_id, $coupon ) {
		woocommerce_wp_text_input(
			array(
				'id'                => '_ulx_number_payments',
				'label'             => __( 'Active for x payments', 'ulixes-subscriptions' ),
				'placeholder'       => __( 'Unlimited payments', 'ulixes-subscriptions' ),
				'description'       => __( 'For recurring coupons, the number of renewal payments the coupon should apply to before being automatically removed.', 'ulixes-subscriptions' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'value'             => $coupon ? $coupon->get_meta( '_ulx_number_payments', true ) : '',
				'custom_attributes' => array(
					'step' => 1,
					'min'  => 0,
				),
			)
		);
	}

	/**
	 * Save `_ulx_number_payments` meta on coupon save.
	 *
	 * @param int       $coupon_id Coupon ID.
	 * @param WC_Coupon $coupon    Coupon.
	 */
	public static function save_coupon_limit_payments_field( $coupon_id, $coupon ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! empty( $_POST['woocommerce_meta_nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		if ( isset( $_POST['_ulx_number_payments'] ) ) {
			$val = sanitize_text_field( wp_unslash( $_POST['_ulx_number_payments'] ) );
			$coupon->update_meta_data( '_ulx_number_payments', '' === $val ? '' : absint( $val ) );
			$coupon->save_meta_data();
		}
	}

	/**
	 * Remove limited-payment coupons from a subscription once the payment count reaches `_ulx_number_payments`.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public static function maybe_remove_limited_payment_coupons( $subscription ) {
		$coupons = $subscription->get_items( 'coupon' );
		if ( empty( $coupons ) ) {
			return;
		}
		$payment_count = $subscription->get_payment_count();
		$removed       = false;

		foreach ( $coupons as $item_id => $coupon_item ) {
			$code   = $coupon_item->get_code();
			$coupon = new WC_Coupon( $code );
			$limit  = (int) $coupon->get_meta( '_ulx_number_payments', true );
			if ( $limit > 0 && $payment_count >= $limit ) {
				$subscription->remove_coupon( $code );
				$subscription->add_order_note(
					sprintf(
						/* translators: 1: coupon code, 2: limit */
						__( 'Coupon "%1$s" removed from subscription after reaching its %2$d-payment limit.', 'ulixes-subscriptions' ),
						$code,
						$limit
					)
				);
				$removed = true;
			}
		}

		if ( $removed ) {
			$subscription->calculate_totals();
			$subscription->save();
		}
	}
}

