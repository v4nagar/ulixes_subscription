<?php
/**
 * Gateway Tokenization & Autonomous Renewals Engine.
 *
 * Copies and synchronizes payment tokens (WC_Payment_Token, Stripe, PayPal, Razorpay, etc.)
 * between parent orders, subscriptions, and renewal orders to allow seamless merchant-initiated
 * background charges when Action Scheduler triggers renewal events.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Gateway_Tokenization
 */
class ULX_Gateway_Tokenization {

	/**
	 * Recognized gateway payment meta keys to synchronize.
	 *
	 * @var array
	 */
	protected static $token_meta_keys = array(
		// Stripe.
		'_stripe_customer_id',
		'_stripe_source_id',
		'_stripe_card_id',
		'_stripe_intent_id',
		// PayPal.
		'_paypal_token',
		'_ppcp_paypal_order_id',
		'_paypal_billing_agreement_id',
		'_billing_agreement_id',
		// Razorpay.
		'_razorpay_customer_id',
		'_razorpay_token_id',
		'_razorpay_subscription_id',
		// Square.
		'_square_customer_id',
		'_square_card_id',
		// Authorize.Net CIM.
		'_wc_authorize_net_cim_customer_profile_id',
		'_wc_authorize_net_cim_payment_profile_id',
		// Braintree.
		'_braintree_customer_id',
		'_braintree_token',
		// Generic WC tokens.
		'_payment_tokens',
		'_payment_method_id',
	);

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'copy_tokens_from_order_to_subscriptions' ), 110, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'copy_tokens_from_order_to_subscriptions' ), 15, 1 );
		add_action( 'ulx_checkout_subscription_created', array( __CLASS__, 'attach_tokens_on_creation' ), 10, 3 );
		add_action( 'ulx_copy_order_meta', array( __CLASS__, 'sync_tokens_on_order_copy' ), 10, 3 );
		add_action( 'ulx_renewal_order_created', array( __CLASS__, 'attach_tokens_to_renewal_order' ), 10, 2 );
	}

	/**
	 * Copy payment tokens from a parent order to all related subscriptions.
	 *
	 * @param int|WC_Order $order_or_id Order.
	 */
	public static function copy_tokens_from_order_to_subscriptions( $order_or_id ) {
		$order = is_numeric( $order_or_id ) ? wc_get_order( $order_or_id ) : $order_or_id;
		if ( ! $order || $order instanceof ULX_Subscription ) {
			return;
		}

		$subscriptions = ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
		if ( empty( $subscriptions ) ) {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			self::copy_tokens( $order, $subscription );
		}
	}

	/**
	 * Attach payment tokens immediately upon subscription creation in checkout.
	 *
	 * @param ULX_Subscription $subscription  Subscription.
	 * @param WC_Order         $order         Parent order.
	 * @param WC_Cart          $recurring_cart Recurring cart.
	 */
	public static function attach_tokens_on_creation( $subscription, $order, $recurring_cart = null ) {
		self::copy_tokens( $order, $subscription );
	}

	/**
	 * Copy tokens when copying order meta.
	 *
	 * @param WC_Order $from_order Source.
	 * @param WC_Order $to_order   Destination.
	 * @param string   $type       Operation type.
	 */
	public static function sync_tokens_on_order_copy( $from_order, $to_order, $type ) {
		self::copy_tokens( $from_order, $to_order );
	}

	/**
	 * Attach subscription payment tokens onto a newly created renewal order so payment gateways can charge it autonomously.
	 *
	 * @param WC_Order         $renewal_order Renewal order.
	 * @param ULX_Subscription $subscription  Subscription.
	 */
	public static function attach_tokens_to_renewal_order( $renewal_order, $subscription ) {
		self::copy_tokens( $subscription, $renewal_order );
	}

	/**
	 * Core token copying logic between orders/subscriptions.
	 *
	 * @param WC_Order $source      Source order or subscription.
	 * @param WC_Order $destination Destination order or subscription.
	 */
	public static function copy_tokens( $source, $destination ) {
		if ( ! $source || ! $destination ) {
			return;
		}

		$keys = apply_filters( 'ulx_token_meta_keys', self::$token_meta_keys, $source, $destination );

		$updated = false;
		foreach ( $keys as $key ) {
			$val = $source->get_meta( $key, true );
			if ( '' !== $val && false !== $val && null !== $val ) {
				$destination->update_meta_data( $key, $val );
				$updated = true;
			}
		}

		// Also handle standard WooCommerce Payment Tokens API (WC_Payment_Tokens).
		if ( class_exists( 'WC_Payment_Tokens' ) && $source->get_id() ) {
			$tokens = WC_Payment_Tokens::get_order_tokens( $source->get_id() );
			if ( ! empty( $tokens ) ) {
				foreach ( $tokens as $token ) {
					if ( method_exists( $destination, 'add_payment_token' ) ) {
						$destination->add_payment_token( $token );
						$updated = true;
					}
				}
			}
		}

		if ( $updated ) {
			$destination->save_meta_data();
		}
	}
}

