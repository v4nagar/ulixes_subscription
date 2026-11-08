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
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_process_overdue_subscriptions' ), 30 );
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
		if ( $timestamp <= 0 ) {
			return;
		}
		$sched_ts = max( time() + 1, $timestamp );

		if ( 'payment_retry' === $date_type && $subscription->has_status( 'on-hold' ) ) {
			self::schedule_action( $sched_ts, $hook, $args );
		} elseif ( $subscription->has_status( 'active' ) && in_array( $date_type, array( 'trial_end', 'next_payment', 'end' ), true ) ) {
			self::schedule_action( $sched_ts, $hook, $args );
		} elseif ( $subscription->has_status( 'pending-cancel' ) && 'end' === $date_type ) {
			self::schedule_action( $sched_ts, 'ulx_scheduled_subscription_end_of_prepaid_term', $args );
		} elseif ( $subscription->has_status( 'on-hold' ) && 'end' === $date_type ) {
			self::schedule_action( $sched_ts, $hook, $args );
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

	/**
	 * Guard against recursive overdue checks.
	 *
	 * @var bool
	 */
	protected static $running_overdue_check = false;

	/**
	 * Automatically process any subscriptions whose scheduled `trial_end`, `next_payment`, `payment_retry`, or `end`
	 * date has passed (ensures instant status transition to `on-hold` / `expired` / `cancelled` + renewal order generation
	 * even when WP-Cron loopback is delayed by "Coming Soon" mode or shared hosting).
	 */
	public static function maybe_process_overdue_subscriptions() {
		if ( self::$running_overdue_check || wp_doing_ajax() ) {
			return;
		}
		// Avoid running while saving subscription schedule meta in admin so we don't clobber the form submission mid-save.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['_ulx_schedule_nonce'] ) ) {
			return;
		}

		$is_sub_screen = is_admin() || is_account_page();
		if ( ! $is_sub_screen && get_transient( 'ulx_overdue_check_lock' ) ) {
			return;
		}
		set_transient( 'ulx_overdue_check_lock', 1, 30 );
		self::$running_overdue_check = true;

		try {
			if ( 'done' !== get_option( 'ulx_sale_switch_repair_v1' ) ) {
				self::repair_sale_pricing_and_switched_renewals();
				update_option( 'ulx_sale_switch_repair_v1', 'done' );
			}
			$now  = time();
			$subs = ulx_get_subscriptions(
				array(
					'subscription_status' => array( 'active', 'on-hold', 'pending-cancel' ),
					'subscriptions_per_page' => 50,
				)
			);

			foreach ( $subs as $sub ) {
				if ( ! ( $sub instanceof ULX_Subscription ) ) {
					continue;
				}
				self::process_overdue_for_subscription( $sub, $now );
			}
		} catch ( Exception $e ) {
			ulx_log( 'Overdue subscription check error: ' . $e->getMessage(), 'error' );
		}

		self::$running_overdue_check = false;
	}

	/**
	 * Process overdue lifecycle dates (`end`, `trial_end`, `next_payment`) for a single subscription.
	 *
	 * @param ULX_Subscription $sub Subscription.
	 * @param int|null         $now Current GMT timestamp.
	 */
	public static function process_overdue_for_subscription( $sub, $now = null ) {
		if ( ! ( $sub instanceof ULX_Subscription ) ) {
			return;
		}
		if ( null === $now ) {
			$now = time();
		}
		$sub_id = $sub->get_id();

		// 1. Check End Date first (Active / On-Hold -> Expired; Pending-Cancel -> Cancelled).
		$end_ts = $sub->get_time( 'end', 'gmt' );
		if ( $end_ts > 0 && $end_ts <= $now ) {
			if ( $sub->has_status( 'pending-cancel' ) ) {
				self::unschedule_actions( 'ulx_scheduled_subscription_end_of_prepaid_term', array( 'subscription_id' => $sub_id ) );
				ULX_Manager::end_of_prepaid_term( $sub_id );
			} elseif ( $sub->has_status( array( 'active', 'on-hold' ) ) ) {
				self::unschedule_actions( 'ulx_scheduled_subscription_expiration', array( 'subscription_id' => $sub_id ) );
				ULX_Manager::expire_subscription( $sub_id );
			}
			return;
		}

		// 2. Check Trial End on Active subscriptions.
		if ( $sub->has_status( 'active' ) ) {
			$trial_ts = $sub->get_time( 'trial_end', 'gmt' );
			if ( $trial_ts > 0 && $trial_ts <= $now && ! $sub->get_meta( '_ulx_trial_end_processed', true ) ) {
				$sub->update_meta_data( '_ulx_trial_end_processed', 'yes' );
				$sub->save_meta_data();
				self::unschedule_actions( 'ulx_scheduled_subscription_trial_end', array( 'subscription_id' => $sub_id ) );
				ULX_Manager::trigger_trial_end( $sub_id );
			}
		}

		// 3. Check Next Payment Date on Active subscriptions -> Put On-Hold, generate Renewal Order, advance Next Payment, and charge gateway if automatic.
		if ( $sub->has_status( 'active' ) ) {
			$next_ts = $sub->get_time( 'next_payment', 'gmt' );
			if ( $next_ts > 0 && $next_ts <= $now ) {
				self::unschedule_actions( 'ulx_scheduled_subscription_payment', array( 'subscription_id' => $sub_id ) );
				ULX_Manager::prepare_renewal( $sub_id );
				ULX_Manager::maybe_process_gateway_renewal( $sub_id );
			}
		}

		// 4. Ensure any subscription that is on-hold or pending whose latest order (renewal or parent) is in processing/completed status gets activated.
		if ( $sub->has_status( array( 'on-hold', 'pending' ) ) && ! $sub->needs_payment() ) {
			$last_paid = $sub->get_last_order( 'all', array( 'renewal', 'parent', 'switch', 'resubscribe' ) );
			if ( $last_paid && $last_paid->has_status( array( 'processing', 'completed' ) ) && $sub->can_be_updated_to( 'active' ) ) {
				$sub->update_status(
					'active',
					sprintf(
						/* translators: %s: order number */
						__( 'Subscription activated: order #%s is paid.', 'ulixes-subscriptions' ),
						$last_paid->get_order_number()
					)
				);
			}
		}
	}

	/**
	 * One-time repair for subscription variations with active Sale prices and any switched subscriptions
	 * or pending renewal orders that recorded `_regular_price` or stale `_subscription_price` instead of `_sale_price`.
	 */
	protected static function repair_sale_pricing_and_switched_renewals() {
		$subs = ulx_get_subscriptions(
			array(
				'subscription_status'    => array( 'active', 'on-hold', 'pending-cancel' ),
				'subscriptions_per_page' => 100,
			)
		);
		foreach ( $subs as $sub ) {
			if ( ! ( $sub instanceof ULX_Subscription ) ) {
				continue;
			}
			$sub_updated = false;
			foreach ( $sub->get_items( 'line_item' ) as $item ) {
				if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
					continue;
				}
				$target_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$product   = wc_get_product( $target_id );
				if ( ! $product ) {
					continue;
				}
				$reg_price  = $product->get_regular_price( 'edit' );
				$sale_price = $product->get_sale_price( 'edit' );
				if ( '' !== $sale_price && '' !== $reg_price && (float) $sale_price < (float) $reg_price && $product->is_on_sale( 'edit' ) ) {
					update_post_meta( $product->get_id(), '_subscription_price', $reg_price );
					update_post_meta( $product->get_id(), '_price', $sale_price );
					wc_delete_product_transients( $product->get_id() );

					$expected_unit  = (float) wc_get_price_excluding_tax( $product, array( 'price' => (float) $sale_price ) );
					$expected_total = $expected_unit * max( 1, $item->get_quantity() );
					$current_total  = (float) $item->get_total();

					if ( abs( $current_total - $expected_total ) > 0.01 ) {
						$item->set_subtotal( $expected_total );
						$item->set_total( $expected_total );
						$item->save();
						$sub_updated = true;
					}
				}
			}

			if ( $sub_updated ) {
				$sub->calculate_totals();
				$sub->save();

				foreach ( $sub->get_related_orders( 'all', 'renewal' ) as $renewal_order ) {
					if ( ! $renewal_order || ! $renewal_order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
						continue;
					}
					$ro_updated = false;
					foreach ( $renewal_order->get_items( 'line_item' ) as $ro_item ) {
						if ( ! ( $ro_item instanceof WC_Order_Item_Product ) ) {
							continue;
						}
						$ro_target = $ro_item->get_variation_id() ? $ro_item->get_variation_id() : $ro_item->get_product_id();
						$ro_prod   = wc_get_product( $ro_target );
						if ( ! $ro_prod ) {
							continue;
						}
						$ro_sale = $ro_prod->get_sale_price( 'edit' );
						$ro_reg  = $ro_prod->get_regular_price( 'edit' );
						if ( '' !== $ro_sale && '' !== $ro_reg && (float) $ro_sale < (float) $ro_reg && $ro_prod->is_on_sale( 'edit' ) ) {
							$ro_expected = (float) wc_get_price_excluding_tax( $ro_prod, array( 'price' => (float) $ro_sale ) ) * max( 1, $ro_item->get_quantity() );
							if ( abs( (float) $ro_item->get_total() - $ro_expected ) > 0.01 ) {
								$ro_item->set_subtotal( $ro_expected );
								$ro_item->set_total( $ro_expected );
								$ro_item->save();
								$ro_updated = true;
							}
						}
					}
					if ( $ro_updated ) {
						$renewal_order->calculate_totals();
						$renewal_order->save();
					}
				}
			}
		}
	}
}

