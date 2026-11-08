<?php
/**
 * GDPR / Personal Data Exporter & Eraser for Subscriptions.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Privacy
 */
class ULX_Privacy {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['ulixes-subscriptions'] = array(
			'exporter_friendly_name' => __( 'Customer Subscriptions', 'ulixes-subscriptions' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['ulixes-subscriptions'] = array(
			'eraser_friendly_name' => __( 'Customer Subscriptions', 'ulixes-subscriptions' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export subscription personal data for an email address.
	 *
	 * @param string $email_address Email.
	 * @param int    $page          Page.
	 * @return array
	 */
	public static function export_personal_data( $email_address, $page = 1 ) {
		$data_to_export = array();
		$user           = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$subscriptions = ulx_get_users_subscriptions( $user->ID );
		foreach ( $subscriptions as $subscription ) {
			$data_to_export[] = array(
				'group_id'    => 'ulx_subscriptions',
				'group_label' => __( 'Subscriptions', 'ulixes-subscriptions' ),
				'item_id'     => 'subscription-' . $subscription->get_id(),
				'data'        => array(
					array(
						'name'  => __( 'Subscription Number', 'ulixes-subscriptions' ),
						'value' => $subscription->get_order_number(),
					),
					array(
						'name'  => __( 'Status', 'ulixes-subscriptions' ),
						'value' => ulx_get_subscription_status_name( $subscription->get_status() ),
					),
					array(
						'name'  => __( 'Start Date', 'ulixes-subscriptions' ),
						'value' => $subscription->get_date_to_display( 'start' ),
					),
					array(
						'name'  => __( 'Recurring Total', 'ulixes-subscriptions' ),
						'value' => wp_strip_all_tags( $subscription->get_formatted_order_total() ),
					),
					array(
						'name'  => __( 'Billing Address', 'ulixes-subscriptions' ),
						'value' => $subscription->get_formatted_billing_address(),
					),
				),
			);
		}

		return array(
			'data' => $data_to_export,
			'done' => true,
		);
	}

	/**
	 * Anonymize ended subscriptions on personal data erasure request.
	 *
	 * @param string $email_address Email.
	 * @param int    $page          Page.
	 * @return array
	 */
	public static function erase_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		if ( $user ) {
			$subscriptions = ulx_get_users_subscriptions( $user->ID );
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->has_status( array( 'cancelled', 'expired', 'switched' ) ) ) {
					WC_Privacy_Erasers::remove_order_personal_data( $subscription );
					$items_removed = true;
				} else {
					$items_retained = true;
					/* translators: %s: subscription id */
					$messages[] = sprintf( __( 'Personal data within active subscription #%s has been retained.', 'ulixes-subscriptions' ), $subscription->get_order_number() );
				}
			}
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}

