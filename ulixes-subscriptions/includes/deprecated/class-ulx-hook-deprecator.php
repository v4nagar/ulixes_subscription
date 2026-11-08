<?php
/**
 * Hook Deprecator: maps deprecated legacy action & filter hooks to current hooks.
 *
 * Ensures full backward compatibility with third-party extensions and snippets
 * using legacy `ulixes_` prefixes by triggering the old hooks and logging warnings
 * via `wc_doing_it_wrong()`.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Deprecated
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class Ulx_Hook_Deprecator
 */
class Ulx_Hook_Deprecator {

	/**
	 * Deprecated action hooks map: new_hook => array( 'old_hook' => ..., 'version' => ... ).
	 *
	 * @var array
	 */
	protected static $deprecated_actions = array(
		'ulx_subscription_status_updated'             => array(
			'old_hook' => 'ulixes_subscription_status_updated',
			'version'  => '1.0.0',
		),
		'ulx_subscription_date_updated'               => array(
			'old_hook' => 'ulixes_subscription_date_updated',
			'version'  => '1.0.0',
		),
		'ulx_scheduled_subscription_payment'          => array(
			'old_hook' => 'ulixes_scheduled_subscription_payment',
			'version'  => '1.0.0',
		),
		'ulx_scheduled_subscription_trial_end'        => array(
			'old_hook' => 'ulixes_scheduled_subscription_trial_end',
			'version'  => '1.0.0',
		),
		'ulx_scheduled_subscription_expiration'       => array(
			'old_hook' => 'ulixes_scheduled_subscription_expiration',
			'version'  => '1.0.0',
		),
		'ulx_renewal_order_created'                   => array(
			'old_hook' => 'ulixes_renewal_order_created',
			'version'  => '1.0.0',
		),
		'ulx_checkout_subscription_created'           => array(
			'old_hook' => 'ulixes_checkout_subscription_created',
			'version'  => '1.0.0',
		),
		'ulx_subscription_renewal_payment_failed'     => array(
			'old_hook' => 'ulixes_subscription_renewal_payment_failed',
			'version'  => '1.0.0',
		),
		'ulx_subscription_renewal_payment_complete'   => array(
			'old_hook' => 'ulixes_subscription_renewal_payment_complete',
			'version'  => '1.0.0',
		),
		'ulx_subscriptions_switched_for_order'        => array(
			'old_hook' => 'ulixes_subscriptions_switched_for_order',
			'version'  => '1.0.0',
		),
		'ulx_early_renewal_processed'                 => array(
			'old_hook' => 'ulixes_early_renewal_processed',
			'version'  => '1.0.0',
		),
	);

	/**
	 * Deprecated filter hooks map: new_hook => array( 'old_hook' => ..., 'version' => ... ).
	 *
	 * @var array
	 */
	protected static $deprecated_filters = array(
		'ulx_subscription_price_string'               => array(
			'old_hook' => 'ulixes_subscription_price_string',
			'version'  => '1.0.0',
		),
		'ulx_can_user_renew_early'                    => array(
			'old_hook' => 'ulixes_can_user_renew_early',
			'version'  => '1.0.0',
		),
		'ulx_default_retry_rules'                     => array(
			'old_hook' => 'ulixes_default_retry_rules',
			'version'  => '1.0.0',
		),
		'ulx_cart_needs_payment'                      => array(
			'old_hook' => 'ulixes_cart_needs_payment',
			'version'  => '1.0.0',
		),
		'ulx_new_renewal_order'                       => array(
			'old_hook' => 'ulixes_new_renewal_order',
			'version'  => '1.0.0',
		),
		'ulx_subscription_is_manual'                  => array(
			'old_hook' => 'ulixes_subscription_is_manual',
			'version'  => '1.0.0',
		),
	);

	/**
	 * Init hooks.
	 */
	public static function init() {
		// Attach dispatchers for deprecated actions.
		foreach ( self::$deprecated_actions as $new_hook => $data ) {
			add_action( $new_hook, array( __CLASS__, 'dispatch_deprecated_action' ), -1000, 10 );
		}

		// Attach dispatchers for deprecated filters.
		foreach ( self::$deprecated_filters as $new_hook => $data ) {
			add_filter( $new_hook, array( __CLASS__, 'dispatch_deprecated_filter' ), 1000, 10 );
		}
	}

	/**
	 * Intercept new actions and execute legacy hooks if listeners are attached.
	 */
	public static function dispatch_deprecated_action() {
		$current_hook = current_action();
		if ( ! isset( self::$deprecated_actions[ $current_hook ] ) ) {
			return;
		}

		$data     = self::$deprecated_actions[ $current_hook ];
		$old_hook = $data['old_hook'];
		$version  = $data['version'];

		if ( has_action( $old_hook ) ) {
			if ( function_exists( 'wc_doing_it_wrong' ) ) {
				wc_doing_it_wrong(
					esc_html( $old_hook ),
					sprintf(
						/* translators: 1: old hook, 2: version, 3: new hook */
						esc_html__( 'The "%1$s" hook is deprecated since Ulixes Subscriptions version %2$s. Please use "%3$s" instead.', 'ulixes-subscriptions' ),
						esc_html( $old_hook ),
						esc_html( $version ),
						esc_html( $current_hook )
					),
					esc_html( $version )
				);
			}

			$args = func_get_args();
			do_action_ref_array( $old_hook, $args );
		}
	}

	/**
	 * Intercept new filters and execute legacy filters if listeners are attached.
	 *
	 * @param mixed $value Filtered value.
	 * @return mixed
	 */
	public static function dispatch_deprecated_filter( $value ) {
		$current_hook = current_filter();
		if ( ! isset( self::$deprecated_filters[ $current_hook ] ) ) {
			return $value;
		}

		$data     = self::$deprecated_filters[ $current_hook ];
		$old_hook = $data['old_hook'];
		$version  = $data['version'];

		if ( has_filter( $old_hook ) ) {
			if ( function_exists( 'wc_doing_it_wrong' ) ) {
				wc_doing_it_wrong(
					esc_html( $old_hook ),
					sprintf(
						/* translators: 1: old hook, 2: version, 3: new hook */
						esc_html__( 'The "%1$s" filter hook is deprecated since Ulixes Subscriptions version %2$s. Please use "%3$s" instead.', 'ulixes-subscriptions' ),
						esc_html( $old_hook ),
						esc_html( $version ),
						esc_html( $current_hook )
					),
					esc_html( $version )
				);
			}

			$args  = func_get_args();
			$value = apply_filters_ref_array( $old_hook, $args );
		}

		return $value;
	}
}

