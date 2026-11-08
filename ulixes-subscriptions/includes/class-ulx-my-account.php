<?php
/**
 * My Account endpoints & views:
 * - `/my-account/subscriptions/`
 * - `/my-account/view-subscription/<id>/`
 * - Related Subscriptions table on the View Order page
 * - Edit Shipping/Billing Address for a specific subscription or all active subscriptions
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_My_Account
 */
class ULX_My_Account {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoints' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_items' ), 10, 1 );
		add_filter( 'the_title', array( __CLASS__, 'endpoint_title' ), 10, 2 );

		add_action( 'woocommerce_account_subscriptions_endpoint', array( __CLASS__, 'subscriptions_endpoint_content' ) );
		add_action( 'woocommerce_account_view-subscription_endpoint', array( __CLASS__, 'view_subscription_endpoint_content' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_related_subscriptions_on_order' ), 10, 1 );

		// Address update propagation to subscriptions.
		add_action( 'woocommerce_after_edit_address_form_billing', array( __CLASS__, 'render_update_all_addresses_checkbox' ) );
		add_action( 'woocommerce_after_edit_address_form_shipping', array( __CLASS__, 'render_update_all_addresses_checkbox' ) );
		add_action( 'woocommerce_customer_save_address', array( __CLASS__, 'maybe_save_subscription_address' ), 10, 2 );
	}

	/**
	 * Register rewrite endpoints.
	 */
	public static function add_endpoints() {
		add_rewrite_endpoint( 'subscriptions', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'view-subscription', EP_ROOT | EP_PAGES );
	}

	/**
	 * Register query vars with WooCommerce.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars['subscriptions']     = 'subscriptions';
		$vars['view-subscription'] = 'view-subscription';
		return $vars;
	}

	/**
	 * Insert "Subscriptions" into the My Account navigation menu right after "Orders".
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function add_menu_items( $items ) {
		$new_items = array();
		$inserted  = false;
		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new_items['subscriptions'] = __( 'Subscriptions', 'ulixes-subscriptions' );
				$inserted                   = true;
			}
		}
		if ( ! $inserted ) {
			$new_items['subscriptions'] = __( 'Subscriptions', 'ulixes-subscriptions' );
		}
		return $new_items;
	}

	/**
	 * Dynamic page title for `subscriptions` and `view-subscription` endpoints.
	 *
	 * @param string $title Title.
	 * @param int    $id    Post ID.
	 * @return string
	 */
	public static function endpoint_title( $title, $id = 0 ) {
		global $wp_query;
		if ( ! is_null( $wp_query ) && ! is_admin() && is_main_query() && in_the_loop() && is_account_page() ) {
			if ( isset( $wp_query->query_vars['view-subscription'] ) ) {
				$sub = ulx_get_subscription( absint( $wp_query->query_vars['view-subscription'] ) );
				/* translators: %s: subscription number */
				$title = $sub ? sprintf( __( 'Subscription #%s', 'ulixes-subscriptions' ), $sub->get_order_number() ) : __( 'View Subscription', 'ulixes-subscriptions' );
				remove_filter( 'the_title', array( __CLASS__, 'endpoint_title' ), 10 );
			} elseif ( isset( $wp_query->query_vars['subscriptions'] ) ) {
				$title = __( 'Subscriptions', 'ulixes-subscriptions' );
				remove_filter( 'the_title', array( __CLASS__, 'endpoint_title' ), 10 );
			}
		}
		return $title;
	}

	/**
	 * Render `/my-account/subscriptions/`.
	 *
	 * @param int $current_page Page number.
	 */
	public static function subscriptions_endpoint_content( $current_page = 1 ) {
		$current_page  = empty( $current_page ) ? 1 : absint( $current_page );
		$all_subs      = ulx_get_users_subscriptions( get_current_user_id() );
		$per_page      = (int) apply_filters( 'ulx_my_account_subscriptions_per_page', 10 );
		$max_pages     = max( 1, (int) ceil( count( $all_subs ) / $per_page ) );
		$subscriptions = array_slice( $all_subs, ( $current_page - 1 ) * $per_page, $per_page, true );

		ulx_get_template(
			'myaccount/my-subscriptions.php',
			array(
				'subscriptions' => $subscriptions,
				'current_page'  => $current_page,
				'max_num_pages' => $max_pages,
				'paginate'      => true,
			)
		);
	}

	/**
	 * Render `/my-account/view-subscription/<id>/`.
	 *
	 * @param int $subscription_id Subscription ID.
	 */
	public static function view_subscription_endpoint_content( $subscription_id ) {
		$subscription = ulx_get_subscription( absint( $subscription_id ) );
		if ( ! $subscription || (int) $subscription->get_user_id() !== get_current_user_id() ) {
			echo '<div class="woocommerce-error">' . esc_html__( 'Invalid subscription.', 'ulixes-subscriptions' ) . ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" class="wc-forward">' . esc_html__( 'My account', 'ulixes-subscriptions' ) . '</a></div>';
			return;
		}
		ulx_get_template(
			'myaccount/view-subscription.php',
			array(
				'subscription' => $subscription,
				'user_id'      => get_current_user_id(),
			)
		);
	}

	/**
	 * Render related subscriptions table on the customer's View Order page.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_related_subscriptions_on_order( $order ) {
		if ( $order instanceof ULX_Subscription ) {
			return;
		}
		$subscriptions = ulx_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		if ( ! empty( $subscriptions ) ) {
			ulx_get_template(
				'myaccount/related-subscriptions.php',
				array(
					'order'         => $order,
					'subscriptions' => $subscriptions,
				)
			);
		}
	}

	/**
	 * Render checkbox on Edit Address form to update subscription addresses.
	 */
	public static function render_update_all_addresses_checkbox() {
		if ( ! ulx_user_has_subscription( get_current_user_id(), '', array( 'active', 'on-hold' ) ) ) {
			return;
		}
		if ( isset( $_GET['subscription'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<input type="hidden" name="ulx_update_subscription_address" value="' . esc_attr( absint( $_GET['subscription'] ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		?>
		<p class="form-row form-row-wide">
			<label for="update_all_subscriptions_addresses">
				<input type="checkbox" name="update_all_subscriptions_addresses" id="update_all_subscriptions_addresses" value="1" />
				<?php esc_html_e( 'Update the address used for all of my active subscriptions', 'ulixes-subscriptions' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Copy updated customer address to a specific subscription or all active subscriptions.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $load_address 'billing' or 'shipping'.
	 */
	public static function maybe_save_subscription_address( $user_id, $load_address ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$update_all = ! empty( $_POST['update_all_subscriptions_addresses'] );
		$single_id  = ! empty( $_POST['ulx_update_subscription_address'] ) ? absint( $_POST['ulx_update_subscription_address'] ) : 0;
		if ( ! $update_all && ! $single_id ) {
			return;
		}

		$customer = new WC_Customer( $user_id );
		$subs     = $update_all
			? ulx_get_users_subscriptions( $user_id, array( 'subscription_status' => array( 'active', 'on-hold' ) ) )
			: array_filter( array( ulx_get_subscription( $single_id ) ) );

		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' );
		foreach ( $subs as $sub ) {
			if ( (int) $sub->get_user_id() !== (int) $user_id ) {
				continue;
			}
			foreach ( $fields as $f ) {
				$getter = "get_{$load_address}_{$f}";
				$setter = "set_{$load_address}_{$f}";
				if ( method_exists( $customer, $getter ) && method_exists( $sub, $setter ) ) {
					$sub->$setter( $customer->$getter() );
				}
			}
			$sub->save();
			$sub->add_order_note( sprintf( /* translators: %s: address type */ __( 'Customer updated their %s address.', 'ulixes-subscriptions' ), $load_address ) );
		}
		// phpcs:enable
	}
}

