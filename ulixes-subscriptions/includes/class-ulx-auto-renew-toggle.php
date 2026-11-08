<?php
/**
 * Customer Auto-Renewal Toggle on View Subscription page.
 *
 * Allows customers to switch their subscription between Automatic and Manual renewal via AJAX
 * when `enable_auto_renewal_toggle` is enabled in settings.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Auto_Renew_Toggle
 */
class ULX_Auto_Renew_Toggle {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_ulx_toggle_auto_renewal', array( __CLASS__, 'ajax_toggle_auto_renewal' ) );
	}

	/**
	 * Can the customer toggle auto-renewal on this subscription?
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @return bool
	 */
	public static function can_user_toggle_auto_renewal( $subscription ) {
		if ( ! ulx_option_is_yes( 'enable_auto_renewal_toggle' ) || ulx_automatic_payments_disabled() ) {
			return false;
		}
		if ( ! $subscription || ! $subscription->has_status( 'active' ) ) {
			return false;
		}
		if ( 0 == $subscription->get_total() || $subscription->is_one_payment() ) { // phpcs:ignore
			return false;
		}
		// To turn auto-renew ON, the subscription must already have a gateway supporting subscriptions attached.
		if ( $subscription->is_manual() ) {
			$gw = ulx_get_payment_gateway( $subscription->get_payment_method() );
			if ( ! $gw || ! $gw->supports( 'subscriptions' ) ) {
				return false;
			}
		}
		return (bool) apply_filters( 'ulx_can_user_toggle_auto_renewal', true, $subscription );
	}

	/**
	 * AJAX handler to toggle `_requires_manual_renewal`.
	 */
	public static function ajax_toggle_auto_renewal() {
		check_ajax_referer( 'ulx_toggle_auto_renew', 'security' );

		$subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
		$subscription    = ulx_get_subscription( $subscription_id );

		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() || ! self::can_user_toggle_auto_renewal( $subscription ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ulixes-subscriptions' ) ) );
		}

		$enable_auto = isset( $_POST['enable_auto_renew'] ) && 'yes' === $_POST['enable_auto_renew'];
		$subscription->set_requires_manual_renewal( ! $enable_auto );
		$subscription->save();

		$note = $enable_auto
			? __( 'Customer enabled automatic renewal via My Account toggle.', 'ulixes-subscriptions' )
			: __( 'Customer disabled automatic renewal (switched to manual renewal) via My Account toggle.', 'ulixes-subscriptions' );
		$subscription->add_order_note( $note );

		wp_send_json_success(
			array(
				'is_manual'      => $subscription->is_manual(),
				'payment_method' => $subscription->get_payment_method_to_display( 'customer' ),
			)
		);
	}
}

