<?php
/**
 * My Account -> View Subscription details template.
 *
 * Renders:
 * - Subscription summary table (Status, Start date, Trial end, Next payment, End date, Auto-renew toggle, Payment method, Actions: Cancel, Suspend, Reactivate, Renew Now, Resubscribe, Change Payment, Change Address)
 * - Subscription Line Items & Totals (with Upgrade/Downgrade switch button per item when eligible)
 * - Related Orders table (Parent, Renewal, Switch, Resubscribe orders with Pay button if unpaid)
 * - Customer Billing & Shipping addresses
 *
 * @package Ulixes_Subscriptions
 * @var ULX_Subscription $subscription
 * @var int              $user_id
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

wc_print_notices();
?>
<div class="ulx-view-subscription-wrap">
	<table class="shop_table subscription_details">
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Status', 'ulixes-subscriptions' ); ?></td>
				<td><?php echo esc_html( ulx_get_subscription_status_name( $subscription->get_status() ) ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Start date', 'ulixes-subscriptions' ); ?></td>
				<td><?php echo esc_html( $subscription->get_date_to_display( 'start' ) ); ?></td>
			</tr>
			<?php if ( $subscription->get_time( 'trial_end' ) > 0 ) : ?>
				<tr>
					<td><?php esc_html_e( 'Trial end date', 'ulixes-subscriptions' ); ?></td>
					<td><?php echo esc_html( $subscription->get_date_to_display( 'trial_end' ) ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<td><?php esc_html_e( 'Last order date', 'ulixes-subscriptions' ); ?></td>
				<td><?php echo esc_html( $subscription->get_date_to_display( 'last_order_date_created' ) ); ?></td>
			</tr>
			<?php if ( $subscription->get_time( 'next_payment' ) > 0 ) : ?>
				<tr>
					<td><?php esc_html_e( 'Next payment date', 'ulixes-subscriptions' ); ?></td>
					<td><?php echo esc_html( $subscription->get_date_to_display( 'next_payment' ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( $subscription->get_time( 'end' ) > 0 ) : ?>
				<tr>
					<td><?php esc_html_e( 'End date', 'ulixes-subscriptions' ); ?></td>
					<td><?php echo esc_html( $subscription->get_date_to_display( 'end' ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( $subscription->get_time( 'payment_retry' ) > time() ) : ?>
				<tr>
					<td><?php esc_html_e( 'Payment retry date', 'ulixes-subscriptions' ); ?></td>
					<td><?php echo esc_html( $subscription->get_date_to_display( 'payment_retry' ) ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<td><?php esc_html_e( 'Payment', 'ulixes-subscriptions' ); ?></td>
				<td>
					<span class="ulx-payment-method-display"><?php echo esc_html( $subscription->get_payment_method_to_display( 'customer' ) ); ?></span>
				</td>
			</tr>
			<?php if ( ULX_Auto_Renew_Toggle::can_user_toggle_auto_renewal( $subscription ) ) : ?>
				<tr>
					<td><?php esc_html_e( 'Auto renew', 'ulixes-subscriptions' ); ?></td>
					<td>
						<label class="ulx-auto-renew-toggle">
							<input type="checkbox" class="ulx-auto-renew-checkbox" data-subscription-id="<?php echo esc_attr( $subscription->get_id() ); ?>" <?php checked( ! $subscription->is_manual(), true ); ?> />
							<span class="ulx-toggle-slider"></span>
						</label>
					</td>
				</tr>
			<?php endif; ?>
			<?php
			$actions = ulx_get_all_user_actions_for_subscription( $subscription, get_current_user_id() );
			if ( ! empty( $actions ) ) :
				?>
				<tr>
					<td><?php esc_html_e( 'Actions', 'ulixes-subscriptions' ); ?></td>
					<td>
						<?php foreach ( $actions as $key => $action ) : ?>
							<a href="<?php echo esc_url( $action['url'] ); ?>" class="button <?php echo esc_attr( sanitize_html_class( $key ) ); ?>"><?php echo esc_html( $action['name'] ); ?></a>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Subscription Totals', 'ulixes-subscriptions' ); ?></h2>
	<table class="shop_table order_details">
		<thead>
			<tr>
				<th class="product-name"><?php esc_html_e( 'Product', 'ulixes-subscriptions' ); ?></th>
				<th class="product-total"><?php esc_html_e( 'Total', 'ulixes-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $subscription->get_items() as $item_id => $item ) : ?>
				<tr class="order_item">
					<td class="product-name">
						<?php
						$product = $item->get_product();
						if ( $product && $product->is_visible() ) {
							echo '<a href="' . esc_url( $product->get_permalink() ) . '">' . esc_html( $item->get_name() ) . '</a>';
						} else {
							echo esc_html( $item->get_name() );
						}
						echo ' <strong class="product-quantity">&times;&nbsp;' . esc_html( $item->get_quantity() ) . '</strong>';
						wc_display_item_meta( $item );

						if ( ulx_can_item_be_switched_by_user( $subscription, $item ) ) {
							$switch_url  = ulx_get_switch_url( $item_id, $item, $subscription );
							$switch_text = ulx_get_option( 'switch_button_text', __( 'Upgrade or Downgrade', 'ulixes-subscriptions' ) );
							echo ' <a href="' . esc_url( $switch_url ) . '" class="button ulx-switch-link">' . esc_html( $switch_text ) . '</a>';
						}
						?>
					</td>
					<td class="product-total">
						<?php echo wp_kses_post( $subscription->get_formatted_line_subtotal( $item ) ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<?php foreach ( $subscription->get_order_item_totals() as $key => $total ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $total['label'] ); ?></th>
					<td><?php echo wp_kses_post( $total['value'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tfoot>
	</table>

	<?php
	$related_orders = $subscription->get_related_orders( 'all', 'any' );
	if ( ! empty( $related_orders ) ) :
		?>
		<header><h2><?php esc_html_e( 'Related Orders', 'ulixes-subscriptions' ); ?></h2></header>
		<table class="shop_table shop_table_responsive my_account_orders">
			<thead>
				<tr>
					<th class="order-number"><span class="nobr"><?php esc_html_e( 'Order', 'ulixes-subscriptions' ); ?></span></th>
					<th class="order-date"><span class="nobr"><?php esc_html_e( 'Date', 'ulixes-subscriptions' ); ?></span></th>
					<th class="order-status"><span class="nobr"><?php esc_html_e( 'Status', 'ulixes-subscriptions' ); ?></span></th>
					<th class="order-total"><span class="nobr"><?php esc_html_e( 'Total', 'ulixes-subscriptions' ); ?></span></th>
					<th class="order-actions">&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $related_orders as $rel_order ) : ?>
					<tr class="order">
						<td class="order-number" data-title="<?php esc_attr_e( 'Order Number', 'ulixes-subscriptions' ); ?>">
							<a href="<?php echo esc_url( $rel_order->get_view_order_url() ); ?>">#<?php echo esc_html( $rel_order->get_order_number() ); ?></a>
						</td>
						<td class="order-date" data-title="<?php esc_attr_e( 'Date', 'ulixes-subscriptions' ); ?>">
							<?php echo esc_html( $rel_order->get_date_created() ? wc_format_datetime( $rel_order->get_date_created() ) : '-' ); ?>
						</td>
						<td class="order-status" data-title="<?php esc_attr_e( 'Status', 'ulixes-subscriptions' ); ?>">
							<?php echo esc_html( wc_get_order_status_name( $rel_order->get_status() ) ); ?>
						</td>
						<td class="order-total" data-title="<?php esc_attr_e( 'Total', 'ulixes-subscriptions' ); ?>">
							<?php echo wp_kses_post( $rel_order->get_formatted_order_total() ); ?>
						</td>
						<td class="order-actions">
							<?php if ( $rel_order->needs_payment() ) : ?>
								<a href="<?php echo esc_url( $rel_order->get_checkout_payment_url() ); ?>" class="button pay"><?php esc_html_e( 'Pay', 'ulixes-subscriptions' ); ?></a>
							<?php endif; ?>
							<a href="<?php echo esc_url( $rel_order->get_view_order_url() ); ?>" class="button view"><?php esc_html_e( 'View', 'ulixes-subscriptions' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php wc_get_template( 'order/order-details-customer.php', array( 'order' => $subscription ) ); ?>
</div>

