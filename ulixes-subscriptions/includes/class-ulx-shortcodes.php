<?php
/**
 * Shortcodes:
 * - `[ulx_my_subscriptions]` (and alias `[wcs_my_subscriptions]`) to render a customer's subscriptions list anywhere.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Shortcodes
 */
class ULX_Shortcodes {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_shortcode( 'ulx_my_subscriptions', array( __CLASS__, 'render_my_subscriptions' ) );
		add_shortcode( 'wcs_my_subscriptions', array( __CLASS__, 'render_my_subscriptions' ) );
	}

	/**
	 * Render `[ulx_my_subscriptions]`.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render_my_subscriptions( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your subscriptions.', 'ulixes-subscriptions' ) . '</p>';
		}
		$atts = shortcode_atts(
			array(
				'status' => 'any',
			),
			$atts,
			'ulx_my_subscriptions'
		);
		$statuses      = 'any' === $atts['status'] ? array( 'any' ) : array_map( 'trim', explode( ',', $atts['status'] ) );
		$subscriptions = ulx_get_users_subscriptions( get_current_user_id(), array( 'subscription_status' => $statuses ) );

		return ulx_get_template_html(
			'myaccount/my-subscriptions.php',
			array(
				'subscriptions' => $subscriptions,
				'current_page'  => 1,
				'max_num_pages' => 1,
				'paginate'      => false,
			)
		);
	}
}

