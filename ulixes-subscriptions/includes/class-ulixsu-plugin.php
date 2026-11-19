<?php
/**
 * Main plugin class.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULIXSU_Plugin
 */
final class ULIXSU_Plugin {

	/**
	 * Singleton.
	 *
	 * @var ULIXSU_Plugin
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return ULIXSU_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include files.
	 */
	private function includes() {
		$dir = ULIXSU_PLUGIN_DIR . 'includes/';

		// Core functions.
		require_once $dir . 'functions/ulixsu-core-functions.php';
		require_once $dir . 'functions/ulixsu-time-functions.php';
		require_once $dir . 'functions/ulixsu-product-functions.php';
		require_once $dir . 'functions/ulixsu-subscription-functions.php';
		require_once $dir . 'functions/ulixsu-order-functions.php';
		require_once $dir . 'functions/ulixsu-cart-functions.php';
		require_once $dir . 'functions/ulixsu-user-functions.php';
		require_once $dir . 'functions/ulixsu-renewal-functions.php';
		require_once $dir . 'functions/ulixsu-switch-functions.php';
		require_once $dir . 'functions/ulixsu-template-functions.php';

		// Data.
		require_once $dir . 'class-ulixsu-subscription.php';
		require_once $dir . 'data-stores/class-ulixsu-subscription-data-store-cpt.php';
		if ( ulixsu_is_hpos_enabled_or_available() ) {
			require_once $dir . 'data-stores/class-ulixsu-subscription-data-store-hpos.php';
		}
		require_once $dir . 'class-ulixsu-post-types.php';

		// Products.
		require_once $dir . 'products/class-ulixsu-product-subscription.php';
		require_once $dir . 'products/class-ulixsu-product-variable-subscription.php';
		require_once $dir . 'products/class-ulixsu-product-subscription-variation.php';
		require_once $dir . 'products/class-ulixsu-product-subscription-data-store.php';
		require_once $dir . 'class-ulixsu-product-hooks.php';

		// Engine.
		require_once $dir . 'class-ulixsu-cart-validator.php';
		require_once $dir . 'class-ulixsu-zero-initial-payment-checkout-manager.php';
		require_once $dir . 'class-ulixsu-cart.php';
		require_once $dir . 'class-ulixsu-cart-renewal.php';
		require_once $dir . 'class-ulixsu-cart-resubscribe.php';
		require_once $dir . 'class-ulixsu-cart-early-renewal.php';
		require_once $dir . 'class-ulixsu-early-renewal-manager.php';
		require_once $dir . 'class-ulixsu-checkout.php';
		require_once $dir . 'class-ulixsu-scheduler.php';
		require_once $dir . 'class-ulixsu-manager.php';
		require_once $dir . 'class-ulixsu-renewal-order.php';
		require_once $dir . 'class-ulixsu-payment-gateways.php';
		require_once $dir . 'class-ulixsu-gateway-tokenization.php';
		require_once $dir . 'class-ulixsu-change-payment-method.php';
		require_once $dir . 'class-ulixsu-retry-table-maker.php';
		require_once $dir . 'class-ulixsu-retry-rules.php';
		require_once $dir . 'class-ulixsu-retry-manager.php';
		require_once $dir . 'class-ulixsu-user-change-status.php';
		require_once $dir . 'class-ulixsu-switch-totals-calculator.php';
		require_once $dir . 'class-ulixsu-switcher.php';
		require_once $dir . 'class-ulixsu-synchronizer.php';
		require_once $dir . 'class-ulixsu-sync.php';
		require_once $dir . 'class-ulixsu-limiter.php';
		require_once $dir . 'class-ulixsu-coupons.php';
		require_once $dir . 'class-ulixsu-my-account.php';
		require_once $dir . 'class-ulixsu-auto-renew-toggle.php';
		require_once $dir . 'class-ulixsu-download-handler.php';
		require_once $dir . 'class-ulixsu-user-roles.php';
		require_once $dir . 'class-ulixsu-emails.php';
		require_once $dir . 'class-ulixsu-customer-notifications.php';
		require_once $dir . 'class-ulixsu-order-hooks.php';
		require_once $dir . 'class-ulixsu-privacy.php';
		require_once $dir . 'class-ulixsu-shortcodes.php';
		require_once $dir . 'api/class-ulixsu-rest-subscriptions-controller.php';
		require_once $dir . 'blocks/class-ulixsu-blocks-integration.php';

		// Caching Layer.
		require_once $dir . 'cache/class-ulixsu-post-meta-cache-manager.php';
		require_once $dir . 'cache/class-ulixsu-object-data-cache-manager-many-to-one.php';

		// Upgrades & Migration Engine.
		require_once $dir . 'upgrades/class-ulixsu-upgrade-logger.php';
		require_once $dir . 'upgrades/class-ulixsu-repair-start-date-metadata.php';
		require_once $dir . 'upgrades/class-ulixsu-upgrader.php';

		// Deprecation Handlers.
		require_once $dir . 'deprecated/class-ulixsu-hook-deprecator.php';
		require_once $dir . 'deprecated/ulixsu-deprecated-functions.php';

		if ( is_admin() ) {
			require_once $dir . 'admin/class-ulixsu-admin.php';
			require_once $dir . 'admin/class-ulixsu-admin-product.php';
			require_once $dir . 'admin/class-ulixsu-admin-settings.php';
			require_once $dir . 'admin/class-ulixsu-admin-list-table.php';
			require_once $dir . 'admin/class-ulixsu-admin-meta-boxes.php';
			require_once $dir . 'admin/class-ulixsu-admin-reports.php';
			require_once $dir . 'admin/debug-tools/class-ulixsu-debug-tool-factory.php';
		}
	}

	/**
	 * Hooks.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
		add_filter( 'plugin_action_links_' . ULIXSU_PLUGIN_BASENAME, array( $this, 'action_links' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );

		ULIXSU_Post_Types::init();
		ULIXSU_Product_Hooks::init();
		ULIXSU_Cart_Validator::init();
		ULIXSU_Zero_Initial_Payment_Checkout_Manager::init();
		ULIXSU_Cart::init();
		ULIXSU_Cart_Renewal::init();
		ULIXSU_Cart_Resubscribe::init();
		ULIXSU_Cart_Early_Renewal::init();
		ULIXSU_Early_Renewal_Manager::init();
		ULIXSU_Checkout::init();
		ULIXSU_Scheduler::init();
		ULIXSU_Manager::init();
		ULIXSU_Renewal_Order::init();
		ULIXSU_Payment_Gateways::init();
		ULIXSU_Gateway_Tokenization::init();
		ULIXSU_Change_Payment_Method::init();
		ULIXSU_Retry_Table_Maker::init();
		ULIXSU_Retry_Manager::init();
		ULIXSU_User_Change_Status::init();
		ULIXSU_Switcher::init();
		ULIXSU_Synchronizer::init();
		ULIXSU_Sync::init();
		ULIXSU_Limiter::init();
		ULIXSU_Coupons::init();
		ULIXSU_My_Account::init();
		ULIXSU_Auto_Renew_Toggle::init();
		ULIXSU_Download_Handler::init();
		ULIXSU_User_Roles::init();
		ULIXSU_Emails::init();
		ULIXSU_Customer_Notifications::init();
		ULIXSU_Order_Hooks::init();
		ULIXSU_Privacy::init();
		ULIXSU_Shortcodes::init();
		ULIXSU_REST_Subscriptions_Controller::init();
		ULIXSU_Blocks_Integration::init();
		Ulixsu_Post_Meta_Cache_Manager::init();
		Ulixsu_Object_Data_Cache_Manager_Many_To_One::init();
		Ulixsu_Upgrader::init();
		Ulixsu_Hook_Deprecator::init();

		if ( is_admin() ) {
			ULIXSU_Admin::init();
			ULIXSU_Admin_Product::init();
			ULIXSU_Admin_Settings::init();
			ULIXSU_Admin_List_Table::init();
			ULIXSU_Admin_Meta_Boxes::init();
			ULIXSU_Admin_Reports::init();
			Ulixsu_Debug_Tool_Factory::init();
		}
	}

	/**
	 * Flush rewrite rules after activation (for My Account endpoints).
	 */
	public function maybe_flush() {
		if ( 'yes' === get_option( 'ulixsu_needs_flush' ) ) {
			flush_rewrite_rules();
			update_option( 'ulixsu_needs_flush', 'no' );
		}
	}

	/**
	 * Plugin action links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=subscriptions' ) ) . '">' . esc_html__( 'Settings', 'ulixes-subscriptions' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Frontend assets.
	 */
	public function frontend_assets() {
		wp_enqueue_style( 'ulixsu-frontend', ULIXSU_PLUGIN_URL . 'assets/css/frontend.css', array(), ULIXSU_VERSION );
		wp_enqueue_script( 'ulixsu-frontend', ULIXSU_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), ULIXSU_VERSION, true );
		wp_localize_script(
			'ulixsu-frontend',
			'ulixsu_params',
			array(
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'auto_renew_nonce'    => wp_create_nonce( 'ulixsu_toggle_auto_renew' ),
				'confirm_cancel'      => __( 'Are you sure you want to cancel this subscription?', 'ulixes-subscriptions' ),
				'confirm_suspend'     => __( 'Are you sure you want to suspend this subscription?', 'ulixes-subscriptions' ),
				'auto_renew_error'    => __( 'We could not update the auto-renewal setting. Please try again.', 'ulixes-subscriptions' ),
			)
		);
	}
}

