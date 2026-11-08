<?php
/**
 * Subscription info table appended to WooCommerce order emails.
 *
 * @package Ulixes_Subscriptions
 * @var ULX_Subscription[] $subscriptions
 * @var bool               $is_admin_email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( empty( $subscriptions ) ) {
	return;
}
?>
<div style="margin-bottom: 40px;">
	<h2><?php esc_html_e( 'Subscription Information', 'ulixes-subscriptions' ); ?></h2>
	<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 0.5em;" border="1">
		<thead>
			<tr>
				<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'ID', 'ulixes-subscriptions' ); ?></th>
				<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Start Date', 'ulixes-subscriptions' ); ?></th>
				<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Next Payment', 'ulixes-subscriptions' ); ?></th>
				<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Recurring Total', 'ulixes-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $subscriptions as $subscription ) : ?>
				<?php $url = $is_admin_email ? $subscription->get_edit_order_url() : $subscription->get_view_order_url(); ?>
				<tr>
					<td class="td" style="text-align:left;"><a href="<?php echo esc_url( $url ); ?>">#<?php echo esc_html( $subscription->get_order_number() ); ?></a></td>
					<td class="td" style="text-align:left;"><?php echo esc_html( $subscription->get_date_to_display( 'start' ) ); ?></td>
					<td class="td" style="text-align:left;"><?php echo esc_html( $subscription->get_date_to_display( 'next_payment' ) ); ?></td>
					<td class="td" style="text-align:left;"><?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

