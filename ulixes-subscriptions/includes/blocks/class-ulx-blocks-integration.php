<?php
/**
 * WooCommerce Cart & Checkout Blocks (Store API) Integration.
 *
 * Extends the `wc/store/cart` schema with `ulixes_subscriptions` containing the recurring carts'
 * billing periods, intervals, next payment dates, and formatted recurring totals so Block Cart &
 * Block Checkout have full subscription visibility.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Blocks_Integration
 */
class ULX_Blocks_Integration {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_extension' ) );
	}

	/**
	 * Register Store API cart extension data.
	 */
	public static function register_store_api_extension() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'ulixes-subscriptions',
				'data_callback'   => array( __CLASS__, 'get_cart_extension_data' ),
				'schema_callback' => array( __CLASS__, 'get_cart_extension_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Return recurring cart details for Store API.
	 *
	 * @return array
	 */
	public static function get_cart_extension_data() {
		$data = array();
		if ( empty( WC()->cart ) || empty( WC()->cart->recurring_carts ) ) {
			return $data;
		}

		foreach ( WC()->cart->recurring_carts as $key => $rc ) {
			$data[] = array(
				'key'                => $key,
				'billing_period'     => getattr_or_prop( $rc, 'subscription_period', 'month' ),
				'billing_interval'   => (int) getattr_or_prop( $rc, 'subscription_period_interval', 1 ),
				'subscription_length' => (int) getattr_or_prop( $rc, 'subscription_length', 0 ),
				'trial_end_date'     => getattr_or_prop( $rc, 'trial_end_date', '' ),
				'next_payment_date'  => getattr_or_prop( $rc, 'next_payment_date', '' ),
				'end_date'           => getattr_or_prop( $rc, 'end_date', '' ),
				'total'              => $rc->get_total( 'edit' ),
				'formatted_total'    => wp_strip_all_tags( ulx_cart_price_string( wc_price( $rc->get_total( 'edit' ) ), $rc ) ),
			);
		}
		return $data;
	}

	/**
	 * Store API Schema for `ulixes-subscriptions`.
	 *
	 * @return array
	 */
	public static function get_cart_extension_schema() {
		return array(
			'description' => __( 'Recurring subscription carts.', 'ulixes-subscriptions' ),
			'type'        => 'array',
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
		);
	}
}

