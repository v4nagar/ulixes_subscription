<?php
/**
 * Renewal Order status & payment synchronisation with parent Subscription.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Renewal_Order
 */
class ULX_Renewal_Order {

	/**
	 * Guard against recursive payment_complete calls.
	 *
	 * @var array
	 */
	protected static $processing_subscriptions = array();

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_record_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_record_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'maybe_record_subscription_payment_failed' ), 10, 2 );
		add_filter( 'woocommerce_order_number', array( __CLASS__, 'filter_renewal_order_number' ), 10, 2 );
	}

	/**
	 * Check if a subscription is currently in the middle of payment_complete processing.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @return bool
	 */
	public static function is_payment_complete_processing( $subscription ) {
		return ! empty( self::$processing_subscriptions[ $subscription->get_id() ] );
	}

	/**
	 * When a renewal order is paid (processing/completed), activate the subscription and update payment method if paid manually.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order.
	 */
	public static function maybe_record_subscription_payment( $order_id, $order = null ) {
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! ulx_order_contains_renewal( $order ) ) {
			return;
		}

		$subscriptions = ulx_get_subscriptions_for_renewal_order( $order );
		foreach ( $subscriptions as $subscription ) {
			if ( self::is_payment_complete_processing( $subscription ) ) {
				continue;
			}
			self::$processing_subscriptions[ $subscription->get_id() ] = true;

			// If this was an early renewal, advance the next_payment date by one billing interval from the previous next_payment date.
			if ( ulx_order_contains_early_renewal( $order ) ) {
				$old_next = $subscription->get_time( 'next_payment' );
				$base     = $old_next > time() ? $old_next : time();
				$new_next = ulx_add_time( $subscription->get_billing_interval(), $subscription->get_billing_period(), $base );
				$end      = $subscription->get_time( 'end' );
				if ( $end && $new_next >= ( $end - 23 * HOUR_IN_SECONDS ) ) {
					$new_next = 0;
				}
				$subscription->update_dates( array( 'next_payment' => $new_next ? ulx_date_to_mysql( $new_next ) : 0 ) );
			}

			// If customer paid a manual/failed renewal order with a gateway that supports automatic renewals, adopt the new payment method.
			if ( $order->get_payment_method() && $order->get_payment_method() !== $subscription->get_payment_method() ) {
				$gw = ulx_get_payment_gateway( $order->get_payment_method() );
				if ( $gw && $gw->supports( 'subscriptions' ) && ! ulx_automatic_payments_disabled() ) {
					$subscription->set_payment_method( $order->get_payment_method() );
					$subscription->set_payment_method_title( $order->get_payment_method_title() );
					$subscription->set_requires_manual_renewal( false );
				}
			}

			$subscription->payment_complete( $order->get_transaction_id() );
			unset( self::$processing_subscriptions[ $subscription->get_id() ] );
		}
	}

	/**
	 * When a renewal order transitions to failed, trigger `payment_failed()` on its subscription.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order.
	 */
	public static function maybe_record_subscription_payment_failed( $order_id, $order = null ) {
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! ulx_order_contains_renewal( $order ) ) {
			return;
		}
		$subscriptions = ulx_get_subscriptions_for_renewal_order( $order );
		foreach ( $subscriptions as $subscription ) {
			$subscription->payment_failed();
		}
	}

	/**
	 * Optionally prefix renewal order numbers if configured in settings.
	 *
	 * @param string   $order_number Order number.
	 * @param WC_Order $order        Order.
	 * @return string
	 */
	public static function filter_renewal_order_number( $order_number, $order ) {
		if ( $order instanceof WC_Order && ! ( $order instanceof ULX_Subscription ) && ulx_order_contains_renewal( $order ) ) {
			$prefix = ulx_get_option( 'renewal_order_number_prefix', '' );
			if ( '' !== $prefix ) {
				return $prefix . $order_number;
			}
		}
		return $order_number;
	}
}

