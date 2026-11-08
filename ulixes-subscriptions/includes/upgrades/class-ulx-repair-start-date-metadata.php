<?php
/**
 * Background data repair routine for missing `_schedule_start` metadata on subscriptions.
 *
 * Runs asynchronously in batches of 50 via Action Scheduler and is 100% HPOS compatible.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Upgrades
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class Ulx_Repair_Start_Date_Metadata
 */
class Ulx_Repair_Start_Date_Metadata {

	/**
	 * Action Scheduler hook name.
	 */
	const HOOK = 'ulx_repair_subscription_start_dates_batch';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'ulx-upgrades';

	/**
	 * Batch size.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process_batch' ), 10, 1 );
	}

	/**
	 * Dispatch the background repair routine if not already running.
	 */
	public static function dispatch() {
		if ( get_option( 'ulx_repair_start_dates_in_progress' ) ) {
			return;
		}

		update_option( 'ulx_repair_start_dates_in_progress', time() );
		Ulx_Upgrade_Logger::info( 'Dispatched background repair for subscription start date metadata.' );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 5, self::HOOK, array( 'page' => 1 ), self::GROUP );
		} else {
			// Fallback immediate execution for non-Action Scheduler test environments.
			self::process_batch( 1 );
		}
	}

	/**
	 * Process a batch of subscriptions to recalculate and fix missing `_schedule_start` dates.
	 *
	 * @param int $page Batch page index.
	 */
	public static function process_batch( $page = 1 ) {
		$page = max( 1, absint( $page ) );

		Ulx_Upgrade_Logger::info( sprintf( 'Processing start date repair batch #%d (limit: %d)...', $page, self::BATCH_SIZE ) );

		// HPOS & CPT unified querying via wc_get_orders.
		$subscriptions = wc_get_orders(
			array(
				'type'    => 'shop_subscription',
				'status'  => 'any',
				'limit'   => self::BATCH_SIZE,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		if ( empty( $subscriptions ) ) {
			delete_option( 'ulx_repair_start_dates_in_progress' );
			Ulx_Upgrade_Logger::success( sprintf( 'Start date metadata repair complete. Finished at batch #%d.', $page ) );
			return;
		}

		$repaired_count = 0;

		foreach ( $subscriptions as $subscription ) {
			if ( ! ( $subscription instanceof WC_Order ) ) {
				continue;
			}

			// Read via HPOS-compatible CRUD.
			$existing_start = $subscription->get_meta( '_schedule_start', true );

			if ( empty( $existing_start ) || '0000-00-00 00:00:00' === $existing_start ) {
				$start_timestamp = 0;

				// 1. Check subscription's date_created.
				$date_created = $subscription->get_date_created();
				if ( $date_created instanceof WC_DateTime ) {
					$start_timestamp = $date_created->getTimestamp();
				}

				// 2. Check parent order creation date.
				if ( ! $start_timestamp && method_exists( $subscription, 'get_parent' ) ) {
					$parent = $subscription->get_parent();
					if ( $parent instanceof WC_Order && $parent->get_date_created() instanceof WC_DateTime ) {
						$start_timestamp = $parent->get_date_created()->getTimestamp();
					}
				}

				// 3. Fallback to current time.
				if ( ! $start_timestamp ) {
					$start_timestamp = time();
				}

				$start_date_gmt = gmdate( 'Y-m-d H:i:s', $start_timestamp );

				// Update via HPOS CRUD.
				$subscription->update_meta_data( '_schedule_start', $start_date_gmt );

				if ( $subscription instanceof ULX_Subscription ) {
					$subscription->update_dates( array( 'start' => $start_date_gmt ) );
				}

				$subscription->save();
				$repaired_count++;

				Ulx_Upgrade_Logger::info(
					sprintf( 'Repaired missing _schedule_start for Subscription #%d -> %s', $subscription->get_id(), $start_date_gmt )
				);
			}
		}

		Ulx_Upgrade_Logger::info(
			sprintf( 'Batch #%d completed. Processed: %d, Repaired: %d.', $page, count( $subscriptions ), $repaired_count )
		);

		// If a full batch was returned, schedule the next batch via Action Scheduler.
		if ( count( $subscriptions ) >= self::BATCH_SIZE ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5, self::HOOK, array( 'page' => $page + 1 ), self::GROUP );
			}
		} else {
			delete_option( 'ulx_repair_start_dates_in_progress' );
			Ulx_Upgrade_Logger::success( 'Start date metadata repair routine successfully completed all records.' );
		}
	}
}

