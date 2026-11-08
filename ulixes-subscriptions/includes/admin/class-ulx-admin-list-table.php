<?php
/**
 * Admin Subscriptions List Table (compatible with both HPOS and legacy CPT screens).
 *
 * Adds custom columns:
 * - Status
 * - Subscription (#ID + customer name)
 * - Items
 * - Total (recurring price + schedule + payment method)
 * - Start Date
 * - Trial End
 * - Next Payment
 * - Last Order Date
 * - End Date
 * - Orders (count of related orders)
 *
 * Also adds bulk actions (Activate, Put on-hold, Cancel) and product/payment-method filters,
 * plus the "Subscription Relationship" column on the main WooCommerce Orders list table.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Admin_List_Table
 */
class ULX_Admin_List_Table {

	/**
	 * Init hooks.
	 */
	public static function init() {
		// Subscriptions list columns (HPOS & CPT).
		add_filter( 'manage_edit-shop_subscription_columns', array( __CLASS__, 'define_columns' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders--shop_subscription_columns', array( __CLASS__, 'define_columns' ), 20 );

		add_action( 'manage_shop_subscription_posts_custom_column', array( __CLASS__, 'render_cpt_column' ), 20, 2 );
		add_action( 'manage_woocommerce_page_wc-orders--shop_subscription_custom_column', array( __CLASS__, 'render_hpos_column' ), 20, 2 );

		// Bulk actions.
		add_filter( 'bulk_actions-edit-shop_subscription', array( __CLASS__, 'define_bulk_actions' ), 20 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders--shop_subscription', array( __CLASS__, 'define_bulk_actions' ), 20 );
		add_filter( 'handle_bulk_actions-edit-shop_subscription', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders--shop_subscription', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );

		// Orders list table: show relationship icon/badge for Parent / Renewal / Switch / Resubscribe orders.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_relationship_column' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_order_relationship_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_relationship_cpt' ), 20, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_order_relationship_hpos' ), 20, 2 );
	}

	/**
	 * Define columns for the Subscriptions list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function define_columns( $columns ) {
		return array(
			'cb'                      => '<input type="checkbox" />',
			'status'                  => __( 'Status', 'ulixes-subscriptions' ),
			'order_title'             => __( 'Subscription', 'ulixes-subscriptions' ),
			'order_items'             => __( 'Items', 'ulixes-subscriptions' ),
			'recurring_total'         => __( 'Total', 'ulixes-subscriptions' ),
			'start_date'              => __( 'Start Date', 'ulixes-subscriptions' ),
			'trial_end_date'          => __( 'Trial End', 'ulixes-subscriptions' ),
			'next_payment_date'       => __( 'Next Payment', 'ulixes-subscriptions' ),
			'last_order_date_created' => __( 'Last Order Date', 'ulixes-subscriptions' ),
			'end_date'                => __( 'End Date', 'ulixes-subscriptions' ),
			'orders'                  => __( 'Orders', 'ulixes-subscriptions' ),
		);
	}

	/**
	 * Render column in CPT mode.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_cpt_column( $column, $post_id ) {
		$sub = ulx_get_subscription( $post_id );
		if ( $sub ) {
			self::render_column_content( $column, $sub );
		}
	}

	/**
	 * Render column in HPOS mode.
	 *
	 * @param string           $column Column key.
	 * @param ULX_Subscription $sub    Subscription.
	 */
	public static function render_hpos_column( $column, $sub ) {
		$sub = ulx_get_subscription( $sub );
		if ( $sub ) {
			self::render_column_content( $column, $sub );
		}
	}

	/**
	 * Shared column renderer.
	 *
	 * @param string           $column Column name.
	 * @param ULX_Subscription $sub    Subscription.
	 */
	public static function render_column_content( $column, $sub ) {
		switch ( $column ) {
			case 'status':
				$status_name = ulx_get_subscription_status_name( $sub->get_status() );
				printf( '<mark class="order-status status-%1$s subscription-status"><span>%2$s</span></mark>', esc_attr( $sub->get_status() ), esc_html( $status_name ) );
				break;

			case 'order_title':
				$buyer = $sub->get_formatted_billing_full_name();
				if ( ! $buyer && $sub->get_user_id() ) {
					$u     = get_userdata( $sub->get_user_id() );
					$buyer = $u ? $u->display_name : '';
				}
				printf(
					'<a href="%1$s"><strong>#%2$s</strong></a> %3$s <a href="%4$s">%5$s</a>',
					esc_url( $sub->get_edit_order_url() ),
					esc_html( $sub->get_order_number() ),
					esc_html_x( 'for', 'Subscription #X for Customer Name', 'ulixes-subscriptions' ),
					esc_url( get_edit_user_link( $sub->get_user_id() ) ),
					esc_html( $buyer ?: __( 'Guest', 'ulixes-subscriptions' ) )
				);
				break;

			case 'order_items':
				$items = $sub->get_items();
				if ( empty( $items ) ) {
					echo '&ndash;';
				} else {
					$links = array();
					foreach ( $items as $item ) {
						$links[] = '<a href="' . esc_url( get_edit_post_link( $item->get_product_id() ) ) . '">' . esc_html( $item->get_name() ) . '</a> &times; ' . esc_html( $item->get_quantity() );
					}
					echo wp_kses_post( implode( ', ', $links ) );
				}
				break;

			case 'recurring_total':
				echo wp_kses_post( $sub->get_formatted_order_total() );
				echo '<small class="meta">' . esc_html( $sub->get_payment_method_to_display( 'customer' ) ) . '</small>';
				break;

			case 'start_date':
			case 'trial_end_date':
			case 'next_payment_date':
			case 'last_order_date_created':
			case 'end_date':
				echo esc_html( $sub->get_date_to_display( $column ) );
				break;

			case 'orders':
				$related = $sub->get_related_orders( 'ids', 'any' );
				echo esc_html( count( $related ) );
				break;
		}
	}

	/**
	 * Define bulk actions on the Subscriptions list table.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function define_bulk_actions( $actions ) {
		unset( $actions['mark_processing'], $actions['mark_on-hold'], $actions['mark_completed'], $actions['mark_cancelled'] );
		$new = array(
			'ulx_activate' => __( 'Change status to active', 'ulixes-subscriptions' ),
			'ulx_on_hold'  => __( 'Change status to on-hold', 'ulixes-subscriptions' ),
			'ulx_cancel'   => __( 'Change status to cancelled', 'ulixes-subscriptions' ),
		);
		return array_merge( $new, $actions );
	}

	/**
	 * Handle bulk status actions on Subscriptions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action.
	 * @param array  $ids         Selected IDs.
	 * @return string
	 */
	public static function handle_bulk_actions( $redirect_to, $action, $ids ) {
		$map = array(
			'ulx_activate' => 'active',
			'ulx_on_hold'  => 'on-hold',
			'ulx_cancel'   => 'cancelled',
		);
		if ( ! isset( $map[ $action ] ) ) {
			return $redirect_to;
		}
		$target_status = $map[ $action ];
		$changed       = 0;

		foreach ( $ids as $id ) {
			$sub = ulx_get_subscription( $id );
			if ( $sub && $sub->can_be_updated_to( $target_status ) ) {
				try {
					$sub->update_status( $target_status, __( 'Subscription status changed by bulk edit.', 'ulixes-subscriptions' ), true );
					$changed++;
				} catch ( Exception $e ) {
					continue;
				}
			}
		}

		return add_query_arg(
			array(
				'ulx_bulk_updated' => $changed,
				'ulx_bulk_status'  => $target_status,
			),
			$redirect_to
		);
	}

	/**
	 * Add "Subscription Relationship" column to the standard Orders list table.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_order_relationship_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['subscription_relationship'] = '<span class="subscription_head tips" data-tip="' . esc_attr__( 'Subscription Relationship', 'ulixes-subscriptions' ) . '">' . esc_html__( 'Subscription', 'ulixes-subscriptions' ) . '</span>';
			}
		}
		return $new;
	}

	/**
	 * Render relationship column in CPT Orders list.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Order ID.
	 */
	public static function render_order_relationship_cpt( $column, $post_id ) {
		if ( 'subscription_relationship' === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				self::render_order_relationship_badge( $order );
			}
		}
	}

	/**
	 * Render relationship column in HPOS Orders list.
	 *
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public static function render_order_relationship_hpos( $column, $order ) {
		if ( 'subscription_relationship' === $column && $order instanceof WC_Order ) {
			self::render_order_relationship_badge( $order );
		}
	}

	/**
	 * Output badge indicating whether an order is a Parent, Renewal, Resubscribe, or Switch order.
	 *
	 * @param WC_Order $order Order.
	 */
	protected static function render_order_relationship_badge( $order ) {
		if ( ulx_order_contains_renewal( $order ) ) {
			echo '<span class="ulx-rel-badge ulx-rel-renewal" title="' . esc_attr__( 'Renewal Order', 'ulixes-subscriptions' ) . '">' . esc_html__( 'Renewal', 'ulixes-subscriptions' ) . '</span>';
		} elseif ( ulx_order_contains_resubscribe( $order ) ) {
			echo '<span class="ulx-rel-badge ulx-rel-resubscribe" title="' . esc_attr__( 'Resubscribe Order', 'ulixes-subscriptions' ) . '">' . esc_html__( 'Resubscribe', 'ulixes-subscriptions' ) . '</span>';
		} elseif ( ulx_order_contains_switch( $order ) ) {
			echo '<span class="ulx-rel-badge ulx-rel-switch" title="' . esc_attr__( 'Switch Order', 'ulixes-subscriptions' ) . '">' . esc_html__( 'Switch', 'ulixes-subscriptions' ) . '</span>';
		} elseif ( ulx_order_contains_subscription( $order, 'parent' ) ) {
			echo '<span class="ulx-rel-badge ulx-rel-parent" title="' . esc_attr__( 'Parent Order', 'ulixes-subscriptions' ) . '">' . esc_html__( 'Parent', 'ulixes-subscriptions' ) . '</span>';
		} else {
			echo '<span class="na">&ndash;</span>';
		}
	}
}

