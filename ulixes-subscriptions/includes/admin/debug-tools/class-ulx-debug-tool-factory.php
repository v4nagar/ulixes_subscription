<?php
/**
 * Admin Debug Tools Factory: registers repair and cache management scripts in WooCommerce > Status > Tools.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Admin
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class Ulx_Debug_Tool_Factory
 */
class Ulx_Debug_Tool_Factory {

	/**
	 * Action Scheduler hook for cache warming.
	 */
	const WARMUP_HOOK = 'ulx_generate_subscription_cache_batch';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'ulx-debug-tools';

	/**
	 * Batch size.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_debug_tools', array( __CLASS__, 'register_debug_tools' ), 20 );
		add_action( self::WARMUP_HOOK, array( __CLASS__, 'process_warmup_batch' ), 10, 1 );
	}

	/**
	 * Register Ulixes Subscriptions debug tools into WooCommerce > Status > Tools.
	 *
	 * @param array $tools Existing debug tools.
	 * @return array
	 */
	public static function register_debug_tools( $tools ) {
		$tools['ulx_clear_cache'] = array(
			'name'     => __( 'Clear Subscription Cache', 'ulixes-subscriptions' ),
			'button'   => __( 'Clear cache', 'ulixes-subscriptions' ),
			'desc'     => __( 'Instantly deletes all transient caches and many-to-one relationship records managed by the Ulixes Subscriptions Caching Layer.', 'ulixes-subscriptions' ),
			'callback' => array( __CLASS__, 'tool_clear_subscription_cache' ),
		);

		$tools['ulx_generate_cache'] = array(
			'name'     => __( 'Generate Subscription Cache', 'ulixes-subscriptions' ),
			'button'   => __( 'Generate cache', 'ulixes-subscriptions' ),
			'desc'     => __( 'Schedules a background Action Scheduler worker to pre-populate summary and renewal order relationship caches for all active subscriptions.', 'ulixes-subscriptions' ),
			'callback' => array( __CLASS__, 'tool_generate_subscription_cache' ),
		);

		$tools['ulx_repair_action_scheduler'] = array(
			'name'     => __( 'Repair Subscription Action Scheduler', 'ulixes-subscriptions' ),
			'button'   => __( 'Repair scheduled hooks', 'ulixes-subscriptions' ),
			'desc'     => __( 'Scans all active subscriptions for missing `ulx_scheduled_subscription_payment` hooks and regenerates future Action Scheduler events based on their next payment date.', 'ulixes-subscriptions' ),
			'callback' => array( __CLASS__, 'tool_repair_action_scheduler' ),
		);

		$tools['ulx_reconcile_switched'] = array(
			'name'     => __( 'Reconcile Switched Subscriptions', 'ulixes-subscriptions' ),
			'button'   => __( 'Reconcile switched subscriptions', 'ulixes-subscriptions' ),
			'desc'     => __( 'Scans for customers who upgraded or changed plans with multiple active subscriptions. Transitions superseded older subscriptions to "Switched" and cancels redundant Action Scheduler renewal actions.', 'ulixes-subscriptions' ),
			'callback' => array( __CLASS__, 'tool_reconcile_switched_subscriptions' ),
		);

		return $tools;
	}

	/**
	 * Tool 1: Clear Subscription Cache.
	 *
	 * @return string Admin notice message.
	 */
	public static function tool_clear_subscription_cache() {
		global $wpdb;

		// 1. Clear Many-to-One caches.
		$rel_count = Ulx_Object_Data_Cache_Manager_Many_To_One::clear_all_caches();

		// 2. Clear subscription and user transient caches.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sub_count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				'_transient_ulx_sub_%',
				'_transient_timeout_ulx_sub_%',
				'_transient_ulx_user_subs_%',
				'_transient_timeout_ulx_user_subs_%'
			)
		);

		// 3. Flush object caches.
		wp_cache_flush();

		$total = (int) $rel_count + (int) $sub_count;

		return sprintf(
			/* translators: %d: number of transient records removed */
			__( 'Subscription cache cleared successfully! (%d cache records removed).', 'ulixes-subscriptions' ),
			$total
		);
	}

	/**
	 * Tool 2: Generate Subscription Cache (Warms up active subscriptions).
	 *
	 * @return string Admin notice message.
	 */
	public static function tool_generate_subscription_cache() {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Unschedule any old pending warmup before starting fresh.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::WARMUP_HOOK, array(), self::GROUP );
			}
			as_schedule_single_action( time() + 2, self::WARMUP_HOOK, array( 'page' => 1 ), self::GROUP );
			return __( 'Subscription cache warmup job scheduled! Active subscriptions are being cached in the background via Action Scheduler.', 'ulixes-subscriptions' );
		}

		// Fallback for immediate processing.
		self::process_warmup_batch( 1 );
		return __( 'Active subscription caches generated immediately (Action Scheduler not detected).', 'ulixes-subscriptions' );
	}

	/**
	 * Action Scheduler worker: warm up active subscriptions in batches of 50.
	 *
	 * @param int $page Batch page.
	 */
	public static function process_warmup_batch( $page = 1 ) {
		$page = max( 1, absint( $page ) );

		$subscriptions = wc_get_orders(
			array(
				'type'    => 'shop_subscription',
				'status'  => 'active',
				'limit'   => self::BATCH_SIZE,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		if ( empty( $subscriptions ) ) {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! ( $subscription instanceof WC_Order ) ) {
				continue;
			}
			$sub_id = $subscription->get_id();

			// Pre-warm summary cache.
			Ulx_Post_Meta_Cache_Manager::get_cached_subscription_summary( $sub_id );

			// Pre-warm renewal, switch, and resubscribe relationship caches.
			foreach ( array( 'renewal', 'switch', 'resubscribe' ) as $rel ) {
				Ulx_Object_Data_Cache_Manager_Many_To_One::get_cached_related_order_ids( $sub_id, $rel );
			}
		}

		if ( count( $subscriptions ) >= self::BATCH_SIZE && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 5, self::WARMUP_HOOK, array( 'page' => $page + 1 ), self::GROUP );
		}
	}

	/**
	 * Tool 3: Repair Action Scheduler (Scans for missing next payment hooks on active subscriptions).
	 *
	 * @return string Admin notice message.
	 */
	public static function tool_repair_action_scheduler() {
		$subscriptions = wc_get_orders(
			array(
				'type'   => 'shop_subscription',
				'status' => 'active',
				'limit'  => -1,
				'return' => 'objects',
			)
		);

		if ( empty( $subscriptions ) ) {
			return __( 'No active subscriptions found to check.', 'ulixes-subscriptions' );
		}

		$repaired = 0;
		$checked  = 0;

		foreach ( $subscriptions as $subscription ) {
			if ( ! ( $subscription instanceof WC_Order ) ) {
				continue;
			}
			$checked++;
			$sub_id = $subscription->get_id();

			// Read next payment date via HPOS CRUD.
			$next_payment_gmt = $subscription->get_meta( '_schedule_next_payment', true );
			if ( ! $next_payment_gmt && method_exists( $subscription, 'get_date' ) ) {
				$next_payment_gmt = $subscription->get_date( 'next_payment' );
			}

			if ( empty( $next_payment_gmt ) || '0000-00-00 00:00:00' === $next_payment_gmt ) {
				continue;
			}

			$timestamp = is_numeric( $next_payment_gmt ) ? (int) $next_payment_gmt : strtotime( $next_payment_gmt . ' UTC' );

			if ( $timestamp <= time() ) {
				// Due or overdue: schedule for immediate execution (+2 seconds).
				$timestamp = time() + 2;
			}

			$args = array( 'subscription_id' => $sub_id );

			// Check if hook is already scheduled in Action Scheduler.
			$is_scheduled = false;
			if ( function_exists( 'as_has_scheduled_action' ) ) {
				$is_scheduled = as_has_scheduled_action( 'ulx_scheduled_subscription_payment', $args, 'ulx_subscription_scheduled_event' )
					|| as_has_scheduled_action( 'woocommerce_scheduled_subscription_payment', $args );
			} elseif ( function_exists( 'as_next_scheduled_action' ) ) {
				$is_scheduled = (bool) as_next_scheduled_action( 'ulx_scheduled_subscription_payment', $args );
			}

			if ( ! $is_scheduled ) {
				if ( class_exists( 'ULX_Scheduler' ) ) {
					ULX_Scheduler::schedule_action( $timestamp, 'ulx_scheduled_subscription_payment', $args );
				} elseif ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( $timestamp, 'ulx_scheduled_subscription_payment', $args, 'ulx_subscription_scheduled_event' );
				}
				$repaired++;
			}
		}

		return sprintf(
			/* translators: 1: number of repaired hooks, 2: total subscriptions scanned */
			__( 'Action Scheduler repair completed: %1$d missing renewal payment hooks regenerated out of %2$d active subscriptions scanned.', 'ulixes-subscriptions' ),
			$repaired,
			$checked
		);
	}

	/**
	 * Tool 4: Reconcile Switched Subscriptions.
	 *
	 * @return string Admin notice message.
	 */
	public static function tool_reconcile_switched_subscriptions() {
		$subscriptions = ulx_get_subscriptions(
			array(
				'subscription_status'    => array( 'active', 'on-hold' ),
				'subscriptions_per_page' => 200,
			)
		);

		$user_subs = array();
		foreach ( $subscriptions as $sub ) {
			if ( ! ( $sub instanceof ULX_Subscription ) ) {
				continue;
			}
			$uid = (int) $sub->get_user_id();
			if ( $uid > 0 ) {
				$user_subs[ $uid ][] = $sub;
			}
		}

		$reconciled = 0;
		foreach ( $user_subs as $uid => $subs ) {
			if ( count( $subs ) < 2 ) {
				continue;
			}
			usort(
				$subs,
				function( $a, $b ) {
					return ( (int) $b->get_id() ) <=> ( (int) $a->get_id() );
				}
			);

			$newest = $subs[0];
			for ( $i = 1; $i < count( $subs ); $i++ ) {
				$older = $subs[ $i ];
				if ( $older->can_be_updated_to( 'switched' ) ) {
					$older->update_meta_data( '_ulx_switched_to_subscription_id', $newest->get_id() );
					$older->save_meta_data();
					$newest->update_meta_data( '_ulx_switched_from_subscription_id', $older->get_id() );
					$newest->save_meta_data();

					if ( class_exists( 'ULX_Scheduler' ) ) {
						ULX_Scheduler::unschedule_all_for_subscription( $older );
					}

					try {
						$older->update_status(
							'switched',
							sprintf(
								/* translators: %s: newer subscription id */
								__( 'Subscription reconciled via WooCommerce Status Tool: switched to new subscription #%s.', 'ulixes-subscriptions' ),
								$newest->get_order_number()
							)
						);
						$reconciled++;
					} catch ( Exception $e ) {
						ulx_log( 'Reconciliation tool error on subscription #' . $older->get_id() . ': ' . $e->getMessage(), 'error' );
					}
				}
			}
		}

		return sprintf(
			/* translators: %d: number of reconciled subscriptions */
			__( 'Reconciliation complete: %d superseded subscriptions transitioned to "Switched".', 'ulixes-subscriptions' ),
			$reconciled
		);
	}
}

