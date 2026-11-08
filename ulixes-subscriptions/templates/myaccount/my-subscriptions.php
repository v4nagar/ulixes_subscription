<?php
/**
 * My Account -> Subscriptions list template.
 *
 * @package Ulixes_Subscriptions
 * @var ULX_Subscription[] $subscriptions
 * @var int                $current_page
 * @var int                $max_num_pages
 * @var bool               $paginate
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
?>
<div class="woocommerce_account_subscriptions">
	<?php if ( ! empty( $subscriptions ) ) : ?>
		<table class="my_account_subscriptions my_account_orders woocommerce-orders-table woocommerce-MyAccount-subscriptions shop_table shop_table_responsive">
			<thead>
				<tr>
					<th class="subscription-id order-number"><span class="nobr"><?php esc_html_e( 'Subscription', 'ulixes-subscriptions' ); ?></span></th>
					<th class="subscription-status order-status"><span class="nobr"><?php esc_html_e( 'Status', 'ulixes-subscriptions' ); ?></span></th>
					<th class="subscription-next-payment order-date"><span class="nobr"><?php esc_html_e( 'Next payment', 'ulixes-subscriptions' ); ?></span></th>
					<th class="subscription-total order-total"><span class="nobr"><?php esc_html_e( 'Total', 'ulixes-subscriptions' ); ?></span></th>
					<th class="subscription-actions order-actions">&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $subscription ) : ?>
					<tr class="order woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $subscription->get_status() ); ?>">
						<td class="subscription-id order-number" data-title="<?php esc_attr_e( 'Subscription', 'ulixes-subscriptions' ); ?>">
							<a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>"><strong>#<?php echo esc_html( $subscription->get_order_number() ); ?></strong></a>
						</td>
						<td class="subscription-status order-status" data-title="<?php esc_attr_e( 'Status', 'ulixes-subscriptions' ); ?>">
							<?php echo esc_html( ulx_get_subscription_status_name( $subscription->get_status() ) ); ?>
						</td>
						<td class="subscription-next-payment order-date" data-title="<?php esc_attr_e( 'Next payment', 'ulixes-subscriptions' ); ?>">
							<?php echo esc_html( $subscription->get_date_to_display( 'next_payment' ) ); ?>
							<?php if ( ! $subscription->is_manual() && $subscription->has_status( 'active' ) && $subscription->get_time( 'next_payment' ) > 0 ) : ?>
								<br/><small><?php echo esc_html( $subscription->get_payment_method_to_display( 'customer' ) ); ?></small>
							<?php endif; ?>
						</td>
						<td class="subscription-total order-total" data-title="<?php esc_attr_e( 'Total', 'ulixes-subscriptions' ); ?>">
							<?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?>
						</td>
						<td class="subscription-actions order-actions">
							<a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>" class="woocommerce-button button view"><?php esc_html_e( 'View', 'ulixes-subscriptions' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $paginate ) && $max_num_pages > 1 ) : ?>
			<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
				<?php if ( 1 !== $current_page ) : ?>
					<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button" href="<?php echo esc_url( wc_get_endpoint_url( 'subscriptions', $current_page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'ulixes-subscriptions' ); ?></a>
				<?php endif; ?>
				<?php if ( intval( $max_num_pages ) !== $current_page ) : ?>
					<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button" href="<?php echo esc_url( wc_get_endpoint_url( 'subscriptions', $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'ulixes-subscriptions' ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<p class="no_subscriptions woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
			<?php esc_html_e( 'You have no active subscriptions.', 'ulixes-subscriptions' ); ?>
			<a class="woocommerce-Button button" href="<?php echo esc_url( apply_filters( 'woocommerce_return_to_shop_redirect', wc_get_page_permalink( 'shop' ) ) ); ?>">
				<?php esc_html_e( 'Browse products', 'ulixes-subscriptions' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>

