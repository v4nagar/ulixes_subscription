<?php
/**
 * Change Payment Method flow for customers on My Account -> View Subscription -> Change Payment.
 *
 * Uses the WooCommerce `order-pay` endpoint with `?change_payment_method=<subscription_id>`.
 * Also supports updating the payment method across all of a customer's active subscriptions at once.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULIXSU_Change_Payment_Method
 */
class ULIXSU_Change_Payment_Method {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'wp', array( __CLASS__, 'maybe_handle_change_payment_method' ), 20 );
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'change_payment_button_text' ), 20 );
		add_action( 'woocommerce_pay_order_before_submit', array( __CLASS__, 'render_update_all_subscriptions_checkbox' ) );
	}

	/**
	 * Is the current request a customer request to change a subscription's payment method?
	 *
	 * @return bool
	 */
	public static function is_request_to_change_payment() {
		return isset( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Button text when changing payment method.
	 *
	 * @param string $text Button text.
	 * @return string
	 */
	public static function change_payment_button_text( $text ) {
		if ( self::is_request_to_change_payment() ) {
			return __( 'Change payment method', 'ulixes-subscriptions' );
		}
		return $text;
	}

	/**
	 * Render "Update the payment method used for all of my current subscriptions" checkbox.
	 */
	public static function render_update_all_subscriptions_checkbox() {
		if ( ! self::is_request_to_change_payment() ) {
			return;
		}
		wp_nonce_field( 'ulixsu_change_payment_method', '_ulixsu_change_payment_nonce' );
		$subs = ulixsu_get_users_subscriptions( get_current_user_id(), array( 'subscription_status' => array( 'active', 'on-hold' ) ) );
		if ( count( $subs ) <= 1 ) {
			return;
		}
		?>
		<p class="form-row form-row-wide ulixsu-update-all-subscriptions-payment-method">
			<label for="update_all_subscriptions_payment_method">
				<input type="checkbox" name="update_all_subscriptions_payment_method" id="update_all_subscriptions_payment_method" value="1" />
				<?php esc_html_e( 'Update the payment method used for all of my current subscriptions', 'ulixes-subscriptions' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Verify the change-payment-method request nonce (our own nonce, or the WooCommerce order-pay form nonce).
	 *
	 * @return bool
	 */
	protected static function is_valid_change_payment_nonce() {
		if ( isset( $_POST['_ulixsu_change_payment_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ulixsu_change_payment_nonce'] ) ), 'ulixsu_change_payment_method' ) ) {
			return true;
		}
		if ( isset( $_POST['woocommerce-pay-nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-pay-nonce'] ) ), 'woocommerce-pay' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Process the submitted change-payment-method form on `order-pay`.
	 */
	public static function maybe_handle_change_payment_method() {
		if ( ! isset( $_POST['payment_method'] ) || ! isset( $_GET['change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Only checks whether this request is a form submission; the nonce is verified immediately below before any input is used.
			return;
		}
		if ( ! self::is_valid_change_payment_nonce() ) {
			wc_add_notice( __( 'Your session has expired. Please refresh the page and try again.', 'ulixes-subscriptions' ), 'error' );
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		$subscription_id = absint( $_GET['change_payment_method'] );
		if ( ! $subscription_id || '' === $_POST['payment_method'] ) {
			return;
		}
		$subscription = ulixsu_get_subscription( $subscription_id );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() ) {
			wc_add_notice( __( 'Invalid subscription.', 'ulixes-subscriptions' ), 'error' );
			return;
		}

		$new_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
		$gateway    = ulixsu_get_payment_gateway( $new_method );
		if ( ! $gateway ) {
			wc_add_notice( __( 'Invalid payment method selected.', 'ulixes-subscriptions' ), 'error' );
			return;
		}

		$old_method = $subscription->get_payment_method();
		$subscription->set_payment_method( $gateway->id );
		$subscription->set_payment_method_title( $gateway->get_title() );
		$subscription->set_requires_manual_renewal( ! $gateway->supports( 'subscriptions' ) );
		$subscription->save();

		$result = $gateway->process_payment( $subscription->get_id() );

		if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
			$subscription->add_order_note(
				sprintf(
					/* translators: 1: old payment method, 2: new payment method */
					__( 'Customer changed payment method from %1$s to %2$s.', 'ulixes-subscriptions' ),
					$old_method ?: __( 'Manual', 'ulixes-subscriptions' ),
					$gateway->get_title()
				)
			);

			do_action( 'ulixsu_subscription_payment_method_updated', $subscription, $new_method, $old_method );

			// Also update all other active/on-hold subscriptions for this user if requested.
			if ( ! empty( $_POST['update_all_subscriptions_payment_method'] ) ) {
				$user_subs = ulixsu_get_users_subscriptions( get_current_user_id(), array( 'subscription_status' => array( 'active', 'on-hold' ) ) );
				foreach ( $user_subs as $other_sub ) {
					if ( $other_sub->get_id() === $subscription->get_id() ) {
						continue;
					}
					$other_sub->set_payment_method( $gateway->id );
					$other_sub->set_payment_method_title( $gateway->get_title() );
					$other_sub->set_requires_manual_renewal( ! $gateway->supports( 'subscriptions' ) );
					// Copy any gateway token metadata from the updated subscription.
					foreach ( $subscription->get_meta_data() as $meta ) {
						$d = $meta->get_data();
						if ( 0 === strpos( $d['key'], '_stripe_' ) || 0 === strpos( $d['key'], '_ulixsu_test_' ) || 0 === strpos( $d['key'], '_payment_' ) ) {
							$other_sub->update_meta_data( $d['key'], $d['value'] );
						}
					}
					$other_sub->save();
					$other_sub->add_order_note( __( 'Payment method updated via customer "Update all subscriptions" option.', 'ulixes-subscriptions' ) );
				}
			}

			wc_add_notice( __( 'Payment method updated.', 'ulixes-subscriptions' ), 'success' );
			wp_safe_redirect( isset( $result['redirect'] ) ? $result['redirect'] : $subscription->get_view_order_url() );
			exit;
		}
	}
}

