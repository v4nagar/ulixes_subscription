<?php
/**
 * Failed Recurring Payment Retry Rules.
 *
 * Implements WooCommerce Subscriptions' 5-step exponential backoff retry rules:
 * - Rule 0: +12 hours (silent admin notification)
 * - Rule 1: +12 hours (24h after initial failure, customer notification)
 * - Rule 2: +24 hours (48h after initial failure)
 * - Rule 3: +48 hours (4 days after initial failure, customer notification)
 * - Rule 4: +72 hours (7 days after initial failure, customer notification)
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Retry_Rules
 */
class ULX_Retry_Rules {

	/**
	 * Get all configured retry rules.
	 *
	 * @return array
	 */
	public static function get_rules() {
		$rules = array(
			array(
				'retry_after_interval'            => 12 * HOUR_IN_SECONDS,
				'email_template_customer'         => '',
				'email_template_admin'            => 'ULX_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
			array(
				'retry_after_interval'            => 12 * HOUR_IN_SECONDS,
				'email_template_customer'         => 'ULX_Email_Customer_Payment_Retry',
				'email_template_admin'            => 'ULX_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
			array(
				'retry_after_interval'            => 24 * HOUR_IN_SECONDS,
				'email_template_customer'         => '',
				'email_template_admin'            => 'ULX_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
			array(
				'retry_after_interval'            => 48 * HOUR_IN_SECONDS,
				'email_template_customer'         => 'ULX_Email_Customer_Payment_Retry',
				'email_template_admin'            => 'ULX_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
			array(
				'retry_after_interval'            => 72 * HOUR_IN_SECONDS,
				'email_template_customer'         => 'ULX_Email_Customer_Payment_Retry',
				'email_template_admin'            => 'ULX_Email_Payment_Retry',
				'status_to_apply_to_order'        => 'pending',
				'status_to_apply_to_subscription' => 'on-hold',
			),
		);

		$rules = apply_filters( 'wcs_default_retry_rules', $rules );
		return apply_filters( 'ulx_default_retry_rules', $rules );
	}

	/**
	 * Does a rule exist for this retry index?
	 *
	 * @param int $retry_index 0-based retry count.
	 * @return bool
	 */
	public static function has_rule( $retry_index ) {
		$rules = self::get_rules();
		return isset( $rules[ $retry_index ] );
	}

	/**
	 * Get a specific rule by retry index.
	 *
	 * @param int $retry_index 0-based retry count.
	 * @return array|false
	 */
	public static function get_rule( $retry_index ) {
		$rules = self::get_rules();
		return isset( $rules[ $retry_index ] ) ? $rules[ $retry_index ] : false;
	}

	/**
	 * Calculate timestamp for next retry.
	 *
	 * @param int $retry_index 0-based retry count.
	 * @param int $from_time   Base timestamp (defaults to current time).
	 * @return int
	 */
	public static function get_retry_time( $retry_index, $from_time = 0 ) {
		$rule = self::get_rule( $retry_index );
		if ( ! $rule ) {
			return 0;
		}
		$base = $from_time > 0 ? $from_time : time();
		return $base + (int) $rule['retry_after_interval'];
	}
}

