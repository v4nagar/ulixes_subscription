<?php
/**
 * Subscription data store for High-Performance Order Storage.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;

/**
 * ULX_Subscription_Data_Store_HPOS
 */
class ULX_Subscription_Data_Store_HPOS extends OrdersTableDataStore {

	/**
	 * Constructor: auto-initialize dependencies from the WooCommerce DI container.
	 */
	public function __construct() {
		if ( method_exists( $this, 'init' ) && function_exists( 'wc_get_container' ) ) {
			try {
				$container = wc_get_container();
				$this->init(
					$container->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta::class ),
					$container->get( \Automattic\WooCommerce\Internal\Utilities\DatabaseUtil::class ),
					$container->get( \Automattic\WooCommerce\Proxies\LegacyProxy::class )
				);
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Container not ready yet.
			}
		}
	}

	/**
	 * Create without firing 'woocommerce_new_order'.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public function create( &$subscription ) {
		if ( '' === $subscription->get_order_key() ) {
			$subscription->set_order_key( wc_generate_order_key() );
		}
		$this->persist_save( $subscription );
		do_action( 'ulx_new_subscription', $subscription->get_id(), $subscription );
	}

	/**
	 * Data store used to sync posts table when compatibility mode is on.
	 *
	 * @return ULX_Subscription_Data_Store_CPT
	 */
	protected function get_post_data_store_for_backfill() {
		return new ULX_Subscription_Data_Store_CPT();
	}

	/**
	 * Count subscriptions by status.
	 *
	 * @return array
	 */
	public function get_subscriptions_count_by_status() {
		global $wpdb;
		$table  = self::get_orders_table_name();
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table} WHERE type = 'shop_subscription' GROUP BY status", ARRAY_A ); // phpcs:ignore
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['cnt'];
		}
		return $counts;
	}
}
