<?php
/**
 * Time / period helper functions.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Billing periods.
 *
 * @param int $number For pluralisation.
 * @return array
 */
function ulx_get_subscription_period_strings( $number = 1, $period = '' ) {
	// phpcs:disable WordPress.WP.I18n.MissingSingularPlaceholder
	$strings = apply_filters(
		'ulx_subscription_periods',
		array(
			/* translators: %s: number */
			'day'   => sprintf( _nx( 'day', '%s days', $number, 'Subscription billing period', 'ulixes-subscriptions' ), $number ),
			/* translators: %s: number */
			'week'  => sprintf( _nx( 'week', '%s weeks', $number, 'Subscription billing period', 'ulixes-subscriptions' ), $number ),
			/* translators: %s: number */
			'month' => sprintf( _nx( 'month', '%s months', $number, 'Subscription billing period', 'ulixes-subscriptions' ), $number ),
			/* translators: %s: number */
			'year'  => sprintf( _nx( 'year', '%s years', $number, 'Subscription billing period', 'ulixes-subscriptions' ), $number ),
		),
		$number
	);
	// phpcs:enable WordPress.WP.I18n.MissingSingularPlaceholder
	return ! empty( $period ) ? ( isset( $strings[ $period ] ) ? $strings[ $period ] : '' ) : $strings;
}

/**
 * Trial period strings.
 *
 * @param int    $number Number.
 * @param string $period Period.
 * @return array|string
 */
function ulx_get_subscription_trial_period_strings( $number = 1, $period = '' ) {
	$strings = apply_filters(
		'ulx_subscription_trial_periods',
		array(
			/* translators: %s: number */
			'day'   => sprintf( _n( '%s day', 'a %s-day', $number, 'ulixes-subscriptions' ), $number ),
			/* translators: %s: number */
			'week'  => sprintf( _n( '%s week', 'a %s-week', $number, 'ulixes-subscriptions' ), $number ),
			/* translators: %s: number */
			'month' => sprintf( _n( '%s month', 'a %s-month', $number, 'ulixes-subscriptions' ), $number ),
			/* translators: %s: number */
			'year'  => sprintf( _n( '%s year', 'a %s-year', $number, 'ulixes-subscriptions' ), $number ),
		),
		$number
	);
	return ! empty( $period ) ? ( isset( $strings[ $period ] ) ? $strings[ $period ] : '' ) : $strings;
}

/**
 * Available time periods for the admin select (singular nouns).
 *
 * @param string $form 'singular' or 'plural'.
 * @return array
 */
function ulx_get_available_time_periods( $form = 'singular' ) {
	$number = 'singular' === $form ? 1 : 2;
	return apply_filters(
		'ulx_available_time_periods',
		array(
			'day'   => _nx( 'day', 'days', $number, 'Used in the trial period dropdown.', 'ulixes-subscriptions' ),
			'week'  => _nx( 'week', 'weeks', $number, 'Used in the trial period dropdown.', 'ulixes-subscriptions' ),
			'month' => _nx( 'month', 'months', $number, 'Used in the trial period dropdown.', 'ulixes-subscriptions' ),
			'year'  => _nx( 'year', 'years', $number, 'Used in the trial period dropdown.', 'ulixes-subscriptions' ),
		)
	);
}

/**
 * Billing intervals 1..6.
 *
 * @return array
 */
function ulx_get_subscription_period_interval_strings( $interval = '' ) {
	$intervals = array( 1 => _x( 'every', 'period interval (eg "$10 _every_ 2 weeks")', 'ulixes-subscriptions' ) );
	foreach ( range( 2, 6 ) as $i ) {
		/* translators: %s: interval number, e.g. "2nd" */
		$intervals[ $i ] = sprintf( _x( 'every %s', 'period interval with ordinal number (e.g. "every 2nd")', 'ulixes-subscriptions' ), ulx_append_numeral_suffix( $i ) );
	}
	$intervals = apply_filters( 'ulx_subscription_period_interval_strings', $intervals );
	return '' === $interval ? $intervals : ( isset( $intervals[ $interval ] ) ? $intervals[ $interval ] : '' );
}

/**
 * Subscription lengths for each period (0 = never expire).
 *
 * @param string $period Period.
 * @return array
 */
function ulx_get_subscription_ranges( $period = '' ) {
	$max = array(
		'day'   => 90,
		'week'  => 52,
		'month' => 24,
		'year'  => 5,
	);
	$ranges = array();
	foreach ( $max as $p => $limit ) {
		$ranges[ $p ] = array( 0 => _x( 'Never expire', 'Subscription length', 'ulixes-subscriptions' ) );
		for ( $i = 1; $i <= $limit; $i++ ) {
			$ranges[ $p ][ $i ] = ulx_get_subscription_period_strings( $i, $p );
		}
	}
	$ranges = apply_filters( 'ulx_subscription_lengths', $ranges, $period );
	return '' === $period ? $ranges : ( isset( $ranges[ $period ] ) ? $ranges[ $period ] : array() );
}

/**
 * Trial lengths.
 *
 * @param string $period Period.
 * @return array
 */
function ulx_get_subscription_trial_lengths( $period = '' ) {
	$lengths = ulx_get_subscription_ranges();
	foreach ( $lengths as $p => $l ) {
		$lengths[ $p ][0] = _x( 'no', 'no trial period', 'ulixes-subscriptions' );
	}
	return '' === $period ? $lengths : ( isset( $lengths[ $period ] ) ? $lengths[ $period ] : array() );
}

/**
 * 1st, 2nd, 3rd...
 *
 * @param int $number Number.
 * @return string
 */
function ulx_append_numeral_suffix( $number ) {
	$number = (int) $number;
	if ( in_array( $number % 100, array( 11, 12, 13 ), true ) ) {
		/* translators: %s: number */
		return sprintf( __( '%sth', 'ulixes-subscriptions' ), $number );
	}
	switch ( $number % 10 ) {
		case 1:
			/* translators: %s: number */
			return sprintf( __( '%sst', 'ulixes-subscriptions' ), $number );
		case 2:
			/* translators: %s: number */
			return sprintf( __( '%snd', 'ulixes-subscriptions' ), $number );
		case 3:
			/* translators: %s: number */
			return sprintf( __( '%srd', 'ulixes-subscriptions' ), $number );
		default:
			/* translators: %s: number */
			return sprintf( __( '%sth', 'ulixes-subscriptions' ), $number );
	}
}

/**
 * Add a number of periods to a GMT timestamp, handling end of month correctly
 * (Jan 31 + 1 month = Feb 28/29, not Mar 3).
 *
 * @param int    $number_of_periods Periods.
 * @param string $period            day|week|month|year.
 * @param int    $from_timestamp    GMT timestamp.
 * @return int
 */
function ulx_add_time( $number_of_periods, $period, $from_timestamp ) {
	$number_of_periods = (int) $number_of_periods;
	if ( $number_of_periods <= 0 ) {
		return (int) $from_timestamp;
	}
	if ( 'month' === $period ) {
		return ulx_add_months( $from_timestamp, $number_of_periods );
	}
	if ( 'year' === $period ) {
		return ulx_add_months( $from_timestamp, $number_of_periods * 12 );
	}
	$seconds = array(
		'day'  => DAY_IN_SECONDS,
		'week' => WEEK_IN_SECONDS,
	);
	if ( ! isset( $seconds[ $period ] ) ) {
		return (int) $from_timestamp;
	}
	return (int) $from_timestamp + $number_of_periods * $seconds[ $period ];
}

/**
 * Add months handling end-of-month.
 *
 * @param int $from_timestamp GMT.
 * @param int $months_to_add  Months.
 * @return int
 */
function ulx_add_months( $from_timestamp, $months_to_add ) {
	$first_day_of_month = gmdate( 'Y-m', $from_timestamp ) . '-1';
	$days_in_next_month = (int) gmdate( 't', strtotime( "+ {$months_to_add} month", strtotime( $first_day_of_month ) ) );
	$day                = (int) gmdate( 'd', $from_timestamp );
	$is_last_day        = (int) gmdate( 'd', $from_timestamp ) === (int) gmdate( 't', $from_timestamp );

	if ( $is_last_day || $day > $days_in_next_month ) {
		$target = strtotime( "+ {$months_to_add} month", strtotime( $first_day_of_month . ' ' . gmdate( 'H:i:s', $from_timestamp ) . ' UTC' ) );
		// Move to last day of target month.
		$target = strtotime( gmdate( 'Y-m-t H:i:s', $target ) . ' UTC' );
		return (int) $target;
	}
	return (int) strtotime( "+ {$months_to_add} month", $from_timestamp );
}

/**
 * Estimate days in a period.
 *
 * @param string $period   Period.
 * @param int    $interval Interval.
 * @return float
 */
function ulx_estimate_days_in_period( $period, $interval = 1 ) {
	$map = array(
		'day'   => 1,
		'week'  => 7,
		'month' => 30.4375,
		'year'  => 365.25,
	);
	return isset( $map[ $period ] ) ? $map[ $period ] * max( 1, (int) $interval ) : 0;
}

/**
 * Convert a period count to days roughly.
 *
 * @param string $period Period.
 * @return int seconds
 */
function ulx_period_to_seconds( $period, $interval = 1 ) {
	return (int) round( ulx_estimate_days_in_period( $period, $interval ) * DAY_IN_SECONDS );
}

/**
 * MySQL date (GMT) from timestamp.
 *
 * @param int $timestamp Timestamp.
 * @return string
 */
function ulx_date_to_mysql( $timestamp ) {
	return gmdate( 'Y-m-d H:i:s', (int) $timestamp );
}

/**
 * Timestamp from GMT MySQL date.
 *
 * @param string $date Date.
 * @return int
 */
function ulx_date_to_time( $date ) {
	if ( empty( $date ) || 0 === $date || '0' === $date ) {
		return 0;
	}
	if ( is_numeric( $date ) ) {
		return (int) $date;
	}
	$ts = strtotime( $date . ' UTC' );
	return false === $ts ? 0 : (int) $ts;
}

/**
 * Whether a datetime string is valid MySQL datetime.
 *
 * @param string $time Time.
 * @return bool
 */
function ulx_is_datetime_mysql_format( $time ) {
	if ( ! is_string( $time ) ) {
		return false;
	}
	return (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time );
}

/**
 * Format a GMT timestamp/date for site display.
 *
 * @param string|int $date   GMT date.
 * @param string     $format Format.
 * @return string
 */
function ulx_format_date( $date, $format = '' ) {
	$ts = ulx_date_to_time( $date );
	if ( ! $ts ) {
		return '-';
	}
	$format = $format ? $format : wc_date_format();
	return date_i18n( $format, $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
}

/**
 * Human readable time diff (e.g. "In 3 days").
 *
 * @param int $timestamp GMT timestamp.
 * @return string
 */
function ulx_human_time_diff( $timestamp ) {
	$now = time();
	if ( $timestamp > $now ) {
		/* translators: %s: human time diff */
		return sprintf( __( 'In %s', 'ulixes-subscriptions' ), human_time_diff( $now, $timestamp ) );
	}
	/* translators: %s: human time diff */
	return sprintf( __( '%s ago', 'ulixes-subscriptions' ), human_time_diff( $timestamp, $now ) );
}

/**
 * Number of whole/fractional days between 2 timestamps.
 *
 * @param int $from From.
 * @param int $to   To.
 * @return float
 */
function ulx_days_between( $from, $to ) {
	return ( (int) $to - (int) $from ) / DAY_IN_SECONDS;
}

