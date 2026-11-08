<?php
/**
 * Synchronised Renewal Dates & Proration Engine.
 *
 * Allows aligning weekly subscriptions to a specific weekday, monthly subscriptions to a day of the month
 * (1..27 or last day of month), and yearly subscriptions to a specific month + day.
 * Supports three initial payment modes:
 * - 'no'        : Charge the full recurring amount upfront at sign-up.
 * - 'yes'       : Prorate the first payment based on days remaining until the sync date (with optional free-days threshold).
 * - 'recurring' : Prorate only virtual products, or charge $0 today until the first sync date.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Sync
 */
class ULX_Sync {

	/**
	 * Init hooks.
	 */
	public static function init() {
		// Sync hooks are called directly by product/cart functions and admin product panels.
	}

	/**
	 * Is synchronisation enabled in store settings?
	 *
	 * @return bool
	 */
	public static function is_syncing_enabled() {
		return ulx_option_is_yes( 'sync_payments' );
	}

	/**
	 * Is a specific product synchronised to a renewal day?
	 *
	 * @param mixed $product Product.
	 * @return bool
	 */
	public static function is_product_synced( $product ) {
		if ( ! self::is_syncing_enabled() ) {
			return false;
		}
		$product = ulx_maybe_get_product( $product );
		if ( ! $product || ! ulx_is_subscription_product( $product ) ) {
			return false;
		}
		$period = ulx_get_product_period( $product );
		if ( 'day' === $period ) {
			return false;
		}
		$sync_date = ulx_get_product_sync_date( $product );
		if ( is_array( $sync_date ) ) {
			return ! empty( $sync_date['day'] ) && ! empty( $sync_date['month'] );
		}
		return absint( $sync_date ) > 0;
	}

	/**
	 * Is a given timestamp falling on today's calendar day (in site timezone)?
	 *
	 * @param int $timestamp GMT timestamp.
	 * @return bool
	 */
	public static function is_today( $timestamp ) {
		$offset = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		return gmdate( 'Y-m-d', $timestamp + $offset ) === gmdate( 'Y-m-d', time() + $offset );
	}

	/**
	 * Calculate the first synchronised renewal payment date for a product.
	 *
	 * @param WC_Product $product   Product.
	 * @param string     $type      'timestamp' or 'mysql'.
	 * @param int        $from_when GMT timestamp.
	 * @return int|string
	 */
	public static function calculate_first_payment_date( $product, $type = 'mysql', $from_when = 0 ) {
		$product = ulx_maybe_get_product( $product );
		if ( ! self::is_product_synced( $product ) ) {
			return 0;
		}

		$from_when = $from_when ? (int) $from_when : time();
		if ( ulx_product_has_trial( $product ) ) {
			$from_when = ulx_date_to_time( ulx_get_product_trial_expiration_date( $product, $from_when ) );
		}

		$period    = ulx_get_product_period( $product );
		$sync_date = ulx_get_product_sync_date( $product );
		$offset    = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$site_now  = $from_when + $offset;

		$first_payment = $from_when;

		if ( 'week' === $period ) {
			// 1 = Monday .. 7 = Sunday.
			$target_dow  = (int) $sync_date % 7;
			$current_dow = (int) gmdate( 'w', $site_now );
			$days_ahead  = ( $target_dow - $current_dow + 7 ) % 7;
			$first_payment = strtotime( gmdate( 'Y-m-d 03:00:00', $site_now + $days_ahead * DAY_IN_SECONDS ) . ' UTC' ) - $offset;
		} elseif ( 'month' === $period ) {
			$target_day  = (int) $sync_date;
			$current_day = (int) gmdate( 'j', $site_now );
			$year        = (int) gmdate( 'Y', $site_now );
			$month       = (int) gmdate( 'n', $site_now );

			if ( $current_day > $target_day ) {
				$month++;
				if ( $month > 12 ) {
					$month = 1;
					$year++;
				}
			}
			$days_in_month = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
			$actual_day    = min( $target_day, $days_in_month );
			$first_payment = gmmktime( 3, 0, 0, $month, $actual_day, $year ) - $offset;
		} elseif ( 'year' === $period && is_array( $sync_date ) ) {
			$target_month = (int) $sync_date['month'];
			$target_day   = (int) $sync_date['day'];
			$year         = (int) gmdate( 'Y', $site_now );
			$candidate    = gmmktime( 3, 0, 0, $target_month, $target_day, $year ) - $offset;
			if ( $candidate < $from_when - DAY_IN_SECONDS ) {
				$candidate = gmmktime( 3, 0, 0, $target_month, $target_day, $year + 1 ) - $offset;
			}
			$first_payment = $candidate;
		}

		return 'timestamp' === $type ? (int) $first_payment : ulx_date_to_mysql( $first_payment );
	}

	/**
	 * Calculate the initial recurring price to charge today for a synced product.
	 *
	 * @param WC_Product $product Product.
	 * @param float      $price   Base recurring price.
	 * @return float
	 */
	public static function get_synced_initial_price( $product, $price ) {
		if ( ulx_product_has_trial( $product ) ) {
			return 0;
		}
		$first_ts = self::calculate_first_payment_date( $product, 'timestamp' );
		if ( ! $first_ts || self::is_today( $first_ts ) ) {
			return (float) $price;
		}

		$prorate_mode = ulx_get_option( 'prorate_synced_payments', 'no' );
		// 'no' = never (charge full amount at sign-up or 0? In WCS: 'no' means do not charge anything until sync date; 'full' means charge full amount at sign-up; 'yes' = prorate all; 'virtual' = prorate virtual).
		if ( 'no' === $prorate_mode ) {
			return 0;
		}
		if ( 'full' === $prorate_mode ) {
			return (float) $price;
		}
		if ( 'virtual' === $prorate_mode && ! $product->is_virtual() ) {
			return (float) $price;
		}

		// Prorate based on days until first sync date.
		$days_until = max( 0, ceil( ( $first_ts - time() ) / DAY_IN_SECONDS ) );
		$no_fee_days = (int) ulx_get_option( 'days_no_fee', 0 );
		if ( $days_until <= $no_fee_days ) {
			return 0;
		}

		$period_days = ulx_estimate_days_in_period( ulx_get_product_period( $product ), ulx_get_product_interval( $product ) );
		if ( $period_days <= 0 ) {
			return (float) $price;
		}

		return ulx_round( ( (float) $price / $period_days ) * $days_until );
	}
}

