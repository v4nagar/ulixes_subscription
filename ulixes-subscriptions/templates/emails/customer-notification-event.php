<?php
/**
 * Ulixes Subscriptions Email Template.
 *
 * @package Ulixes_Subscriptions
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

do_action( 'woocommerce_email_header', $email_heading, $email );

$target = ! empty( $subscription ) ? $subscription : $order;
if ( $target ) {
	if ( ! empty( $order ) && method_exists( $order, 'needs_payment' ) && $order->needs_payment() && ! $sent_to_admin ) {
		echo '<p>' . sprintf(
			/* translators: %s: payment url */
			wp_kses_post( __( 'Your renewal order is awaiting payment. <a href="%s">Click here to pay for your renewal order now &raquo;</a>', 'ulixes-subscriptions' ) ),
			esc_url( $order->get_checkout_payment_url() )
		) . '</p>';
	}
	do_action( 'woocommerce_email_order_details', $target, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_order_meta', $target, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_customer_details', $target, $sent_to_admin, $plain_text, $email );
}

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
