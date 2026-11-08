<?php
/**
 * Built-in Automatic Subscription Test Gateway.
 *
 * Supports every WooCommerce Subscriptions gateway feature (`subscriptions`, `multiple_subscriptions`,
 * `subscription_cancellation`, `subscription_suspension`, `subscription_reactivation`,
 * `subscription_amount_changes`, `subscription_date_changes`, `subscription_payment_method_change`,
 * `subscription_payment_method_change_customer`, `subscription_payment_method_change_admin`, `tokenization`)
 * so you can test the entire automatic renewal, retry, switching and payment-method-change flow locally.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Gateway_Manual_Test
 */
class ULX_Gateway_Manual_Test extends WC_Payment_Gateway_CC {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'ulx_test_gateway';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = __( 'Ulixes Automatic Subscription Test Gateway', 'ulixes-subscriptions' );
		$this->method_description = __( 'Test payment gateway with full support for automatic recurring payments, WC Payment Tokens, failed-payment retries, upgrades/downgrades and payment method changes.', 'ulixes-subscriptions' );

		$this->supports = array(
			'products',
			'refunds',
			'tokenization',
			'subscriptions',
			'multiple_subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Credit Card (Subscription Test)', 'ulixes-subscriptions' ) );
		$this->description = $this->get_option( 'description', __( 'Pay with a test card. Renewal outcome can be controlled via the "Simulate Renewal Failure" setting.', 'ulixes-subscriptions' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'ulx_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		add_filter( 'ulx_subscription_payment_meta', array( $this, 'add_payment_meta' ), 10, 2 );
	}

	/**
	 * Gateway settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'ulixes-subscriptions' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Ulixes Automatic Subscription Test Gateway', 'ulixes-subscriptions' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'   => __( 'Title', 'ulixes-subscriptions' ),
				'type'    => 'text',
				'default' => __( 'Credit Card (Subscription Test)', 'ulixes-subscriptions' ),
			),
			'description'      => array(
				'title'   => __( 'Description', 'ulixes-subscriptions' ),
				'type'    => 'textarea',
				'default' => __( 'Test automatic recurring payments.', 'ulixes-subscriptions' ),
			),
			'simulate_failure' => array(
				'title'   => __( 'Simulate Renewal Failure', 'ulixes-subscriptions' ),
				'type'    => 'checkbox',
				'label'   => __( 'Fail scheduled automatic renewals (useful for testing the Failed Payment Retry system)', 'ulixes-subscriptions' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Payment fields.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}
		if ( $this->supports( 'tokenization' ) && is_checkout() ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
		}
	}

	/**
	 * Process initial payment (or $0 trial / change payment method).
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		// Handle changing payment method on an existing subscription.
		$subscription = ulx_get_subscription( $order_id );
		if ( $subscription ) {
			$token = $this->create_or_get_test_token( $subscription->get_user_id() );
			if ( $token ) {
				$subscription->update_meta_data( '_ulx_test_token', $token->get_token() );
				$subscription->save();
			}
			return array(
				'result'   => 'success',
				'redirect' => $subscription->get_view_order_url(),
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$token     = $this->create_or_get_test_token( $order->get_user_id() );
		$token_str = $token ? $token->get_token() : 'tok_ulx_' . wp_generate_password( 12, false );

		$order->update_meta_data( '_ulx_test_token', $token_str );
		$order->save();

		foreach ( ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $sub ) {
			$sub->update_meta_data( '_ulx_test_token', $token_str );
			$sub->save();
		}

		$order->payment_complete( 'txn_ulx_' . wp_generate_password( 10, false ) );
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process a scheduled renewal payment.
	 *
	 * @param float    $amount_to_charge Amount.
	 * @param WC_Order $renewal_order    Renewal order.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		if ( 'yes' === $this->get_option( 'simulate_failure', 'no' ) ) {
			$renewal_order->add_order_note( __( 'Simulated automatic renewal payment failure (Ulixes Test Gateway).', 'ulixes-subscriptions' ) );
			$renewal_order->update_status( 'failed' );
			return;
		}

		$token = $renewal_order->get_meta( '_ulx_test_token', true );
		if ( empty( $token ) ) {
			$renewal_order->add_order_note( __( 'Automatic renewal failed: missing payment token.', 'ulixes-subscriptions' ) );
			$renewal_order->update_status( 'failed' );
			return;
		}

		$txn = 'txn_ulx_ren_' . wp_generate_password( 10, false );
		$renewal_order->add_order_note( sprintf( /* translators: %s: token */ __( 'Automatic renewal payment charged using token %s.', 'ulixes-subscriptions' ), $token ) );
		$renewal_order->payment_complete( $txn );
	}

	/**
	 * Create or retrieve a saved WC_Payment_Token_CC for a user.
	 *
	 * @param int $user_id User ID.
	 * @return WC_Payment_Token_CC|null
	 */
	protected function create_or_get_test_token( $user_id ) {
		if ( ! $user_id || ! class_exists( 'WC_Payment_Token_CC' ) ) {
			return null;
		}
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'tok_ulx_' . wp_generate_password( 12, false ) );
		$token->set_gateway_id( $this->id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 4 ) );
		$token->set_user_id( $user_id );
		$token->save();
		return $token;
	}

	/**
	 * Expose token meta field in admin subscription billing box.
	 *
	 * @param array            $payment_meta Meta.
	 * @param ULX_Subscription $subscription Subscription.
	 * @return array
	 */
	public function add_payment_meta( $payment_meta, $subscription ) {
		$payment_meta[ $this->id ] = array(
			'post_meta' => array(
				'_ulx_test_token' => array(
					'value' => $subscription->get_meta( '_ulx_test_token', true ),
					'label' => __( 'Test Payment Token', 'ulixes-subscriptions' ),
				),
			),
		);
		return $payment_meta;
	}
}

