<?php
/**
 * Registers the subscription order type, statuses and data stores.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Post_Types
 */
class ULX_Post_Types {

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'woocommerce_after_register_post_type', array( __CLASS__, 'register_post_types' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 6 );
		add_action( 'init', array( __CLASS__, 'register_post_statuses' ), 9 );
		add_filter( 'woocommerce_data_stores', array( __CLASS__, 'add_data_stores' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'maybe_filter_order_statuses' ), 100 );
		add_filter( 'woocommerce_order_list_table_default_statuses', array( __CLASS__, 'list_table_default_statuses' ), 10, 1 );
		add_filter( 'woocommerce_shop_subscription_list_table_prepare_items_query_args', array( __CLASS__, 'hpos_list_query_args' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'cpt_list_query' ) );
		add_filter( 'post_updated_messages', array( __CLASS__, 'updated_messages' ) );
	}

	/**
	 * Register shop_subscription order type.
	 */
	public static function register_post_types() {
		if ( post_type_exists( 'shop_subscription' ) ) {
			return;
		}
		wc_register_order_type(
			'shop_subscription',
			apply_filters(
				'ulx_register_post_type_shop_subscription',
				array(
					'labels'                           => array(
						'name'               => __( 'Subscriptions', 'ulixes-subscriptions' ),
						'singular_name'      => __( 'Subscription', 'ulixes-subscriptions' ),
						'add_new'            => _x( 'Add Subscription', 'custom post type setting', 'ulixes-subscriptions' ),
						'add_new_item'       => _x( 'Add New Subscription', 'custom post type setting', 'ulixes-subscriptions' ),
						'edit'               => _x( 'Edit', 'custom post type setting', 'ulixes-subscriptions' ),
						'edit_item'          => _x( 'Edit Subscription', 'custom post type setting', 'ulixes-subscriptions' ),
						'new_item'           => _x( 'New Subscription', 'custom post type setting', 'ulixes-subscriptions' ),
						'view'               => _x( 'View Subscription', 'custom post type setting', 'ulixes-subscriptions' ),
						'view_item'          => _x( 'View Subscription', 'custom post type setting', 'ulixes-subscriptions' ),
						'search_items'       => __( 'Search Subscriptions', 'ulixes-subscriptions' ),
						'not_found'          => __( 'No Subscriptions found', 'ulixes-subscriptions' ),
						'not_found_in_trash' => _x( 'No Subscriptions found in trash', 'custom post type setting', 'ulixes-subscriptions' ),
						'parent'             => _x( 'Parent Subscriptions', 'custom post type setting', 'ulixes-subscriptions' ),
						'menu_name'          => __( 'Subscriptions', 'ulixes-subscriptions' ),
					),
					'description'                      => __( 'This is where subscriptions are stored.', 'ulixes-subscriptions' ),
					'public'                           => false,
					'show_ui'                          => true,
					'capability_type'                  => 'shop_order',
					'map_meta_cap'                     => true,
					'publicly_queryable'               => false,
					'exclude_from_search'              => true,
					'show_in_menu'                     => 'woocommerce',
					'hierarchical'                     => false,
					'show_in_nav_menus'                => false,
					'rewrite'                          => false,
					'query_var'                        => false,
					'supports'                         => array( 'title', 'comments', 'custom-fields' ),
					'has_archive'                      => false,
					// WooCommerce order type specific.
					'exclude_from_orders_screen'       => true,
					'add_order_meta_boxes'             => true,
					'exclude_from_order_count'         => true,
					'exclude_from_order_views'         => true,
					'exclude_from_order_webhooks'      => true,
					'exclude_from_order_reports'       => true,
					'exclude_from_order_sales_reports' => true,
					'class_name'                       => 'ULX_Subscription',
				)
			)
		);
	}

	/**
	 * Register custom post statuses (wc-pending/on-hold/cancelled already exist).
	 */
	public static function register_post_statuses() {
		$statuses = array(
			/* translators: %s: count */
			'wc-active'         => _nx_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'post status label including post count', 'ulixes-subscriptions' ),
			/* translators: %s: count */
			'wc-switched'       => _nx_noop( 'Switched <span class="count">(%s)</span>', 'Switched <span class="count">(%s)</span>', 'post status label including post count', 'ulixes-subscriptions' ),
			/* translators: %s: count */
			'wc-expired'        => _nx_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'post status label including post count', 'ulixes-subscriptions' ),
			/* translators: %s: count */
			'wc-pending-cancel' => _nx_noop( 'Pending Cancellation <span class="count">(%s)</span>', 'Pending Cancellation <span class="count">(%s)</span>', 'post status label including post count', 'ulixes-subscriptions' ),
		);
		$labels   = ulx_get_subscription_statuses();
		foreach ( $statuses as $status => $label_count ) {
			register_post_status(
				$status,
				array(
					'label'                     => $labels[ $status ],
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $label_count,
				)
			);
		}
	}

	/**
	 * Data stores.
	 *
	 * @param array $stores Stores.
	 * @return array
	 */
	public static function add_data_stores( $stores ) {
		if ( ulx_is_hpos_enabled() && class_exists( 'ULX_Subscription_Data_Store_HPOS' ) ) {
			$stores['subscription']      = 'ULX_Subscription_Data_Store_HPOS';
			$stores['shop_subscription'] = 'ULX_Subscription_Data_Store_HPOS';
		} else {
			$stores['subscription']      = 'ULX_Subscription_Data_Store_CPT';
			$stores['shop_subscription'] = 'ULX_Subscription_Data_Store_CPT';
		}
		$stores['product-subscription']           = 'ULX_Product_Subscription_Data_Store';
		$stores['product-variable-subscription']  = 'WC_Product_Variable_Data_Store_CPT';
		$stores['product-subscription_variation'] = 'WC_Product_Variation_Data_Store_CPT';
		return $stores;
	}

	/**
	 * Are we on a subscription admin screen?
	 *
	 * @return bool
	 */
	public static function is_subscription_admin_screen() {
		if ( ! is_admin() ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['page'] ) && 'wc-orders--shop_subscription' === $_GET['page'] ) {
			return true;
		}
		if ( isset( $_GET['post_type'] ) && 'shop_subscription' === $_GET['post_type'] ) {
			return true;
		}
		if ( isset( $_GET['post'] ) && 'shop_subscription' === get_post_type( absint( $_GET['post'] ) ) ) {
			return true;
		}
		if ( isset( $_POST['post_type'] ) && 'shop_subscription' === $_POST['post_type'] ) {
			return true;
		}
		if ( isset( $_POST['post_ID'] ) && 'shop_subscription' === get_post_type( absint( $_POST['post_ID'] ) ) ) {
			return true;
		}
		// phpcs:enable
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ( 'shop_subscription' === $screen->post_type || 'woocommerce_page_wc-orders--shop_subscription' === $screen->id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get current subscription being viewed or edited in admin.
	 *
	 * @return ULX_Subscription|false
	 */
	public static function get_current_subscription() {
		global $theorder, $post;
		if ( $theorder instanceof ULX_Subscription ) {
			return $theorder;
		}
		$id = 0;
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id = absint( $_GET['id'] );
		} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id = absint( $_GET['post'] );
		} elseif ( isset( $_POST['post_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$id = absint( $_POST['post_ID'] );
		} elseif ( isset( $_POST['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$id = absint( $_POST['order_id'] );
		} elseif ( ! empty( $post ) && 'shop_subscription' === $post->post_type ) {
			$id = $post->ID;
		}
		if ( $id > 0 ) {
			return ulx_get_subscription( $id );
		}
		return false;
	}

	/**
	 * Ensure both standard WooCommerce order statuses and subscription statuses are registered in wc_order_statuses
	 * so WooCommerce CRUD (wc_create_order, set_status, HPOS) never throws WC_Data_Exception during renewals or updates.
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function maybe_filter_order_statuses( $statuses ) {
		return array_merge( (array) $statuses, ulx_get_subscription_statuses() );
	}

	/**
	 * Default statuses for HPOS list table.
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function list_table_default_statuses( $statuses ) {
		if ( self::is_subscription_admin_screen() ) {
			return array_keys( ulx_get_subscription_statuses() );
		}
		return $statuses;
	}

	/**
	 * HPOS list args.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function hpos_list_query_args( $args ) {
		if ( empty( $args['status'] ) || 'all' === $args['status'] || array( 'all' ) === $args['status'] ) {
			$args['status'] = array_keys( ulx_get_subscription_statuses() );
		}
		return $args;
	}

	/**
	 * CPT list query – ensure "All" includes our custom statuses.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function cpt_list_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_subscription' === $query->get( 'post_type' ) && ! $query->get( 'post_status' ) ) {
			$query->set( 'post_status', array_keys( ulx_get_subscription_statuses() ) );
		}
	}

	/**
	 * Post updated messages.
	 *
	 * @param array $messages Messages.
	 * @return array
	 */
	public static function updated_messages( $messages ) {
		$messages['shop_subscription'] = array(
			0  => '',
			1  => __( 'Subscription updated.', 'ulixes-subscriptions' ),
			2  => __( 'Custom field updated.', 'ulixes-subscriptions' ),
			3  => __( 'Custom field deleted.', 'ulixes-subscriptions' ),
			4  => __( 'Subscription updated.', 'ulixes-subscriptions' ),
			5  => __( 'Subscription restored.', 'ulixes-subscriptions' ),
			6  => __( 'Subscription updated.', 'ulixes-subscriptions' ),
			7  => __( 'Subscription saved.', 'ulixes-subscriptions' ),
			8  => __( 'Subscription submitted.', 'ulixes-subscriptions' ),
			9  => __( 'Subscription scheduled.', 'ulixes-subscriptions' ),
			10 => __( 'Subscription draft updated.', 'ulixes-subscriptions' ),
		);
		return $messages;
	}
}

