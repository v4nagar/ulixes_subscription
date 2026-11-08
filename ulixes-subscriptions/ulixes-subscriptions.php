<?php
/**
 * Plugin Name:       Ulixes Subscriptions
 * Plugin URI:        https://ulixes.in/
 * Description:       Sell products and services with recurring payments in WooCommerce. Subscription products, free trials, sign-up fees, renewals, retries, switching, synchronisation, emails, reports and REST API.
 * Version:           1.0.0
 * Author:            Ulixes
 * Text Domain:       ulixes-subscriptions
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   10.0
 * License:           GPL-3.0-or-later
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Declare compatibility with all WooCommerce features (HPOS, Cart/Checkout Blocks, Product Block Editor, etc.).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		$features = array(
			'custom_order_tables',
			'custom_orders_table',
			'cart_checkout_blocks',
			'product_block_editor',
			'order_attribution',
			'remote_logging',
			'email_improvements',
			'blueprint',
			'cost_of_goods_sold',
			'product_instance_caching',
			'analytics',
			'new_navigation',
			'reactify-classic-payments-settings',
			'coming_soon_newsletter_template',
		);
		if ( method_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, 'get_features' ) ) {
			$registered = array_keys( (array) \Automattic\WooCommerce\Utilities\FeaturesUtil::get_features( true, false ) );
			$features   = array_unique( array_merge( $features, $registered ) );
		}
		foreach ( $features as $feature_id ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $feature_id, __FILE__, true );
		}
	}
);

if ( ! defined( 'ULX_VERSION' ) ) {
	define( 'ULX_VERSION', '1.0.0' );
}
if ( ! defined( 'ULX_PLUGIN_FILE' ) ) {
	define( 'ULX_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'ULX_PLUGIN_DIR' ) ) {
	define( 'ULX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'ULX_PLUGIN_URL' ) ) {
	define( 'ULX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'ULX_PLUGIN_BASENAME' ) ) {
	define( 'ULX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! function_exists( 'ulx_missing_wc_notice' ) ) {
	/**
	 * Admin notice when WooCommerce is missing.
	 */
	function ulx_missing_wc_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Ulixes Subscriptions requires WooCommerce to be installed and active.', 'ulixes-subscriptions' ) . '</p></div>';
	}
}

if ( ! function_exists( 'ulx_init_plugin' ) ) {
	/**
	 * Boot the plugin once all plugins are loaded.
	 */
	function ulx_init_plugin() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'ulx_missing_wc_notice' );
			return;
		}
		if ( '1.0.4' !== get_option( 'ulx_db_migration_version' ) ) {
			update_option( 'ulx_accept_manual_renewals', 'yes' );
			update_option( 'ulx_allow_switching_variable', 'yes' );
			update_option( 'ulx_allow_switching_grouped', 'yes' );
			update_option( 'ulx_enable_early_renewal', 'yes' );
			update_option( 'ulx_enable_auto_renewal_toggle', 'yes' );
			delete_option( 'woocommerce_ulx_test_gateway_settings' );
			update_option( 'ulx_db_migration_version', '1.0.4' );
		}
		require_once ULX_PLUGIN_DIR . 'includes/class-ulx-plugin.php';
		ULX_Plugin::instance();
	}
}
add_action( 'plugins_loaded', 'ulx_init_plugin', 5 );

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_url  = esc_url( admin_url( 'admin.php?page=wc-settings&tab=subscriptions' ) );
		$settings_link = '<a href="' . $settings_url . '">' . esc_html__( 'Settings', 'ulixes-subscriptions' ) . '</a>';
		if ( ! in_array( $settings_link, $links, true ) ) {
			array_unshift( $links, $settings_link );
		}
		return $links;
	}
);

register_activation_hook( __FILE__, 'ulx_activate' );
register_deactivation_hook( __FILE__, 'ulx_deactivate' );

if ( ! function_exists( 'ulx_activate' ) ) {
	/**
	 * Activation.
	 */
	function ulx_activate() {
		update_option( 'ulx_needs_flush', 'yes' );
		if ( false === get_option( 'ulx_installed_version' ) ) {
			add_option( 'ulx_installed_version', ULX_VERSION );
			add_option( 'ulx_install_date', time() );
		}
		$defaults = array(
			'ulx_add_to_cart_button_text'               => __( 'Sign up now', 'ulixes-subscriptions' ),
			'ulx_order_button_text'                     => __( 'Sign up now', 'ulixes-subscriptions' ),
			'ulx_accept_manual_renewals'                => 'yes',
			'ulx_turn_off_automatic_payments'           => 'no',
			'ulx_enable_retry'                          => 'no',
			'ulx_allow_switching_simple'                => 'yes',
			'ulx_allow_switching_variable'              => 'yes',
			'ulx_allow_switching_grouped'               => 'yes',
			'ulx_apportion_recurring_price'             => 'no',
			'ulx_apportion_sign_up_fee'                 => 'no',
			'ulx_apportion_length'                      => 'no',
			'ulx_switch_button_text'                    => __( 'Upgrade or Downgrade', 'ulixes-subscriptions' ),
			'ulx_sync_payments'                         => 'no',
			'ulx_prorate_synced_payments'               => 'no',
			'ulx_days_no_fee'                           => 0,
			'ulx_enable_early_renewal'                  => 'yes',
			'ulx_enable_early_renewal_via_modal'        => 'no',
			'ulx_allow_customer_suspension'             => 0,
			'ulx_multiple_purchase'                     => 'no',
			'ulx_drip_downloadable_content'             => 'no',
			'ulx_enable_auto_renewal_toggle'            => 'yes',
			'ulx_zero_initial_payment_requires_payment' => 'no',
			'ulx_cancelled_role'                        => 'customer',
			'ulx_subscriber_role'                       => 'subscriber',
			'ulx_allow_switching_downgrade_fee'         => 'no',
			'ulx_gifting'                               => 'no',
			'ulx_renewal_order_number_prefix'           => '',
			'ulx_customer_notifications_offset'         => 3,
			'ulx_customer_notifications_enabled'        => 'no',
		);
		foreach ( $defaults as $key => $value ) {
			add_option( $key, $value );
		}
	}
}

if ( ! function_exists( 'ulx_deactivate' ) ) {
	/**
	 * Deactivation.
	 */
	function ulx_deactivate() {
		wp_clear_scheduled_hook( 'ulx_daily_maintenance' );
		update_option( 'ulx_needs_flush', 'yes' );
	}
}
