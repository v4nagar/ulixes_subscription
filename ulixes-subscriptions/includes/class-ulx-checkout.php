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
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'ensure_subscriptions_for_order' ), 105, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'ensure_subscriptions_for_order' ), 105, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'ensure_subscriptions_for_order' ), 5, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'ensure_subscriptions_for_order' ), 5, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'ensure_subscriptions_for_order' ), 5, 1 );
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

	/**
	 * Ensure subscriptions are created directly from order line items if `WC()->cart->recurring_carts`
	 * was not available (e.g. Store API Checkout Blocks, REST API orders, or Admin orders).
	 *
	 * @param int|WC_Order $order_or_id Order or Order ID.
	 */
	public static function ensure_subscriptions_for_order( $order_or_id ) {
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order || $order instanceof ULX_Subscription ) {
			return;
		}
		if ( ulx_order_contains_renewal( $order ) || ulx_order_contains_switch( $order ) || ulx_order_contains_resubscribe( $order ) ) {
			return;
		}

		$existing = ulx_get_subscriptions_for_order( $order->get_id(), array( 'order_type' => 'parent' ) );
		if ( ! empty( $existing ) ) {
			return;
		}

		$groups = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! ulx_is_subscription_product( $product ) ) {
				continue;
			}
			$period   = ulx_get_product_period( $product );
			$interval = ulx_get_product_interval( $product );
			$length   = ulx_get_product_length( $product );
			$trial_l  = ulx_get_product_trial_length( $product );
			$trial_p  = ulx_get_product_trial_period( $product );
			$key      = implode( '_', array( $period, $interval, $length, $trial_l, $trial_p ) );
			$groups[ $key ][] = array(
				'item'    => $item,
				'product' => $product,
			);
		}

		if ( empty( $groups ) ) {
			return;
		}

		$start_date = $order->get_date_created() ? gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() ) : current_time( 'mysql', true );

		foreach ( $groups as $group_items ) {
			$first_product = $group_items[0]['product'];
			$period        = ulx_get_product_period( $first_product );
			$interval      = ulx_get_product_interval( $first_product );

			$subscription = ulx_create_subscription(
				array(
					'status'             => 'pending',
					'order_id'           => $order->get_id(),
					'customer_id'        => $order->get_customer_id(),
					'customer_note'      => $order->get_customer_note(),
					'billing_period'     => $period,
					'billing_interval'   => $interval,
					'start_date'         => $start_date,
					'created_via'        => $order->get_created_via() ?: 'checkout',
					'currency'           => $order->get_currency(),
					'prices_include_tax' => $order->get_prices_include_tax() ? 'yes' : 'no',
				)
			);

			if ( is_wp_error( $subscription ) ) {
				continue;
			}

			ulx_copy_order_address( $order, $subscription );
			$subscription->set_payment_method( $order->get_payment_method() );
			$subscription->set_payment_method_title( $order->get_payment_method_title() );

			$gateway = ulx_get_payment_gateway( $order->get_payment_method() );
			$manual  = ! $gateway || ! $gateway->supports( 'subscriptions' ) || ulx_automatic_payments_disabled();
			$subscription->set_requires_manual_renewal( $manual );

			$trial_length = ulx_get_product_trial_length( $first_product );
			if ( $trial_length > 0 ) {
				$subscription->set_trial_period( ulx_get_product_trial_period( $first_product ) );
			}

			$trial_end    = ulx_get_product_trial_expiration_date( $first_product, $start_date );
			$next_payment = ulx_get_product_first_renewal_payment_date( $first_product, $start_date );
			$end_date     = ulx_get_product_expiration_date( $first_product, $start_date );

			if ( ! $next_payment && 0 === $trial_length ) {
				$next_payment = ulx_date_to_mysql( ulx_add_time( $interval, $period, ulx_date_to_time( $start_date ) ) );
			}

			$subscription->update_dates(
				array(
					'trial_end'    => $trial_end,
					'next_payment' => $next_payment,
					'end'          => $end_date,
				)
			);

			foreach ( $group_items as $entry ) {
				$new_item = clone $entry['item'];
				$new_item->set_id( 0 );
				$new_item->set_order_id( $subscription->get_id() );
				$subscription->add_item( $new_item );
			}

			$subscription->calculate_totals();
			$subscription->save();

			if ( $order->has_status( array( 'processing', 'completed' ) ) && $subscription->can_be_updated_to( 'active' ) ) {
				$subscription->update_status( 'active', __( 'Subscription activated by parent order payment.', 'ulixes-subscriptions' ) );
			} elseif ( $order->has_status( 'on-hold' ) && $subscription->can_be_updated_to( 'on-hold' ) ) {
				$subscription->update_status( 'on-hold', __( 'Subscription placed on hold by parent order.', 'ulixes-subscriptions' ) );
			}
		}
	}
}

