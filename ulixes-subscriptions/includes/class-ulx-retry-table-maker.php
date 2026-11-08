<?php
/**
 * Payment Retry Table Maker.
 *
 * Creates and maintains the custom database table for tracking automatic payment retries,
 * matching WooCommerce Subscriptions' WCS_Retry_Table_Maker architecture.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Retry_Table_Maker
 */
class ULX_Retry_Table_Maker {

	/**
	 * Table name without prefix.
	 */
	const TABLE_NAME = 'ulx_payment_retries';

	/**
	 * DB schema version.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Init hooks.
	 */
	public static function init() {
		if ( get_option( 'ulx_retry_table_db_version' ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * Get full table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or update the retries database table using dbDelta.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			retry_id bigint(20) NOT NULL AUTO_INCREMENT,
			order_id bigint(20) NOT NULL,
			subscription_id bigint(20) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			rule_raw longtext NULL,
			PRIMARY KEY  (retry_id),
			KEY order_id (order_id),
			KEY subscription_id (subscription_id),
			KEY status (status),
			KEY date_gmt (date_gmt)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'ulx_retry_table_db_version', self::DB_VERSION );
	}

	/**
	 * Record a retry attempt into the custom table.
	 *
	 * @param int          $order_id        Renewal order ID.
	 * @param int          $subscription_id Subscription ID.
	 * @param string       $status          Retry status (e.g. 'pending', 'processing', 'completed', 'failed', 'cancelled').
	 * @param string|int   $date_gmt        Scheduled date/time GMT.
	 * @param array|string $rule_data       Rule details.
	 * @return int|false Inserted retry_id or false on failure.
	 */
	public static function record_retry( $order_id, $subscription_id = 0, $status = 'pending', $date_gmt = '', $rule_data = array() ) {
		global $wpdb;

		$table_name = self::get_table_name();
		if ( is_numeric( $date_gmt ) ) {
			$date_gmt = gmdate( 'Y-m-d H:i:s', $date_gmt );
		} elseif ( empty( $date_gmt ) ) {
			$date_gmt = current_time( 'mysql', true );
		}

		$rule_raw = is_array( $rule_data ) ? wp_json_encode( $rule_data ) : (string) $rule_data;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table_name,
			array(
				'order_id'        => (int) $order_id,
				'subscription_id' => (int) $subscription_id,
				'status'          => sanitize_key( $status ),
				'date_gmt'        => $date_gmt,
				'rule_raw'        => $rule_raw,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Update the status of a retry attempt.
	 *
	 * @param int    $retry_id Retry ID.
	 * @param string $status   New status.
	 * @return bool
	 */
	public static function update_status( $retry_id, $status ) {
		global $wpdb;
		$table_name = self::get_table_name();
		$updated    = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table_name,
			array( 'status' => sanitize_key( $status ) ),
			array( 'retry_id' => (int) $retry_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $updated;
	}

	/**
	 * Get all retry records for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public static function get_retries_for_order( $order_id ) {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY retry_id ASC", (int) $order_id ),
			ARRAY_A
		);
	}

	/**
	 * Get the most recent retry for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array|null
	 */
	public static function get_last_retry_for_order( $order_id ) {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY retry_id DESC LIMIT 1", (int) $order_id ),
			ARRAY_A
		);
	}
}

