<?php
/**
 * Admin core hooks: assets, staging/duplicate site banner, and WooCommerce System Status report section.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Admin
 */
class ULX_Admin {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'ensure_subscriptions_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_staging_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_staging_action' ) );
		add_action( 'woocommerce_system_status_report', array( __CLASS__, 'render_system_status_section' ) );
	}

	/**
	 * Ensure "Subscriptions" appears in the WooCommerce submenu right below "Orders".
	 */
	public static function ensure_subscriptions_menu() {
		global $submenu;
		if ( ! isset( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
			return;
		}

		$sub_slugs = array(
			'edit.php?post_type=shop_subscription',
			'wc-orders--shop_subscription',
			'admin.php?page=wc-orders--shop_subscription',
		);

		$found_item = null;
		$found_key  = null;
		foreach ( $submenu['woocommerce'] as $key => $item ) {
			if ( isset( $item[2] ) && in_array( $item[2], $sub_slugs, true ) ) {
				$found_item = $item;
				$found_key  = $key;
				break;
			}
		}

		if ( null === $found_item ) {
			$target_slug = 'edit.php?post_type=shop_subscription';
			add_submenu_page(
				'woocommerce',
				__( 'Subscriptions', 'ulixes-subscriptions' ),
				__( 'Subscriptions', 'ulixes-subscriptions' ),
				'manage_woocommerce',
				$target_slug
			);
			foreach ( $submenu['woocommerce'] as $key => $item ) {
				if ( isset( $item[2] ) && $target_slug === $item[2] ) {
					$found_item = $item;
					$found_key  = $key;
					break;
				}
			}
		}

		if ( null !== $found_item && null !== $found_key ) {
			unset( $submenu['woocommerce'][ $found_key ] );
			$reordered = array();
			$inserted  = false;
			foreach ( $submenu['woocommerce'] as $item ) {
				$reordered[] = $item;
				if ( ! $inserted && isset( $item[2] ) && in_array( $item[2], array( 'edit.php?post_type=shop_order', 'wc-orders', 'admin.php?page=wc-orders' ), true ) ) {
					$reordered[] = $found_item;
					$inserted    = true;
				}
			}
			if ( ! $inserted ) {
				$reordered[] = $found_item;
			}
			$submenu['woocommerce'] = $reordered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Enqueue admin CSS & JS.
	 *
	 * @param string $hook_suffix Hook.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		wp_enqueue_style( 'ulx-admin', ULX_PLUGIN_URL . 'assets/css/admin.css', array(), ULX_VERSION );
		wp_enqueue_script( 'ulx-admin', ULX_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-datepicker' ), ULX_VERSION, true );
		wp_localize_script(
			'ulx-admin',
			'ulx_admin_params',
			array(
				'ranges'        => ulx_get_subscription_ranges(),
				'trial_lengths' => ulx_get_subscription_trial_lengths(),
				'sync_enabled'  => ULX_Sync::is_syncing_enabled() ? 'yes' : 'no',
			)
		);
	}

	/**
	 * Show a prominent warning banner if the site URL changed (staging/duplicate site protection).
	 */
	public static function maybe_show_staging_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! ulx_is_duplicate_site() ) {
			return;
		}
		$accept_live_url = wp_nonce_url( add_query_arg( 'ulx_duplicate_site_action', 'set_live' ), 'ulx_duplicate_site' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Ulixes Subscriptions Staging Mode Active:', 'ulixes-subscriptions' ); ?></strong>
				<?php esc_html_e( 'It looks like this site has moved to a new URL. To prevent duplicate recurring charges from a staging or test copy, automatic subscription payments have been switched to Manual Renewal.', 'ulixes-subscriptions' ); ?>
				<a href="<?php echo esc_url( $accept_live_url ); ?>" class="button button-small"><?php esc_html_e( 'Consider this the live site & enable automatic payments', 'ulixes-subscriptions' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle admin confirming the current domain is the live site.
	 */
	public static function maybe_handle_staging_action() {
		if ( ! empty( $_GET['ulx_duplicate_site_action'] ) && 'set_live' === $_GET['ulx_duplicate_site_action'] && current_user_can( 'manage_woocommerce' ) ) {
			check_admin_referer( 'ulx_duplicate_site' );
			update_option( 'ulx_siteurl', ulx_get_site_url_obfuscated() );
			wp_safe_redirect( remove_query_arg( array( 'ulx_duplicate_site_action', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Render Subscriptions section in WooCommerce -> Status.
	 */
	public static function render_system_status_section() {
		$store  = WC_Data_Store::load( 'subscription' );
		$counts = method_exists( $store, 'get_subscriptions_count_by_status' ) ? $store->get_subscriptions_count_by_status() : array();
		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
				<tr>
					<th colspan="3" data-export-label="Subscriptions"><h2><?php esc_html_e( 'Ulixes Subscriptions', 'ulixes-subscriptions' ); ?></h2></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td data-export-label="ULX Version"><?php esc_html_e( 'Version', 'ulixes-subscriptions' ); ?>:</td>
					<td class="help">&nbsp;</td>
					<td><?php echo esc_html( ULX_VERSION ); ?></td>
				</tr>
				<tr>
					<td data-export-label="Subscriptions Mode"><?php esc_html_e( 'Mode', 'ulixes-subscriptions' ); ?>:</td>
					<td class="help">&nbsp;</td>
					<td><?php echo ulx_is_duplicate_site() ? '<mark class="error">' . esc_html__( 'Staging', 'ulixes-subscriptions' ) . '</mark>' : '<mark class="yes">' . esc_html__( 'Live', 'ulixes-subscriptions' ) . '</mark>'; ?></td>
				</tr>
				<tr>
					<td data-export-label="Retry System"><?php esc_html_e( 'Failed Payment Retry', 'ulixes-subscriptions' ); ?>:</td>
					<td class="help">&nbsp;</td>
					<td><?php echo ULX_Retry_Manager::is_retry_enabled() ? esc_html__( 'Enabled', 'ulixes-subscriptions' ) : esc_html__( 'Disabled', 'ulixes-subscriptions' ); ?></td>
				</tr>
				<tr>
					<td data-export-label="Subscription Status Counts"><?php esc_html_e( 'Subscriptions by Status', 'ulixes-subscriptions' ); ?>:</td>
					<td class="help">&nbsp;</td>
					<td>
						<?php
						if ( empty( $counts ) ) {
							esc_html_e( 'None', 'ulixes-subscriptions' );
						} else {
							foreach ( $counts as $st => $cnt ) {
								echo esc_html( ulx_get_subscription_status_name( $st ) . ': ' . $cnt ) . '<br/>';
							}
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}

