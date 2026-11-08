<?php
/**
 * Action Scheduler integration for subscription lifecycle events.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Scheduler
 */
class ULX_Scheduler {

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'ulx_subscription_scheduled_event';

	/**
	 * Map of date types to Action Scheduler hooks.
	 *
	 * @var array
	 */
	protected static $date_hooks = array(
		'trial_end'     => 'ulx_scheduled_subscription_trial_end',
		'next_payment'  => 'ulx_scheduled_subscription_payment',
		'payment_retry' => 'ulx_scheduled_subscription_payment_retry',
		'end'           => 'ulx_scheduled_subscription_expiration',
	);

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'ulx_subscription_date_updated', array( __CLASS__, 'update_date' ), 10, 3 );
		add_action( 'ulx_subscription_date_deleted', array( __CLASS__, 'delete_date' ), 10, 2 );
		add_action( 'ulx_subscription_status_updated', array( __CLASS__, 'update_status' ), 10, 3 );
	}

	/**
	 * Get the Action Scheduler hook for a date type and subscription status.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $date_type    Date type.
	 * @return string
	 */
	public static function get_scheduled_action_hook( $subscription, $date_type ) {
		if ( 'end' === $date_type && $subscription->has_status( 'pending-cancel' ) ) {
			return 'ulx_scheduled_subscription_end_of_prepaid_term';
		}
		return isset( self::$date_hooks[ $date_type ] ) ? self::$date_hooks[ $date_type ] : '';
	}

	/**
	 * Action arguments.
	 *
	 * @param string           $date_type    Date type.
	 * @param ULX_Subscription $subscription Subscription.
	 * @return array
	 */
	public static function get_action_args( $date_type, $subscription ) {
		$args = array( 'subscription_id' => $subscription->get_id() );
		if ( 'payment_retry' === $date_type ) {
			$last_order_id = $subscription->get_last_order( 'ids', 'renewal' );
			$args          = array( 'order_id' => (int) $last_order_id );
		}
		return apply_filters( 'ulx_scheduled_action_args', $args, $date_type, $subscription );
	}

	/**
	 * React to a subscription date update.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $date_type    Date type.
	 * @param string           $datetime     MySQL GMT datetime.
	 */
	public static function update_date( $subscription, $date_type, $datetime ) {
		if ( ! isset( self::$date_hooks[ $date_type ] ) ) {
			return;
		}
		$hook = self::get_scheduled_action_hook( $subscription, $date_type );
		$args = self::get_action_args( $date_type, $subscription );

		self::unschedule_actions( $hook, $args );
		if ( 'end' === $date_type ) {
			self::unschedule_actions( 'ulx_scheduled_subscription_expiration', $args );
			self::unschedule_actions( 'ulx_scheduled_subscription_end_of_prepaid_term', $args );
		}

		$timestamp = ulx_date_to_time( $datetime );
		if ( $timestamp <= time() ) {
			return;
		}

		if ( 'payment_retry' === $date_type && $subscription->has_status( 'on-hold' ) ) {
			self::schedule_action( $timestamp, $hook, $args );
		} elseif ( $subscription->has_status( 'active' ) && in_array( $date_type, array( 'trial_end', 'next_payment', 'end' ), true ) ) {
			self::schedule_action( $timestamp, $hook, $args );
		} elseif ( $subscription->has_status( 'pending-cancel' ) && 'end' === $date_type ) {
			self::schedule_action( $timestamp, 'ulx_scheduled_subscription_end_of_prepaid_term', $args );
		} elseif ( $subscription->has_status( 'on-hold' ) && 'end' === $date_type ) {
			self::schedule_action( $timestamp, $hook, $args );
		}
	}

	/**
	 * React to a subscription date deletion.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $date_type    Date type.
	 */
	public static function delete_date( $subscription, $date_type ) {
		if ( ! isset( self::$date_hooks[ $date_type ] ) ) {
			return;
		}
		$args = self::get_action_args( $date_type, $subscription );
		$hook = self::get_scheduled_action_hook( $subscription, $date_type );
		self::unschedule_actions( $hook, $args );
		if ( 'end' === $date_type ) {
			self::unschedule_actions( 'ulx_scheduled_subscription_expiration', $args );
			self::unschedule_actions( 'ulx_scheduled_subscription_end_of_prepaid_term', $args );
		}
	}

	/**
	 * React to a subscription status change.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param string           $new_status   New status.
	 * @param string           $old_status   Old status.
	 */
	public static function update_status( $subscription, $new_status, $old_status ) {
		switch ( $new_status ) {
			case 'active':
				self::unschedule_all_for_subscription( $subscription );
				foreach ( array( 'trial_end', 'next_payment', 'end' ) as $type ) {
					$ts = $subscription->get_time( $type );
					if ( $ts > time() ) {
						$hook = self::get_scheduled_action_hook( $subscription, $type );
						self::schedule_action( $ts, $hook, self::get_action_args( $type, $subscription ) );
					}
				}
				break;

			case 'pending-cancel':
				self::unschedule_all_for_subscription( $subscription );
				$end = $subscription->get_time( 'end' );
				if ( $end > time() ) {
					self::schedule_action( $end, 'ulx_scheduled_subscription_end_of_prepaid_term', self::get_action_args( 'end', $subscription ) );
				}
				break;

			case 'on-hold':
				self::unschedule_all_for_subscription( $subscription );
				$retry = $subscription->get_time( 'payment_retry' );
				if ( $retry > time() ) {
					self::schedule_action( $retry, 'ulx_scheduled_subscription_payment_retry', self::get_action_args( 'payment_retry', $subscription ) );
				}
				$end = $subscription->get_time( 'end' );
				if ( $end > time() ) {
					self::schedule_action( $end, 'ulx_scheduled_subscription_expiration', self::get_action_args( 'end', $subscription ) );
				}
				break;

			case 'cancelled':
			case 'switched':
			case 'expired':
			case 'trash':
				self::unschedule_all_for_subscription( $subscription );
				break;
		}
	}

	/**
	 * Unschedule all lifecycle actions for a subscription.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public static function unschedule_all_for_subscription( $subscription ) {
		foreach ( self::$date_hooks as $type => $hook ) {
			self::unschedule_actions( $hook, self::get_action_args( $type, $subscription ) );
		}
		self::unschedule_actions( 'ulx_scheduled_subscription_end_of_prepaid_term', array( 'subscription_id' => $subscription->get_id() ) );
	}

	/**
	 * Schedule a single action via Action Scheduler (or WP-Cron fallback).
	 *
	 * @param int    $timestamp GMT timestamp.
	 * @param string $hook      Hook name.
	 * @param array  $args      Args.
	 */
	public static function schedule_action( $timestamp, $hook, $args ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
		} else {
			wp_schedule_single_event( $timestamp, $hook, array_values( $args ) );
		}
	}

	/**
	 * Unschedule actions via Action Scheduler (or WP-Cron fallback).
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Args.
	 */
	public static function unschedule_actions( $hook, $args ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, self::GROUP );
		} else {
			wp_clear_scheduled_hook( $hook, array_values( $args ) );
		}
	}
}

