<?php
/**
 * REST API Controller for Subscriptions (`/wp-json/wc/v3/subscriptions`).
 *
 * Supports:
 * - `GET    /wc/v3/subscriptions`
 * - `POST   /wc/v3/subscriptions`
 * - `GET    /wc/v3/subscriptions/<id>`
 * - `PUT    /wc/v3/subscriptions/<id>`
 * - `DELETE /wc/v3/subscriptions/<id>`
 * - `GET    /wc/v3/subscriptions/<id>/orders`
 * - `GET    /wc/v3/subscriptions/statuses`
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_REST_Subscriptions_Controller
 */
class ULX_REST_Subscriptions_Controller {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
	}

	/**
	 * Register REST routes under `wc/v3/subscriptions` and `wc/v1/subscriptions`.
	 */
	public static function register_routes() {
		if ( ! class_exists( 'WC_REST_Orders_Controller' ) ) {
			return;
		}
		foreach ( array( 'wc/v3', 'wc/v2', 'wc/v1' ) as $namespace ) {
			register_rest_route(
				$namespace,
				'/subscriptions',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_items' ),
						'permission_callback' => array( __CLASS__, 'permissions_read' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'create_item' ),
						'permission_callback' => array( __CLASS__, 'permissions_write' ),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/subscriptions/statuses',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_statuses' ),
						'permission_callback' => array( __CLASS__, 'permissions_read' ),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/subscriptions/(?P<id>[\d]+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_item' ),
						'permission_callback' => array( __CLASS__, 'permissions_read' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( __CLASS__, 'update_item' ),
						'permission_callback' => array( __CLASS__, 'permissions_write' ),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( __CLASS__, 'delete_item' ),
						'permission_callback' => array( __CLASS__, 'permissions_write' ),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/subscriptions/(?P<id>[\d]+)/orders',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_subscription_orders' ),
						'permission_callback' => array( __CLASS__, 'permissions_read' ),
					),
				)
			);
		}
	}

	/**
	 * Read permission check.
	 *
	 * @return bool
	 */
	public static function permissions_read() {
		return wc_rest_check_post_permissions( 'shop_order', 'read' );
	}

	/**
	 * Write permission check.
	 *
	 * @return bool
	 */
	public static function permissions_write() {
		return wc_rest_check_post_permissions( 'shop_order', 'create' );
	}

	/**
	 * Format a subscription for REST response.
	 *
	 * @param ULX_Subscription $sub Subscription.
	 * @return array
	 */
	public static function prepare_subscription_data( $sub ) {
		$line_items = array();
		foreach ( $sub->get_items() as $item_id => $item ) {
			$line_items[] = array(
				'id'           => $item_id,
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $item->get_quantity(),
				'subtotal'     => wc_format_decimal( $item->get_subtotal(), 2 ),
				'total'        => wc_format_decimal( $item->get_total(), 2 ),
			);
		}

		return array(
			'id'                        => $sub->get_id(),
			'parent_id'                 => $sub->get_parent_id(),
			'status'                    => $sub->get_status(),
			'currency'                  => $sub->get_currency(),
			'version'                   => $sub->get_version(),
			'prices_include_tax'        => $sub->get_prices_include_tax(),
			'date_created_gmt'          => $sub->get_date( 'date_created' ) ?: null,
			'date_modified_gmt'         => $sub->get_date( 'date_modified' ) ?: null,
			'customer_id'               => $sub->get_customer_id(),
			'discount_total'            => wc_format_decimal( $sub->get_discount_total(), 2 ),
			'shipping_total'            => wc_format_decimal( $sub->get_shipping_total(), 2 ),
			'total'                     => wc_format_decimal( $sub->get_total(), 2 ),
			'total_tax'                 => wc_format_decimal( $sub->get_total_tax(), 2 ),
			'billing_period'            => $sub->get_billing_period(),
			'billing_interval'          => $sub->get_billing_interval(),
			'start_date_gmt'            => $sub->get_date( 'start' ) ?: '',
			'trial_end_date_gmt'        => $sub->get_date( 'trial_end' ) ?: '',
			'next_payment_date_gmt'     => $sub->get_date( 'next_payment' ) ?: '',
			'last_payment_date_gmt'     => $sub->get_date( 'last_order_date_created' ) ?: '',
			'cancelled_date_gmt'        => $sub->get_date( 'cancelled' ) ?: '',
			'end_date_gmt'              => $sub->get_date( 'end' ) ?: '',
			'payment_retry_date_gmt'    => $sub->get_date( 'payment_retry' ) ?: '',
			'is_manual'                 => $sub->is_manual(),
			'payment_method'            => $sub->get_payment_method(),
			'payment_method_title'      => $sub->get_payment_method_title(),
			'billing'                   => $sub->get_address( 'billing' ),
			'shipping'                  => $sub->get_address( 'shipping' ),
			'line_items'                => $line_items,
			'related_orders'            => $sub->get_related_orders( 'ids', 'any' ),
		);
	}

	/**
	 * `GET /wc/v3/subscriptions`
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( $request ) {
		$per_page = $request->get_param( 'per_page' ) ? absint( $request->get_param( 'per_page' ) ) : 10;
		$page     = $request->get_param( 'page' ) ? absint( $request->get_param( 'page' ) ) : 1;
		$status   = $request->get_param( 'status' ) ? (array) $request->get_param( 'status' ) : array( 'any' );
		$customer = $request->get_param( 'customer' ) ? absint( $request->get_param( 'customer' ) ) : 0;

		$subs = ulx_get_subscriptions(
			array(
				'subscriptions_per_page' => $per_page,
				'paged'                  => $page,
				'subscription_status'    => $status,
				'customer_id'            => $customer,
			)
		);

		$data = array();
		foreach ( $subs as $sub ) {
			$data[] = self::prepare_subscription_data( $sub );
		}
		return rest_ensure_response( $data );
	}

	/**
	 * `GET /wc/v3/subscriptions/<id>`
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( $request ) {
		$sub = ulx_get_subscription( absint( $request['id'] ) );
		if ( ! $sub ) {
			return new WP_Error( 'ulx_rest_invalid_id', __( 'Invalid subscription ID.', 'ulixes-subscriptions' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::prepare_subscription_data( $sub ) );
	}

	/**
	 * `POST /wc/v3/subscriptions`
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( $request ) {
		$parent_id = absint( $request->get_param( 'parent_id' ) );
		$sub       = ulx_create_subscription(
			array(
				'status'           => $request->get_param( 'status' ) ?: 'pending',
				'order_id'         => $parent_id,
				'customer_id'      => absint( $request->get_param( 'customer_id' ) ),
				'billing_period'   => $request->get_param( 'billing_period' ) ?: 'month',
				'billing_interval' => $request->get_param( 'billing_interval' ) ? absint( $request->get_param( 'billing_interval' ) ) : 1,
				'start_date'       => $request->get_param( 'start_date_gmt' ) ?: current_time( 'mysql', true ),
				'created_via'      => 'rest-api',
			)
		);

		if ( is_wp_error( $sub ) ) {
			return $sub;
		}

		if ( $parent_id ) {
			$parent_order = wc_get_order( $parent_id );
			if ( $parent_order ) {
				ulx_copy_order_address( $parent_order, $sub );
				if ( ! $request->get_param( 'payment_method' ) ) {
					$sub->set_payment_method( $parent_order->get_payment_method() );
					$sub->set_payment_method_title( $parent_order->get_payment_method_title() );
				}
			}
		}

		self::apply_request_fields( $sub, $request );

		if ( ! $sub->get_date( 'next_payment' ) ) {
			$calc_next = $sub->calculate_date( 'next_payment' );
			if ( $calc_next ) {
				$sub->update_dates( array( 'next_payment' => $calc_next ) );
				$sub->save();
			}
		}

		return rest_ensure_response( self::prepare_subscription_data( $sub ) );
	}

	/**
	 * `PUT /wc/v3/subscriptions/<id>`
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( $request ) {
		$sub = ulx_get_subscription( absint( $request['id'] ) );
		if ( ! $sub ) {
			return new WP_Error( 'ulx_rest_invalid_id', __( 'Invalid subscription ID.', 'ulixes-subscriptions' ), array( 'status' => 404 ) );
		}
		self::apply_request_fields( $sub, $request );
		return rest_ensure_response( self::prepare_subscription_data( $sub ) );
	}

	/**
	 * Apply REST request fields to a subscription.
	 *
	 * @param ULX_Subscription $sub     Subscription.
	 * @param WP_REST_Request  $request Request.
	 */
	protected static function apply_request_fields( $sub, $request ) {
		if ( $request->get_param( 'billing_period' ) ) {
			$sub->set_billing_period( sanitize_text_field( $request->get_param( 'billing_period' ) ) );
		}
		if ( $request->get_param( 'billing_interval' ) ) {
			$sub->set_billing_interval( absint( $request->get_param( 'billing_interval' ) ) );
		}
		if ( $request->get_param( 'payment_method' ) !== null ) {
			$sub->set_payment_method( sanitize_text_field( $request->get_param( 'payment_method' ) ) );
		}
		if ( is_array( $request->get_param( 'billing' ) ) ) {
			$sub->set_address( $request->get_param( 'billing' ), 'billing' );
		}
		if ( is_array( $request->get_param( 'shipping' ) ) ) {
			$sub->set_address( $request->get_param( 'shipping' ), 'shipping' );
		}
		if ( is_array( $request->get_param( 'line_items' ) ) ) {
			foreach ( $request->get_param( 'line_items' ) as $li ) {
				if ( ! empty( $li['product_id'] ) ) {
					$prod = wc_get_product( ! empty( $li['variation_id'] ) ? $li['variation_id'] : $li['product_id'] );
					if ( $prod ) {
						$sub->add_product( $prod, isset( $li['quantity'] ) ? absint( $li['quantity'] ) : 1 );
					}
				}
			}
			$sub->calculate_totals();
		}

		$dates = array();
		foreach ( array( 'trial_end', 'next_payment', 'end', 'cancelled' ) as $dt ) {
			$param = $dt . '_date_gmt';
			if ( null !== $request->get_param( $param ) ) {
				$dates[ $dt ] = sanitize_text_field( $request->get_param( $param ) );
			}
		}
		if ( ! empty( $dates ) ) {
			$sub->update_dates( $dates );
		}

		$sub->save();

		if ( $request->get_param( 'status' ) && $request->get_param( 'status' ) !== $sub->get_status() ) {
			$sub->update_status( sanitize_text_field( $request->get_param( 'status' ) ), __( 'Status updated via REST API.', 'ulixes-subscriptions' ) );
		}
	}

	/**
	 * `DELETE /wc/v3/subscriptions/<id>`
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( $request ) {
		$sub = ulx_get_subscription( absint( $request['id'] ) );
		if ( ! $sub ) {
			return new WP_Error( 'ulx_rest_invalid_id', __( 'Invalid subscription ID.', 'ulixes-subscriptions' ), array( 'status' => 404 ) );
		}
		$data  = self::prepare_subscription_data( $sub );
		$force = (bool) $request->get_param( 'force' );
		$sub->delete( $force );
		return rest_ensure_response( $data );
	}

	/**
	 * `GET /wc/v3/subscriptions/<id>/orders`
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_subscription_orders( $request ) {
		$sub = ulx_get_subscription( absint( $request['id'] ) );
		if ( ! $sub ) {
			return new WP_Error( 'ulx_rest_invalid_id', __( 'Invalid subscription ID.', 'ulixes-subscriptions' ), array( 'status' => 404 ) );
		}
		$orders = array();
		foreach ( array( 'parent', 'renewal', 'switch', 'resubscribe' ) as $type ) {
			foreach ( $sub->get_related_orders( 'all', $type ) as $order ) {
				$orders[] = array(
					'id'                => $order->get_id(),
					'order_number'      => $order->get_order_number(),
					'relationship_type' => $type,
					'status'            => $order->get_status(),
					'total'             => $order->get_total(),
					'currency'          => $order->get_currency(),
					'date_created_gmt'  => $order->get_date_created() ? gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() ) : null,
				);
			}
		}
		return rest_ensure_response( $orders );
	}

	/**
	 * `GET /wc/v3/subscriptions/statuses`
	 *
	 * @return WP_REST_Response
	 */
	public static function get_statuses() {
		return rest_ensure_response( ulx_get_subscription_statuses() );
	}
}

