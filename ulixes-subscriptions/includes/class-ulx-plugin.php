<?php
/**
 * Main plugin class.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Plugin
 */
final class ULX_Plugin {

	/**
	 * Singleton.
	 *
	 * @var ULX_Plugin
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return ULX_Plugin
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
		$dir = ULX_PLUGIN_DIR . 'includes/';

		// Core functions.
		require_once $dir . 'functions/ulx-core-functions.php';
		require_once $dir . 'functions/ulx-time-functions.php';
		require_once $dir . 'functions/ulx-product-functions.php';
		require_once $dir . 'functions/ulx-subscription-functions.php';
		require_once $dir . 'functions/ulx-order-functions.php';
		require_once $dir . 'functions/ulx-cart-functions.php';
		require_once $dir . 'functions/ulx-user-functions.php';
		require_once $dir . 'functions/ulx-renewal-functions.php';
		require_once $dir . 'functions/ulx-switch-functions.php';
		require_once $dir . 'functions/ulx-template-functions.php';

		// Data.
		require_once $dir . 'class-ulx-subscription.php';
		require_once $dir . 'data-stores/class-ulx-subscription-data-store-cpt.php';
		if ( ulx_is_hpos_enabled_or_available() ) {
			require_once $dir . 'data-stores/class-ulx-subscription-data-store-hpos.php';
		}
		require_once $dir . 'class-ulx-post-types.php';

		// Products.
		require_once $dir . 'products/class-ulx-product-subscription.php';
		require_once $dir . 'products/class-ulx-product-variable-subscription.php';
		require_once $dir . 'products/class-ulx-product-subscription-variation.php';
		require_once $dir . 'products/class-ulx-product-subscription-data-store.php';
		require_once $dir . 'class-ulx-product-hooks.php';

		// Engine.
		require_once $dir . 'class-ulx-cart-validator.php';
		require_once $dir . 'class-ulx-zero-initial-payment-checkout-manager.php';
		require_once $dir . 'class-ulx-cart.php';
		require_once $dir . 'class-ulx-cart-renewal.php';
		require_once $dir . 'class-ulx-cart-resubscribe.php';
		require_once $dir . 'class-ulx-cart-early-renewal.php';
		require_once $dir . 'class-ulx-early-renewal-manager.php';
		require_once $dir . 'class-ulx-checkout.php';
		require_once $dir . 'class-ulx-scheduler.php';
		require_once $dir . 'class-ulx-manager.php';
		require_once $dir . 'class-ulx-renewal-order.php';
		require_once $dir . 'class-ulx-payment-gateways.php';
		require_once $dir . 'class-ulx-gateway-tokenization.php';
		require_once $dir . 'class-ulx-change-payment-method.php';
		require_once $dir . 'class-ulx-retry-table-maker.php';
		require_once $dir . 'class-ulx-retry-rules.php';
		require_once $dir . 'class-ulx-retry-manager.php';
		require_once $dir . 'class-ulx-user-change-status.php';
		require_once $dir . 'class-ulx-switch-totals-calculator.php';
		require_once $dir . 'class-ulx-switcher.php';
		require_once $dir . 'class-ulx-synchronizer.php';
		require_once $dir . 'class-ulx-sync.php';
		require_once $dir . 'class-ulx-limiter.php';
		require_once $dir . 'class-ulx-coupons.php';
		require_once $dir . 'class-ulx-my-account.php';
		require_once $dir . 'class-ulx-auto-renew-toggle.php';
		require_once $dir . 'class-ulx-download-handler.php';
		require_once $dir . 'class-ulx-user-roles.php';
		require_once $dir . 'class-ulx-emails.php';
		require_once $dir . 'class-ulx-customer-notifications.php';
		require_once $dir . 'class-ulx-order-hooks.php';
		require_once $dir . 'class-ulx-privacy.php';
		require_once $dir . 'class-ulx-shortcodes.php';
		require_once $dir . 'api/class-ulx-rest-subscriptions-controller.php';
		require_once $dir . 'blocks/class-ulx-blocks-integration.php';

		// Caching Layer.
		require_once $dir . 'cache/class-ulx-post-meta-cache-manager.php';
		require_once $dir . 'cache/class-ulx-object-data-cache-manager-many-to-one.php';

		// Upgrades & Migration Engine.
		require_once $dir . 'upgrades/class-ulx-upgrade-logger.php';
		require_once $dir . 'upgrades/class-ulx-repair-start-date-metadata.php';
		require_once $dir . 'upgrades/class-ulx-upgrader.php';

		// Deprecation Handlers.
		require_once $dir . 'deprecated/class-ulx-hook-deprecator.php';
		require_once $dir . 'deprecated/ulx-deprecated-functions.php';

		if ( is_admin() ) {
			require_once $dir . 'admin/class-ulx-admin.php';
			require_once $dir . 'admin/class-ulx-admin-product.php';
			require_once $dir . 'admin/class-ulx-admin-settings.php';
			require_once $dir . 'admin/class-ulx-admin-list-table.php';
			require_once $dir . 'admin/class-ulx-admin-meta-boxes.php';
			require_once $dir . 'admin/class-ulx-admin-reports.php';
			require_once $dir . 'admin/debug-tools/class-ulx-debug-tool-factory.php';
		}
	}

	/**
	 * Hooks.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
		add_filter( 'plugin_action_links_' . ULX_PLUGIN_BASENAME, array( $this, 'action_links' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );

		ULX_Post_Types::init();
		ULX_Product_Hooks::init();
		ULX_Cart_Validator::init();
		ULX_Zero_Initial_Payment_Checkout_Manager::init();
		ULX_Cart::init();
		ULX_Cart_Renewal::init();
		ULX_Cart_Resubscribe::init();
		ULX_Cart_Early_Renewal::init();
		ULX_Early_Renewal_Manager::init();
		ULX_Checkout::init();
		ULX_Scheduler::init();
		ULX_Manager::init();
		ULX_Renewal_Order::init();
		ULX_Payment_Gateways::init();
		ULX_Gateway_Tokenization::init();
		ULX_Change_Payment_Method::init();
		ULX_Retry_Table_Maker::init();
		ULX_Retry_Manager::init();
		ULX_User_Change_Status::init();
		ULX_Switcher::init();
		ULX_Synchronizer::init();
		ULX_Sync::init();
		ULX_Limiter::init();
		ULX_Coupons::init();
		ULX_My_Account::init();
		ULX_Auto_Renew_Toggle::init();
		ULX_Download_Handler::init();
		ULX_User_Roles::init();
		ULX_Emails::init();
		ULX_Customer_Notifications::init();
		ULX_Order_Hooks::init();
		ULX_Privacy::init();
		ULX_Shortcodes::init();
		ULX_REST_Subscriptions_Controller::init();
		ULX_Blocks_Integration::init();
		Ulx_Post_Meta_Cache_Manager::init();
		Ulx_Object_Data_Cache_Manager_Many_To_One::init();
		Ulx_Upgrader::init();
		Ulx_Hook_Deprecator::init();

		if ( is_admin() ) {
			ULX_Admin::init();
			ULX_Admin_Product::init();
			ULX_Admin_Settings::init();
			ULX_Admin_List_Table::init();
			ULX_Admin_Meta_Boxes::init();
			ULX_Admin_Reports::init();
			Ulx_Debug_Tool_Factory::init();
		}
	}

	/**
	 * Flush rewrite rules after activation (for My Account endpoints).
	 */
	public function maybe_flush() {
		if ( 'yes' === get_option( 'ulx_needs_flush' ) ) {
			flush_rewrite_rules();
			update_option( 'ulx_needs_flush', 'no' );
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
		wp_enqueue_style( 'ulx-frontend', ULX_PLUGIN_URL . 'assets/css/frontend.css', array(), ULX_VERSION );
		wp_enqueue_script( 'ulx-frontend', ULX_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), ULX_VERSION, true );
		wp_localize_script(
			'ulx-frontend',
			'ulx_params',
			array(
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'auto_renew_nonce'    => wp_create_nonce( 'ulx_toggle_auto_renew' ),
				'confirm_cancel'      => __( 'Are you sure you want to cancel this subscription?', 'ulixes-subscriptions' ),
				'confirm_suspend'     => __( 'Are you sure you want to suspend this subscription?', 'ulixes-subscriptions' ),
				'auto_renew_error'    => __( 'We could not update the auto-renewal setting. Please try again.', 'ulixes-subscriptions' ),
			)
		);
	}
}

