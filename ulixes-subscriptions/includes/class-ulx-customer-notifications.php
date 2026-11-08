<?php
/**
 * Pre-Renewal, Pre-Trial-End and Pre-Expiration Customer Notification Scheduler.
 *
 * When enabled in WooCommerce -> Settings -> Subscriptions ("Customer Notifications"), schedules
 * reminder emails N days before trial end, renewal payment, or subscription expiration.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Customer_Notifications
 */
class ULX_Customer_Notifications {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'ulx_subscription_date_updated', array( __CLASS__, 'maybe_schedule_notification' ), 20, 3 );
		add_action( 'ulx_subscription_status_updated', array( __CLASS__, 'maybe_reschedule_on_status' ), 20, 3 );
		add_action( 'ulx_scheduled_customer_notification', array( __CLASS__, 'send_notification' ), 10, 2 );
	}

	/**
	 * Are customer pre-event notifications enabled?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return ulx_option_is_yes( 'customer_notifications_enabled' );
	}

	/**
	 * Offset in seconds before the event.
	 *
	 * @return int
	 */
	public static function get_offset_seconds() {
		$days = max( 1, (int) ulx_get_option( 'customer_notifications_offset', 3 ) );
		return $days * DAY_IN_SECONDS;
	}

	/**
	 * Schedule a pre-event notification when a subscription date is updated.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $date_type    Date type.
	 * @param string           $datetime     MySQL GMT datetime.
	 */
	public static function maybe_schedule_notification( $subscription, $date_type, $datetime ) {
		if ( ! self::is_enabled() || ! in_array( $date_type, array( 'trial_end', 'next_payment', 'end' ), true ) ) {
			return;
		}
		$args = array(
			'subscription_id' => $subscription->get_id(),
			'event_type'      => $date_type,
		);
		ULX_Scheduler::unschedule_actions( 'ulx_scheduled_customer_notification', $args );

		if ( ! $subscription->has_status( array( 'active', 'pending-cancel' ) ) ) {
			return;
		}
		$event_ts  = ulx_date_to_time( $datetime );
		$notify_ts = $event_ts - self::get_offset_seconds();
		if ( $notify_ts > time() ) {
			ULX_Scheduler::schedule_action( $notify_ts, 'ulx_scheduled_customer_notification', $args );
		}
	}

	/**
	 * Reschedule or unschedule notifications on status change.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $new_status   New status.
	 * @param string           $old_status   Old status.
	 */
	public static function maybe_reschedule_on_status( $subscription, $new_status, $old_status ) {
		foreach ( array( 'trial_end', 'next_payment', 'end' ) as $type ) {
			$args = array(
				'subscription_id' => $subscription->get_id(),
				'event_type'      => $type,
			);
			ULX_Scheduler::unschedule_actions( 'ulx_scheduled_customer_notification', $args );
			if ( self::is_enabled() && in_array( $new_status, array( 'active', 'pending-cancel' ), true ) ) {
				$date = $subscription->get_date( $type );
				if ( $date ) {
					self::maybe_schedule_notification( $subscription, $type, $date );
				}
			}
		}
	}

	/**
	 * Trigger the customer notification email.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $event_type      trial_end|next_payment|end.
	 */
	public static function send_notification( $subscription_id, $event_type ) {
		$subscription = ulx_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}
		WC()->mailer();
		do_action( 'ulx_send_customer_notification_' . $event_type, $subscription );
	}
}

