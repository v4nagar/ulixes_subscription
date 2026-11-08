<?php
/**
 * Admin Product Editor Fields for Simple Subscriptions & Variable Subscription Variations.
 *
 * Adds:
 * - Subscription price, billing interval (every 1st..6th), billing period (day/week/month/year)
 * - Expire after (subscription length)
 * - Sign-up fee
 * - Free trial length & period
 * - Synchronise renewals (day of week / day of month / month + day for yearly)
 * - One time shipping checkbox
 * - Limit subscription (No limit / Limit to one active / Limit to one of any status)
 * - Bulk variation actions for subscription fields
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Admin_Product
 */
class ULX_Admin_Product {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tabs' ), 20 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_simple_subscription_fields' ) );
		add_action( 'woocommerce_product_options_shipping', array( __CLASS__, 'render_one_time_shipping_field' ) );
		add_action( 'woocommerce_product_options_advanced', array( __CLASS__, 'render_limit_subscription_field' ) );
		add_action( 'woocommerce_process_product_meta_subscription', array( __CLASS__, 'save_simple_subscription_meta' ), 10, 1 );
		add_action( 'woocommerce_process_product_meta_variable-subscription', array( __CLASS__, 'save_variable_subscription_meta' ), 10, 1 );

		// Variations.
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_subscription_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_subscription_fields' ), 10, 2 );
	}

	/**
	 * Ensure the Variations and Inventory tabs appear when "Variable subscription" is selected.
	 *
	 * @param array $tabs Product data tabs.
	 * @return array
	 */
	public static function product_data_tabs( $tabs ) {
		if ( isset( $tabs['variations']['class'] ) && is_array( $tabs['variations']['class'] ) ) {
			$tabs['variations']['class'][] = 'show_if_variable-subscription';
		}
		if ( isset( $tabs['inventory']['class'] ) && is_array( $tabs['inventory']['class'] ) ) {
			$tabs['inventory']['class'][] = 'show_if_subscription';
			$tabs['inventory']['class'][] = 'show_if_variable-subscription';
		}
		return $tabs;
	}

	/**
	 * Render fields in the General tab for Simple Subscription products.
	 */
	public static function render_simple_subscription_fields() {
		global $post;
		$product_id = $post->ID;

		$price          = get_post_meta( $product_id, '_subscription_price', true );
		$interval       = get_post_meta( $product_id, '_subscription_period_interval', true ) ?: 1;
		$period         = get_post_meta( $product_id, '_subscription_period', true ) ?: 'month';
		$length         = get_post_meta( $product_id, '_subscription_length', true ) ?: 0;
		$sign_up_fee    = get_post_meta( $product_id, '_subscription_sign_up_fee', true );
		$trial_length   = get_post_meta( $product_id, '_subscription_trial_length', true ) ?: 0;
		$trial_period   = get_post_meta( $product_id, '_subscription_trial_period', true ) ?: 'day';
		$sync_date      = get_post_meta( $product_id, '_subscription_payment_sync_date', true );
		?>
		<div class="options_group subscription_pricing show_if_subscription hidden">
			<p class="form-field _subscription_price_fields _subscription_price_field">
				<label for="_subscription_price">
					<?php
					/* translators: %s: currency symbol */
					printf( esc_html__( 'Subscription price (%s)', 'ulixes-subscriptions' ), esc_html( get_woocommerce_currency_symbol() ) );
					?>
				</label>
				<span class="wrap">
					<input type="text" id="_subscription_price" name="_subscription_price" class="wc_input_price wc_input_subscription_price short" placeholder="<?php echo esc_attr_x( 'e.g. 9.90', 'example price', 'ulixes-subscriptions' ); ?>" value="<?php echo esc_attr( wc_format_localized_price( $price ) ); ?>" />
					<select id="_subscription_period_interval" name="_subscription_period_interval" class="wc_input_subscription_period_interval">
						<?php foreach ( ulx_get_subscription_period_interval_strings() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $interval ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="_subscription_period" name="_subscription_period" class="wc_input_subscription_period">
						<?php foreach ( ulx_get_subscription_period_strings() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $period ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			</p>

			<?php
			woocommerce_wp_select(
				array(
					'id'          => '_subscription_length',
					'class'       => 'wc_input_subscription_length select short',
					'label'       => __( 'Expire after', 'ulixes-subscriptions' ),
					'options'     => ulx_get_subscription_ranges( $period ),
					'value'       => $length,
					'desc_tip'    => true,
					'description' => __( 'Automatically expire the subscription after this length of time.', 'ulixes-subscriptions' ),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'          => '_subscription_sign_up_fee',
					'class'       => 'wc_input_subscription_intial_price wc_input_price short',
					/* translators: %s: currency symbol */
					'label'       => sprintf( __( 'Sign-up fee (%s)', 'ulixes-subscriptions' ), get_woocommerce_currency_symbol() ),
					'placeholder' => _x( 'e.g. 9.90', 'example price', 'ulixes-subscriptions' ),
					'description' => __( 'Optionally include an amount to be charged at the outset of the subscription.', 'ulixes-subscriptions' ),
					'desc_tip'    => true,
					'type'        => 'text',
					'data_type'   => 'price',
					'value'       => $sign_up_fee,
				)
			);
			?>

			<p class="form-field _subscription_trial_length_field">
				<label for="_subscription_trial_length"><?php esc_html_e( 'Free trial', 'ulixes-subscriptions' ); ?></label>
				<span class="wrap">
					<input type="number" step="1" min="0" id="_subscription_trial_length" name="_subscription_trial_length" class="wc_input_subscription_trial_length short" value="<?php echo esc_attr( $trial_length ); ?>" />
					<select id="_subscription_trial_period" name="_subscription_trial_period" class="wc_input_subscription_trial_period">
						<?php foreach ( ulx_get_available_time_periods() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $trial_period ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			</p>

			<?php if ( ULX_Sync::is_syncing_enabled() ) : ?>
				<p class="form-field _subscription_payment_sync_date_field">
					<label for="_subscription_payment_sync_date"><?php esc_html_e( 'Synchronise renewals', 'ulixes-subscriptions' ); ?></label>
					<?php self::render_sync_dropdown( '_subscription_payment_sync_date', $period, $sync_date ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render synchronisation day/month selector.
	 *
	 * @param string     $field_name Field name.
	 * @param string     $period     Period.
	 * @param int|array  $sync_date  Saved value.
	 */
	public static function render_sync_dropdown( $field_name, $period, $sync_date ) {
		global $wp_locale;
		$day_val = is_array( $sync_date ) ? ( isset( $sync_date['day'] ) ? (int) $sync_date['day'] : 0 ) : (int) $sync_date;
		?>
		<select name="<?php echo esc_attr( $field_name ); ?>" class="wc_input_subscription_payment_sync select short">
			<option value="0" <?php selected( 0, $day_val ); ?>><?php esc_html_e( 'Do not synchronise', 'ulixes-subscriptions' ); ?></option>
			<optgroup label="<?php esc_attr_e( 'Day of Month (Monthly)', 'ulixes-subscriptions' ); ?>">
				<?php for ( $d = 1; $d <= 27; $d++ ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $d, $day_val ); ?>>
						<?php
						/* translators: %s: ordinal day */
						printf( esc_html__( '%s day of the month', 'ulixes-subscriptions' ), esc_html( ulx_append_numeral_suffix( $d ) ) );
						?>
					</option>
				<?php endfor; ?>
				<option value="28" <?php selected( 28, $day_val ); ?>><?php esc_html_e( 'Last day of the month', 'ulixes-subscriptions' ); ?></option>
			</optgroup>
			<optgroup label="<?php esc_attr_e( 'Day of Week (Weekly)', 'ulixes-subscriptions' ); ?>">
				<?php for ( $w = 1; $w <= 7; $w++ ) : ?>
					<option value="<?php echo esc_attr( $w ); ?>" <?php selected( $w, $day_val ); ?>><?php echo esc_html( $wp_locale->get_weekday( $w % 7 ) ); ?></option>
				<?php endfor; ?>
			</optgroup>
		</select>
		<?php
	}

	/**
	 * Render "One time shipping" field in the Shipping tab.
	 */
	public static function render_one_time_shipping_field() {
		global $post;
		woocommerce_wp_checkbox(
			array(
				'id'            => '_subscription_one_time_shipping',
				'wrapper_class' => 'show_if_subscription show_if_variable-subscription hidden',
				'label'         => __( 'One time shipping', 'ulixes-subscriptions' ),
				'description'   => __( 'Shipping for subscription products is normally charged on the initial order and all renewal orders. Enable this to only charge shipping once on the initial order.', 'ulixes-subscriptions' ),
				'desc_tip'      => true,
				'value'         => get_post_meta( $post->ID, '_subscription_one_time_shipping', true ),
			)
		);
	}

	/**
	 * Render "Limit subscription" field in the Advanced tab.
	 */
	public static function render_limit_subscription_field() {
		global $post;
		woocommerce_wp_select(
			array(
				'id'            => '_subscription_limit',
				'wrapper_class' => 'show_if_subscription show_if_variable-subscription hidden',
				'label'         => __( 'Limit subscription', 'ulixes-subscriptions' ),
				'options'       => array(
					'no'     => __( 'Do not limit', 'ulixes-subscriptions' ),
					'active' => __( 'Limit to one active subscription', 'ulixes-subscriptions' ),
					'any'    => __( 'Limit to one of any status', 'ulixes-subscriptions' ),
				),
				'description'   => __( 'Optionally limit a customer to one subscription to this product.', 'ulixes-subscriptions' ),
				'desc_tip'      => true,
				'value'         => get_post_meta( $post->ID, '_subscription_limit', true ) ?: 'no',
			)
		);
	}

	/**
	 * Save simple subscription meta.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save_simple_subscription_meta( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$price = isset( $_POST['_subscription_price'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_subscription_price'] ) ) ) : '';
		update_post_meta( $post_id, '_subscription_price', $price );
		update_post_meta( $post_id, '_regular_price', $price );

		$sale_price = get_post_meta( $post_id, '_sale_price', true );
		if ( '' === $sale_price ) {
			update_post_meta( $post_id, '_price', $price );
		}

		$fields = array(
			'_subscription_period_interval'   => 1,
			'_subscription_period'            => 'month',
			'_subscription_length'            => 0,
			'_subscription_trial_length'      => 0,
			'_subscription_trial_period'      => 'day',
			'_subscription_payment_sync_date' => 0,
			'_subscription_limit'             => 'no',
		);
		foreach ( $fields as $key => $default ) {
			$val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
			update_post_meta( $post_id, $key, $val );
		}

		$fee = isset( $_POST['_subscription_sign_up_fee'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_subscription_sign_up_fee'] ) ) ) : '';
		update_post_meta( $post_id, '_subscription_sign_up_fee', $fee );

		$one_time = isset( $_POST['_subscription_one_time_shipping'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_subscription_one_time_shipping', $one_time );
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Save variable subscription parent-level meta (limit & one-time shipping).
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save_variable_subscription_meta( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$limit    = isset( $_POST['_subscription_limit'] ) ? sanitize_text_field( wp_unslash( $_POST['_subscription_limit'] ) ) : 'no';
		$one_time = isset( $_POST['_subscription_one_time_shipping'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_subscription_limit', $limit );
		update_post_meta( $post_id, '_subscription_one_time_shipping', $one_time );
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Render subscription fields on each variation row.
	 *
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_subscription_fields( $loop, $variation_data, $variation ) {
		$vid          = $variation->ID;
		$price        = get_post_meta( $vid, '_subscription_price', true );
		$interval     = get_post_meta( $vid, '_subscription_period_interval', true ) ?: 1;
		$period       = get_post_meta( $vid, '_subscription_period', true ) ?: 'month';
		$length       = get_post_meta( $vid, '_subscription_length', true ) ?: 0;
		$sign_up_fee  = get_post_meta( $vid, '_subscription_sign_up_fee', true );
		$trial_length = get_post_meta( $vid, '_subscription_trial_length', true ) ?: 0;
		$trial_period = get_post_meta( $vid, '_subscription_trial_period', true ) ?: 'day';
		?>
		<div class="variable_subscription_pricing show_if_variable-subscription">
			<p class="form-row form-row-first">
				<label>
					<?php
					/* translators: %s: currency symbol */
					printf( esc_html__( 'Subscription price (%s)', 'ulixes-subscriptions' ), esc_html( get_woocommerce_currency_symbol() ) );
					?>
				</label>
				<input type="text" class="wc_input_price" name="variable_subscription_price[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( $price ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><?php esc_html_e( 'Billing interval & period', 'ulixes-subscriptions' ); ?></label>
				<select name="variable_subscription_period_interval[<?php echo esc_attr( $loop ); ?>]" style="width:48%;">
					<?php foreach ( ulx_get_subscription_period_interval_strings() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $interval ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="variable_subscription_period[<?php echo esc_attr( $loop ); ?>]" style="width:48%;">
					<?php foreach ( ulx_get_subscription_period_strings() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $period ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-row form-row-first">
				<label><?php esc_html_e( 'Expire after', 'ulixes-subscriptions' ); ?></label>
				<select name="variable_subscription_length[<?php echo esc_attr( $loop ); ?>]">
					<?php foreach ( ulx_get_subscription_ranges( $period ) as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $length ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-row form-row-last">
				<label>
					<?php
					/* translators: %s: currency symbol */
					printf( esc_html__( 'Sign-up fee (%s)', 'ulixes-subscriptions' ), esc_html( get_woocommerce_currency_symbol() ) );
					?>
				</label>
				<input type="text" class="wc_input_price" name="variable_subscription_sign_up_fee[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( $sign_up_fee ) ); ?>" />
			</p>
			<p class="form-row form-row-first">
				<label><?php esc_html_e( 'Free trial length', 'ulixes-subscriptions' ); ?></label>
				<input type="number" step="1" min="0" name="variable_subscription_trial_length[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $trial_length ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><?php esc_html_e( 'Free trial period', 'ulixes-subscriptions' ); ?></label>
				<select name="variable_subscription_trial_period[<?php echo esc_attr( $loop ); ?>]">
					<?php foreach ( ulx_get_available_time_periods() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $trial_period ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		</div>
		<?php
	}

	/**
	 * Save variation subscription fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Loop index.
	 */
	public static function save_variation_subscription_fields( $variation_id, $i ) {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_POST['variable_subscription_price'][ $i ] ) ) {
			$price = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['variable_subscription_price'][ $i ] ) ) );
			update_post_meta( $variation_id, '_subscription_price', $price );
			if ( '' !== $price ) {
				update_post_meta( $variation_id, '_regular_price', $price );
				if ( '' === get_post_meta( $variation_id, '_sale_price', true ) ) {
					update_post_meta( $variation_id, '_price', $price );
				}
			}
		}

		$map = array(
			'variable_subscription_period_interval' => '_subscription_period_interval',
			'variable_subscription_period'          => '_subscription_period',
			'variable_subscription_length'          => '_subscription_length',
			'variable_subscription_trial_length'    => '_subscription_trial_length',
			'variable_subscription_trial_period'    => '_subscription_trial_period',
		);
		foreach ( $map as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ][ $i ] ) ) {
				update_post_meta( $variation_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ][ $i ] ) ) );
			}
		}

		if ( isset( $_POST['variable_subscription_sign_up_fee'][ $i ] ) ) {
			$fee = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['variable_subscription_sign_up_fee'][ $i ] ) ) );
			update_post_meta( $variation_id, '_subscription_sign_up_fee', $fee );
		}
		// phpcs:enable WordPress.Security.NonceVerification
	}
}

