<?php
/**
 * Subscription Switching (Upgrades, Downgrades & Crossgrades) with Proration.
 *
 * Supports:
 * - Variable subscription variation switching
 * - Grouped subscription switching
 * - Apportioning recurring price (no proration, virtual only, or all products; charge for upgrades only or credit downgrades)
 * - Apportioning sign-up fee (never, full, or difference)
 * - Apportioning subscription length (reset vs subtract completed payments)
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Switcher
 */
class ULX_Switcher {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'switch_add_to_cart_text' ), 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_switch_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'calculate_prorated_switch_totals' ), 30, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_switch_order' ), 50, 3 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'complete_switch_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'complete_switch_for_order' ), 10, 1 );
	}

	/**
	 * Change "Sign up now" button text to "Upgrade or Downgrade" when switching.
	 *
	 * @param string     $text    Button text.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function switch_add_to_cart_text( $text, $product ) {
		if ( isset( $_GET['switch-subscription'] ) && isset( $_GET['item'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return ulx_get_option( 'switch_button_text', __( 'Upgrade or Downgrade', 'ulixes-subscriptions' ) );
		}
		return $text;
	}

	/**
	 * Attach `subscription_switch` metadata when adding a product to cart from a switch link.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public static function add_switch_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( empty( $_GET['switch-subscription'] ) || empty( $_GET['item'] ) ) {
			return $cart_item_data;
		}
		$subscription_id = absint( $_GET['switch-subscription'] );
		$item_id         = absint( $_GET['item'] );
		$subscription    = ulx_get_subscription( $subscription_id );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() ) {
			return $cart_item_data;
		}

		$existing_item = $subscription->get_item( $item_id );
		if ( ! $existing_item || ! ulx_can_item_be_switched_by_user( $subscription, $existing_item ) ) {
			return $cart_item_data;
		}

		$new_product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$old_product = $existing_item->get_product();

		$direction = 'crossgraded';
		if ( $new_product && $old_product ) {
			$new_per_day = self::get_price_per_day( $new_product );
			$old_per_day = ( (float) $existing_item->get_subtotal() / max( 1, $existing_item->get_quantity() ) ) / max( 1, ulx_estimate_days_in_period( $subscription->get_billing_period(), $subscription->get_billing_interval() ) );
			if ( $new_per_day > $old_per_day ) {
				$direction = 'upgraded';
			} elseif ( $new_per_day < $old_per_day ) {
				$direction = 'downgraded';
			}
		}

		$cart_item_data['subscription_switch'] = array(
			'subscription_id'        => $subscription->get_id(),
			'item_id'                => $item_id,
			'next_payment_timestamp' => $subscription->get_time( 'next_payment' ),
			'upgraded_or_downgraded' => $direction,
		);
		// phpcs:enable
		return $cart_item_data;
	}

	/**
	 * Restore switch data from session.
	 *
	 * @param array  $cart_item_data Cart item.
	 * @param array  $values         Session values.
	 * @param string $key            Key.
	 * @return array
	 */
	public static function get_cart_item_from_session( $cart_item_data, $values, $key ) {
		if ( isset( $values['subscription_switch'] ) ) {
			$cart_item_data['subscription_switch'] = $values['subscription_switch'];
		}
		return $cart_item_data;
	}

	/**
	 * Calculate per-day recurring price for a product.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function get_price_per_day( $product ) {
		$days = ulx_estimate_days_in_period( ulx_get_product_period( $product ), ulx_get_product_interval( $product ) );
		return $days > 0 ? (float) ulx_get_product_price( $product ) / $days : (float) ulx_get_product_price( $product );
	}

	/**
	 * Calculate prorated recurring price and sign-up fee for switch items in the initial cart.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function calculate_prorated_switch_totals( $cart ) {
		if ( 'recurring_total' === ULX_Cart::get_calculation_type() ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['subscription_switch'] ) || empty( $item['data'] ) ) {
				continue;
			}
			$switch_data  = $item['subscription_switch'];
			$subscription = ulx_get_subscription( $switch_data['subscription_id'] );
			if ( ! $subscription ) {
				continue;
			}
			$old_item = $subscription->get_item( $switch_data['item_id'] );
			if ( ! $old_item ) {
				continue;
			}
			$product = $item['data'];

			// 1. Sign-up fee proration.
			$apportion_fee = ulx_get_option( 'apportion_sign_up_fee', 'no' );
			$sign_up_fee   = 0;
			if ( 'yes' === $apportion_fee ) {
				$new_fee     = ulx_get_product_sign_up_fee( $product );
				$old_fee     = (float) $old_item->get_meta( '_ulx_sign_up_fee', true );
				$sign_up_fee = max( 0, $new_fee - $old_fee );
			} elseif ( 'full' === $apportion_fee ) {
				$sign_up_fee = ulx_get_product_sign_up_fee( $product );
			}

			// 2. Recurring price proration.
			$apportion_price = ulx_get_option( 'apportion_recurring_price', 'no' );
			$should_prorate  = ( 'yes' === $apportion_price || 'yes-upgrade' === $apportion_price ) || ( ( 'virtual' === $apportion_price || 'virtual-upgrade' === $apportion_price ) && $product->is_virtual() );

			$initial_amount = 0;
			if ( $should_prorate ) {
				$next_payment = $subscription->get_time( 'next_payment' );
				$last_order   = $subscription->get_last_order( 'all', array( 'parent', 'renewal' ) );
				$last_time    = $last_order && $last_order->get_date_created() ? $last_order->get_date_created()->getTimestamp() : $subscription->get_time( 'start' );

				$days_in_old_period = max( 1, ulx_days_between( $last_time, $next_payment ) );
				$days_remaining     = max( 0, ulx_days_between( time(), $next_payment ) );

				$old_daily = ( (float) $old_item->get_subtotal() / max( 1, $old_item->get_quantity() ) ) / $days_in_old_period;
				$new_daily = self::get_price_per_day( $product );

				$diff = ( $new_daily - $old_daily ) * $days_remaining;
				if ( $diff > 0 ) {
					$initial_amount = ulx_round( $diff );
				}
			}

			$product->update_meta_data( '_subscription_sign_up_fee', $sign_up_fee );
			$product->set_price( $initial_amount );
		}
	}

	/**
	 * Link switch order to subscription during checkout.
	 *
	 * @param int      $order_id    Order ID.
	 * @param array    $posted_data Posted data.
	 * @param WC_Order $order       Order.
	 */
	public static function process_switch_order( $order_id, $posted_data, $order = null ) {
		$switches = ulx_cart_contains_switches();
		if ( ! $switches ) {
			return;
		}
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $switches as $cart_key => $switch_info ) {
			ulx_add_order_subscription_relation( $order, $switch_info['subscription_id'], 'switch' );
		}
	}

	/**
	 * When a switch order completes, update the subscription's line items and totals.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function complete_switch_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! ulx_order_contains_switch( $order ) ) {
			return;
		}
		if ( 'yes' === $order->get_meta( '_ulx_switch_completed', true ) ) {
			return;
		}

		foreach ( $order->get_items() as $order_item ) {
			$switch_data = $order_item->get_meta( '_subscription_switch_data', true );
			if ( empty( $switch_data['subscription_id'] ) ) {
				continue;
			}
			$subscription = ulx_get_subscription( $switch_data['subscription_id'] );
			if ( ! $subscription ) {
				continue;
			}

			// Remove the old line item from the subscription and add the new product at its full recurring price.
			if ( ! empty( $switch_data['item_id'] ) ) {
				$subscription->remove_item( $switch_data['item_id'] );
			}

			$product = $order_item->get_product();
			if ( $product ) {
				$recurring_price = wc_get_price_excluding_tax( $product, array( 'price' => $product->get_regular_price() ?: $product->get_price() ) );
				$subscription->add_product(
					$product,
					$order_item->get_quantity(),
					array(
						'subtotal' => $recurring_price * $order_item->get_quantity(),
						'total'    => $recurring_price * $order_item->get_quantity(),
					)
				);
				$subscription->set_billing_period( ulx_get_product_period( $product ) );
				$subscription->set_billing_interval( ulx_get_product_interval( $product ) );
			}

			$subscription->calculate_totals();
			$subscription->save();
			$subscription->add_order_note(
				sprintf(
					/* translators: %s: order number */
					__( 'Customer switched subscription via order #%s.', 'ulixes-subscriptions' ),
					$order->get_order_number()
				)
			);
			do_action( 'ulx_subscription_switched_item', $subscription, $order_item, $switch_data );
		}

		$order->update_meta_data( '_ulx_switch_completed', 'yes' );
		$order->save_meta_data();
		do_action( 'ulx_subscriptions_switched_for_order', $order );
	}
}

