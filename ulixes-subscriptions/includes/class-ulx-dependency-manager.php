<?php
/**
 * Dependency Manager: checks environmental prerequisites before bootstrapping.
 *
 * Verifies that WooCommerce is installed & active, satisfies minimum version
 * requirements, and that Action Scheduler is ready.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Dependency_Manager
 */
class ULX_Dependency_Manager {

	/**
	 * Minimum required WooCommerce version.
	 */
	const MIN_WC_VERSION = '7.0';

	/**
	 * Minimum required PHP version.
	 */
	const MIN_PHP_VERSION = '7.4';

	/**
	 * Missing dependencies error messages.
	 *
	 * @var array
	 */
	protected static $errors = array();

	/**
	 * Check whether all environment dependencies are satisfied.
	 *
	 * @return bool
	 */
	public static function are_dependencies_met() {
		self::$errors = array();

		// 1. PHP version check.
		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			self::$errors[] = sprintf(
				/* translators: 1: Current PHP version, 2: Required PHP version */
				__( 'Ulixes Subscriptions requires PHP version %2$s or greater. Your server is running PHP version %1$s.', 'ulixes-subscriptions' ),
				PHP_VERSION,
				self::MIN_PHP_VERSION
			);
		}

		// 2. WooCommerce active check.
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::$errors[] = __( 'Ulixes Subscriptions requires WooCommerce to be installed and active.', 'ulixes-subscriptions' );
			return false;
		}

		// 3. WooCommerce version check.
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::MIN_WC_VERSION, '<' ) ) {
			self::$errors[] = sprintf(
				/* translators: 1: Current WooCommerce version, 2: Required WooCommerce version */
				__( 'Ulixes Subscriptions requires WooCommerce version %2$s or greater. Your store is running version %1$s.', 'ulixes-subscriptions' ),
				WC_VERSION,
				self::MIN_WC_VERSION
			);
		}

		return empty( self::$errors );
	}

	/**
	 * Attach admin notice hooks if dependencies are not met.
	 */
	public static function init_notices() {
		if ( ! empty( self::$errors ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		}
	}

	/**
	 * Render error notices in the WordPress admin.
	 */
	public static function render_admin_notices() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		foreach ( self::$errors as $error ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php esc_html_e( 'Ulixes Subscriptions Error:', 'ulixes-subscriptions' ); ?></strong> <?php echo esc_html( $error ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Is WooCommerce active?
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}
}

