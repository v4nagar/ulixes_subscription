<?php
/**
 * Recurring totals template for Cart and Checkout.
 *
 * @package Ulixes_Subscriptions
 * @var array $recurring_carts
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( empty( $recurring_carts ) ) {
	return;
}
?>
<tr class="recurring-totals">
	<th colspan="2"><?php esc_html_e( 'Recurring totals', 'ulixes-subscriptions' ); ?></th>
</tr>

<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
	<?php if ( 0 == $recurring_cart->next_payment_date && 0 == $recurring_cart->total ) { continue; } // phpcs:ignore ?>
	<tr class="cart-subtotal recurring-total">
		<th><?php esc_html_e( 'Subtotal', 'ulixes-subscriptions' ); ?></th>
		<td data-title="<?php esc_attr_e( 'Subtotal', 'ulixes-subscriptions' ); ?>">
			<?php echo wp_kses_post( ulx_cart_price_string( $recurring_cart->get_cart_subtotal(), $recurring_cart ) ); ?>
		</td>
	</tr>

	<?php foreach ( $recurring_cart->get_coupons() as $code => $coupon ) : ?>
		<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?> recurring-total">
			<th><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
			<td data-title="<?php echo esc_attr( wc_cart_totals_coupon_label( $coupon, false ) ); ?>">
				<?php echo wp_kses_post( ulx_cart_price_string( '-' . wc_price( $recurring_cart->get_coupon_discount_amount( $code, $recurring_cart->display_cart_ex_tax ) ), $recurring_cart ) ); ?>
			</td>
		</tr>
	<?php endforeach; ?>

	<?php if ( $recurring_cart->needs_shipping() && $recurring_cart->show_shipping() ) : ?>
		<tr class="shipping recurring-total">
			<th><?php esc_html_e( 'Recurring Shipping', 'ulixes-subscriptions' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Recurring Shipping', 'ulixes-subscriptions' ); ?>">
				<?php echo wp_kses_post( ulx_cart_price_string( wc_price( $recurring_cart->get_shipping_total() ), $recurring_cart ) ); ?>
			</td>
		</tr>
	<?php endif; ?>

	<?php foreach ( $recurring_cart->get_fees() as $fee ) : ?>
		<tr class="fee recurring-total">
			<th><?php echo esc_html( $fee->name ); ?></th>
			<td data-title="<?php echo esc_attr( $fee->name ); ?>"><?php wc_cart_totals_fee_html( $fee ); ?></td>
		</tr>
	<?php endforeach; ?>

	<tr class="order-total recurring-total">
		<th><?php esc_html_e( 'Recurring total', 'ulixes-subscriptions' ); ?></th>
		<td data-title="<?php esc_attr_e( 'Recurring total', 'ulixes-subscriptions' ); ?>">
			<strong><?php echo wp_kses_post( ulx_cart_price_string( $recurring_cart->get_total(), $recurring_cart ) ); ?></strong>
			<?php if ( ! empty( $recurring_cart->next_payment_date ) ) : ?>
				<div class="first-payment-date"><small>
					<?php
					/* translators: %s: first renewal date */
					printf( esc_html__( 'First renewal: %s', 'ulixes-subscriptions' ), esc_html( ulx_format_date( $recurring_cart->next_payment_date ) ) );
					?>
				</small></div>
			<?php endif; ?>
		</td>
	</tr>
<?php endforeach; ?>

