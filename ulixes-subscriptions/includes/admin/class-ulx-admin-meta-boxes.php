<?php
/**
 * Admin Meta Boxes on the Subscription & Order Edit Screens.
 *
 * Adds:
 * 1. "Billing Schedule" meta box on `shop_subscription` (interval, period, start date, trial end, next payment, end date, timezone note, gateway token fields).
 * 2. "Related Orders" meta box on both `shop_subscription` and `shop_order` showing all linked Parent, Renewal, Resubscribe, and Switch orders/subscriptions.
 * 3. Subscription Order Actions in the "Order Actions" dropdown on `shop_subscription`:
 *    - Process renewal (charge gateway or generate manual renewal order)
 *    - Create pending renewal order
 *    - Retry failed renewal payment
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Admin_Meta_Boxes
 */
class ULX_Admin_Meta_Boxes {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 25 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_subscription_schedule_meta' ), 40, 2 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_subscription_actions' ), 10, 2 );

		add_action( 'woocommerce_order_action_ulx_process_renewal', array( __CLASS__, 'action_process_renewal' ) );
		add_action( 'woocommerce_order_action_ulx_create_pending_renewal', array( __CLASS__, 'action_create_pending_renewal' ) );
		add_action( 'woocommerce_order_action_ulx_retry_renewal_payment', array( __CLASS__, 'action_retry_renewal_payment' ) );
	}

	/**
	 * Register meta boxes on Subscription and Order screens (both HPOS and CPT screen IDs).
	 */
	public static function add_meta_boxes() {
		$sub_screens   = array( 'shop_subscription', 'woocommerce_page_wc-orders--shop_subscription' );
		$order_screens = array( 'shop_order', 'woocommerce_page_wc-orders', 'shop_subscription', 'woocommerce_page_wc-orders--shop_subscription' );

		foreach ( $sub_screens as $screen ) {
			add_meta_box(
				'ulx-subscription-schedule',
				__( 'Billing Schedule', 'ulixes-subscriptions' ),
				array( __CLASS__, 'render_schedule_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}

		foreach ( $order_screens as $screen ) {
			add_meta_box(
				'ulx-related-orders',
				__( 'Related Orders', 'ulixes-subscriptions' ),
				array( __CLASS__, 'render_related_orders_meta_box' ),
				$screen,
				'normal',
				'low'
			);
		}
	}

	/**
	 * Render the Billing Schedule meta box on the Subscription edit screen.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or Order object.
	 */
	public static function render_schedule_meta_box( $post_or_order ) {
		$sub = $post_or_order instanceof ULX_Subscription ? $post_or_order : ulx_get_subscription( $post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order );
		if ( ! $sub ) {
			return;
		}

		wp_nonce_field( 'ulx_save_schedule_meta', '_ulx_schedule_nonce' );
		$interval = $sub->get_billing_interval();
		$period   = $sub->get_billing_period() ?: 'month';
		?>
		<div class="ulx-metabox-schedule">
			<p class="form-field">
				<label><strong><?php esc_html_e( 'Recurring:', 'ulixes-subscriptions' ); ?></strong></label><br/>
				<select name="_billing_interval" style="width:48%;">
					<?php foreach ( ulx_get_subscription_period_interval_strings() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $interval ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="_billing_period" style="width:48%;">
					<?php foreach ( ulx_get_subscription_period_strings() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $period ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<?php
			$date_fields = array(
				'start'        => __( 'Start Date (UTC)', 'ulixes-subscriptions' ),
				'trial_end'    => __( 'Trial End (UTC)', 'ulixes-subscriptions' ),
				'next_payment' => __( 'Next Payment (UTC)', 'ulixes-subscriptions' ),
				'end'          => __( 'End Date (UTC)', 'ulixes-subscriptions' ),
			);
			foreach ( $date_fields as $type => $label ) :
				$val = $sub->get_date( $type, 'gmt' );
				$val = $val ? $val : '';
				?>
				<p class="form-field">
					<label for="ulx_schedule_<?php echo esc_attr( $type ); ?>"><strong><?php echo esc_html( $label ); ?>:</strong></label><br/>
					<input type="text" class="widefat" id="ulx_schedule_<?php echo esc_attr( $type ); ?>" name="ulx_schedule_dates[<?php echo esc_attr( $type ); ?>]" value="<?php echo esc_attr( $val ); ?>" placeholder="YYYY-MM-DD HH:MM:SS" />
				</p>
			<?php endforeach; ?>

			<p class="form-field">
				<label>
					<input type="checkbox" name="_requires_manual_renewal" value="true" <?php checked( $sub->get_requires_manual_renewal(), true ); ?> />
					<?php esc_html_e( 'Force Manual Renewal', 'ulixes-subscriptions' ); ?>
				</label>
			</p>

			<?php
			$payment_meta = ULX_Payment_Gateways::get_payment_meta( $sub );
			$gw_id        = $sub->get_payment_method();
			if ( ! empty( $payment_meta[ $gw_id ]['post_meta'] ) ) :
				?>
				<hr/>
				<p><strong><?php esc_html_e( 'Gateway Payment Tokens:', 'ulixes-subscriptions' ); ?></strong></p>
				<?php foreach ( $payment_meta[ $gw_id ]['post_meta'] as $meta_key => $info ) : ?>
					<p class="form-field">
						<label><?php echo esc_html( $info['label'] ); ?>:</label><br/>
						<input type="text" class="widefat" name="ulx_gateway_meta[<?php echo esc_attr( $meta_key ); ?>]" value="<?php echo esc_attr( $info['value'] ); ?>" />
					</p>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save Billing Schedule & Gateway Token meta when a subscription is saved in admin.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post or Order.
	 */
	public static function save_subscription_schedule_meta( $post_id, $post = null ) {
		if ( empty( $_POST['_ulx_schedule_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ulx_schedule_nonce'] ) ), 'ulx_save_schedule_meta' ) ) {
			return;
		}
		$sub = ulx_get_subscription( $post_id );
		if ( ! $sub ) {
			return;
		}

		if ( isset( $_POST['_billing_interval'] ) ) {
			$sub->set_billing_interval( absint( $_POST['_billing_interval'] ) );
		}
		if ( isset( $_POST['_billing_period'] ) ) {
			$sub->set_billing_period( sanitize_text_field( wp_unslash( $_POST['_billing_period'] ) ) );
		}

		$sub->set_requires_manual_renewal( ! empty( $_POST['_requires_manual_renewal'] ) );

		if ( ! empty( $_POST['ulx_gateway_meta'] ) && is_array( $_POST['ulx_gateway_meta'] ) ) {
			foreach ( wp_unslash( $_POST['ulx_gateway_meta'] ) as $k => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$sub->update_meta_data( sanitize_key( $k ), sanitize_text_field( $v ) );
			}
		}

		if ( ! empty( $_POST['ulx_schedule_dates'] ) && is_array( $_POST['ulx_schedule_dates'] ) ) {
			$dates = array();
			foreach ( wp_unslash( $_POST['ulx_schedule_dates'] ) as $type => $date_str ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$type     = sanitize_key( $type );
				$date_str = trim( sanitize_text_field( $date_str ) );
				$dates[ $type ] = '' === $date_str ? 0 : $date_str;
			}
			try {
				$sub->update_dates( $dates, 'gmt' );
			} catch ( Exception $e ) {
				WC_Admin_Meta_Boxes::add_error( $e->getMessage() );
			}
		}

		$sub->save();
	}

	/**
	 * Render the Related Orders meta box on both Subscriptions and Orders.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or Order.
	 */
	public static function render_related_orders_meta_box( $post_or_order ) {
		$id    = $post_or_order instanceof WC_Order ? $post_or_order->get_id() : ( $post_or_order instanceof WP_Post ? $post_or_order->ID : absint( $post_or_order ) );
		$order = wc_get_order( $id );
		if ( ! $order ) {
			return;
		}

		$rows = array();

		if ( $order instanceof ULX_Subscription ) {
			$sub = $order;
			if ( $sub->get_parent_id() ) {
				$p = $sub->get_parent();
				if ( $p ) {
					$rows[] = array(
						'obj'  => $p,
						'type' => __( 'Parent Order', 'ulixes-subscriptions' ),
					);
				}
			}
			foreach ( array(
				'renewal'     => __( 'Renewal Order', 'ulixes-subscriptions' ),
				'switch'      => __( 'Switch Order', 'ulixes-subscriptions' ),
				'resubscribe' => __( 'Resubscribe Order', 'ulixes-subscriptions' ),
			) as $rel_key => $label ) {
				foreach ( $sub->get_related_orders( 'all', $rel_key ) as $rel_order ) {
					$rows[] = array(
						'obj'  => $rel_order,
						'type' => $label,
					);
				}
			}
		} else {
			$subs = ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
			foreach ( $subs as $sub ) {
				$rows[] = array(
					'obj'  => $sub,
					'type' => __( 'Subscription', 'ulixes-subscriptions' ),
				);
				if ( $sub->get_parent_id() && (int) $sub->get_parent_id() !== (int) $order->get_id() ) {
					$p = $sub->get_parent();
					if ( $p ) {
						$rows[] = array(
							'obj'  => $p,
							'type' => __( 'Parent Order', 'ulixes-subscriptions' ),
						);
					}
				}
				foreach ( $sub->get_related_orders( 'all', 'renewal' ) as $ren ) {
					if ( (int) $ren->get_id() !== (int) $order->get_id() ) {
						$rows[] = array(
							'obj'  => $ren,
							'type' => __( 'Renewal Order', 'ulixes-subscriptions' ),
						);
					}
				}
			}
		}

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No related orders or subscriptions found.', 'ulixes-subscriptions' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped ulx-related-orders-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order / Subscription Number', 'ulixes-subscriptions' ); ?></th>
					<th><?php esc_html_e( 'Relationship', 'ulixes-subscriptions' ); ?></th>
					<th><?php esc_html_e( 'Date', 'ulixes-subscriptions' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ulixes-subscriptions' ); ?></th>
					<th><?php esc_html_e( 'Total', 'ulixes-subscriptions' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $row ) :
					$o = $row['obj'];
					$status_label = $o instanceof ULX_Subscription ? ulx_get_subscription_status_name( $o->get_status() ) : wc_get_order_status_name( $o->get_status() );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>"><strong>#<?php echo esc_html( $o->get_order_number() ); ?></strong></a></td>
						<td><?php echo esc_html( $row['type'] ); ?></td>
						<td><?php echo esc_html( $o->get_date_created() ? wc_format_datetime( $o->get_date_created() ) : '-' ); ?></td>
						<td><mark class="order-status status-<?php echo esc_attr( $o->get_status() ); ?>"><span><?php echo esc_html( $status_label ); ?></span></mark></td>
						<td><?php echo wp_kses_post( $o->get_formatted_order_total() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Add Subscription-specific actions to the "Order Actions" dropdown on the Subscription edit screen.
	 *
	 * @param array         $actions Actions.
	 * @param WC_Order|null $order   Order.
	 * @return array
	 */
	public static function add_subscription_actions( $actions, $order = null ) {
		if ( ! $order ) {
			global $theorder;
			$order = $theorder;
		}
		if ( ! ( $order instanceof ULX_Subscription ) ) {
			return $actions;
		}

		if ( $order->has_status( array( 'active', 'on-hold' ) ) ) {
			$actions['ulx_process_renewal']        = __( 'Process renewal', 'ulixes-subscriptions' );
			$actions['ulx_create_pending_renewal'] = __( 'Create pending renewal order', 'ulixes-subscriptions' );
		}

		$last_renewal = $order->get_last_order( 'all', 'renewal' );
		if ( $last_renewal && $last_renewal->has_status( array( 'failed', 'pending', 'on-hold' ) ) && ! $order->is_manual() ) {
			$actions['ulx_retry_renewal_payment'] = __( 'Retry renewal payment', 'ulixes-subscriptions' );
		}

		return $actions;
	}

	/**
	 * Order Action: Process renewal now (creates renewal order + triggers gateway if automatic).
	 *
	 * @param WC_Order $order Subscription.
	 */
	public static function action_process_renewal( $order ) {
		$sub = ulx_get_subscription( $order );
		if ( ! $sub ) {
			return;
		}
		$sub->add_order_note( __( 'Process renewal action requested by admin.', 'ulixes-subscriptions' ), 0, true );
		ULX_Manager::prepare_renewal( $sub->get_id() );
		ULX_Manager::maybe_process_gateway_renewal( $sub->get_id() );
	}

	/**
	 * Order Action: Create a pending renewal order without charging the gateway.
	 *
	 * @param WC_Order $order Subscription.
	 */
	public static function action_create_pending_renewal( $order ) {
		$sub = ulx_get_subscription( $order );
		if ( ! $sub ) {
			return;
		}
		$renewal = ulx_create_renewal_order( $sub );
		if ( ! is_wp_error( $renewal ) ) {
			$sub->update_status( 'on-hold', sprintf( /* translators: %s: order number */ __( 'Pending renewal order #%s created by admin.', 'ulixes-subscriptions' ), $renewal->get_order_number() ), true );
		}
	}

	/**
	 * Order Action: Retry failed renewal payment via gateway.
	 *
	 * @param WC_Order $order Subscription.
	 */
	public static function action_retry_renewal_payment( $order ) {
		$sub = ulx_get_subscription( $order );
		if ( ! $sub ) {
			return;
		}
		$last_renewal = $sub->get_last_order( 'all', 'renewal' );
		if ( $last_renewal ) {
			$sub->add_order_note( __( 'Admin triggered manual retry of renewal payment.', 'ulixes-subscriptions' ), 0, true );
			ULX_Payment_Gateways::trigger_gateway_renewal_payment_hook( $last_renewal );
		}
	}
}

