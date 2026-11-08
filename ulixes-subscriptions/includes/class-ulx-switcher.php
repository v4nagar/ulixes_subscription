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
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_switch_item_data' ), 10, 2 );
		add_filter( 'wc_add_to_cart_message_html', array( __CLASS__, 'custom_switch_add_to_cart_message' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect_switch_to_checkout' ), 20, 1 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'calculate_prorated_switch_totals' ), 30, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_switch_order' ), 50, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'process_blocks_switch_order' ), 50, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'complete_switch_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'complete_switch_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'complete_switch_for_order' ), 10, 1 );
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
		if ( is_user_logged_in() && $product && ulx_is_subscription_product( $product ) && 'no' !== ulx_get_option( 'allow_switching_variable', 'yes' ) ) {
			$check_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			if ( ulx_user_has_subscription( get_current_user_id(), $check_id, 'active' ) ) {
				return ulx_get_option( 'switch_button_text', __( 'Upgrade or Downgrade', 'ulixes-subscriptions' ) );
			}
		}
		return $text;
	}

	/**
	 * Attach `subscription_switch` metadata when adding a product to cart from a switch link
	 * or when a logged-in customer with an active subscription to this variable product selects a new variation.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public static function add_switch_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( empty( $variation_id ) && ! empty( $_REQUEST['variation_id'] ) ) {
			$variation_id = absint( $_REQUEST['variation_id'] );
		}
		$maybe_parent = wp_get_post_parent_id( $product_id );
		if ( $maybe_parent > 0 ) {
			$variation_id = (int) $product_id;
			$product_id   = (int) $maybe_parent;
		}
		$subscription_id = ! empty( $_GET['switch-subscription'] ) ? absint( $_GET['switch-subscription'] ) : 0;
		$item_id         = ! empty( $_GET['item'] ) ? absint( $_GET['item'] ) : 0;
		$subscription    = $subscription_id ? ulx_get_subscription( $subscription_id ) : false;
		$existing_item   = null;

		if ( $subscription && (int) $subscription->get_user_id() === get_current_user_id() && $item_id ) {
			$existing_item = $subscription->get_item( $item_id );
		} elseif ( is_user_logged_in() && $variation_id > 0 && 'no' !== ulx_get_option( 'allow_switching_variable', 'yes' ) ) {
			$subscription = ulx_get_user_subscription_for_product( get_current_user_id(), $product_id, array( 'active', 'on-hold' ) );
			if ( $subscription && ! $subscription->has_product( $variation_id ) ) {
				foreach ( $subscription->get_items() as $sub_item_id => $sub_item ) {
					if ( (int) $sub_item->get_product_id() === (int) $product_id ) {
						$item_id       = $sub_item_id;
						$existing_item = $sub_item;
						break;
					}
				}
			}
		} elseif ( is_user_logged_in() && ! ulx_option_is_yes( 'multiple_purchase' ) ) {
			// If customer already has an active subscription and is purchasing another subscription product, treat as plan switch.
			$user_subs = ulx_get_users_subscriptions( get_current_user_id() );
			$check_id  = $variation_id > 0 ? $variation_id : $product_id;
			foreach ( $user_subs as $user_sub ) {
				if ( $user_sub->has_status( array( 'active', 'on-hold' ) ) && ! $user_sub->has_product( $check_id ) ) {
					$sub_items = $user_sub->get_items();
					$first     = reset( $sub_items );
					if ( $first ) {
						$subscription  = $user_sub;
						$existing_item = $first;
						$item_id       = $first->get_id();
						break;
					}
				}
			}
		}

		if ( ! $subscription || ! $existing_item || ! ulx_can_item_be_switched_by_user( $subscription, $existing_item ) ) {
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
		// phpcs:enable WordPress.Security.NonceVerification
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
	 * Display switch badge in Cart and Checkout item metadata so customer clearly sees they are upgrading/switching their existing subscription.
	 *
	 * @param array $item_data Item data rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function display_switch_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['subscription_switch']['subscription_id'] ) ) {
			$sub_id    = absint( $cart_item['subscription_switch']['subscription_id'] );
			$direction = isset( $cart_item['subscription_switch']['upgraded_or_downgraded'] ) ? $cart_item['subscription_switch']['upgraded_or_downgraded'] : 'switched';
			$label     = 'upgraded' === $direction ? __( 'Upgrading Subscription', 'ulixes-subscriptions' ) : ( 'downgraded' === $direction ? __( 'Downgrading Subscription', 'ulixes-subscriptions' ) : __( 'Switching Subscription', 'ulixes-subscriptions' ) );
			$item_data[] = array(
				'key'   => $label,
				'value' => '#' . $sub_id,
			);
		}
		return $item_data;
	}

	/**
	 * Customize add-to-cart message when switching plans to show the exact variation name and subscription number.
	 *
	 * @param string $message  HTML message.
	 * @param array  $products Products array.
	 * @return string
	 */
	public static function custom_switch_add_to_cart_message( $message, $products ) {
		$switches = ulx_cart_contains_switches();
		if ( empty( $switches ) || empty( WC()->cart ) ) {
			return $message;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['subscription_switch']['subscription_id'] ) && ! empty( $cart_item['data'] ) ) {
				$sub_id    = absint( $cart_item['subscription_switch']['subscription_id'] );
				$prod_name = $cart_item['data']->get_name();
				return sprintf(
					/* translators: 1: variation name, 2: subscription ID */
					esc_html__( 'Plan change to "%1$s" for Subscription #%2$s has been added to your cart. Complete checkout below to apply your upgrade/downgrade.', 'ulixes-subscriptions' ),
					esc_html( $prod_name ),
					esc_html( $sub_id )
				);
			}
		}
		return $message;
	}

	/**
	 * Redirect directly to Checkout when a customer adds a subscription upgrade/downgrade switch to the cart.
	 *
	 * @param string $url Redirect URL.
	 * @return string
	 */
	public static function redirect_switch_to_checkout( $url ) {
		if ( ulx_cart_contains_switches() ) {
			return wc_get_checkout_url();
		}
		return $url;
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

			$calc = ULX_Switch_Totals_Calculator::calculate_prorated_totals( $subscription, $old_item, $product );

			$product->update_meta_data( '_ulx_prorated_switch_price', $calc['prorated_price'] );
			$product->update_meta_data( '_ulx_recurring_base_price', $calc['full_recurring'] );
			$product->update_meta_data( '_subscription_sign_up_fee', $calc['sign_up_fee'] );
			$product->set_price( $calc['prorated_price'] );
		}
	}

	/**
	 * Blocks checkout hook for switch orders.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function process_blocks_switch_order( $order ) {
		self::process_switch_order( $order->get_id(), array(), $order );
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
		if ( $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
			self::complete_switch_for_order( $order->get_id() );
		}
	}

	/**
	 * When a switch order completes, update the subscription's line items, schedule, and totals.
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
			$old_item_name = '';
			if ( ! empty( $switch_data['item_id'] ) ) {
				$old_sub_item = $subscription->get_item( $switch_data['item_id'] );
				if ( $old_sub_item ) {
					$old_item_name = $old_sub_item->get_name();
				}
				$subscription->remove_item( $switch_data['item_id'] );
			}

			$target_id = $order_item->get_variation_id() ? $order_item->get_variation_id() : $order_item->get_product_id();
			$product   = wc_get_product( $target_id );
			if ( ! $product ) {
				$product = $order_item->get_product();
			}
			if ( $product ) {
				$apportion_price = ulx_get_option( 'apportion_recurring_price', 'no' );
				$should_prorate  = ( 'yes' === $apportion_price || 'yes-upgrade' === $apportion_price ) || ( ( 'virtual' === $apportion_price || 'virtual-upgrade' === $apportion_price ) && $product->is_virtual() );

				if ( ! $should_prorate && (float) $order_item->get_total() > 0 ) {
					$item_subtotal = (float) $order_item->get_subtotal();
					$item_total    = (float) $order_item->get_total();
				} else {
					if ( $product->is_on_sale( 'edit' ) && '' !== $product->get_sale_price( 'edit' ) ) {
						$base_price = (float) $product->get_sale_price( 'edit' );
					} elseif ( '' !== $product->get_price( 'edit' ) ) {
						$base_price = (float) $product->get_price( 'edit' );
					} else {
						$raw_price  = $product->get_meta( '_subscription_price', true );
						$base_price = '' !== $raw_price ? (float) $raw_price : (float) $product->get_regular_price( 'edit' );
					}
					$recurring_price = wc_get_price_excluding_tax( $product, array( 'price' => $base_price ) );
					$item_subtotal   = $recurring_price * $order_item->get_quantity();
					$item_total      = $recurring_price * $order_item->get_quantity();
				}

				$subscription->add_product(
					$product,
					$order_item->get_quantity(),
					array(
						'subtotal' => $item_subtotal,
						'total'    => $item_total,
					)
				);
				$new_period   = ulx_get_product_period( $product );
				$new_interval = ulx_get_product_interval( $product );
				$subscription->set_billing_period( $new_period );
				$subscription->set_billing_interval( $new_interval );

				$next_payment = ulx_add_time( $new_interval, $new_period, time() );
				$end_date     = ulx_get_product_expiration_date( $product, time() );
				$subscription->update_dates(
					array(
						'trial_end'    => 0,
						'next_payment' => ulx_date_to_mysql( $next_payment ),
						'end'          => $end_date ? $end_date : 0,
					)
				);
			}

			// Copy updated billing/shipping addresses and automatic payment gateway if applicable (WCS parity).
			ulx_copy_order_address( $order, $subscription );
			if ( $subscription->is_manual() && $order->get_payment_method() && ! ulx_automatic_payments_disabled() ) {
				$gw = ulx_get_payment_gateway( $order->get_payment_method() );
				if ( $gw && $gw->supports( 'subscriptions' ) ) {
					$subscription->set_payment_method( $order->get_payment_method() );
					$subscription->set_payment_method_title( $order->get_payment_method_title() );
					$subscription->set_requires_manual_renewal( false );
				}
			}

			// Cancel any other pending switch orders for this subscription.
			foreach ( $subscription->get_related_orders( 'all', 'switch' ) as $other_switch ) {
				if ( (int) $other_switch->get_id() !== (int) $order->get_id() && $other_switch->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
					$other_switch->update_status(
						'cancelled',
						sprintf(
							/* translators: %s: order number */
							__( 'Switch order cancelled due to a new switch order being created #%s.', 'ulixes-subscriptions' ),
							$order->get_order_number()
						)
					);
				}
			}

			$subscription->calculate_totals();
			$subscription->save();
			if ( $old_item_name && $product ) {
				$subscription->add_order_note(
					sprintf(
						/* translators: 1: old item name, 2: new item name */
						__( 'Customer switched from: %1$s to %2$s.', 'ulixes-subscriptions' ),
						$old_item_name,
						$product->get_name()
					)
				);
			} else {
				$subscription->add_order_note(
					sprintf(
						/* translators: %s: order number */
						__( 'Customer switched subscription via order #%s.', 'ulixes-subscriptions' ),
						$order->get_order_number()
					)
				);
			}
			do_action( 'ulx_subscription_switched_item', $subscription, $order_item, $switch_data );
		}

		$order->update_meta_data( '_ulx_switch_completed', 'yes' );
		$order->save_meta_data();
		do_action( 'ulx_subscriptions_switched_for_order', $order );
	}
}

