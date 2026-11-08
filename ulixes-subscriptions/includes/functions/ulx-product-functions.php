<?php
/**
 * Product helper functions.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Subscription product types.
 *
 * @return array
 */
function ulx_get_subscription_product_types() {
	return apply_filters( 'ulx_subscription_product_types', array( 'subscription', 'variable-subscription', 'subscription_variation' ) );
}

/**
 * Resolve a product object.
 *
 * @param mixed $product Product, ID or post.
 * @return WC_Product|false
 */
function ulx_maybe_get_product( $product ) {
	if ( $product instanceof WC_Product ) {
		return $product;
	}
	if ( is_numeric( $product ) || $product instanceof WP_Post ) {
		return wc_get_product( $product );
	}
	return false;
}

/**
 * Whether a product is a subscription.
 *
 * @param mixed $product Product.
 * @return bool
 */
function ulx_is_subscription_product( $product ) {
	$product = ulx_maybe_get_product( $product );
	$is      = $product && $product->is_type( ulx_get_subscription_product_types() );
	return (bool) apply_filters( 'ulx_is_subscription', $is, $product ? $product->get_id() : 0, $product );
}

/**
 * Get subscription meta from product, falling back to parent for variations where appropriate.
 *
 * @param mixed  $product Product.
 * @param string $key     Meta key without _subscription_ prefix.
 * @param mixed  $default Default.
 * @return mixed
 */
function ulx_get_product_meta( $product, $key, $default = '' ) {
	$product = ulx_maybe_get_product( $product );
	if ( ! $product ) {
		return $default;
	}
	$value = $product->get_meta( '_subscription_' . $key, true );
	if ( ( '' === $value || null === $value ) && $product->is_type( 'variable-subscription' ) ) {
		// Use the min-priced variation's value for variable parents.
		$child = ulx_get_min_price_variation( $product );
		if ( $child ) {
			$value = $child->get_meta( '_subscription_' . $key, true );
		}
	}
	return ( '' === $value || null === $value ) ? $default : $value;
}

/**
 * Recurring price.
 *
 * @param mixed $product Product.
 * @return float
 */
function ulx_get_product_price( $product ) {
	$product = ulx_maybe_get_product( $product );
	if ( ! $product ) {
		return 0;
	}
	return (float) apply_filters( 'ulx_product_price', (float) $product->get_price(), $product );
}

/**
 * Billing period.
 *
 * @param mixed $product Product.
 * @return string
 */
function ulx_get_product_period( $product ) {
	return apply_filters( 'ulx_product_period', ulx_get_product_meta( $product, 'period', 'month' ), ulx_maybe_get_product( $product ) );
}

/**
 * Billing interval.
 *
 * @param mixed $product Product.
 * @return int
 */
function ulx_get_product_interval( $product ) {
	return (int) apply_filters( 'ulx_product_period_interval', max( 1, (int) ulx_get_product_meta( $product, 'period_interval', 1 ) ), ulx_maybe_get_product( $product ) );
}

/**
 * Length in periods (0 = forever).
 *
 * @param mixed $product Product.
 * @return int
 */
function ulx_get_product_length( $product ) {
	return (int) apply_filters( 'ulx_product_length', (int) ulx_get_product_meta( $product, 'length', 0 ), ulx_maybe_get_product( $product ) );
}

/**
 * Trial length.
 *
 * @param mixed $product Product.
 * @return int
 */
function ulx_get_product_trial_length( $product ) {
	return (int) apply_filters( 'ulx_product_trial_length', (int) ulx_get_product_meta( $product, 'trial_length', 0 ), ulx_maybe_get_product( $product ) );
}

/**
 * Trial period.
 *
 * @param mixed $product Product.
 * @return string
 */
function ulx_get_product_trial_period( $product ) {
	$period = ulx_get_product_meta( $product, 'trial_period', '' );
	if ( empty( $period ) ) {
		$period = ulx_get_product_period( $product );
	}
	return apply_filters( 'ulx_product_trial_period', $period, ulx_maybe_get_product( $product ) );
}

/**
 * Sign-up fee.
 *
 * @param mixed $product Product.
 * @return float
 */
function ulx_get_product_sign_up_fee( $product ) {
	return (float) apply_filters( 'ulx_product_sign_up_fee', (float) ulx_get_product_meta( $product, 'sign_up_fee', 0 ), ulx_maybe_get_product( $product ) );
}

/**
 * Sync payment day.
 *
 * @param mixed $product Product.
 * @return int|array 0 none, int day of week/month, array(day,month) for yearly.
 */
function ulx_get_product_sync_date( $product ) {
	$value = ulx_get_product_meta( $product, 'payment_sync_date', 0 );
	return apply_filters( 'ulx_product_payment_sync_date', $value, ulx_maybe_get_product( $product ) );
}

/**
 * Purchase limit: no | active | any.
 *
 * @param mixed $product Product.
 * @return string
 */
function ulx_get_product_limit( $product ) {
	$product = ulx_maybe_get_product( $product );
	if ( $product && $product->is_type( 'subscription_variation' ) ) {
		$product = wc_get_product( $product->get_parent_id() );
	}
	$limit = $product ? $product->get_meta( '_subscription_limit', true ) : 'no';
	return apply_filters( 'ulx_product_limit', $limit ? $limit : 'no', $product );
}

/**
 * One time shipping.
 *
 * @param mixed $product Product.
 * @return bool
 */
function ulx_product_needs_one_time_shipping( $product ) {
	$product = ulx_maybe_get_product( $product );
	if ( ! $product ) {
		return false;
	}
	$check = $product->is_type( 'subscription_variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
	$value = $check ? 'yes' === $check->get_meta( '_subscription_one_time_shipping', true ) : false;
	// Synced products with trials cannot have one-time shipping disabled correctly; keep WCS behaviour.
	return (bool) apply_filters( 'ulx_product_needs_one_time_shipping', $value, $product );
}

/**
 * Has a free trial?
 *
 * @param mixed $product Product.
 * @return bool
 */
function ulx_product_has_trial( $product ) {
	return ulx_get_product_trial_length( $product ) > 0;
}

/**
 * Variation with lowest price for a variable subscription.
 *
 * @param WC_Product $product Variable product.
 * @return WC_Product|false
 */
function ulx_get_min_price_variation( $product ) {
	if ( ! $product || ! $product->is_type( 'variable-subscription' ) ) {
		return false;
	}
	$min_id = $product->get_meta( '_min_price_variation_id', true );
	if ( $min_id ) {
		$v = wc_get_product( $min_id );
		if ( $v ) {
			return $v;
		}
	}
	$best    = false;
	$best_pp = null;
	foreach ( $product->get_children() as $child_id ) {
		$child = wc_get_product( $child_id );
		if ( ! $child || ! $child->is_purchasable() ) {
			continue;
		}
		// Compare per-day price so "$10/week" < "$50/month" correctly.
		$days = ulx_estimate_days_in_period( $child->get_meta( '_subscription_period', true ) ?: 'month', $child->get_meta( '_subscription_period_interval', true ) ?: 1 );
		$pp   = $days > 0 ? (float) $child->get_price() / $days : (float) $child->get_price();
		if ( null === $best_pp || $pp < $best_pp ) {
			$best_pp = $pp;
			$best    = $child;
		}
	}
	return $best;
}

/**
 * Trial expiration date for a product purchased now.
 *
 * @param mixed      $product Product.
 * @param string|int $from    GMT mysql date or ts.
 * @return string 0 or mysql date
 */
function ulx_get_product_trial_expiration_date( $product, $from = '' ) {
	$length = ulx_get_product_trial_length( $product );
	if ( $length <= 0 ) {
		return 0;
	}
	$from = $from ? ulx_date_to_time( $from ) : time();
	return ulx_date_to_mysql( ulx_add_time( $length, ulx_get_product_trial_period( $product ), $from ) );
}

/**
 * First renewal payment date.
 *
 * @param mixed      $product Product.
 * @param string|int $from    From.
 * @return string 0|mysql
 */
function ulx_get_product_first_renewal_payment_date( $product, $from = '' ) {
	$from_ts = $from ? ulx_date_to_time( $from ) : time();
	$length  = ulx_get_product_length( $product );
	$period  = ulx_get_product_period( $product );
	$int     = ulx_get_product_interval( $product );

	if ( ULX_Sync::is_product_synced( $product ) ) {
		$first = ULX_Sync::calculate_first_payment_date( $product, 'timestamp', $from_ts );
		if ( $first && ! ULX_Sync::is_today( $first ) ) {
			// First payment occurs at sync date (unless the subscription only lasts one period).
			$expiration = ulx_get_product_expiration_date( $product, $from_ts );
			if ( $expiration && $first >= ulx_date_to_time( $expiration ) ) {
				return 0;
			}
			return ulx_date_to_mysql( $first );
		}
	}

	$trial_end = ulx_get_product_trial_expiration_date( $product, $from_ts );
	if ( $trial_end ) {
		$first = ulx_date_to_time( $trial_end );
	} else {
		if ( 1 === $length / max( 1, $int ) && $length > 0 && $length === $int ) {
			return 0; // Only one payment.
		}
		$first = ulx_add_time( $int, $period, $from_ts );
	}

	$expiration = ulx_get_product_expiration_date( $product, $from_ts );
	if ( $expiration && $first >= ulx_date_to_time( $expiration ) ) {
		return 0;
	}
	return apply_filters( 'ulx_product_first_renewal_payment_date', ulx_date_to_mysql( $first ), $product, $from_ts );
}

/**
 * Expiration date.
 *
 * @param mixed      $product Product.
 * @param string|int $from    From.
 * @return string 0|mysql
 */
function ulx_get_product_expiration_date( $product, $from = '' ) {
	$length = ulx_get_product_length( $product );
	if ( $length <= 0 ) {
		return 0;
	}
	$from_ts = $from ? ulx_date_to_time( $from ) : time();
	$trial   = ulx_get_product_trial_expiration_date( $product, $from_ts );
	$start   = $trial ? ulx_date_to_time( $trial ) : $from_ts;
	if ( ULX_Sync::is_product_synced( $product ) ) {
		$first = ULX_Sync::calculate_first_payment_date( $product, 'timestamp', $from_ts );
		if ( $first && ! ULX_Sync::is_today( $first ) ) {
			// First renewal at the sync date; length counts from there minus one interval.
			$start  = $first;
			$length = $length - ulx_get_product_interval( $product );
			if ( $length <= 0 ) {
				return ulx_date_to_mysql( $first );
			}
		}
	}
	return ulx_date_to_mysql( ulx_add_time( $length, ulx_get_product_period( $product ), $start ) );
}

/**
 * Build a human readable subscription price string.
 *
 * @param mixed $product Product.
 * @param array $include Options: price, tax_calculation, subscription_price, subscription_period, subscription_length, sign_up_fee, trial_length.
 * @return string
 */
function ulx_get_product_price_string( $product, $include = array() ) {
	$product = ulx_maybe_get_product( $product );
	if ( ! $product || ! ulx_is_subscription_product( $product ) ) {
		return '';
	}

	$include = wp_parse_args(
		$include,
		array(
			'tax_calculation'     => get_option( 'woocommerce_tax_display_shop' ),
			'subscription_price'  => true,
			'subscription_period' => true,
			'subscription_length' => true,
			'sign_up_fee'         => true,
			'trial_length'        => true,
		)
	);
	$include = apply_filters( 'ulx_product_price_string_inclusions', $include, $product );

	$base_price = isset( $include['price'] ) ? $include['price'] : null;
	if ( null === $base_price ) {
		$raw = ulx_get_product_price( $product );
		if ( 'incl' === $include['tax_calculation'] ) {
			$raw = wc_get_price_including_tax( $product, array( 'price' => $raw ) );
		} elseif ( 'excl' === $include['tax_calculation'] ) {
			$raw = wc_get_price_excluding_tax( $product, array( 'price' => $raw ) );
		}
		$base_price = wc_price( $raw );
	}

	$sign_up_fee = ulx_get_product_sign_up_fee( $product );
	if ( $include['sign_up_fee'] && $sign_up_fee > 0 && 'excl' === $include['tax_calculation'] ) {
		$sign_up_fee = wc_get_price_excluding_tax( $product, array( 'price' => $sign_up_fee ) );
	} elseif ( $include['sign_up_fee'] && $sign_up_fee > 0 && 'incl' === $include['tax_calculation'] ) {
		$sign_up_fee = wc_get_price_including_tax( $product, array( 'price' => $sign_up_fee ) );
	}

	$details = array(
		'recurring_amount'      => $include['subscription_price'] ? $base_price : '',
		'subscription_period'   => ulx_get_product_period( $product ),
		'subscription_interval' => ulx_get_product_interval( $product ),
		'subscription_length'   => $include['subscription_length'] ? ulx_get_product_length( $product ) : 0,
		'trial_length'          => $include['trial_length'] ? ulx_get_product_trial_length( $product ) : 0,
		'trial_period'          => ulx_get_product_trial_period( $product ),
		'initial_amount'        => ( $include['sign_up_fee'] && $sign_up_fee > 0 ) ? wc_price( $sign_up_fee ) : '',
		'initial_description'   => _x( 'sign-up fee', 'initial payment on a subscription', 'ulixes-subscriptions' ),
		'display_period'        => (bool) $include['subscription_period'],
		'synced_date'           => ULX_Sync::is_product_synced( $product ) ? ulx_get_product_sync_date( $product ) : 0,
	);

	$string = ulx_get_formatted_price_string( $details );
	return apply_filters( 'ulx_product_price_string', $string, $product, $include );
}

/**
 * Format a price string from details (shared by products, cart & subscriptions).
 *
 * @param array $d Details.
 * @return string
 */
function ulx_get_formatted_price_string( $d ) {
	$d = wp_parse_args(
		$d,
		array(
			'recurring_amount'      => '',
			'subscription_period'   => 'month',
			'subscription_interval' => 1,
			'subscription_length'   => 0,
			'trial_length'          => 0,
			'trial_period'          => '',
			'initial_amount'        => '',
			'initial_description'   => _x( 'sign-up fee', 'initial payment on a subscription', 'ulixes-subscriptions' ),
			'display_period'        => true,
			'display_excluding_tax_label' => false,
			'synced_date'           => 0,
		)
	);

	$period   = $d['subscription_period'];
	$interval = (int) $d['subscription_interval'];
	$amount   = $d['recurring_amount'];
	$string   = $amount;

	if ( $d['display_period'] && $period ) {
		$synced = $d['synced_date'];
		if ( $synced && 'week' === $period && 1 === $interval ) {
			global $wp_locale;
			/* translators: 1: price, 2: weekday */
			$string = sprintf( __( '%1$s every %2$s', 'ulixes-subscriptions' ), $amount, $wp_locale->get_weekday( (int) $synced % 7 ) );
		} elseif ( $synced && 'week' === $period ) {
			global $wp_locale;
			/* translators: 1: price, 2: weekday, 3: interval */
			$string = sprintf( __( '%1$s every %2$s every %3$s weeks', 'ulixes-subscriptions' ), $amount, $wp_locale->get_weekday( (int) $synced % 7 ), $interval );
		} elseif ( $synced && 'month' === $period ) {
			$day = (int) $synced;
			$day_string = $day > 27 ? __( 'last day', 'ulixes-subscriptions' ) : ulx_append_numeral_suffix( $day );
			if ( 1 === $interval ) {
				/* translators: 1: price, 2: day of month (e.g. 23rd) */
				$string = sprintf( __( '%1$s on the %2$s of each month', 'ulixes-subscriptions' ), $amount, $day_string );
			} else {
				/* translators: 1: price, 2: day, 3: interval ordinal */
				$string = sprintf( __( '%1$s on the %2$s day of every %3$s month', 'ulixes-subscriptions' ), $amount, $day_string, ulx_append_numeral_suffix( $interval ) );
			}
		} elseif ( $synced && 'year' === $period && is_array( $synced ) && ! empty( $synced['month'] ) ) {
			global $wp_locale;
			$month = $wp_locale->get_month( (int) $synced['month'] );
			if ( 1 === $interval ) {
				/* translators: 1: price, 2: month, 3: day */
				$string = sprintf( __( '%1$s on %2$s %3$s each year', 'ulixes-subscriptions' ), $amount, $month, ulx_append_numeral_suffix( (int) $synced['day'] ) );
			} else {
				/* translators: 1: price, 2: month, 3: day, 4: interval ordinal */
				$string = sprintf( __( '%1$s on %2$s %3$s every %4$s year', 'ulixes-subscriptions' ), $amount, $month, ulx_append_numeral_suffix( (int) $synced['day'] ), ulx_append_numeral_suffix( $interval ) );
			}
		} elseif ( 1 === $interval ) {
			/* translators: 1: price, 2: period */
			$string = sprintf( _x( '%1$s / %2$s', 'subscription price e.g. $10 / month', 'ulixes-subscriptions' ), $amount, ulx_get_subscription_period_strings( 1, $period ) );
		} else {
			/* translators: 1: price, 2: period with interval e.g. 2 months */
			$string = sprintf( _x( '%1$s every %2$s', 'subscription price e.g. $10 every 2 months', 'ulixes-subscriptions' ), $amount, ulx_get_subscription_period_strings( $interval, $period ) );
		}
	}

	$length = (int) $d['subscription_length'];
	if ( $length > 0 && $length !== $interval ) {
		/* translators: 1: price string, 2: length e.g. 12 months */
		$string = sprintf( __( '%1$s for %2$s', 'ulixes-subscriptions' ), $string, ulx_get_subscription_period_strings( $length, $period ) );
	} elseif ( $length > 0 && $length === $interval && $d['display_period'] ) {
		// Single payment subscription e.g. "$10 for 1 month".
		/* translators: 1: price, 2: length */
		$string = sprintf( __( '%1$s for %2$s', 'ulixes-subscriptions' ), $amount, ulx_get_subscription_period_strings( $length, $period ) );
	}

	if ( $d['trial_length'] > 0 ) {
		$trial_period = $d['trial_period'] ? $d['trial_period'] : $period;
		/* translators: 1: price string, 2: trial length e.g. "a 7-day" */
		$string = sprintf( __( '%1$s with %2$s free trial', 'ulixes-subscriptions' ), $string, ulx_get_subscription_trial_period_strings( $d['trial_length'], $trial_period ) );
	}

	if ( ! empty( $d['initial_amount'] ) ) {
		if ( $d['trial_length'] > 0 ) {
			/* translators: 1: price string, 2: fee, 3: fee description */
			$string = sprintf( __( '%1$s and a %2$s %3$s', 'ulixes-subscriptions' ), $string, $d['initial_amount'], $d['initial_description'] );
		} else {
			/* translators: 1: price string, 2: fee, 3: fee description */
			$string = sprintf( __( '%1$s and a %2$s %3$s', 'ulixes-subscriptions' ), $string, $d['initial_amount'], $d['initial_description'] );
		}
	}

	return apply_filters( 'ulx_formatted_price_string', '<span class="subscription-details">' . $string . '</span>', $d );
}

