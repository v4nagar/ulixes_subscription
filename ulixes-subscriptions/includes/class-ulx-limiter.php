<?php
/**
 * Subscription Purchase Limiter (limit to 1 active subscription or 1 subscription of any status per customer).
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Limiter
 */
class ULX_Limiter {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_cart_limit' ), 20, 5 );
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'maybe_render_existing_subscription_notice' ), 15 );
	}

	/**
	 * Display an informational notice on single subscription product pages when the customer already has an active subscription.
	 */
	public static function maybe_render_existing_subscription_notice() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['switch-subscription'] ) || isset( $_GET['resubscribe'] ) || ulx_cart_contains_switches() ) {
			return;
		}
		global $product;
		$prod_obj = ulx_maybe_get_product( $product );
		if ( ! $prod_obj || ! ulx_is_subscription_product( $prod_obj ) ) {
			return;
		}
		$limit = ulx_get_product_limit( $prod_obj );
		if ( 'no' === $limit || empty( $limit ) ) {
			return;
		}
		$check_id     = $prod_obj->get_parent_id() ? $prod_obj->get_parent_id() : $prod_obj->get_id();
		$statuses     = 'any' === $limit ? 'any' : array( 'active', 'on-hold', 'pending-cancel' );
		$existing_sub = ulx_get_user_subscription_for_product( get_current_user_id(), $check_id, $statuses );
		if ( ! $existing_sub ) {
			return;
		}

		$links   = array();
		$links[] = '<a class="button wc-forward" href="' . esc_url( $existing_sub->get_view_order_url() ) . '">' . esc_html( sprintf( /* translators: %s: subscription number */ __( 'View Subscription #%s', 'ulixes-subscriptions' ), $existing_sub->get_order_number() ) ) . '</a>';
		if ( ulx_can_user_renew_early( $existing_sub ) ) {
			$links[] = '<a class="button" href="' . esc_url( ulx_get_early_renewal_url( $existing_sub ) ) . '" style="margin-left:6px;">' . esc_html__( 'Renew Now (Early Renewal)', 'ulixes-subscriptions' ) . '</a>';
		}

		if ( $prod_obj->is_type( 'variable-subscription' ) && ulx_option_is_yes( 'allow_switching_variable' ) && $existing_sub->has_status( 'active' ) ) {
			$msg = sprintf(
				/* translators: %s: subscription number */
				esc_html__( 'You already have an active subscription (#%s) to this product. Select a different plan below to upgrade or downgrade, or renew your current plan early.', 'ulixes-subscriptions' ),
				esc_html( $existing_sub->get_order_number() )
			);
		} else {
			$msg = sprintf(
				/* translators: %s: subscription number */
				esc_html__( 'You already have a subscription (#%s) to this product.', 'ulixes-subscriptions' ),
				esc_html( $existing_sub->get_order_number() )
			);
		}
		wc_print_notice( $msg . ' ' . implode( ' ', $links ), 'notice' );
	}

	/**
	 * Filter `is_purchasable` for subscription products with a purchase limit.
	 *
	 * @param bool       $purchasable Purchasable.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public static function is_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! is_user_logged_in() ) {
			return $purchasable;
		}
		// Allow resubscribe, renewal, and switch flows when permitted.
		if ( isset( $_GET['resubscribe'] ) || isset( $_GET['switch-subscription'] ) || isset( $_GET['subscription_renewal_early'] ) || ulx_cart_contains_resubscribe() || ulx_cart_contains_switches() || ulx_cart_contains_renewal() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $purchasable;
		}

		$limit = ulx_get_product_limit( $product );
		if ( 'no' === $limit || empty( $limit ) ) {
			return $purchasable;
		}

		$user_id  = get_current_user_id();
		$statuses = 'any' === $limit ? 'any' : array( 'active', 'on-hold', 'pending-cancel' );

		// 1. Variable parent product: keep purchasable when switching is enabled and user has an active subscription so they can select another variation to upgrade/downgrade.
		if ( $product->is_type( array( 'variable-subscription', 'variable' ) ) && 0 === (int) $product->get_parent_id() ) {
			$existing_sub = ulx_get_user_subscription_for_product( $user_id, $product->get_id(), $statuses );
			if ( ! $existing_sub ) {
				return $purchasable;
			}
			if ( $existing_sub->has_status( 'active' ) && 'no' !== ulx_get_option( 'allow_switching_variable', 'yes' ) ) {
				return true;
			}
			return false;
		}

		// 2. Variation product: block ONLY the specific variation the user already has on their subscription; allow all other variations for upgrade/downgrade!
		if ( $product->get_parent_id() > 0 ) {
			$parent_id    = $product->get_parent_id();
			$variation_id = $product->get_id();
			$existing_sub = ulx_get_user_subscription_for_product( $user_id, $parent_id, $statuses );
			if ( ! $existing_sub ) {
				return $purchasable;
			}
			// If the user's subscription already has this exact variation, they cannot buy the same variation again.
			if ( $existing_sub->has_product( $variation_id ) ) {
				return false;
			}
			// Different variation: allow if subscription is active and variable switching is enabled.
			if ( $existing_sub->has_status( 'active' ) && 'no' !== ulx_get_option( 'allow_switching_variable', 'yes' ) ) {
				return true;
			}
			return false;
		}

		// 3. Simple subscription product.
		if ( ulx_user_has_subscription( $user_id, $product->get_id(), $statuses ) ) {
			return false;
		}

		return $purchasable;
	}

	/**
	 * Prevent adding duplicate limited subscription products to the cart, while allowing upgrades/downgrades to a different variation.
	 *
	 * @param bool  $valid        Valid.
	 * @param int   $product_id   Product ID.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation ID.
	 * @param array $variations   Variation attributes.
	 * @return bool
	 */
	public static function validate_cart_limit( $valid, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $valid ) {
			return $valid;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $variation_id ) && ! empty( $_REQUEST['variation_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$variation_id = absint( $_REQUEST['variation_id'] );
		}
		$target_id = $variation_id ? $variation_id : $product_id;
		$product   = wc_get_product( $target_id );
		if ( ! $product || ! ulx_is_subscription_product( $product ) ) {
			return $valid;
		}
		if ( empty( $variation_id ) && $product->get_parent_id() > 0 ) {
			$variation_id = $product->get_id();
		}
		// Allow explicit renewal, resubscribe, or switch requests.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['resubscribe'] ) || isset( $_GET['switch-subscription'] ) || isset( $_GET['subscription_renewal_early'] ) ) {
			return $valid;
		}

		$limit = ulx_get_product_limit( $product );
		if ( 'no' === $limit || empty( $limit ) ) {
			return $valid;
		}

		$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

		// Prevent adding the same limited subscription product to the cart twice.
		if ( ! empty( WC()->cart ) ) {
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$item_pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
				if ( $item_pid === (int) $parent_id ) {
					if ( $variation_id && (int) $cart_item['variation_id'] !== (int) $variation_id ) {
						// Replace the previous variation in cart when switching plans.
						WC()->cart->remove_cart_item( $cart_item_key );
						continue;
					}
					wc_add_notice( __( 'You already have this subscription product in your cart.', 'ulixes-subscriptions' ), 'error' );
					return false;
				}
			}
		}

		if ( ! is_user_logged_in() ) {
			return $valid;
		}

		$statuses     = 'any' === $limit ? 'any' : array( 'active', 'on-hold', 'pending-cancel' );
		$existing_sub = ulx_get_user_subscription_for_product( get_current_user_id(), $parent_id, $statuses );
		if ( ! $existing_sub ) {
			return $valid;
		}

		// If this is a variable subscription and the customer selected a DIFFERENT variation while their subscription is active, allow it as an automatic Upgrade / Downgrade switch!
		if ( $variation_id > 0 && $existing_sub->has_status( 'active' ) && 'no' !== ulx_get_option( 'allow_switching_variable', 'yes' ) && ! $existing_sub->has_product( $variation_id ) ) {
			return $valid;
		}

		$actions   = array();
		$actions[] = '<a class="button wc-forward" href="' . esc_url( $existing_sub->get_view_order_url() ) . '">' . esc_html( sprintf( /* translators: %s: subscription number */ __( 'View Subscription #%s', 'ulixes-subscriptions' ), $existing_sub->get_order_number() ) ) . '</a>';
		if ( ulx_can_user_renew_early( $existing_sub ) ) {
			$actions[] = '<a class="button" href="' . esc_url( ulx_get_early_renewal_url( $existing_sub ) ) . '" style="margin-left:6px;">' . esc_html__( 'Renew Early', 'ulixes-subscriptions' ) . '</a>';
		}

		$message = sprintf(
			/* translators: %s: subscription number */
			esc_html__( 'You already have an active subscription (#%s) for this plan and cannot purchase a duplicate subscription. You can renew your existing subscription early or select a different variation to upgrade/downgrade.', 'ulixes-subscriptions' ),
			esc_html( $existing_sub->get_order_number() )
		);
		wc_add_notice( $message . ' ' . implode( ' ', $actions ), 'error' );
		return false;
	}
}

