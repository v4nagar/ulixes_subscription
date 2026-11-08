<?php
/**
 * Downloadable Content Handler & Drip Downloads.
 *
 * - Grants download permissions on the subscription itself so permissions are tied to the subscription's active status.
 * - Revokes access when a subscription is on-hold, cancelled or expired, and restores access when reactivated.
 * - Respects the "Drip Downloadable Content" setting: when enabled, newly added files on a subscription product
 *   are only granted upon the next renewal payment rather than immediately to existing subscribers.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Download_Handler
 */
class ULX_Download_Handler {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'ulx_created_subscription', array( __CLASS__, 'grant_download_permissions' ), 10, 1 );
		add_filter( 'woocommerce_get_item_downloads', array( __CLASS__, 'filter_item_downloads' ), 10, 3 );
		add_filter( 'woocommerce_customer_get_downloadable_products', array( __CLASS__, 'filter_customer_downloads' ), 10, 1 );
		add_filter( 'woocommerce_process_product_file_download_paths_grant_access_to_new_files', array( __CLASS__, 'maybe_drip_new_files' ), 10, 4 );
	}

	/**
	 * Grant download permissions on a newly created subscription.
	 *
	 * @param ULX_Subscription $subscription Subscription.
	 */
	public static function grant_download_permissions( $subscription ) {
		if ( $subscription instanceof ULX_Subscription ) {
			wc_downloadable_product_permissions( $subscription->get_id(), true );
		}
	}

	/**
	 * Hide downloadable files on customer My Account -> Downloads if the linked subscription is not active or pending-cancel.
	 *
	 * @param array $downloads Downloads.
	 * @return array
	 */
	public static function filter_customer_downloads( $downloads ) {
		foreach ( $downloads as $key => $download ) {
			if ( empty( $download['order_id'] ) ) {
				continue;
			}
			$sub = ulx_get_subscription( $download['order_id'] );
			if ( $sub && ! $sub->has_status( array( 'active', 'pending-cancel' ) ) ) {
				unset( $downloads[ $key ] );
				continue;
			}
			// If download belongs to a parent/renewal order, check its subscriptions.
			$subs = ulx_get_subscriptions_for_order( $download['order_id'], array( 'order_type' => 'any' ) );
			foreach ( $subs as $s ) {
				if ( $s->has_product( $download['product_id'] ) && ! $s->has_status( array( 'active', 'pending-cancel' ) ) ) {
					unset( $downloads[ $key ] );
					break;
				}
			}
		}
		return array_values( $downloads );
	}

	/**
	 * Filter item downloads on order/subscription tables.
	 *
	 * @param array                 $filesItem Files.
	 * @param WC_Order_Item_Product $item      Item.
	 * @param WC_Order              $order     Order.
	 * @return array
	 */
	public static function filter_item_downloads( $filesItem, $item, $order ) {
		if ( $order instanceof ULX_Subscription && ! $order->has_status( array( 'active', 'pending-cancel' ) ) ) {
			return array();
		}
		return $filesItem;
	}

	/**
	 * Drip downloadable content: prevent granting immediate access to newly added files if dripping is enabled.
	 *
	 * @param bool       $grant      Grant access.
	 * @param string     $file_id    File ID.
	 * @param array      $file       File data.
	 * @param WC_Product $product    Product.
	 * @return bool
	 */
	public static function maybe_drip_new_files( $grant, $file_id, $file, $product ) {
		if ( ulx_option_is_yes( 'drip_downloadable_content' ) && ulx_is_subscription_product( $product ) ) {
			return false;
		}
		return $grant;
	}
}

