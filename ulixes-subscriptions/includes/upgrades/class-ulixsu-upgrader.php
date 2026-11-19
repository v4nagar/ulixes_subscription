<?php
/**
 * Upgrades & Migration Engine: handles database schema migrations and data repairs on updates.
 *
 * @package Ulixes_Subscriptions
 * @subpackage Upgrades
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class Ulixsu_Upgrader
 */
class Ulixsu_Upgrader {

	/**
	 * Database version option name.
	 */
	const DB_VERSION_OPTION = 'ulixsu_db_version';

	/**
	 * Map of version numbers to migration routine methods.
	 *
	 * @var array
	 */
	protected static $updates = array(
		'1.0.1' => 'upgrade_1_0_1',
		'1.0.5' => 'upgrade_1_0_5',
	);

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_update' ), 5 );
		Ulixsu_Repair_Start_Date_Metadata::init();
	}

	/**
	 * Compare database version against current plugin code version and run upgrade routines.
	 */
	public static function maybe_update() {
		$current_db_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		$target_version     = defined( 'ULIXSU_VERSION' ) ? ULIXSU_VERSION : '1.0.0';

		if ( version_compare( $current_db_version, $target_version, '<' ) ) {
			self::run_updates( $current_db_version, $target_version );
		}
	}

	/**
	 * Execute all updates sequentially.
	 *
	 * @param string $current_db_version Current DB version.
	 * @param string $target_version     Target plugin version.
	 */
	protected static function run_updates( $current_db_version, $target_version ) {
		Ulixsu_Upgrade_Logger::info(
			sprintf( 'Starting database upgrade routine: from %s to %s.', $current_db_version, $target_version )
		);

		foreach ( self::$updates as $version => $method ) {
			if ( version_compare( $current_db_version, $version, '<' ) ) {
				if ( is_callable( array( __CLASS__, $method ) ) ) {
					try {
						Ulixsu_Upgrade_Logger::info( sprintf( 'Executing migration routine for version %s (%s)...', $version, $method ) );
						call_user_func( array( __CLASS__, $method ) );
						Ulixsu_Upgrade_Logger::success( sprintf( 'Migration routine for version %s completed successfully.', $version ) );
					} catch ( \Throwable $e ) {
						Ulixsu_Upgrade_Logger::error(
							sprintf( 'Error executing upgrade routine %s for version %s: %s', $method, $version, $e->getMessage() ),
							array( 'exception' => $e )
						);
					}
				}
			}
		}

		update_option( self::DB_VERSION_OPTION, $target_version );
		update_option( 'ulixsu_db_migration_version', $target_version );
		Ulixsu_Upgrade_Logger::success( sprintf( 'Ulixes Subscriptions database successfully updated to version %s.', $target_version ) );
	}

	/**
	 * Upgrade routine for 1.0.1: ensure payment retry table exists and default settings are populated.
	 */
	public static function upgrade_1_0_1() {
		if ( class_exists( 'ULIXSU_Retry_Table_Maker' ) ) {
			ULIXSU_Retry_Table_Maker::create_table();
		}
	}

	/**
	 * Upgrade routine for 1.0.5: triggers background Action Scheduler data repair for start date metadata.
	 */
	public static function upgrade_1_0_5() {
		Ulixsu_Repair_Start_Date_Metadata::dispatch();
	}
}

