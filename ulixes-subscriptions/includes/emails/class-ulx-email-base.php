<?php
/**
 * All 14 Subscription Email Classes.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Base class for Ulixes Subscription / Renewal Order emails.
 */
abstract class ULX_Email_Base extends WC_Email {

	/**
	 * Constructor helper.
	 */
	public function __construct() {
		$this->template_base = ULX_PLUGIN_DIR . 'templates/';
		parent::__construct();
	}

	/**
	 * Content HTML.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return ulx_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'subscription'       => $this->object instanceof ULX_Subscription ? $this->object : null,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => ! $this->is_customer_email(),
				'plain_text'         => false,
				'email'              => $this,
			)
		);
	}

	/**
	 * Content Plain.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return ulx_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'subscription'       => $this->object instanceof ULX_Subscription ? $this->object : null,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => ! $this->is_customer_email(),
				'plain_text'         => true,
				'email'              => $this,
			)
		);
	}
}

/**
 * 1. Admin: New Renewal Order
 */
class ULX_Email_New_Renewal_Order extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'new_renewal_order';
		$this->title          = __( 'New Renewal Order', 'ulixes-subscriptions' );
		$this->description    = __( 'New renewal order emails are sent to chosen recipient(s) when a subscription renewal payment is processed.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/admin-new-renewal-order.php';
		$this->template_plain = 'emails/plain/admin-new-renewal-order.php';
		$this->placeholders   = array( '{order_date}' => '', '{order_number}' => '' );

		add_action( 'ulx_subscription_renewal_payment_complete', array( $this, 'trigger_from_sub' ), 10, 2 );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}
	public function get_default_subject() {
		return __( '[{site_title}]: New subscription renewal order ({order_number})', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'New Subscription Renewal Order: #{order_number}', 'ulixes-subscriptions' );
	}
	public function trigger_from_sub( $subscription, $order ) {
		if ( ! $order ) {
			return;
		}
		$this->object                         = $order;
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 2. Admin: New Switch Order
 */
class ULX_Email_New_Switch_Order extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'new_switch_order';
		$this->title          = __( 'Subscription Switched', 'ulixes-subscriptions' );
		$this->description    = __( 'Subscription switched emails are sent to chosen recipient(s) when a customer switches a subscription.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/admin-new-switch-order.php';
		$this->template_plain = 'emails/plain/admin-new-switch-order.php';
		$this->placeholders   = array( '{order_date}' => '', '{order_number}' => '' );

		add_action( 'ulx_subscriptions_switched_for_order', array( $this, 'trigger' ), 10, 1 );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}
	public function get_default_subject() {
		return __( '[{site_title}]: Subscription switched ({order_number})', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Subscription Switched: #{order_number}', 'ulixes-subscriptions' );
	}
	public function trigger( $order ) {
		if ( ! $order ) {
			return;
		}
		$this->object                         = $order;
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 3. Customer: Processing Renewal Order
 */
class ULX_Email_Processing_Renewal_Order extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'customer_processing_renewal_order';
		$this->customer_email = true;
		$this->title          = __( 'Processing Renewal order', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to the customer when a subscription renewal order is paid and being processed.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/customer-processing-renewal-order.php';
		$this->template_plain = 'emails/plain/customer-processing-renewal-order.php';
		$this->placeholders   = array( '{order_date}' => '', '{order_number}' => '' );

		add_action( 'ulx_subscription_renewal_payment_complete', array( $this, 'trigger_from_sub' ), 10, 2 );
		parent::__construct();
	}
	public function get_default_subject() {
		return __( 'Your {site_title} renewal order receipt from {order_date}', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Thank you for your renewal order', 'ulixes-subscriptions' );
	}
	public function trigger_from_sub( $subscription, $order ) {
		if ( ! $order || ! $order->has_status( 'processing' ) ) {
			return;
		}
		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 4. Customer: Completed Renewal Order
 */
class ULX_Email_Completed_Renewal_Order extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'customer_completed_renewal_order';
		$this->customer_email = true;
		$this->title          = __( 'Completed Renewal Order', 'ulixes-subscriptions' );
		$this->description    = __( 'Renewal order complete emails are sent to customers when their subscription renewal order is marked completed.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/customer-completed-renewal-order.php';
		$this->template_plain = 'emails/plain/customer-completed-renewal-order.php';
		$this->placeholders   = array( '{order_date}' => '', '{order_number}' => '' );

		add_action( 'woocommerce_order_status_completed_notification', array( $this, 'maybe_trigger' ), 10, 2 );
		parent::__construct();
	}
	public function get_default_subject() {
		return __( 'Your {site_title} renewal order from {order_date} is complete', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Your renewal order is complete', 'ulixes-subscriptions' );
	}
	public function maybe_trigger( $order_id, $order = false ) {
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! ulx_order_contains_renewal( $order ) ) {
			return;
		}
		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 5. Customer: Completed Switch Order
 */
class ULX_Email_Completed_Switch_Order extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'customer_completed_switch_order';
		$this->customer_email = true;
		$this->title          = __( 'Subscription Switch Complete', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to the customer when they successfully switch a subscription.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/customer-completed-switch-order.php';
		$this->template_plain = 'emails/plain/customer-completed-switch-order.php';
		$this->placeholders   = array( '{order_date}' => '', '{order_number}' => '' );

		add_action( 'ulx_subscriptions_switched_for_order', array( $this, 'trigger' ), 10, 1 );
		parent::__construct();
	}
	public function get_default_subject() {
		return __( 'Your {site_title} subscription change from {order_date} is complete', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Your subscription change is complete', 'ulixes-subscriptions' );
	}
	public function trigger( $order ) {
		if ( ! $order ) {
			return;
		}
		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 6. Customer: Renewal Invoice (manual renewal due or failed automatic renewal)
 */
class ULX_Email_Customer_Renewal_Invoice extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'customer_renewal_invoice';
		$this->customer_email = true;
		$this->title          = __( 'Customer Renewal Invoice', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to a customer when a manual renewal is due or an automatic renewal payment fails and requires payment.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/customer-renewal-invoice.php';
		$this->template_plain = 'emails/plain/customer-renewal-invoice.php';
		$this->placeholders   = array( '{order_date}' => '', '{order_number}' => '' );

		add_action( 'ulx_generated_manual_renewal_order', array( $this, 'trigger' ), 10, 1 );
		add_action( 'ulx_subscription_renewal_payment_failed', array( $this, 'trigger_on_failed' ), 20, 2 );
		parent::__construct();
	}
	public function get_default_subject() {
		return __( 'Invoice for renewal order {order_number} from {order_date}', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Invoice for renewal order #{order_number}', 'ulixes-subscriptions' );
	}
	public function trigger_on_failed( $subscription, $renewal_order ) {
		// If retry is active and scheduled, don't send the invoice email until retries are exhausted (the retry email handles it).
		if ( $subscription->get_time( 'payment_retry' ) > time() ) {
			return;
		}
		if ( $renewal_order ) {
			$this->trigger( $renewal_order->get_id() );
		}
	}
	public function trigger( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 7. Admin: Cancelled Subscription
 */
class ULX_Email_Cancelled_Subscription extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'cancelled_subscription';
		$this->title          = __( 'Cancelled Subscription', 'ulixes-subscriptions' );
		$this->description    = __( 'Cancelled Subscription emails are sent to chosen recipient(s) when a subscription is cancelled.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/cancelled-subscription.php';
		$this->template_plain = 'emails/plain/cancelled-subscription.php';
		$this->placeholders   = array( '{subscription_number}' => '' );

		add_action( 'ulx_subscription_status_cancelled', array( $this, 'trigger' ), 10, 1 );
		add_action( 'ulx_subscription_status_pending-cancel', array( $this, 'trigger' ), 10, 1 );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}
	public function get_default_subject() {
		return __( '[{site_title}]: Subscription #{subscription_number} has been cancelled', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Subscription Cancelled: #{subscription_number}', 'ulixes-subscriptions' );
	}
	public function trigger( $subscription ) {
		$subscription = ulx_get_subscription( $subscription );
		if ( ! $subscription || $subscription->get_cancelled_email_sent() ) {
			return;
		}
		$this->object                                = $subscription;
		$this->placeholders['{subscription_number}'] = $subscription->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			$subscription->set_cancelled_email_sent( true );
			$subscription->save();
		}
	}
}

/**
 * 8. Admin: Expired Subscription
 */
class ULX_Email_Expired_Subscription extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'expired_subscription';
		$this->title          = __( 'Expired Subscription', 'ulixes-subscriptions' );
		$this->description    = __( 'Expired Subscription emails are sent to chosen recipient(s) when a subscription expires.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/expired-subscription.php';
		$this->template_plain = 'emails/plain/expired-subscription.php';
		$this->placeholders   = array( '{subscription_number}' => '' );

		add_action( 'ulx_subscription_status_expired', array( $this, 'trigger' ), 10, 1 );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}
	public function get_default_subject() {
		return __( '[{site_title}]: Subscription #{subscription_number} has expired', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Subscription Expired: #{subscription_number}', 'ulixes-subscriptions' );
	}
	public function trigger( $subscription ) {
		$subscription = ulx_get_subscription( $subscription );
		if ( ! $subscription ) {
			return;
		}
		$this->object                                = $subscription;
		$this->placeholders['{subscription_number}'] = $subscription->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 9. Admin: Suspended (On-Hold) Subscription
 */
class ULX_Email_On_Hold_Subscription extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'suspended_subscription';
		$this->title          = __( 'Suspended Subscription', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to chosen recipient(s) when a customer suspends their subscription.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/suspended-subscription.php';
		$this->template_plain = 'emails/plain/suspended-subscription.php';
		$this->placeholders   = array( '{subscription_number}' => '' );

		add_action( 'ulx_customer_changed_subscription_to_on-hold', array( $this, 'trigger' ), 10, 1 );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}
	public function get_default_subject() {
		return __( '[{site_title}]: Subscription #{subscription_number} has been suspended by the customer', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Subscription Suspended: #{subscription_number}', 'ulixes-subscriptions' );
	}
	public function trigger( $subscription ) {
		$subscription = ulx_get_subscription( $subscription );
		if ( ! $subscription ) {
			return;
		}
		$this->object                                = $subscription;
		$this->placeholders['{subscription_number}'] = $subscription->get_order_number();
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 10. Customer: Payment Retry Scheduled
 */
class ULX_Email_Customer_Payment_Retry extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'customer_payment_retry';
		$this->customer_email = true;
		$this->title          = __( 'Customer Payment Retry', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to a customer when an automatic renewal payment fails and a retry is scheduled.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/customer-payment-retry.php';
		$this->template_plain = 'emails/plain/customer-payment-retry.php';
		$this->placeholders   = array( '{order_number}' => '', '{retry_time}' => '' );

		add_action( 'ulx_after_apply_retry_rule', array( $this, 'maybe_trigger' ), 10, 3 );
		parent::__construct();
	}
	public function get_default_subject() {
		return __( 'Automatic payment failed for order {order_number}, we will retry {retry_time}', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Automatic renewal payment failed', 'ulixes-subscriptions' );
	}
	public function maybe_trigger( $rule, $renewal_order, $subscription ) {
		if ( empty( $rule['email_template_customer'] ) || __CLASS__ !== $rule['email_template_customer'] ) {
			return;
		}
		$this->object                         = $renewal_order;
		$this->recipient                      = $renewal_order->get_billing_email();
		$this->placeholders['{order_number}'] = $renewal_order->get_order_number();
		$this->placeholders['{retry_time}']   = strtolower( ulx_human_time_diff( $subscription->get_time( 'payment_retry' ) ) );
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 11. Admin: Payment Retry Scheduled
 */
class ULX_Email_Payment_Retry extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'payment_retry';
		$this->title          = __( 'Payment Retry', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to chosen recipient(s) when an automatic renewal payment fails and a retry is scheduled.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/admin-payment-retry.php';
		$this->template_plain = 'emails/plain/admin-payment-retry.php';
		$this->placeholders   = array( '{order_number}' => '', '{retry_time}' => '' );

		add_action( 'ulx_after_apply_retry_rule', array( $this, 'maybe_trigger' ), 10, 3 );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}
	public function get_default_subject() {
		return __( '[{site_title}]: Automatic payment failed for {order_number}, retry scheduled {retry_time}', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Automatic renewal payment failed: #{order_number}', 'ulixes-subscriptions' );
	}
	public function maybe_trigger( $rule, $renewal_order, $subscription ) {
		if ( empty( $rule['email_template_admin'] ) || __CLASS__ !== $rule['email_template_admin'] ) {
			return;
		}
		$this->object                         = $renewal_order;
		$this->placeholders['{order_number}'] = $renewal_order->get_order_number();
		$this->placeholders['{retry_time}']   = strtolower( ulx_human_time_diff( $subscription->get_time( 'payment_retry' ) ) );
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 12. Customer Notification: Upcoming Renewal Reminder
 */
class ULX_Email_Customer_Notification_Remind extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'customer_notification_upcoming_renewal';
		$this->customer_email = true;
		$this->title          = __( 'Upcoming Subscription Renewal Reminder', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to a customer prior to their upcoming subscription renewal payment.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/customer-notification-event.php';
		$this->template_plain = 'emails/plain/customer-notification-event.php';
		$this->placeholders   = array( '{subscription_number}' => '', '{event_date}' => '' );

		add_action( 'ulx_send_customer_notification_next_payment', array( $this, 'trigger' ), 10, 1 );
		parent::__construct();
	}
	public function get_default_subject() {
		return __( '[{site_title}]: Your subscription #{subscription_number} will renew on {event_date}', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Upcoming Subscription Renewal', 'ulixes-subscriptions' );
	}
	public function trigger( $subscription ) {
		$subscription = ulx_get_subscription( $subscription );
		if ( ! $subscription ) {
			return;
		}
		$this->object                                = $subscription;
		$this->recipient                             = $subscription->get_billing_email();
		$this->placeholders['{subscription_number}'] = $subscription->get_order_number();
		$this->placeholders['{event_date}']          = $subscription->get_date_to_display( 'next_payment' );
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 13. Customer Notification: Upcoming Trial End Reminder
 */
class ULX_Email_Customer_Notification_Trial extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'customer_notification_trial_ending';
		$this->customer_email = true;
		$this->title          = __( 'Subscription Free Trial Ending Reminder', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to a customer prior to their subscription free trial ending.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/customer-notification-event.php';
		$this->template_plain = 'emails/plain/customer-notification-event.php';
		$this->placeholders   = array( '{subscription_number}' => '', '{event_date}' => '' );

		add_action( 'ulx_send_customer_notification_trial_end', array( $this, 'trigger' ), 10, 1 );
		parent::__construct();
	}
	public function get_default_subject() {
		return __( '[{site_title}]: Your free trial for subscription #{subscription_number} ends on {event_date}', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Your Free Trial is Ending Soon', 'ulixes-subscriptions' );
	}
	public function trigger( $subscription ) {
		$subscription = ulx_get_subscription( $subscription );
		if ( ! $subscription ) {
			return;
		}
		$this->object                                = $subscription;
		$this->recipient                             = $subscription->get_billing_email();
		$this->placeholders['{subscription_number}'] = $subscription->get_order_number();
		$this->placeholders['{event_date}']          = $subscription->get_date_to_display( 'trial_end' );
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

/**
 * 14. Customer Notification: Upcoming Expiration Reminder
 */
class ULX_Email_Customer_Notification_Expire extends ULX_Email_Base {
	public function __construct() {
		$this->id             = 'customer_notification_expiring';
		$this->customer_email = true;
		$this->title          = __( 'Subscription Expiring Reminder', 'ulixes-subscriptions' );
		$this->description    = __( 'Sent to a customer prior to their subscription expiring.', 'ulixes-subscriptions' );
		$this->template_html  = 'emails/customer-notification-event.php';
		$this->template_plain = 'emails/plain/customer-notification-event.php';
		$this->placeholders   = array( '{subscription_number}' => '', '{event_date}' => '' );

		add_action( 'ulx_send_customer_notification_end', array( $this, 'trigger' ), 10, 1 );
		parent::__construct();
	}
	public function get_default_subject() {
		return __( '[{site_title}]: Your subscription #{subscription_number} expires on {event_date}', 'ulixes-subscriptions' );
	}
	public function get_default_heading() {
		return __( 'Your Subscription is Expiring Soon', 'ulixes-subscriptions' );
	}
	public function trigger( $subscription ) {
		$subscription = ulx_get_subscription( $subscription );
		if ( ! $subscription ) {
			return;
		}
		$this->object                                = $subscription;
		$this->recipient                             = $subscription->get_billing_email();
		$this->placeholders['{subscription_number}'] = $subscription->get_order_number();
		$this->placeholders['{event_date}']          = $subscription->get_date_to_display( 'end' );
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}
}

