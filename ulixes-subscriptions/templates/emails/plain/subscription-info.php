<?php
/**
 * Plain-text subscription info appended to WooCommerce order emails.
 *
 * @package Ulixes_Subscriptions
 * @var ULX_Subscription[] $subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( empty( $subscriptions ) ) {
	return;
}

echo "\n" . esc_html( strtoupper( __( 'Subscription Information', 'ulixes-subscriptions' ) ) ) . "\n\n";
foreach ( $subscriptions as $subscription ) {
	echo sprintf(
		/* translators: 1: id, 2: next payment, 3: total */
		esc_html__( 'Subscription #%1$s | Next Payment: %2$s | Total: %3$s', 'ulixes-subscriptions' ),
		esc_html( $subscription->get_order_number() ),
		esc_html( $subscription->get_date_to_display( 'next_payment' ) ),
		 esc_html( wp_strip_all_tags( $subscription->get_formatted_order_total() ) )
	) . "\n";
}
echo "\n****************************************************\n\n";

