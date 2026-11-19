<?php
/**
 * Plugin Name:       Ulixes Subscriptions
 * Description:       Sell products and services with recurring payments in WooCommerce. Subscription products, free trials, sign-up fees, renewals, retries, switching, synchronisation, emails, reports and REST API.
 * Version:           1.0.0
 * Author:            Vishnu Kumar Nagar
 * Author URI:        https://ulixes.in/
 * Text Domain:       ulixes-subscriptions
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   10.0
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package Ulixes_Subscriptions
 * @author  Vishnu Kumar Nagar <info@ulixes.in>
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

if ( ! defined( 'ULIXSU_VERSION' ) ) {
	define( 'ULIXSU_VERSION', '1.0.0' );
}
if ( ! defined( 'ULIXSU_PLUGIN_FILE' ) ) {
	define( 'ULIXSU_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'ULIXSU_PLUGIN_DIR' ) ) {
	define( 'ULIXSU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'ULIXSU_PLUGIN_URL' ) ) {
	define( 'ULIXSU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'ULIXSU_PLUGIN_BASENAME' ) ) {
	define( 'ULIXSU_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

require_once ULIXSU_PLUGIN_DIR . 'includes/class-ulixsu-dependency-manager.php';

/**
 * Boot the plugin once all plugins are loaded.
 */
function ulixsu_init_plugin() {
	if ( ! ULIXSU_Dependency_Manager::are_dependencies_met() ) {
		ULIXSU_Dependency_Manager::init_notices();
		return;
	}
	if ( '1.0.5' !== get_option( 'ulixsu_db_migration_version' ) ) {
		update_option( 'ulixsu_accept_manual_renewals', 'yes' );
		update_option( 'ulixsu_allow_switching_variable', 'yes' );
		update_option( 'ulixsu_allow_switching_grouped', 'yes' );
		update_option( 'ulixsu_enable_early_renewal', 'yes' );
		update_option( 'ulixsu_enable_auto_renewal_toggle', 'yes' );
		delete_option( 'woocommerce_ulixsu_test_gateway_settings' );
		update_option( 'ulixsu_db_migration_version', '1.0.5' );
	}
	require_once ULIXSU_PLUGIN_DIR . 'includes/class-ulixsu-plugin.php';
	ULIXSU_Plugin::instance();
}
add_action( 'plugins_loaded', 'ulixsu_init_plugin', 5 );

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

register_activation_hook( __FILE__, 'ulixsu_activate' );
register_deactivation_hook( __FILE__, 'ulixsu_deactivate' );

/**
 * Activation.
 */
function ulixsu_activate() {
	update_option( 'ulixsu_needs_flush', 'yes' );
	require_once ULIXSU_PLUGIN_DIR . 'includes/class-ulixsu-retry-table-maker.php';
	ULIXSU_Retry_Table_Maker::create_table();
	if ( false === get_option( 'ulixsu_installed_version' ) ) {
		add_option( 'ulixsu_installed_version', ULIXSU_VERSION );
		add_option( 'ulixsu_install_date', time() );
	}
	$defaults = array(
		'ulixsu_add_to_cart_button_text'               => __( 'Sign up now', 'ulixes-subscriptions' ),
		'ulixsu_order_button_text'                     => __( 'Sign up now', 'ulixes-subscriptions' ),
		'ulixsu_accept_manual_renewals'                => 'yes',
		'ulixsu_turn_off_automatic_payments'           => 'no',
		'ulixsu_enable_retry'                          => 'no',
		'ulixsu_allow_switching_simple'                => 'yes',
		'ulixsu_allow_switching_variable'              => 'yes',
		'ulixsu_allow_switching_grouped'               => 'yes',
		'ulixsu_apportion_recurring_price'             => 'no',
		'ulixsu_apportion_sign_up_fee'                 => 'no',
		'ulixsu_apportion_length'                      => 'no',
		'ulixsu_switch_button_text'                    => __( 'Upgrade or Downgrade', 'ulixes-subscriptions' ),
		'ulixsu_sync_payments'                         => 'no',
		'ulixsu_prorate_synced_payments'               => 'no',
		'ulixsu_days_no_fee'                           => 0,
		'ulixsu_enable_early_renewal'                  => 'yes',
		'ulixsu_enable_early_renewal_via_modal'        => 'no',
		'ulixsu_allow_customer_suspension'             => 0,
		'ulixsu_multiple_purchase'                     => 'no',
		'ulixsu_drip_downloadable_content'             => 'no',
		'ulixsu_enable_auto_renewal_toggle'            => 'yes',
		'ulixsu_zero_initial_payment_requires_payment' => 'no',
		'ulixsu_cancelled_role'                        => 'customer',
		'ulixsu_subscriber_role'                       => 'subscriber',
		'ulixsu_allow_switching_downgrade_fee'         => 'no',
		'ulixsu_gifting'                               => 'no',
		'ulixsu_renewal_order_number_prefix'           => '',
		'ulixsu_customer_notifications_offset'         => 3,
		'ulixsu_customer_notifications_enabled'        => 'no',
	);
	foreach ( $defaults as $key => $value ) {
		add_option( $key, $value );
	}
}

/**
 * Deactivation.
 */
function ulixsu_deactivate() {
	wp_clear_scheduled_hook( 'ulixsu_daily_maintenance' );
	update_option( 'ulixsu_needs_flush', 'yes' );
}
