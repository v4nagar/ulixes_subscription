<?php
/**
 * Related Subscriptions section on My Account -> View Order page.
 *
 * @package Ulixes_Subscriptions
 * @var ULX_Subscription[] $subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( empty( $subscriptions ) ) {
	return;
}
?>
<header><h2><?php esc_html_e( 'Related Subscriptions', 'ulixes-subscriptions' ); ?></h2></header>
<table class="shop_table shop_table_responsive my_account_orders ulx-related-subscriptions">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Subscription', 'ulixes-subscriptions' ); ?></th>
			<th><?php esc_html_e( 'Status', 'ulixes-subscriptions' ); ?></th>
			<th><?php esc_html_e( 'Next Payment', 'ulixes-subscriptions' ); ?></th>
			<th><?php esc_html_e( 'Total', 'ulixes-subscriptions' ); ?></th>
			<th>&nbsp;</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $subscriptions as $subscription ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>">#<?php echo esc_html( $subscription->get_order_number() ); ?></a></td>
				<td><?php echo esc_html( ulx_get_subscription_status_name( $subscription->get_status() ) ); ?></td>
				<td><?php echo esc_html( $subscription->get_date_to_display( 'next_payment' ) ); ?></td>
				<td><?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?></td>
				<td><a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>" class="button view"><?php esc_html_e( 'View', 'ulixes-subscriptions' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

