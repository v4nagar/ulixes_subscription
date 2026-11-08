<?php
/**
 * Order hooks: appends Related Subscriptions details to order emails and thank-you pages,
 * and handles stock reduction rules on renewals.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Order_Hooks
 */
class ULX_Order_Hooks {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'add_sub_info_to_email' ), 15, 4 );
	}

	/**
	 * Include a summary of related subscriptions in standard WooCommerce order emails.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text    Plain text.
	 * @param WC_Email $email         Email object.
	 */
	public static function add_sub_info_to_email( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $order instanceof ULX_Subscription ) {
			return;
		}
		$subscriptions = ulx_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'switch', 'resubscribe' ) ) );
		if ( empty( $subscriptions ) ) {
			return;
		}
		$template = $plain_text ? 'emails/plain/subscription-info.php' : 'emails/subscription-info.php';
		ulx_get_template(
			$template,
			array(
				'order'          => $order,
				'subscriptions'  => $subscriptions,
				'is_admin_email' => $sent_to_admin,
			)
		);
	}
}

