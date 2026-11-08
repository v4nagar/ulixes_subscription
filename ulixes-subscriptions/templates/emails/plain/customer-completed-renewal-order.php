<?php
/**
 * Ulixes Subscriptions Plain-Text Email Template.
 *
 * @package Ulixes_Subscriptions
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

echo "= " . esc_html( $email_heading ) . " =\n\n";
$target = ! empty( $subscription ) ? $subscription : $order;
if ( $target ) {
	if ( ! empty( $order ) && method_exists( $order, 'needs_payment' ) && $order->needs_payment() && ! $sent_to_admin ) {
		echo esc_html__( 'Pay for your renewal order:', 'ulixes-subscriptions' ) . ' ' . esc_url( $order->get_checkout_payment_url() ) . "\n\n";
	}
	do_action( 'woocommerce_email_order_details', $target, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_order_meta', $target, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_customer_details', $target, $sent_to_admin, $plain_text, $email );
}
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
