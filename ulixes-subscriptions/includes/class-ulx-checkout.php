<?php
/**
 * Checkout handler: creates subscriptions from `WC()->cart->recurring_carts` when a customer checks out.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Checkout
 */
class ULX_Checkout {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_checkout' ), 100, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'process_blocks_checkout' ), 100, 1 );
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'require_registration_for_subscriptions' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'add_line_item_meta' ), 10, 4 );
	}

	/**
	 * Force account creation during subscription checkout.
	 *
	 * @param bool $required Registration required.
	 * @return bool
	 */
	public static function require_registration_for_subscriptions( $required ) {
		if ( ulx_cart_contains_subscription() && ! is_user_logged_in() ) {
			$required = true;
		}
		return $required;
	}

	/**
	 * Persist sign-up fee and switch/resubscribe metadata onto parent order line items.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order.
	 */
	public static function add_line_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['data'] ) && ulx_is_subscription_product( $values['data'] ) ) {
			$fee = ulx_get_product_sign_up_fee( $values['data'] );
			if ( $fee > 0 ) {
				$item->add_meta_data( '_ulx_sign_up_fee', $fee, true );
			}
		}
		if ( ! empty( $values['subscription_switch'] ) ) {
			$item->add_meta_data( '_subscription_switch_data', $values['subscription_switch'], true );
		}
		if ( ! empty( $values['subscription_resubscribe'] ) ) {
			$item->add_meta_data( '_subscription_resubscribe', $values['subscription_resubscribe'], true );
		}
		if ( ! empty( $values['subscription_renewal'] ) ) {
			$item->add_meta_data( '_subscription_renewal', $values['subscription_renewal'], true );
		}
	}

	/**
	 * Blocks Checkout hook.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function process_blocks_checkout( $order ) {
		self::process_checkout( $order->get_id(), array(), $order );
	}

	/**
	 * Create `ULX_Subscription` records for each recurring cart on checkout.
	 *
	 * @param int      $order_id    Order ID.
	 * @param array    $posted_data Posted data.
	 * @param WC_Order $order       Order object.
	 * @throws Exception On creation error.
	 */
	public static function process_checkout( $order_id, $posted_data = array(), $order = null ) {
		if ( empty( WC()->cart ) || ! ulx_cart_contains_subscription() ) {
			return;
		}
		if ( ulx_cart_contains_renewal() ) {
			return;
		}

		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$existing = ulx_get_subscriptions_for_order( $order->get_id(), array( 'order_type' => 'parent' ) );
		if ( ! empty( $existing ) ) {
			return;
		}

		if ( empty( WC()->cart->recurring_carts ) ) {
			ULX_Cart::calculate_subscription_totals( WC()->cart );
		}

		foreach ( (array) WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {
			$subscription = self::create_subscription( $order, $recurring_cart, $posted_data );
			if ( is_wp_error( $subscription ) ) {
				throw new Exception( esc_html( $subscription->get_error_message() ) );
			}
		}

		do_action( 'ulx_checkout_subscription_created_for_order', $order, $posted_data );
	}

	/**
	 * Create a single subscription from a recurring cart and parent order.
	 *
	 * @param WC_Order $order          Parent order.
	 * @param WC_Cart  $recurring_cart Recurring cart.
	 * @param array    $posted_data    Posted data.
	 * @return ULX_Subscription|WP_Error
	 */
	public static function create_subscription( $order, $recurring_cart, $posted_data = array() ) {
		$start_date   = getattr_or_prop( $recurring_cart, 'start_date', current_time( 'mysql', true ) );
		$subscription = ulx_create_subscription(
			array(
				'status'             => 'pending',
				'order_id'           => $order->get_id(),
				'customer_id'        => $order->get_customer_id(),
				'customer_note'      => $order->get_customer_note(),
				'billing_period'     => getattr_or_prop( $recurring_cart, 'subscription_period', 'month' ),
				'billing_interval'   => getattr_or_prop( $recurring_cart, 'subscription_period_interval', 1 ),
				'start_date'         => $start_date,
				'created_via'        => 'checkout',
				'currency'           => $order->get_currency(),
				'prices_include_tax' => $order->get_prices_include_tax() ? 'yes' : 'no',
			)
		);

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		ulx_copy_order_address( $order, $subscription );
		$subscription->set_payment_method( $order->get_payment_method() );
		$subscription->set_payment_method_title( $order->get_payment_method_title() );

		$gateway = ulx_get_payment_gateway( $order->get_payment_method() );
		$manual  = ! $gateway || ! $gateway->supports( 'subscriptions' ) || ulx_automatic_payments_disabled();
		$subscription->set_requires_manual_renewal( $manual );

		$trial_period = getattr_or_prop( $recurring_cart, 'trial_period', '' );
		if ( $trial_period ) {
			$subscription->set_trial_period( $trial_period );
		}

		$dates = array(
			'trial_end'    => getattr_or_prop( $recurring_cart, 'trial_end_date', 0 ),
			'next_payment' => getattr_or_prop( $recurring_cart, 'next_payment_date', 0 ),
			'end'          => getattr_or_prop( $recurring_cart, 'end_date', 0 ),
		);
		$subscription->update_dates( $dates );

		WC()->checkout()->create_order_line_items( $subscription, $recurring_cart );
		WC()->checkout()->create_order_fee_lines( $subscription, $recurring_cart );
		WC()->checkout()->create_order_shipping_lines( $subscription, WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array(), $recurring_cart->get_shipping_packages() );
		WC()->checkout()->create_order_tax_lines( $subscription, $recurring_cart );
		WC()->checkout()->create_order_coupon_lines( $subscription, $recurring_cart );

		$subscription->set_shipping_total( $recurring_cart->get_shipping_total() );
		$subscription->set_discount_total( $recurring_cart->get_discount_total() );
		$subscription->set_discount_tax( $recurring_cart->get_discount_tax() );
		$subscription->set_cart_tax( $recurring_cart->get_cart_contents_tax() + $recurring_cart->get_fee_tax() );
		$subscription->set_shipping_tax( $recurring_cart->get_shipping_tax() );
		$subscription->set_total( $recurring_cart->get_total( 'edit' ) );

		foreach ( $recurring_cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['subscription_resubscribe']['subscription_id'] ) ) {
				$old_sub_id = absint( $cart_item['subscription_resubscribe']['subscription_id'] );
				ulx_add_order_subscription_relation( $order, $old_sub_id, 'resubscribe' );
				$old_sub = ulx_get_subscription( $old_sub_id );
				if ( $old_sub && $old_sub->has_status( 'pending-cancel' ) && $old_sub->can_be_updated_to( 'cancelled' ) ) {
					$old_sub->update_status( 'cancelled', __( 'Customer resubscribed to a new subscription.', 'ulixes-subscriptions' ) );
				}
			}
		}

		$subscription->save();

		do_action( 'ulx_checkout_subscription_created', $subscription, $order, $recurring_cart );
		return $subscription;
	}
}

