<?php
/**
 * Cart & recurring cart calculation engine.
 *
 * Handles initial prices (sign-up fee + recurring price or free trial), builds
 * WC()->cart->recurring_carts grouped by billing schedule, and renders recurring
 * totals on the Cart and Checkout pages.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Cart
 */
class ULX_Cart {

	/**
	 * Calculation type currently running: 'none' | 'combined_total' | 'recurring_total'.
	 *
	 * @var string
	 */
	private static $calculation_type = 'none';

	/**
	 * Active recurring cart key during calculation.
	 *
	 * @var string
	 */
	private static $recurring_cart_key = 'none';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 5 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'add_calculation_price_filter' ), 10 );
		add_action( 'woocommerce_calculate_totals', array( __CLASS__, 'remove_calculation_price_filter' ), 10 );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'calculate_subscription_totals' ), 1000, 1 );

		add_filter( 'woocommerce_cart_product_price', array( __CLASS__, 'cart_product_price' ), 10, 2 );
		add_filter( 'woocommerce_cart_product_subtotal', array( __CLASS__, 'cart_product_subtotal' ), 10, 4 );
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 10, 2 );
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ) );

		// Render recurring totals in cart & checkout.
		add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'display_recurring_totals' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'display_recurring_totals' ) );
	}

	/**
	 * Get current calculation type.
	 *
	 * @return string
	 */
	public static function get_calculation_type() {
		return self::$calculation_type;
	}

	/**
	 * Set calculation type.
	 *
	 * @param string $type Type.
	 */
	public static function set_calculation_type( $type ) {
		self::$calculation_type = $type;
	}

	/**
	 * Enforce mixed checkout settings when adding items to cart.
	 *
	 * @param bool  $valid        Valid.
	 * @param int   $product_id   Product ID.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation ID.
	 * @param array $variations   Variations.
	 * @return bool
	 */
	public static function validate_add_to_cart( $valid, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $valid || empty( WC()->cart ) ) {
			return $valid;
		}
		$target_id = $variation_id ? $variation_id : $product_id;
		$product   = wc_get_product( $target_id );
		if ( ! $product ) {
			return $valid;
		}

		if ( ! ulx_option_is_yes( 'multiple_purchase' ) && ! WC()->cart->is_empty() ) {
			$adding_sub   = ulx_is_subscription_product( $product );
			$cart_has_sub = ulx_cart_contains_subscription();

			if ( $adding_sub && $cart_has_sub ) {
				wc_add_notice( __( 'Because mixed checkout is disabled, you can only purchase one subscription at a time. Your previous cart has been replaced.', 'ulixes-subscriptions' ), 'notice' );
				WC()->cart->empty_cart();
			}
		}

		return $valid;
	}

	/**
	 * Attach price filter for initial cart calculation.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function add_calculation_price_filter( $cart ) {
		if ( 'recurring_total' === self::$calculation_type ) {
			return;
		}
		self::$calculation_type = 'combined_total';
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'set_subscription_prices_for_calculation' ), 100, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'set_subscription_prices_for_calculation' ), 100, 2 );
	}

	/**
	 * Remove price filter after initial calculation.
	 */
	public static function remove_calculation_price_filter() {
		if ( 'combined_total' === self::$calculation_type ) {
			remove_filter( 'woocommerce_product_get_price', array( __CLASS__, 'set_subscription_prices_for_calculation' ), 100 );
			remove_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'set_subscription_prices_for_calculation' ), 100 );
			self::$calculation_type = 'none';
		}
	}

	/**
	 * Adjust product price for initial cart calculation (adds sign-up fee, sets recurring price to 0 during free trial or unsynced initial period).
	 *
	 * @param float      $price   Price.
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function set_subscription_prices_for_calculation( $price, $product ) {
		if ( ! ulx_is_subscription_product( $product ) ) {
			return $price;
		}
		if ( 'combined_total' !== self::$calculation_type ) {
			return $price;
		}

		if ( ulx_cart_contains_renewal() ) {
			return $price;
		}

		$sign_up_fee  = ulx_get_product_sign_up_fee( $product );
		$trial_length = ulx_get_product_trial_length( $product );

		if ( ULX_Sync::is_product_synced( $product ) ) {
			$price = ULX_Sync::get_synced_initial_price( $product, $price );
		} elseif ( $trial_length > 0 ) {
			$price = 0;
		}

		if ( $sign_up_fee > 0 ) {
			$price = (float) $price + (float) $sign_up_fee;
		}

		return apply_filters( 'ulx_cart_get_price', $price, $product );
	}

	/**
	 * Build `WC()->cart->recurring_carts` after the main cart finishes calculating totals.
	 *
	 * @param WC_Cart $cart Main cart.
	 */
	public static function calculate_subscription_totals( $cart ) {
		if ( 'recurring_total' === self::$calculation_type ) {
			return;
		}
		if ( ! ulx_cart_contains_subscription() || ulx_cart_contains_renewal() ) {
			$cart->recurring_carts = array();
			return;
		}

		self::$calculation_type = 'recurring_total';
		$recurring_carts        = array();
		$cart_groups            = array();

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! ulx_is_subscription_product( $cart_item['data'] ) ) {
				continue;
			}
			$product  = $cart_item['data'];
			$length   = ulx_get_product_length( $product );
			$interval = ulx_get_product_interval( $product );
			$trial    = ulx_get_product_trial_length( $product );
			if ( $length > 0 && $length === $interval && 0 === $trial && ! ULX_Sync::is_product_synced( $product ) ) {
				continue;
			}

			$key                                   = ulx_get_Recurring_cart_key( $cart_item );
			$cart_groups[ $key ][ $cart_item_key ] = $cart_item;
		}

		foreach ( $cart_groups as $recurring_key => $items ) {
			self::$recurring_cart_key           = $recurring_key;
			$recurring_cart                     = clone $cart;
			$recurring_cart->recurring_cart_key = $recurring_key;
			$recurring_cart->cart_contents      = array();

			$first_product = null;
			foreach ( $items as $item_key => $item ) {
				$cloned_item                                = $item;
				$cloned_item['data']                        = clone $item['data'];
				$recurring_cart->cart_contents[ $item_key ] = $cloned_item;
				if ( null === $first_product ) {
					$first_product = $cloned_item['data'];
				}
			}

			if ( $first_product ) {
				$recurring_cart->subscription_period          = ulx_get_product_period( $first_product );
				$recurring_cart->subscription_period_interval = ulx_get_product_interval( $first_product );
				$recurring_cart->subscription_length          = ulx_get_product_length( $first_product );
				$recurring_cart->trial_length                 = ulx_get_product_trial_length( $first_product );
				$recurring_cart->trial_period                 = ulx_get_product_trial_period( $first_product );

				$start_date                        = current_time( 'mysql', true );
				$recurring_cart->start_date        = $start_date;
				$recurring_cart->trial_end_date    = ulx_get_product_trial_expiration_date( $first_product, $start_date );
				$recurring_cart->next_payment_date = ulx_get_product_first_renewal_payment_date( $first_product, $start_date );
				$recurring_cart->end_date          = ulx_get_product_expiration_date( $first_product, $start_date );
			}

			$recurring_cart->calculate_totals();
			$recurring_carts[ $recurring_key ] = $recurring_cart;
		}

		self::$calculation_type   = 'none';
		self::$recurring_cart_key = 'none';
		$cart->recurring_carts    = apply_filters( 'ulx_recurring_carts', $recurring_carts, $cart );
	}

	/**
	 * Format product price in cart table.
	 *
	 * @param string     $price   Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function cart_product_price( $price, $product ) {
		if ( ulx_is_subscription_product( $product ) ) {
			$price = ulx_get_product_price_string(
				$product,
				array(
					'price'           => $price,
					'tax_calculation' => WC()->cart->display_prices_including_tax() ? 'incl' : 'excl',
				)
			);
		}
		return $price;
	}

	/**
	 * Format product subtotal in cart table.
	 *
	 * @param string     $subtotal Subtotal HTML.
	 * @param WC_Product $product  Product.
	 * @param int        $quantity Quantity.
	 * @param WC_Cart    $cart     Cart.
	 * @return string
	 */
	public static function cart_product_subtotal( $subtotal, $product, $quantity, $cart ) {
		if ( ulx_is_subscription_product( $product ) && ! ulx_cart_contains_renewal() ) {
			$subtotal = ulx_get_product_price_string(
				$product,
				array(
					'price'           => $subtotal,
					'sign_up_fee'     => false,
					'trial_length'    => false,
					'tax_calculation' => $cart->display_prices_including_tax() ? 'incl' : 'excl',
				)
			);
		}
		return $subtotal;
	}

	/**
	 * Require payment method even on $0 initial checkouts unless configured otherwise.
	 *
	 * @param bool    $needs_payment Needs payment.
	 * @param WC_Cart $cart          Cart.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs_payment, $cart ) {
		if ( false === $needs_payment && ulx_cart_contains_subscription() && 0 == $cart->total ) { // phpcs:ignore
			if ( ! ulx_option_is_yes( 'zero_initial_payment_requires_payment' ) && ! ulx_automatic_payments_disabled() ) {
				$recurring_total = 0;
				if ( ! empty( $cart->recurring_carts ) ) {
					foreach ( $cart->recurring_carts as $rc ) {
						$recurring_total += $rc->total;
					}
				}
				if ( $recurring_total > 0 ) {
					$needs_payment = true;
				}
			}
		}
		return apply_filters( 'ulx_cart_needs_payment', $needs_payment, $cart );
	}

	/**
	 * Custom "Place order" button text when cart contains a subscription.
	 *
	 * @param string $button_text Button text.
	 * @return string
	 */
	public static function order_button_text( $button_text ) {
		if ( ulx_cart_contains_subscription() && ! ulx_cart_contains_renewal() ) {
			$button_text = ulx_get_option( 'order_button_text', __( 'Sign up now', 'ulixes-subscriptions' ) );
		}
		return $button_text;
	}

	/**
	 * Display recurring totals table rows on cart and checkout.
	 */
	public static function display_recurring_totals() {
		if ( ! ulx_cart_contains_subscription() || empty( WC()->cart->recurring_carts ) ) {
			return;
		}
		ulx_get_template(
			'checkout/recurring-totals.php',
			array(
				'shipping_methods'             => array(),
				'recurring_carts'              => WC()->cart->recurring_carts,
				'carts_with_multiple_payments' => count( WC()->cart->recurring_carts ),
			)
		);
	}
}

