<?php
/**
 * Core helper functions.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Whether HPOS classes are available (WC 8.2+).
 *
 * @return bool
 */
function ulx_is_hpos_enabled_or_available() {
	return class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' );
}

/**
 * Whether HPOS is the authoritative order storage.
 *
 * @return bool
 */
function ulx_is_hpos_enabled() {
	return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Get the admin URL of the subscription list.
 *
 * @return string
 */
function ulx_get_subscriptions_admin_url() {
	if ( ulx_is_hpos_enabled() ) {
		return admin_url( 'admin.php?page=wc-orders--shop_subscription' );
	}
	return admin_url( 'edit.php?post_type=shop_subscription' );
}

/**
 * Edit URL of a subscription/order in admin.
 *
 * @param int $id ID.
 * @return string
 */
function ulx_get_edit_post_link( $id ) {
	if ( class_exists( OrderUtil::class ) && method_exists( OrderUtil::class, 'get_order_admin_edit_url' ) ) {
		return OrderUtil::get_order_admin_edit_url( $id );
	}
	return admin_url( 'post.php?post=' . absint( $id ) . '&action=edit' );
}

/**
 * Get the subscription statuses with labels.
 *
 * @return array
 */
function ulx_get_subscription_statuses() {
	$statuses = array(
		'wc-pending'        => _x( 'Pending', 'Subscription status', 'ulixes-subscriptions' ),
		'wc-active'         => _x( 'Active', 'Subscription status', 'ulixes-subscriptions' ),
		'wc-on-hold'        => _x( 'On hold', 'Subscription status', 'ulixes-subscriptions' ),
		'wc-cancelled'      => _x( 'Cancelled', 'Subscription status', 'ulixes-subscriptions' ),
		'wc-switched'       => _x( 'Switched', 'Subscription status', 'ulixes-subscriptions' ),
		'wc-expired'        => _x( 'Expired', 'Subscription status', 'ulixes-subscriptions' ),
		'wc-pending-cancel' => _x( 'Pending Cancellation', 'Subscription status', 'ulixes-subscriptions' ),
	);
	return apply_filters( 'ulx_subscription_statuses', $statuses );
}

/**
 * Get a status label.
 *
 * @param string $status Status with/without wc- prefix.
 * @return string
 */
function ulx_get_subscription_status_name( $status ) {
	$statuses = ulx_get_subscription_statuses();
	$key      = ulx_sanitize_subscription_status_key( $status );
	return isset( $statuses[ $key ] ) ? $statuses[ $key ] : $status;
}

/**
 * Ensure status key carries wc- prefix.
 *
 * @param string $status Status.
 * @return string
 */
function ulx_sanitize_subscription_status_key( $status ) {
	if ( ! is_string( $status ) || '' === $status ) {
		return '';
	}
	return 'wc-' === substr( $status, 0, 3 ) ? $status : 'wc-' . $status;
}

/**
 * Settings getter with filter.
 *
 * @param string $key     Option name w/o ulx_ prefix.
 * @param mixed  $default Default.
 * @return mixed
 */
function ulx_get_option( $key, $default = false ) {
	return apply_filters( 'ulx_option_' . $key, get_option( 'ulx_' . $key, $default ) );
}

/**
 * Boolean setting.
 *
 * @param string $key Key.
 * @return bool
 */
function ulx_option_is_yes( $key ) {
	return 'yes' === ulx_get_option( $key, 'no' );
}

/**
 * Whether the store is a staging/duplicate site where automatic payments must not run.
 *
 * Mirrors WooCommerce Subscriptions "duplicate site" lock: we store the site URL at install,
 * if it changes we switch to manual renewals until the admin confirms.
 *
 * @return bool
 */
function ulx_is_duplicate_site() {
	$stored = get_option( 'ulx_siteurl' );
	if ( false === $stored ) {
		update_option( 'ulx_siteurl', ulx_get_site_url_obfuscated() );
		return false;
	}
	$is_dup = $stored !== ulx_get_site_url_obfuscated();
	return (bool) apply_filters( 'ulx_is_duplicate_site', $is_dup );
}

/**
 * Obfuscated site url (to avoid search-replace tools changing it).
 *
 * @return string
 */
function ulx_get_site_url_obfuscated() {
	$url = get_site_url();
	$url = preg_replace( '#^https?://#', '', $url );
	return substr_replace( $url, '_[ulx_siteurl]_', (int) floor( strlen( $url ) / 2 ), 0 );
}

/**
 * Whether automatic payments are disabled store-wide.
 *
 * @return bool
 */
function ulx_automatic_payments_disabled() {
	return ulx_option_is_yes( 'turn_off_automatic_payments' ) || ulx_is_duplicate_site();
}

/**
 * Get a template.
 *
 * @param string $template_name Template.
 * @param array  $args          Args.
 */
function ulx_get_template( $template_name, $args = array() ) {
	wc_get_template( $template_name, $args, 'ulixes-subscriptions/', ULX_PLUGIN_DIR . 'templates/' );
}

/**
 * Get a template HTML.
 *
 * @param string $template_name Template.
 * @param array  $args          Args.
 * @return string
 */
function ulx_get_template_html( $template_name, $args = array() ) {
	return wc_get_template_html( $template_name, $args, 'ulixes-subscriptions/', ULX_PLUGIN_DIR . 'templates/' );
}

/**
 * Log helper.
 *
 * @param string $message Message.
 * @param string $level   Level.
 * @param string $source  Source.
 */
function ulx_log( $message, $level = 'info', $source = 'ulixes-subscriptions' ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => $source ) );
	}
}

/**
 * Round a price as WooCommerce would.
 *
 * @param float $amount Amount.
 * @return float
 */
function ulx_round( $amount ) {
	return round( (float) $amount, wc_get_price_decimals() );
}

/**
 * JSON-safe array of human readable list "a, b and c".
 *
 * @param array $items Items.
 * @return string
 */
function ulx_human_list( $items ) {
	$items = array_values( array_filter( (array) $items ) );
	$count = count( $items );
	if ( 0 === $count ) {
		return '';
	}
	if ( 1 === $count ) {
		return $items[0];
	}
	$last = array_pop( $items );
	/* translators: 1: comma separated list, 2: last item */
	return sprintf( __( '%1$s and %2$s', 'ulixes-subscriptions' ), implode( ', ', $items ), $last );
}

