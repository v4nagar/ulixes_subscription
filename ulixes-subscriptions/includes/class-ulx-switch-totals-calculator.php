<?php
/**
 * Switch Totals Calculator: computes prorated recurring amounts and sign-up fees
 * based on remaining cycle time when a customer upgrades or downgrades their subscription.
 *
 * Matches WooCommerce Subscriptions' WCS_Switch_Totals_Calculator architecture.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Switch_Totals_Calculator
 */
class ULX_Switch_Totals_Calculator {

	/**
	 * Calculate prorated switch totals for upgrading or downgrading a subscription item.
	 *
	 * @param ULX_Subscription      $subscription Existing subscription.
	 * @param WC_Order_Item_Product $old_item     Line item on existing subscription.
	 * @param WC_Product            $new_product  Product/variation being switched to.
	 * @return array Calculation breakdown.
	 */
	public static function calculate_prorated_totals( $subscription, $old_item, $new_product ) {
		$calc = array(
			'prorated_price'  => 0.0,
			'sign_up_fee'     => 0.0,
			'old_credit'      => 0.0,
			'days_remaining'  => 0,
			'days_in_cycle'   => 0,
			'direction'       => 'crossgraded',
			'full_recurring'  => (float) ulx_get_product_price( $new_product ),
		);

		if ( ! $subscription || ! $old_item || ! $new_product ) {
			return $calc;
		}

		$old_product = $old_item->get_product();

		// 1. Calculate switch direction (upgraded, downgraded, crossgraded).
		$direction = 'crossgraded';
		if ( $new_product && $old_product ) {
			$new_per_day = self::get_price_per_day( $new_product );
			$old_days    = max( 1, ulx_estimate_days_in_period( $subscription->get_billing_period(), $subscription->get_billing_interval() ) );
			$old_per_day = ( (float) $old_item->get_subtotal() / max( 1, $old_item->get_quantity() ) ) / $old_days;
			if ( $new_per_day > $old_per_day ) {
				$direction = 'upgraded';
			} elseif ( $new_per_day < $old_per_day ) {
				$direction = 'downgraded';
			}
		}
		$calc['direction'] = $direction;

		// 2. Sign-up fee apportionment.
		$apportion_fee = ulx_get_option( 'apportion_sign_up_fee', 'no' );
		$sign_up_fee   = 0.0;
		if ( 'yes' === $apportion_fee ) {
			$new_fee     = (float) ulx_get_product_sign_up_fee( $new_product );
			$old_fee     = (float) $old_item->get_meta( '_ulx_sign_up_fee', true );
			$sign_up_fee = max( 0.0, $new_fee - $old_fee );
		} elseif ( 'full' === $apportion_fee ) {
			$sign_up_fee = (float) ulx_get_product_sign_up_fee( $new_product );
		}
		$calc['sign_up_fee'] = $sign_up_fee;

		// 3. Recurring price proration.
		$apportion_price = ulx_get_option( 'apportion_recurring_price', 'no' );
		$should_prorate  = ( 'yes' === $apportion_price || 'yes-upgrade' === $apportion_price ) ||
			( ( 'virtual' === $apportion_price || 'virtual-upgrade' === $apportion_price ) && $new_product->is_virtual() );

		// If proration is only for upgrades and this is a downgrade, skip proration.
		if ( ( 'yes-upgrade' === $apportion_price || 'virtual-upgrade' === $apportion_price ) && 'downgraded' === $direction ) {
			$should_prorate = false;
		}

		$true_recurring = (float) ulx_get_product_price( $new_product );
		if ( ! $should_prorate ) {
			$calc['prorated_price'] = $true_recurring;
			return $calc;
		}

		$next_payment = $subscription->get_time( 'next_payment' );
		if ( $next_payment <= time() ) {
			// Renewal is already due or past due; charge full recurring amount.
			$calc['prorated_price'] = $true_recurring;
			return $calc;
		}

		$last_order = $subscription->get_last_order( 'all', array( 'parent', 'renewal' ) );
		$last_time  = $last_order && $last_order->get_date_created() ? $last_order->get_date_created()->getTimestamp() : $subscription->get_time( 'start' );

		$days_in_old_period = max( 1, ulx_days_between( $last_time, $next_payment ) );
		$days_remaining     = max( 0, ulx_days_between( time(), $next_payment ) );

		$calc['days_in_cycle']  = $days_in_old_period;
		$calc['days_remaining'] = $days_remaining;

		$old_daily  = ( (float) $old_item->get_subtotal() / max( 1, $old_item->get_quantity() ) ) / $days_in_old_period;
		$old_credit = $old_daily * $days_remaining;
		$calc['old_credit'] = $old_credit;

		$new_period   = ulx_get_product_period( $new_product );
		$new_interval = ulx_get_product_interval( $new_product );

		if ( $new_period === $subscription->get_billing_period() && (int) $new_interval === (int) $subscription->get_billing_interval() ) {
			// Same billing schedule: prorate the daily price difference over the remaining days.
			$new_daily = self::get_price_per_day( $new_product );
			$diff      = ( $new_daily - $old_daily ) * $days_remaining;
			$amount    = $diff > 0 ? ulx_round( $diff ) : 0.0;
		} else {
			// Different billing schedule: deduct unused credit from the new plan's full initial price.
			$diff   = $true_recurring - $old_credit;
			$amount = $diff > 0 ? ulx_round( $diff ) : 0.0;
		}

		$calc['prorated_price'] = $amount;
		return apply_filters( 'ulx_switch_prorated_totals', $calc, $subscription, $old_item, $new_product );
	}

	/**
	 * Get daily rate for a product based on its billing schedule.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function get_price_per_day( $product ) {
		$days = ulx_estimate_days_in_period( ulx_get_product_period( $product ), ulx_get_product_interval( $product ) );
		return $days > 0 ? (float) ulx_get_product_price( $product ) / $days : (float) ulx_get_product_price( $product );
	}
}

