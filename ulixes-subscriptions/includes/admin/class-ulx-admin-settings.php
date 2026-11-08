<?php
/**
 * WooCommerce -> Settings -> Subscriptions Tab.
 *
 * Includes all WooCommerce Subscriptions configuration sections:
 * - Button Text ("Add to Cart Button Text", "Place Order Button Text")
 * - Roles ("Subscriber Default Role", "Inactive Subscriber Role")
 * - Renewals ("Accept Manual Renewals", "Turn off Automatic Payments", "Display Auto Renewal Toggle", "Enable Early Renewal", "Enable Early Renewal via Modal")
 * - Switching ("Allow Switching", "Prorate Recurring Payment", "Prorate Sign-up Fee", "Prorate Subscription Length", "Switch Button Text")
 * - Synchronisation ("Synchronise Renewals", "Prorate First Renewal", "Days Before Sync Date for No Fee")
 * - Miscellaneous ("Customer Suspensions", "Mixed Checkout", "Zero Initial Checkout without Payment Method", "Drip Downloadable Content", "Retry Failed Payments", "Customer Pre-Event Notifications")
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Admin_Settings
 */
class ULX_Admin_Settings {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_subscriptions', array( __CLASS__, 'render_settings' ) );
		add_action( 'woocommerce_update_options_subscriptions', array( __CLASS__, 'save_settings' ) );
	}

	/**
	 * Add "Subscriptions" tab to WooCommerce Settings.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function add_settings_tab( $tabs ) {
		$tabs['subscriptions'] = __( 'Subscriptions', 'ulixes-subscriptions' );
		return $tabs;
	}

	/**
	 * Render settings fields.
	 */
	public static function render_settings() {
		woocommerce_admin_fields( self::get_settings() );
	}

	/**
	 * Save settings fields.
	 */
	public static function save_settings() {
		woocommerce_update_options( self::get_settings() );
	}

	/**
	 * Get all settings definitions.
	 *
	 * @return array
	 */
	public static function get_settings() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$roles = $wp_roles->get_names();

		$settings = array(
			array(
				'name' => __( 'Button Text', 'ulixes-subscriptions' ),
				'type' => 'title',
				'desc' => __( 'Customise the text displayed on buttons for subscription products.', 'ulixes-subscriptions' ),
				'id'   => 'ulx_button_text_options',
			),
			array(
				'name'    => __( 'Add to Cart Button Text', 'ulixes-subscriptions' ),
				'id'      => 'ulx_add_to_cart_button_text',
				'type'    => 'text',
				'default' => __( 'Sign up now', 'ulixes-subscriptions' ),
			),
			array(
				'name'    => __( 'Place Order Button Text', 'ulixes-subscriptions' ),
				'id'      => 'ulx_order_button_text',
				'type'    => 'text',
				'default' => __( 'Sign up now', 'ulixes-subscriptions' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ulx_button_text_options',
			),

			array(
				'name' => __( 'Roles', 'ulixes-subscriptions' ),
				'type' => 'title',
				'desc' => __( 'Choose the default roles assigned to active and inactive subscribers.', 'ulixes-subscriptions' ),
				'id'   => 'ulx_role_options',
			),
			array(
				'name'    => __( 'Subscriber Default Role', 'ulixes-subscriptions' ),
				'id'      => 'ulx_subscriber_role',
				'type'    => 'select',
				'options' => $roles,
				'default' => 'subscriber',
			),
			array(
				'name'    => __( 'Inactive Subscriber Role', 'ulixes-subscriptions' ),
				'id'      => 'ulx_cancelled_role',
				'type'    => 'select',
				'options' => $roles,
				'default' => 'customer',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ulx_role_options',
			),

			array(
				'name' => __( 'Renewals', 'ulixes-subscriptions' ),
				'type' => 'title',
				'id'   => 'ulx_renewal_options',
			),
			array(
				'name'          => __( 'Manual Renewals', 'ulixes-subscriptions' ),
				'desc'          => __( 'Accept Manual Renewals', 'ulixes-subscriptions' ),
				'id'            => 'ulx_accept_manual_renewals',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'start',
			),
			array(
				'desc'          => __( 'Turn off Automatic Payments (all new subscriptions will use manual renewals)', 'ulixes-subscriptions' ),
				'id'            => 'ulx_turn_off_automatic_payments',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'end',
			),
			array(
				'name'    => __( 'Auto Renewal Toggle', 'ulixes-subscriptions' ),
				'desc'    => __( 'Display the auto renewal toggle so customers can turn automatic renewals on or off from My Account', 'ulixes-subscriptions' ),
				'id'      => 'ulx_enable_auto_renewal_toggle',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'name'          => __( 'Early Renewal', 'ulixes-subscriptions' ),
				'desc'          => __( 'Accept Early Renewal Payments', 'ulixes-subscriptions' ),
				'id'            => 'ulx_enable_early_renewal',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'start',
			),
			array(
				'desc'          => __( 'Accept Early Renewal Payments via a Modal', 'ulixes-subscriptions' ),
				'id'            => 'ulx_enable_early_renewal_via_modal',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'end',
			),
			array(
				'name'    => __( 'Renewal Order Number Prefix', 'ulixes-subscriptions' ),
				'desc'    => __( 'Optional prefix added to renewal order numbers (e.g. "R-").', 'ulixes-subscriptions' ),
				'id'      => 'ulx_renewal_order_number_prefix',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ulx_renewal_options',
			),

			array(
				'name' => __( 'Switching (Upgrades & Downgrades)', 'ulixes-subscriptions' ),
				'type' => 'title',
				'id'   => 'ulx_switching_options',
			),
			array(
				'name'          => __( 'Allow Switching', 'ulixes-subscriptions' ),
				'desc'          => __( 'Between Subscription Variations', 'ulixes-subscriptions' ),
				'id'            => 'ulx_allow_switching_variable',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'start',
			),
			array(
				'desc'          => __( 'Between Grouped Subscriptions', 'ulixes-subscriptions' ),
				'id'            => 'ulx_allow_switching_grouped',
				'type'          => 'checkbox',
				'default'       => 'no',
				'checkboxgroup' => 'end',
			),
			array(
				'name'    => __( 'Prorate Recurring Payment', 'ulixes-subscriptions' ),
				'id'      => 'ulx_apportion_recurring_price',
				'type'    => 'select',
				'default' => 'no',
				'options' => array(
					'no'              => __( 'Never', 'ulixes-subscriptions' ),
					'virtual-upgrade' => __( 'For Upgrades of Virtual Subscription Products Only', 'ulixes-subscriptions' ),
					'yes-upgrade'     => __( 'For Upgrades of All Subscription Products', 'ulixes-subscriptions' ),
					'virtual'         => __( 'For Virtual Subscription Products Only', 'ulixes-subscriptions' ),
					'yes'             => __( 'For All Subscription Products', 'ulixes-subscriptions' ),
				),
			),
			array(
				'name'    => __( 'Prorate Sign-up Fee', 'ulixes-subscriptions' ),
				'id'      => 'ulx_apportion_sign_up_fee',
				'type'    => 'select',
				'default' => 'no',
				'options' => array(
					'no'   => __( 'Never (do not charge a sign-up fee)', 'ulixes-subscriptions' ),
					'full' => __( 'Never (charge the full sign-up fee)', 'ulixes-subscriptions' ),
					'yes'  => __( 'Always charge the difference between sign-up fees', 'ulixes-subscriptions' ),
				),
			),
			array(
				'name'    => __( 'Prorate Subscription Length', 'ulixes-subscriptions' ),
				'id'      => 'ulx_apportion_length',
				'type'    => 'select',
				'default' => 'no',
				'options' => array(
					'no'      => __( 'Never', 'ulixes-subscriptions' ),
					'virtual' => __( 'For Virtual Subscription Products Only', 'ulixes-subscriptions' ),
					'yes'     => __( 'For All Subscription Products', 'ulixes-subscriptions' ),
				),
			),
			array(
				'name'    => __( 'Switch Button Text', 'ulixes-subscriptions' ),
				'id'      => 'ulx_switch_button_text',
				'type'    => 'text',
				'default' => __( 'Upgrade or Downgrade', 'ulixes-subscriptions' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ulx_switching_options',
			),

			array(
				'name' => __( 'Synchronisation', 'ulixes-subscriptions' ),
				'type' => 'title',
				'id'   => 'ulx_sync_options',
			),
			array(
				'name'    => __( 'Synchronise Renewals', 'ulixes-subscriptions' ),
				'desc'    => __( 'Align Subscription Renewal Day', 'ulixes-subscriptions' ),
				'id'      => 'ulx_sync_payments',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'name'    => __( 'Prorate First Renewal', 'ulixes-subscriptions' ),
				'id'      => 'ulx_prorate_synced_payments',
				'type'    => 'select',
				'default' => 'no',
				'options' => array(
					'no'      => __( 'Never (do not charge any recurring amount at sign-up)', 'ulixes-subscriptions' ),
					'full'    => __( 'Never (charge the full recurring amount at sign-up)', 'ulixes-subscriptions' ),
					'virtual' => __( 'For Virtual Subscription Products Only', 'ulixes-subscriptions' ),
					'yes'     => __( 'For All Subscription Products', 'ulixes-subscriptions' ),
				),
			),
			array(
				'name'    => __( 'Sign-up Grace Period (Days)', 'ulixes-subscriptions' ),
				'desc'    => __( 'Number of days prior to the sync date during which prorated initial payment is waived.', 'ulixes-subscriptions' ),
				'id'      => 'ulx_days_no_fee',
				'type'    => 'number',
				'default' => 0,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ulx_sync_options',
			),

			array(
				'name' => __( 'Miscellaneous & Notifications', 'ulixes-subscriptions' ),
				'type' => 'title',
				'id'   => 'ulx_misc_options',
			),
			array(
				'name'    => __( 'Customer Suspensions', 'ulixes-subscriptions' ),
				'desc'    => __( 'suspensions per billing period (set to -1 for unlimited)', 'ulixes-subscriptions' ),
				'id'      => 'ulx_allow_customer_suspension',
				'type'    => 'number',
				'default' => 0,
			),
			array(
				'name'    => __( 'Mixed Checkout', 'ulixes-subscriptions' ),
				'desc'    => __( 'Allow multiple subscriptions and products to be purchased simultaneously in a single checkout', 'ulixes-subscriptions' ),
				'id'      => 'ulx_multiple_purchase',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'name'    => __( '$0 Initial Checkout', 'ulixes-subscriptions' ),
				'desc'    => __( 'Allow $0 initial checkout without a payment method (subscriptions will require manual renewal unless customer adds a payment method later)', 'ulixes-subscriptions' ),
				'id'      => 'ulx_zero_initial_payment_requires_payment',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'name'    => __( 'Drip Downloadable Content', 'ulixes-subscriptions' ),
				'desc'    => __( 'Enable dripping for downloadable content on subscription products (new files only unlock upon next renewal)', 'ulixes-subscriptions' ),
				'id'      => 'ulx_drip_downloadable_content',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'name'    => __( 'Retry Failed Payments', 'ulixes-subscriptions' ),
				'desc'    => __( 'Enable automatic retry of failed recurring payments', 'ulixes-subscriptions' ),
				'id'      => 'ulx_enable_retry',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'name'    => __( 'Customer Pre-Event Notifications', 'ulixes-subscriptions' ),
				'desc'    => __( 'Send automated reminder emails before upcoming renewals, trial endings, and expirations', 'ulixes-subscriptions' ),
				'id'      => 'ulx_customer_notifications_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'name'    => __( 'Notification Offset (Days)', 'ulixes-subscriptions' ),
				'desc'    => __( 'How many days before the event to send reminder emails.', 'ulixes-subscriptions' ),
				'id'      => 'ulx_customer_notifications_offset',
				'type'    => 'number',
				'default' => 3,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ulx_misc_options',
			),
		);

		return apply_filters( 'ulx_subscription_settings', $settings );
	}
}

