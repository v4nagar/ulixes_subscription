<?php
/**
 * Data store for simple subscription products.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Product_Subscription_Data_Store
 */
class ULX_Product_Subscription_Data_Store extends WC_Product_Data_Store_CPT {

	/**
	 * Keep _subscription_price in sync with the active price after saving.
	 *
	 * @param WC_Product $product Product.
	 * @param bool       $force   Force.
	 */
	protected function update_post_meta( &$product, $force = false ) {
		parent::update_post_meta( $product, $force );
		$reg_price = $product->get_regular_price( 'edit' );
		$sub_price = '' !== $reg_price ? $reg_price : $product->get_price( 'edit' );
		update_post_meta( $product->get_id(), '_subscription_price', $sub_price );
	}
}

