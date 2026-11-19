<?php
/**
 * Subscription Emails Manager & Email Classes.
 *
 * Registers 14 specialized WooCommerce email classes covering all admin and customer subscription events:
 * 1.  `ULIXSU_Email_New_Renewal_Order`            (Admin)
 * 2.  `ULIXSU_Email_New_Switch_Order`             (Admin)
 * 3.  `ULIXSU_Email_Processing_Renewal_Order`     (Customer)
 * 4.  `ULIXSU_Email_Completed_Renewal_Order`      (Customer)
 * 5.  `ULIXSU_Email_Completed_Switch_Order`       (Customer)
 * 6.  `ULIXSU_Email_Customer_Renewal_Invoice`     (Customer - manual renewal due or failed renewal)
 * 7.  `ULIXSU_Email_Cancelled_Subscription`       (Admin)
 * 8.  `ULIXSU_Email_Expired_Subscription`         (Admin)
 * 9.  `ULIXSU_Email_On_Hold_Subscription`         (Admin)
 * 10. `ULIXSU_Email_Customer_Payment_Retry`       (Customer)
 * 11. `ULIXSU_Email_Payment_Retry`                (Admin)
 * 12. `ULIXSU_Email_Customer_Notification_Remind` (Customer - upcoming renewal)
 * 13. `ULIXSU_Email_Customer_Notification_Trial`  (Customer - upcoming trial end)
 * 14. `ULIXSU_Email_Customer_Notification_Expire` (Customer - upcoming expiration)
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULIXSU_Emails
 */
class ULIXSU_Emails {

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
			'ulixsu_subscription_status_cancelled',
			'ulixsu_subscription_status_expired',
			'ulixsu_subscription_status_on-hold',
			'ulixsu_generated_manual_renewal_order',
			'ulixsu_subscription_renewal_payment_complete',
			'ulixsu_subscription_renewal_payment_failed',
			'ulixsu_after_apply_retry_rule',
			'ulixsu_subscriptions_switched_for_order',
			'ulixsu_send_customer_notification_next_payment',
			'ulixsu_send_customer_notification_trial_end',
			'ulixsu_send_customer_notification_end',
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
		require_once ULIXSU_PLUGIN_DIR . 'includes/emails/class-ulixsu-email-base.php';

		$emails_map = array(
			'ULIXSU_Email_New_Renewal_Order'            => new ULIXSU_Email_New_Renewal_Order(),
			'ULIXSU_Email_New_Switch_Order'             => new ULIXSU_Email_New_Switch_Order(),
			'ULIXSU_Email_Processing_Renewal_Order'     => new ULIXSU_Email_Processing_Renewal_Order(),
			'ULIXSU_Email_Completed_Renewal_Order'      => new ULIXSU_Email_Completed_Renewal_Order(),
			'ULIXSU_Email_Completed_Switch_Order'       => new ULIXSU_Email_Completed_Switch_Order(),
			'ULIXSU_Email_Customer_Renewal_Invoice'     => new ULIXSU_Email_Customer_Renewal_Invoice(),
			'ULIXSU_Email_Cancelled_Subscription'       => new ULIXSU_Email_Cancelled_Subscription(),
			'ULIXSU_Email_Expired_Subscription'         => new ULIXSU_Email_Expired_Subscription(),
			'ULIXSU_Email_On_Hold_Subscription'         => new ULIXSU_Email_On_Hold_Subscription(),
			'ULIXSU_Email_Customer_Payment_Retry'       => new ULIXSU_Email_Customer_Payment_Retry(),
			'ULIXSU_Email_Payment_Retry'                => new ULIXSU_Email_Payment_Retry(),
			'ULIXSU_Email_Customer_Notification_Remind' => new ULIXSU_Email_Customer_Notification_Remind(),
			'ULIXSU_Email_Customer_Notification_Trial'  => new ULIXSU_Email_Customer_Notification_Trial(),
			'ULIXSU_Email_Customer_Notification_Expire' => new ULIXSU_Email_Customer_Notification_Expire(),
		);

		return array_merge( $emails, $emails_map );
	}
}
