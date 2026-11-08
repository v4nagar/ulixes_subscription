<?php
/**
 * Subscription CRUD and query helper functions.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Get a subscription object.
 *
 * @param int|ULX_Subscription|WP_Post $the_subscription ID or object.
 * @return ULX_Subscription|false
 */
function ulx_get_subscription( $the_subscription ) {
	if ( $the_subscription instanceof ULX_Subscription ) {
		return $the_subscription;
	}
	if ( empty( $the_subscription ) ) {
		return false;
	}
	$order = wc_get_order( $the_subscription );
	if ( $order instanceof ULX_Subscription ) {
		return $order;
	}
	return false;
}

/**
 * Whether an object/ID is a subscription.
 *
 * @param mixed $subscription Subscription.
 * @return bool
 */
function ulx_is_subscription( $subscription ) {
	return (bool) ulx_get_subscription( $subscription );
}

/**
 * Create a new subscription object and persist it.
 *
 * @param array $args Args: status, order_id, customer_note, customer_id, billing_period, billing_interval, start_date, created_via, currency, prices_include_tax.
 * @return ULX_Subscription|WP_Error
 */
function ulx_create_subscription( $args = array() ) {
	$now  = current_time( 'mysql', true );
	$args = wp_parse_args(
		$args,
		array(
			'status'             => 'pending',
			'order_id'           => 0,
			'customer_note'      => null,
			'customer_id'        => null,
			'start_date'         => $now,
			'created_via'        => '',
			'order_version'      => WC_VERSION,
			'currency'           => get_woocommerce_currency(),
			'prices_include_tax' => get_option( 'woocommerce_prices_include_tax' ),
			'billing_period'     => '',
			'billing_interval'   => 1,
		)
	);

	if ( empty( $args['billing_period'] ) || ! array_key_exists( $args['billing_period'], ulx_get_subscription_period_strings() ) ) {
		return new WP_Error( 'ulx_invalid_billing_period', __( 'Invalid subscription billing period given.', 'ulixes-subscriptions' ) );
	}
	if ( ! is_numeric( $args['billing_interval'] ) || absint( $args['billing_interval'] ) <= 0 ) {
		return new WP_Error( 'ulx_invalid_billing_interval', __( 'Invalid subscription billing interval given.', 'ulixes-subscriptions' ) );
	}

	$parent_order = $args['order_id'] ? wc_get_order( $args['order_id'] ) : false;
	if ( $parent_order ) {
		if ( null === $args['customer_id'] ) {
			$args['customer_id'] = $parent_order->get_customer_id();
		}
		if ( null === $args['customer_note'] ) {
			$args['customer_note'] = $parent_order->get_customer_note();
		}
		if ( empty( $args['created_via'] ) ) {
			$args['created_via'] = $parent_order->get_created_via();
		}
		$args['currency']           = $parent_order->get_currency();
		$args['prices_include_tax'] = $parent_order->get_prices_include_tax() ? 'yes' : 'no';
	}

	$subscription = new ULX_Subscription();
	$subscription->set_status( 'wc-' === substr( $args['status'], 0, 3 ) ? substr( $args['status'], 3 ) : $args['status'] );
	$subscription->set_parent_id( absint( $args['order_id'] ) );
	if ( $args['customer_id'] ) {
		$subscription->set_customer_id( absint( $args['customer_id'] ) );
	}
	if ( null !== $args['customer_note'] ) {
		$subscription->set_customer_note( $args['customer_note'] );
	}
	$subscription->set_created_via( $args['created_via'] ?: 'checkout' );
	$subscription->set_currency( $args['currency'] );
	$subscription->set_prices_include_tax( 'yes' === $args['prices_include_tax'] || true === $args['prices_include_tax'] );
	$subscription->set_billing_period( $args['billing_period'] );
	$subscription->set_billing_interval( absint( $args['billing_interval'] ) );

	$start_ts = is_numeric( $args['start_date'] ) ? (int) $args['start_date'] : ulx_date_to_time( $args['start_date'] );
	if ( ! $start_ts ) {
		$start_ts = time();
	}
	$subscription->set_date_created( $start_ts );
	$subscription->update_meta_data( '_schedule_start', ulx_date_to_mysql( $start_ts ) );

	$subscription->save();

	do_action( 'ulx_created_subscription', $subscription );
	return $subscription;
}

/**
 * Query subscriptions with flexible filters.
 *
 * @param array $args Args: subscriptions_per_page, offset, paged, customer_id, product_id, variation_id, order_id, subscription_status, meta_query, orderby, order, return ('objects'|'ids').
 * @return array
 */
function ulx_get_subscriptions( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'subscriptions_per_page' => 10,
			'paged'                  => 1,
			'offset'                 => 0,
			'orderby'                => 'start_date',
			'order'                  => 'DESC',
			'customer_id'            => 0,
			'product_id'             => 0,
			'variation_id'           => 0,
			'order_id'               => 0,
			'subscription_status'    => array( 'any' ),
			'meta_query'             => array(),
			'return'                 => 'objects',
		)
	);

	$statuses = (array) $args['subscription_status'];
	if ( in_array( 'any', $statuses, true ) || empty( $statuses ) ) {
		$statuses = array_keys( ulx_get_subscription_statuses() );
	} else {
		$statuses = array_map( 'ulx_sanitize_subscription_status_key', $statuses );
	}

	$query_args = array(
		'type'     => 'shop_subscription',
		'status'   => $statuses,
		'limit'    => (int) $args['subscriptions_per_page'],
		'paged'    => (int) $args['paged'],
		'order'    => $args['order'],
		'orderby'  => 'start_date' === $args['orderby'] ? 'date' : $args['orderby'],
		'return'   => 'ids',
		'paginate' => false,
	);

	if ( $args['offset'] ) {
		$query_args['offset'] = (int) $args['offset'];
	}
	if ( ! empty( $args['customer_id'] ) ) {
		$query_args['customer_id'] = (int) $args['customer_id'];
	}
	if ( ! empty( $args['order_id'] ) ) {
		$query_args['parent'] = (int) $args['order_id'];
	}
	if ( ! empty( $args['meta_query'] ) ) {
		$query_args['meta_query'] = $args['meta_query']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	$ids = wc_get_orders( $query_args );

	// Filter by product_id / variation_id if requested.
	if ( ! empty( $args['product_id'] ) || ! empty( $args['variation_id'] ) ) {
		$target_id = ! empty( $args['variation_id'] ) ? (int) $args['variation_id'] : (int) $args['product_id'];
		$filtered  = array();
		foreach ( $ids as $id ) {
			$sub = ulx_get_subscription( $id );
			if ( $sub && $sub->has_product( $target_id ) ) {
				$filtered[] = $id;
			}
		}
		$ids = $filtered;
	}

	if ( 'ids' === $args['return'] ) {
		return $ids;
	}

	$subscriptions = array();
	foreach ( $ids as $id ) {
		$sub = ulx_get_subscription( $id );
		if ( $sub ) {
			$subscriptions[ $id ] = $sub;
		}
	}
	return apply_filters( 'ulx_got_subscriptions', $subscriptions, $args );
}

/**
 * Get all subscriptions for a user.
 *
 * @param int   $user_id User ID (0 = current user).
 * @param array $args    Extra query args.
 * @return ULX_Subscription[]
 */
function ulx_get_users_subscriptions( $user_id = 0, $args = array() ) {
	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}
	if ( empty( $user_id ) ) {
		return array();
	}
	$args = wp_parse_args(
		$args,
		array(
			'customer_id'            => $user_id,
			'subscriptions_per_page' => -1,
			'subscription_status'    => array( 'any' ),
		)
	);
	return apply_filters( 'ulx_users_subscriptions', ulx_get_subscriptions( $args ), $user_id );
}

/**
 * Get subscriptions containing a given product.
 *
 * @param int|array $product_ids Product IDs.
 * @param string    $fields      'ids' or 'subscriptions'.
 * @param array     $args        Filter args (subscription_statuses, user_id, limit).
 * @return array
 */
function ulx_get_product_subscriptions( $product_ids, $fields = 'ids', $args = array() ) {
	global $wpdb;
	$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );
	if ( empty( $product_ids ) ) {
		return array();
	}

	$args = wp_parse_args(
		$args,
		array(
			'subscription_statuses' => 'any',
			'user_id'               => 0,
			'limit'                 => -1,
		)
	);

	$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
	$sql          = "SELECT DISTINCT oi.order_id
		FROM {$wpdb->prefix}woocommerce_order_items oi
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
		WHERE oi.order_item_type = 'line_item'
		AND oim.meta_key IN ('_product_id', '_variation_id')
		AND oim.meta_value IN ({$placeholders})";

	$order_ids = $wpdb->get_col( $wpdb->prepare( $sql, $product_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

	$results = array();
	$statuses = (array) $args['subscription_statuses'];
	$any      = in_array( 'any', $statuses, true );
	$statuses = array_map(
		function ( $s ) {
			return 'wc-' === substr( $s, 0, 3 ) ? substr( $s, 3 ) : $s;
		},
		$statuses
	);

	foreach ( $order_ids as $id ) {
		$sub = ulx_get_subscription( $id );
		if ( ! $sub ) {
			continue;
		}
		if ( ! $any && ! $sub->has_status( $statuses ) ) {
			continue;
		}
		if ( $args['user_id'] && (int) $sub->get_user_id() !== (int) $args['user_id'] ) {
			continue;
		}
		$results[ $sub->get_id() ] = 'ids' === $fields ? $sub->get_id() : $sub;
		if ( $args['limit'] > 0 && count( $results ) >= $args['limit'] ) {
			break;
		}
	}
	return $results;
}

/**
 * Copy billing & shipping addresses from one order/subscription to another.
 *
 * @param WC_Order $from_order Source.
 * @param WC_Order $to_order   Destination.
 * @param string   $address_type 'all', 'billing', 'shipping'.
 * @return WC_Order
 */
function ulx_copy_order_address( $from_order, $to_order, $address_type = 'all' ) {
	if ( in_array( $address_type, array( 'all', 'billing' ), true ) ) {
		$to_order->set_props(
			array(
				'billing_first_name' => $from_order->get_billing_first_name(),
				'billing_last_name'  => $from_order->get_billing_last_name(),
				'billing_company'    => $from_order->get_billing_company(),
				'billing_address_1'  => $from_order->get_billing_address_1(),
				'billing_address_2'  => $from_order->get_billing_address_2(),
				'billing_city'       => $from_order->get_billing_city(),
				'billing_state'      => $from_order->get_billing_state(),
				'billing_postcode'   => $from_order->get_billing_postcode(),
				'billing_country'    => $from_order->get_billing_country(),
				'billing_email'      => $from_order->get_billing_email(),
				'billing_phone'      => $from_order->get_billing_phone(),
			)
		);
	}
	if ( in_array( $address_type, array( 'all', 'shipping' ), true ) ) {
		$to_order->set_props(
			array(
				'shipping_first_name' => $from_order->get_shipping_first_name(),
				'shipping_last_name'  => $from_order->get_shipping_last_name(),
				'shipping_company'    => $from_order->get_shipping_company(),
				'shipping_address_1'  => $from_order->get_shipping_address_1(),
				'shipping_address_2'  => $from_order->get_shipping_address_2(),
				'shipping_city'       => $from_order->get_shipping_city(),
				'shipping_state'      => $from_order->get_shipping_state(),
				'shipping_postcode'   => $from_order->get_shipping_postcode(),
				'shipping_country'    => $from_order->get_shipping_country(),
				'shipping_phone'      => method_exists( $from_order, 'get_shipping_phone' ) ? $from_order->get_shipping_phone() : '',
			)
		);
	}
	return apply_filters( 'ulx_copy_order_address', $to_order, $from_order, $address_type );
}

/**
 * Copy payment meta and custom order meta between orders/subscriptions.
 *
 * @param WC_Order $from_order Source.
 * @param WC_Order $to_order   Destination.
 * @param string   $type       'subscription' | 'renewal_order' | 'resubscribe_order'.
 */
function ulx_copy_order_meta( $from_order, $to_order, $type = 'subscription' ) {
	$to_order->set_payment_method( $from_order->get_payment_method() );
	$to_order->set_payment_method_title( $from_order->get_payment_method_title() );
	$to_order->set_currency( $from_order->get_currency() );
	$to_order->set_prices_include_tax( $from_order->get_prices_include_tax() );

	$excluded_meta = apply_filters(
		'ulx_Excluded_copy_meta_keys',
		array(
			'_edit_lock',
			'_edit_last',
			'_order_key',
			'_Schedule_start',
			'_schedule_start',
			'_schedule_trial_end',
			'_schedule_next_payment',
			'_schedule_cancelled',
			'_schedule_end',
			'_schedule_payment_retry',
			'_subscription_renewal',
			'_subscription_resubscribe',
			'_subscription_switch',
			'_subscription_switch_data',
			'_billing_period',
			'_billing_interval',
			'_trial_period',
			'_suspension_count',
			'_requires_manual_renewal',
			'_cancelled_email_sent',
			'_paid_date',
			'_date_paid',
			'_completed_date',
			'_date_completed',
			'_transaction_id',
			'_order_stock_reduced',
			'_download_permissions_granted',
			'_new_order_email_sent',
			'_recorded_sales',
			'_recorded_coupon_usage_counts',
			'_cart_hash',
		),
		$to_order,
		$from_order,
		$type
	);

	foreach ( $from_order->get_meta_data() as $meta ) {
		$data = $meta->get_data();
		if ( in_array( $data['key'], $excluded_meta, true ) ) {
			continue;
		}
		if ( apply_filters( 'ulx_copy_order_meta_key', true, $data['key'], $data['value'], $from_order, $to_order, $type ) ) {
			$to_order->update_meta_data( $data['key'], $data['value'] );
		}
	}

	do_action( 'ulx_copy_order_meta', $from_order, $to_order, $type );
}

/**
 * Copy line items (products, shipping, fees, taxes, coupons) from a subscription to a renewal/resubscribe order or vice versa.
 *
 * @param WC_Order $from_order Source.
 * @param WC_Order $to_order   Destination.
 * @param array    $item_types Types to copy.
 */
function ulx_copy_order_items( $from_order, $to_order, $item_types = array( 'line_item', 'fee', 'shipping', 'tax', 'coupon' ) ) {
	foreach ( $from_order->get_items( $item_types ) as $item_id => $item ) {
		// Skip one-time shipping on renewal orders.
		if ( $item instanceof WC_Order_Item_Shipping && $to_order instanceof WC_Order && ! ( $to_order instanceof ULX_Subscription ) ) {
			// Check if all products on the subscription have one-time shipping.
			$all_one_time = true;
			foreach ( $from_order->get_items( 'line_item' ) as $line_item ) {
				$product = $line_item->get_product();
				if ( $product && $product->needs_shipping() && ! ulx_product_needs_one_time_shipping( $product ) ) {
					$all_one_time = false;
					break;
				}
			}
			if ( $all_one_time ) {
				continue;
			}
		}

		$new_item = clone $item;
		$new_item->set_id( 0 );
		$new_item->set_order_id( $to_order->get_id() );
		$to_order->add_item( $new_item );
	}
}
