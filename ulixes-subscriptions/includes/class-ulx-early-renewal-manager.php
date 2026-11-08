<?php
/**
 * Early Renewal Manager.
 *
 * Provides customer UI, URL handlers, and schedule resetting for processing renewals
 * ahead of the scheduled next payment date without losing prepaid time.
 * Matches WooCommerce Subscriptions' WCS_Early_Renewal_Manager.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Early_Renewal_Manager
 */
class ULX_Early_Renewal_Manager {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_process_early_renewal' ), 5, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_process_early_renewal' ), 5, 1 );
		add_action( 'ulx_subscription_details_table_before_dates', array( __CLASS__, 'display_early_renewal_button' ), 10, 1 );
	}

	/**
	 * Is early renewal feature enabled?
	 *
	 * @return bool
	 */
	public static function is_early_renewal_enabled() {
		return ulx_option_is_yes( 'enable_early_renewal' );
	}

	/**
	 * Can a customer renew this subscription early?
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @param int              $user_id      User ID.
	 * @return bool
	 */
	public static function can_user_renew_early( $subscription, $user_id = 0 ) {
		if ( ! self::is_early_renewal_enabled() || ! $subscription ) {
			return false;
		}
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}
		if ( (int) $subscription->get_user_id() !== (int) $user_id && ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		if ( ! $subscription->has_status( 'active' ) ) {
			return false;
		}
		if ( ! $subscription->get_time( 'next_payment' ) || $subscription->get_time( 'next_payment' ) <= time() ) {
			return false;
		}
		return apply_filters( 'ulx_can_user_renew_early', true, $subscription, $user_id );
	}

	/**
	 * Get early renewal URL with nonce.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 * @return string
	 */
	public static function get_early_renewal_url( $subscription ) {
		return wp_nonce_url(
			add_query_arg(
				array( 'subscription_renewal_early' => $subscription->get_id() ),
				wc_get_checkout_url()
			),
			'ulx-renew-early-' . $subscription->get_id(),
			'ulx_nonce'
		);
	}

	/**
	 * Render "Renew early" button on My Account View Subscription page.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public static function display_early_renewal_button( $subscription ) {
		if ( ! self::can_user_renew_early( $subscription ) ) {
			return;
		}
		?>
		<p class="ulx-early-renewal-action">
			<a href="<?php echo esc_url( self::get_early_renewal_url( $subscription ) ); ?>" class="button ulx-button-early-renewal">
				<?php esc_html_e( 'Renew early', 'ulixes-subscriptions' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * When an early renewal order completes, advance the next payment date from the scheduled date
	 * and reset the Action Scheduler queue.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function maybe_process_early_renewal( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_subscription_renewal_early', true ) ) {
			return;
		}

		$subscription_id = absint( $order->get_meta( '_subscription_renewal_early', true ) );
		$subscription    = ulx_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}

		if ( 'yes' === $order->get_meta( '_ulx_early_renewal_processed', true ) ) {
			return;
		}

		// Calculate new next payment date preserving full cycle length from existing scheduled next payment.
		$current_next = $subscription->get_time( 'next_payment' );
		$new_next     = ulx_add_time( $subscription->get_billing_interval(), $subscription->get_billing_period(), $current_next );

		// Also advance end date if subscription has an expiration.
		$current_end = $subscription->get_time( 'end' );
		$dates       = array( 'next_payment' => ulx_date_to_mysql( $new_next ) );
		if ( $current_end > 0 ) {
			$new_end       = ulx_add_time( $subscription->get_billing_interval(), $subscription->get_billing_period(), $current_end );
			$dates['end'] = ulx_date_to_mysql( $new_end );
		}

		// Update dates: triggers ULX_Scheduler::update_date to reset the Action Scheduler queue!
		$subscription->update_dates( $dates );

		$subscription->add_order_note(
			sprintf(
				/* translators: 1: order number, 2: formatted new date */
				__( 'Early renewal order #%1$s completed. Next payment successfully advanced to %2$s and Action Scheduler queue reset.', 'ulixes-subscriptions' ),
				$order->get_order_number(),
				ulx_format_date( $new_next )
			)
		);

		$order->update_meta_data( '_ulx_early_renewal_processed', 'yes' );
		$order->save_meta_data();

		do_action( 'ulx_early_renewal_processed', $subscription, $order );
	}
}

