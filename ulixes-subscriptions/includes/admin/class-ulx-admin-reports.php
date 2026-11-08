<?php
/**
 * WooCommerce Subscriptions Admin Reports:
 * - Subscription Events by Date (New Subscriptions, Renewals, Switches, Cancellations, Ended, Current MRR)
 * - Upcoming Recurring Revenue
 * - Subscriptions by Product
 * - Subscriptions by Customer
 * - Failed Payment Retries
 *
 * Registered under `WooCommerce -> Reports -> Subscriptions`.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Admin_Reports
 */
class ULX_Admin_Reports {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_admin_reports', array( __CLASS__, 'register_reports' ) );
	}

	/**
	 * Add the "Subscriptions" tab and sub-reports to `WooCommerce -> Reports`.
	 *
	 * @param array $reports Reports.
	 * @return array
	 */
	public static function register_reports( $reports ) {
		$reports['subscriptions'] = array(
			'title'   => __( 'Subscriptions', 'ulixes-subscriptions' ),
			'reports' => array(
				'subscription_events_by_date' => array(
					'title'       => __( 'Subscription Events by Date', 'ulixes-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( __CLASS__, 'render_events_by_date' ),
				),
				'upcoming_recurring_revenue'  => array(
					'title'       => __( 'Upcoming Recurring Revenue', 'ulixes-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( __CLASS__, 'render_upcoming_revenue' ),
				),
				'subscriptions_by_product'    => array(
					'title'       => __( 'Subscriptions by Product', 'ulixes-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( __CLASS__, 'render_by_product' ),
				),
				'subscriptions_by_customer'   => array(
					'title'       => __( 'Subscriptions by Customer', 'ulixes-subscriptions' ),
					'description' => '',
					'hide_title'  => true,
					'callback'    => array( __CLASS__, 'render_by_customer' ),
				),
			),
		);
		return $reports;
	}

	/**
	 * 1. Subscription Events by Date & KPI summary cards.
	 */
	public static function render_events_by_date() {
		$all_subs    = ulx_get_subscriptions( array( 'subscriptions_per_page' => -1 ) );
		$active_cnt  = 0;
		$onhold_cnt  = 0;
		$cancel_cnt  = 0;
		$expired_cnt = 0;
		$mrr         = 0;
		$arr         = 0;

		foreach ( $all_subs as $sub ) {
			$st = $sub->get_status();
			if ( 'active' === $st ) {
				$active_cnt++;
				$days = ulx_estimate_days_in_period( $sub->get_billing_period(), $sub->get_billing_interval() );
				if ( $days > 0 ) {
					$daily = (float) $sub->get_total() / $days;
					$mrr  += $daily * 30.4375;
					$arr  += $daily * 365.25;
				}
			} elseif ( 'on-hold' === $st ) {
				$onhold_cnt++;
			} elseif ( in_array( $st, array( 'cancelled', 'pending-cancel' ), true ) ) {
				$cancel_cnt++;
			} elseif ( 'expired' === $st ) {
				$expired_cnt++;
			}
		}
		?>
		<div class="ulx-reports-wrap">
			<h2><?php esc_html_e( 'Subscription Overview & Recurring Revenue Metrics', 'ulixes-subscriptions' ); ?></h2>
			<div class="ulx-kpi-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:16px 0;">
				<div class="postbox" style="padding:16px;margin:0;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Active Subscriptions', 'ulixes-subscriptions' ); ?></h4>
					<div style="font-size:24px;font-weight:600;"><?php echo esc_html( $active_cnt ); ?></div>
				</div>
				<div class="postbox" style="padding:16px;margin:0;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Estimated MRR', 'ulixes-subscriptions' ); ?></h4>
					<div style="font-size:24px;font-weight:600;"><?php echo wp_kses_post( wc_price( $mrr ) ); ?></div>
				</div>
				<div class="postbox" style="padding:16px;margin:0;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Estimated ARR', 'ulixes-subscriptions' ); ?></h4>
					<div style="font-size:24px;font-weight:600;"><?php echo wp_kses_post( wc_price( $arr ) ); ?></div>
				</div>
				<div class="postbox" style="padding:16px;margin:0;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'On-Hold', 'ulixes-subscriptions' ); ?></h4>
					<div style="font-size:24px;font-weight:600;"><?php echo esc_html( $onhold_cnt ); ?></div>
				</div>
				<div class="postbox" style="padding:16px;margin:0;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Cancelled', 'ulixes-subscriptions' ); ?></h4>
					<div style="font-size:24px;font-weight:600;"><?php echo esc_html( $cancel_cnt ); ?></div>
				</div>
				<div class="postbox" style="padding:16px;margin:0;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Expired', 'ulixes-subscriptions' ); ?></h4>
					<div style="font-size:24px;font-weight:600;"><?php echo esc_html( $expired_cnt ); ?></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * 2. Upcoming Recurring Revenue (next 30 days).
	 */
	public static function render_upcoming_revenue() {
		$active_subs = ulx_get_subscriptions(
			array(
				'subscriptions_per_page' => -1,
				'subscription_status'    => array( 'active' ),
			)
		);
		$upcoming_30_total = 0;
		$upcoming_rows     = array();
		$cutoff            = time() + 30 * DAY_IN_SECONDS;

		foreach ( $active_subs as $sub ) {
			$next = $sub->get_time( 'next_payment' );
			if ( $next > time() && $next <= $cutoff ) {
				$upcoming_30_total += (float) $sub->get_total();
				$upcoming_rows[]    = $sub;
			}
		}
		?>
		<div class="ulx-reports-wrap">
			<h2><?php esc_html_e( 'Upcoming Recurring Revenue (Next 30 Days)', 'ulixes-subscriptions' ); ?>: <?php echo wp_kses_post( wc_price( $upcoming_30_total ) ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Subscription', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Next Payment Date', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Recurring Amount', 'ulixes-subscriptions' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $upcoming_rows ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No renewals scheduled in the next 30 days.', 'ulixes-subscriptions' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $upcoming_rows as $sub ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( $sub->get_edit_order_url() ); ?>">#<?php echo esc_html( $sub->get_order_number() ); ?></a></td>
								<td><?php echo esc_html( $sub->get_formatted_billing_full_name() ); ?></td>
								<td><?php echo esc_html( $sub->get_date_to_display( 'next_payment' ) ); ?></td>
								<td><?php echo wp_kses_post( $sub->get_formatted_order_total() ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * 3. Subscriptions by Product.
	 */
	public static function render_by_product() {
		$all_subs = ulx_get_subscriptions( array( 'subscriptions_per_page' => -1 ) );
		$products = array();

		foreach ( $all_subs as $sub ) {
			foreach ( $sub->get_items() as $item ) {
				$pid = $item->get_product_id();
				if ( ! isset( $products[ $pid ] ) ) {
					$products[ $pid ] = array(
						'name'    => $item->get_name(),
						'count'   => 0,
						'active'  => 0,
						'revenue' => 0,
					);
				}
				$products[ $pid ]['count']++;
				if ( $sub->has_status( 'active' ) ) {
					$products[ $pid ]['active']++;
				}
				$products[ $pid ]['revenue'] += (float) $item->get_total();
			}
		}
		?>
		<div class="ulx-reports-wrap">
			<h2><?php esc_html_e( 'Subscriptions by Product', 'ulixes-subscriptions' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Total Subscriptions', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Active Subscriptions', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Recurring Line Total', 'ulixes-subscriptions' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No subscription products purchased yet.', 'ulixes-subscriptions' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $products as $pid => $info ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>"><?php echo esc_html( $info['name'] ); ?></a></td>
								<td><?php echo esc_html( $info['count'] ); ?></td>
								<td><?php echo esc_html( $info['active'] ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $info['revenue'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * 4. Subscriptions by Customer.
	 */
	public static function render_by_customer() {
		$all_subs  = ulx_get_subscriptions( array( 'subscriptions_per_page' => -1 ) );
		$customers = array();

		foreach ( $all_subs as $sub ) {
			$uid = $sub->get_user_id();
			if ( ! isset( $customers[ $uid ] ) ) {
				$customers[ $uid ] = array(
					'name'   => $sub->get_formatted_billing_full_name() ?: ( '#' . $uid ),
					'email'  => $sub->get_billing_email(),
					'total'  => 0,
					'active' => 0,
					'ltv'    => 0,
				);
			}
			$customers[ $uid ]['total']++;
			if ( $sub->has_status( 'active' ) ) {
				$customers[ $uid ]['active']++;
			}
			foreach ( $sub->get_related_orders( 'all', 'any' ) as $o ) {
				if ( $o->is_paid() ) {
					$customers[ $uid ]['ltv'] += (float) $o->get_total();
				}
			}
		}
		?>
		<div class="ulx-reports-wrap">
			<h2><?php esc_html_e( 'Subscriptions by Customer', 'ulixes-subscriptions' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Customer', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Email', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Active Subscriptions', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Total Subscriptions', 'ulixes-subscriptions' ); ?></th>
						<th><?php esc_html_e( 'Lifetime Subscription Value', 'ulixes-subscriptions' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $customers ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No subscribers found.', 'ulixes-subscriptions' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $customers as $uid => $info ) : ?>
							<tr>
								<td><?php echo esc_html( $info['name'] ); ?></td>
								<td><?php echo esc_html( $info['email'] ); ?></td>
								<td><?php echo esc_html( $info['active'] ); ?></td>
								<td><?php echo esc_html( $info['total'] ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $info['ltv'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

