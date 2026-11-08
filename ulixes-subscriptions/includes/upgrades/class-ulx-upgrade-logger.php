<?php
/**
 * Upgrade Logger: logs database updates, migrations, and repair routines to wc-logs.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Upgrades
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class Ulx_Upgrade_Logger
 */
class Ulx_Upgrade_Logger {

	/**
	 * Log source handle for wc-logs.
	 */
	const SOURCE = 'ulx-upgrades';

	/**
	 * Log a message to the WooCommerce logger.
	 *
	 * @param string $level   Log level ('info', 'notice', 'warning', 'error', 'debug').
	 * @param string $message The message to log.
	 * @param array  $context Additional contextual data.
	 */
	public static function log( $level, $message, $context = array() ) {
		$context['source'] = self::SOURCE;

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( $logger ) {
				$logger->log( $level, $message, $context );
				return;
			}
		}

		// Fallback to error_log if WooCommerce logger is not yet available.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[%s] [%s] %s %s', self::SOURCE, strtoupper( $level ), $message, ! empty( $context ) ? wp_json_encode( $context ) : '' ) );
		}
	}

	/**
	 * Log info.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( $message, $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( $message, $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Log error.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log success event.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function success( $message, $context = array() ) {
		self::log( 'info', '[SUCCESS] ' . $message, $context );
	}
}

