<?php
/**
 * Subscription Emails Manager & Email Classes.
 *
 * Registers 14 specialized WooCommerce email classes covering all admin and customer subscription events:
 * 1.  `ULX_Email_New_Renewal_Order`            (Admin)
 * 2.  `ULX_Email_New_Switch_Order`             (Admin)
 * 3.  `ULX_Email_Processing_Renewal_Order`     (Customer)
 * 4.  `ULX_Email_Completed_Renewal_Order`      (Customer)
 * 5.  `ULX_Email_Completed_Switch_Order`       (Customer)
 * 6.  `ULX_Email_Customer_Renewal_Invoice`     (Customer - manual renewal due or failed renewal)
 * 7.  `ULX_Email_Cancelled_Subscription`       (Admin)
 * 8.  `ULX_Email_Expired_Subscription`         (Admin)
 * 9.  `ULX_Email_On_Hold_Subscription`         (Admin)
 * 10. `ULX_Email_Customer_Payment_Retry`       (Customer)
 * 11. `ULX_Email_Payment_Retry`                (Admin)
 * 12. `ULX_Email_Customer_Notification_Remind` (Customer - upcoming renewal)
 * 13. `ULX_Email_Customer_Notification_Trial`  (Customer - upcoming trial end)
 * 14. `ULX_Email_Customer_Notification_Expire` (Customer - upcoming expiration)
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Emails
 */
class ULX_Emails {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'add_emails' ), 10, 1 );
		add_action( 'woocommerce_init', array( __CLASS__, 'hook_transactional_emails' ) );
	}

	/**
	 * Register transactional hooks so WC()->mailer() is loaded before our events fire.
	 */
	public static function hook_transactional_emails() {
		$actions = array(
			'ulx_subscription_status_cancelled',
			'ulx_subscription_status_expired',
			'ulx_subscription_status_on-hold',
			'ulx_generated_manual_renewal_order',
			'ulx_subscription_renewal_payment_complete',
			'ulx_subscription_renewal_payment_failed',
			'ulx_after_apply_retry_rule',
			'ulx_subscriptions_switched_for_order',
			'ulx_send_customer_notification_next_payment',
			'ulx_send_customer_notification_trial_end',
			'ulx_send_customer_notification_end',
		);
		foreach ( $actions as $hook ) {
			add_action( $hook, array( __CLASS__, 'init_wc_mailer' ), 5 );
		}
	}

	/**
	 * Ensure WC_Emails is instantiated.
	 */
	public static function init_wc_mailer() {
		WC()->mailer();
	}

	/**
	 * Add our email classes to WooCommerce.
	 *
	 * @param array $emails Emails.
	 * @return array
	 */
	public static function add_emails( $emails ) {
		require_once ULX_PLUGIN_DIR . 'includes/emails/class-ulx-email-base.php';

		$emails_map = array(
			'ULX_Email_New_Renewal_Order'            => new ULX_Email_New_Renewal_Order(),
			'ULX_Email_New_Switch_Order'             => new ULX_Email_New_Switch_Order(),
			'ULX_Email_Processing_Renewal_Order'     => new ULX_Email_Processing_Renewal_Order(),
			'ULX_Email_Completed_Renewal_Order'      => new ULX_Email_Completed_Renewal_Order(),
			'ULX_Email_Completed_Switch_Order'       => new ULX_Email_Completed_Switch_Order(),
			'ULX_Email_Customer_Renewal_Invoice'     => new ULX_Email_Customer_Renewal_Invoice(),
			'ULX_Email_Cancelled_Subscription'       => new ULX_Email_Cancelled_Subscription(),
			'ULX_Email_Expired_Subscription'         => new ULX_Email_Expired_Subscription(),
			'ULX_Email_On_Hold_Subscription'         => new ULX_Email_On_Hold_Subscription(),
			'ULX_Email_Customer_Payment_Retry'       => new ULX_Email_Customer_Payment_Retry(),
			'ULX_Email_Payment_Retry'                => new ULX_Email_Payment_Retry(),
			'ULX_Email_Customer_Notification_Remind' => new ULX_Email_Customer_Notification_Remind(),
			'ULX_Email_Customer_Notification_Trial'  => new ULX_Email_Customer_Notification_Trial(),
			'ULX_Email_Customer_Notification_Expire' => new ULX_Email_Customer_Notification_Expire(),
		);

		return array_merge( $emails, $emails_map );
	}
}
