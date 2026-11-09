<?php
/**
 * Subscription object.
 *
 * A subscription is a special order type ("shop_subscription") that stores the recurring
 * line items, totals, billing schedule and payment method used for renewals.
 *
 * @package Ulixes_Subscriptions
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

/**
 * ULX_Subscription
 */
class ULX_Subscription extends WC_Order {

	/**
	 * Data store name.
	 *
	 * @var string
	 */
	protected $data_store_name = 'subscription';

	/**
	 * Object type.
	 *
	 * @var string
	 */
	protected $object_type = 'subscription';

	/**
	 * Date types stored in meta.
	 *
	 * @var array
	 */
	protected static $meta_date_types = array( 'start', 'trial_end', 'next_payment', 'cancelled', 'end', 'payment_retry' );

	/**
	 * Cache of related orders.
	 *
	 * @var array
	 */
	protected $related_cache = array();

	/**
	 * Guard against recursive payment_complete calls.
	 *
	 * @var array
	 */
	protected static $processing_payment_complete = array();

	/**
	 * Order type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'shop_subscription';
	}

	/**
	 * Valid statuses for subscriptions.
	 *
	 * @return array
	 */
	protected function get_valid_statuses() {
		return array_keys( ulx_get_subscription_statuses() );
	}

	/*
	|--------------------------------------------------------------------------
	| Getters / setters for billing schedule & flags
	|--------------------------------------------------------------------------
	*/

	/**
	 * Billing period.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_billing_period( $context = 'view' ) {
		return (string) $this->get_meta( '_billing_period', true, $context );
	}

	/**
	 * Billing interval.
	 *
	 * @param string $context Context.
	 * @return int
	 */
	public function get_billing_interval( $context = 'view' ) {
		return max( 1, (int) $this->get_meta( '_billing_interval', true, $context ) );
	}

	/**
	 * Set billing period.
	 *
	 * @param string $value Period.
	 */
	public function set_billing_period( $value ) {
		if ( array_key_exists( $value, ulx_get_subscription_period_strings() ) ) {
			$this->update_meta_data( '_billing_period', $value );
		}
	}

	/**
	 * Set billing interval.
	 *
	 * @param int $value Interval.
	 */
	public function set_billing_interval( $value ) {
		$this->update_meta_data( '_billing_interval', max( 1, absint( $value ) ) );
	}

	/**
	 * Trial period.
	 *
	 * @return string
	 */
	public function get_trial_period() {
		$p = (string) $this->get_meta( '_trial_period', true );
		return $p ? $p : $this->get_billing_period();
	}

	/**
	 * Set trial period.
	 *
	 * @param string $value Period.
	 */
	public function set_trial_period( $value ) {
		$this->update_meta_data( '_trial_period', $value );
	}

	/**
	 * Suspension count.
	 *
	 * @return int
	 */
	public function get_suspension_count() {
		return (int) $this->get_meta( '_suspension_count', true );
	}

	/**
	 * Set suspension count.
	 *
	 * @param int $count Count.
	 */
	public function set_suspension_count( $count ) {
		$this->update_meta_data( '_suspension_count', absint( $count ) );
	}

	/**
	 * Requires manual renewal flag (stored on the subscription).
	 *
	 * @return bool
	 */
	public function get_requires_manual_renewal() {
		$value = $this->get_meta( '_requires_manual_renewal', true );
		return 'false' !== $value && false !== $value && '' !== $value ? true : false;
	}

	/**
	 * Set requires manual renewal.
	 *
	 * @param bool $value Value.
	 */
	public function set_requires_manual_renewal( $value ) {
		$this->update_meta_data( '_requires_manual_renewal', $value ? 'true' : 'false' );
	}

	/**
	 * Is the subscription renewed manually?
	 *
	 * True if flagged manual, the store has turned off automatic payments, this is a staging
	 * site, or the payment method doesn't exist / doesn't support subscriptions.
	 *
	 * @return bool
	 */
	public function is_manual() {
		$manual = false;
		if ( ulx_automatic_payments_disabled() ) {
			$manual = true;
		} elseif ( $this->get_requires_manual_renewal() ) {
			$manual = true;
		} elseif ( ! $this->get_payment_method() ) {
			$manual = true;
		} else {
			$gateway = ulx_get_payment_gateway( $this->get_payment_method() );
			if ( ! $gateway || ! $gateway->supports( 'subscriptions' ) ) {
				$manual = true;
			}
		}
		return (bool) apply_filters( 'ulx_subscription_is_manual', $manual, $this );
	}

	/**
	 * Switch data stored when a switch occurs.
	 *
	 * @return array
	 */
	public function get_switch_data() {
		$d = $this->get_meta( '_subscription_switch_data', true );
		return is_array( $d ) ? $d : array();
	}

	/**
	 * Cancelled email sent?
	 *
	 * @return bool
	 */
	public function get_cancelled_email_sent() {
		return 'true' === $this->get_meta( '_cancelled_email_sent', true );
	}

	/**
	 * Set cancelled email sent.
	 *
	 * @param bool $v Value.
	 */
	public function set_cancelled_email_sent( $v ) {
		$this->update_meta_data( '_cancelled_email_sent', $v ? 'true' : 'false' );
	}

	/*
	|--------------------------------------------------------------------------
	| Dates
	|--------------------------------------------------------------------------
	*/

	/**
	 * All date types.
	 *
	 * @return array
	 */
	public static function get_date_types() {
		return apply_filters(
			'ulx_subscription_date_types',
			array(
				'start'                  => _x( 'Start Date', 'table heading', 'ulixes-subscriptions' ),
				'trial_end'              => _x( 'Trial End', 'table heading', 'ulixes-subscriptions' ),
				'next_payment'           => _x( 'Next Payment', 'table heading', 'ulixes-subscriptions' ),
				'last_order_date_created' => _x( 'Last Order Date', 'table heading', 'ulixes-subscriptions' ),
				'cancelled'              => _x( 'Cancelled Date', 'table heading', 'ulixes-subscriptions' ),
				'end'                    => _x( 'End Date', 'table heading', 'ulixes-subscriptions' ),
			)
		);
	}

	/**
	 * Normalise a date type key.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	protected function normalise_date_type( $type ) {
		$map = array(
			'date_created'    => 'date_created',
			'start_date'      => 'start',
			'trial_end_date'  => 'trial_end',
			'next_payment_date' => 'next_payment',
			'end_date'        => 'end',
			'last_payment'    => 'last_order_date_created',
			'last_order_date' => 'last_order_date_created',
			'cancelled_date'  => 'cancelled',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}

	/**
	 * Get a date as MySQL string.
	 *
	 * @param string $date_type Date type.
	 * @param string $timezone  gmt|site.
	 * @return string 0 when not set.
	 */
	public function get_date( $date_type, $timezone = 'gmt' ) {
		$date_type = $this->normalise_date_type( $date_type );
		$date      = 0;

		if ( in_array( $date_type, self::$meta_date_types, true ) ) {
			$date = $this->get_meta( '_schedule_' . $date_type, true );
			if ( 'start' === $date_type && empty( $date ) && $this->get_date_created() ) {
				$date = gmdate( 'Y-m-d H:i:s', $this->get_date_created()->getTimestamp() );
			}
		} elseif ( 'date_created' === $date_type ) {
			$date = $this->get_date_created() ? gmdate( 'Y-m-d H:i:s', $this->get_date_created()->getTimestamp() ) : 0;
		} elseif ( 'date_modified' === $date_type ) {
			$date = $this->get_date_modified() ? gmdate( 'Y-m-d H:i:s', $this->get_date_modified()->getTimestamp() ) : 0;
		} elseif ( 'date_paid' === $date_type ) {
			$date = $this->get_date_paid() ? gmdate( 'Y-m-d H:i:s', $this->get_date_paid()->getTimestamp() ) : 0;
		} elseif ( 'last_order_date_created' === $date_type || 'last_order_date_paid' === $date_type ) {
			$last = $this->get_last_order( 'all' );
			if ( $last ) {
				$d = 'last_order_date_created' === $date_type ? $last->get_date_created() : $last->get_date_paid();
				$date = $d ? gmdate( 'Y-m-d H:i:s', $d->getTimestamp() ) : 0;
			}
		}

		if ( empty( $date ) ) {
			$date = 0;
		}

		if ( $date && 'gmt' !== $timezone ) {
			$date = get_date_from_gmt( $date );
		}

		return apply_filters( 'ulx_subscription_get_' . $date_type . '_date', $date, $this, $timezone );
	}

	/**
	 * Get a date as timestamp.
	 *
	 * @param string $date_type Type.
	 * @param string $timezone  Timezone.
	 * @return int
	 */
	public function get_time( $date_type, $timezone = 'gmt' ) {
		$date = $this->get_date( $date_type, 'gmt' );
		$ts   = ulx_date_to_time( $date );
		if ( $ts && 'gmt' !== $timezone ) {
			$ts += (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		}
		return $ts;
	}

	/**
	 * Formatted date for display.
	 *
	 * @param string $date_type Type.
	 * @return string
	 */
	public function get_date_to_display( $date_type = 'next_payment' ) {
		$date_type = $this->normalise_date_type( $date_type );
		$ts        = $this->get_time( $date_type );

		if ( $ts ) {
			$display = ulx_format_date( $ts );
		} else {
			switch ( $date_type ) {
				case 'end':
					$display = __( 'Not yet ended', 'ulixes-subscriptions' );
					break;
				case 'cancelled':
					$display = __( 'Not cancelled', 'ulixes-subscriptions' );
					break;
				case 'next_payment':
				case 'trial_end':
				default:
					$display = '-';
					break;
			}
		}
		return apply_filters( 'ulx_subscription_date_to_display', $display, $date_type, $this );
	}

	/**
	 * Validate and update dates.
	 *
	 * @param array  $dates    date_type => mysql GMT date (or 0 to delete).
	 * @param string $timezone Timezone of the given dates.
	 * @throws Exception When invalid.
	 */
	public function update_dates( $dates, $timezone = 'gmt' ) {
		$dates = (array) $dates;
		$norm  = array();
		foreach ( $dates as $type => $date ) {
			$type = $this->normalise_date_type( $type );
			if ( '_date' === substr( $type, -5 ) ) {
				$type = substr( $type, 0, -5 );
			}
			if ( 'start' !== $type && 'trial_end' !== $type && 'next_payment' !== $type && 'end' !== $type && 'cancelled' !== $type && 'payment_retry' !== $type && 'last_order_date_created' !== $type && 'date_created' !== $type ) {
				continue;
			}
			if ( empty( $date ) ) {
				$norm[ $type ] = 0;
				continue;
			}
			if ( is_numeric( $date ) ) {
				$date = gmdate( 'Y-m-d H:i:s', (int) $date );
			} elseif ( ! ulx_is_datetime_mysql_format( $date ) ) {
				/* translators: %s: date type */
				throw new InvalidArgumentException( sprintf( esc_html__( 'Invalid %s date. The date must be of the format: "Y-m-d H:i:s".', 'ulixes-subscriptions' ), esc_html( $type ) ) );
			}
			if ( 'gmt' !== $timezone ) {
				$date = get_gmt_from_date( $date );
			}
			$norm[ $type ] = $date;
		}

		$errors = $this->validate_date_updates( $norm );
		if ( ! empty( $errors ) ) {
			throw new Exception( esc_html( implode( ' ', $errors ) ) );
		}

		foreach ( $norm as $type => $date ) {
			if ( 'date_created' === $type ) {
				$this->set_date_created( $date ? ulx_date_to_time( $date ) : null );
				continue;
			}
			if ( 'last_order_date_created' === $type ) {
				continue; // Calculated from orders.
			}
			$old = $this->get_date( $type );
			if ( empty( $date ) ) {
				$this->delete_meta_data( '_schedule_' . $type );
			} else {
				$this->update_meta_data( '_schedule_' . $type, $date );
			}
			if ( (string) $old !== (string) $date ) {
				$this->pending_date_updates[ $type ] = $date;
			}
		}

		if ( $this->get_id() ) {
			$this->save_meta_data();
			$this->fire_date_update_hooks();
		}
	}

	/**
	 * Dates updated since last hook firing.
	 *
	 * @var array
	 */
	protected $pending_date_updates = array();

	/**
	 * Fire update hooks so the scheduler can react.
	 */
	protected function fire_date_update_hooks() {
		$updates                    = $this->pending_date_updates;
		$this->pending_date_updates = array();
		foreach ( $updates as $type => $date ) {
			if ( empty( $date ) ) {
				do_action( 'ulx_subscription_date_deleted', $this, $type );
			} else {
				do_action( 'ulx_subscription_date_updated', $this, $type, $date );
			}
		}
	}

	/**
	 * Save – fires pending date hooks once ID exists.
	 *
	 * @return int
	 */
	public function save() {
		$id = parent::save();
		if ( ! empty( $this->pending_date_updates ) ) {
			$this->fire_date_update_hooks();
		}
		return $id;
	}

	/**
	 * Delete a date.
	 *
	 * @param string $date_type Type.
	 * @throws Exception When not allowed.
	 */
	public function delete_date( $date_type ) {
		$date_type = $this->normalise_date_type( $date_type );
		if ( in_array( $date_type, array( 'date_created', 'start' ), true ) ) {
			/* translators: %s: date type */
			throw new Exception( sprintf( esc_html__( 'The %s date of a subscription can not be deleted.', 'ulixes-subscriptions' ), esc_html( $date_type ) ) );
		}
		if ( 'last_order_date_created' === $date_type ) {
			return;
		}
		$this->update_dates( array( $date_type => 0 ) );
	}

	/**
	 * Validate date order rules.
	 *
	 * @param array $updates Updates.
	 * @return array errors
	 */
	protected function validate_date_updates( $updates ) {
		$errors = array();
		$times  = array();
		foreach ( array( 'start', 'trial_end', 'next_payment', 'end', 'cancelled' ) as $t ) {
			$times[ $t ] = array_key_exists( $t, $updates ) ? ulx_date_to_time( $updates[ $t ] ) : $this->get_time( $t );
		}
		if ( ! $times['start'] && $this->get_date_created() ) {
			$times['start'] = $this->get_date_created()->getTimestamp();
		}

		if ( $times['trial_end'] && $times['start'] && $times['trial_end'] <= $times['start'] && array_key_exists( 'trial_end', $updates ) ) {
			$errors[] = __( 'The trial_end date must occur after the start date.', 'ulixes-subscriptions' );
		}
		if ( $times['next_payment'] && array_key_exists( 'next_payment', $updates ) ) {
			if ( $times['start'] && $times['next_payment'] <= $times['start'] ) {
				$errors[] = __( 'The next_payment date must occur after the start date.', 'ulixes-subscriptions' );
			}
			if ( $times['trial_end'] && $times['next_payment'] < $times['trial_end'] ) {
				$errors[] = __( 'The next_payment date must occur after the trial_end date.', 'ulixes-subscriptions' );
			}
			if ( $times['end'] && $times['next_payment'] >= $times['end'] ) {
				$errors[] = __( 'The next_payment date must occur before the end date.', 'ulixes-subscriptions' );
			}
		}
		if ( $times['end'] && array_key_exists( 'end', $updates ) ) {
			if ( $times['start'] && $times['end'] <= $times['start'] ) {
				$errors[] = __( 'The end date must occur after the start date.', 'ulixes-subscriptions' );
			}
			if ( $times['trial_end'] && $times['end'] <= $times['trial_end'] && ! $this->has_status( array( 'cancelled', 'pending-cancel', 'expired', 'switched' ) ) ) {
				$errors[] = __( 'The end date must occur after the trial_end date.', 'ulixes-subscriptions' );
			}
			if ( $times['next_payment'] && $times['end'] <= $times['next_payment'] && ! array_key_exists( 'next_payment', $updates ) && ! $this->has_status( array( 'cancelled', 'pending-cancel', 'expired', 'switched' ) ) ) {
				$errors[] = __( 'The end date must occur after the next_payment date.', 'ulixes-subscriptions' );
			}
		}
		return apply_filters( 'ulx_subscription_date_validation_errors', $errors, $updates, $this );
	}

	/**
	 * Calculate a date.
	 *
	 * @param string $date_type next_payment|trial_end|end_of_prepaid_term.
	 * @return string 0|mysql gmt
	 */
	public function calculate_date( $date_type ) {
		switch ( $date_type ) {
			case 'next_payment':
				$date = $this->calculate_next_payment_date();
				break;
			case 'trial_end':
				if ( $this->get_payment_count() >= 2 ) {
					$date = 0;
				} elseif ( $this->get_time( 'trial_end' ) ) {
					$date = $this->get_date( 'trial_end' );
				} else {
					$date = 0;
				}
				break;
			case 'end_of_prepaid_term':
				$next = $this->get_time( 'next_payment' );
				$end  = $this->get_time( 'end' );
				if ( $next > time() ) {
					$date = ulx_date_to_mysql( $next );
				} elseif ( $end > time() ) {
					$date = ulx_date_to_mysql( $end );
				} else {
					$date = current_time( 'mysql', true );
				}
				break;
			default:
				$date = 0;
		}
		return apply_filters( 'ulx_subscription_calculated_' . $date_type . '_date', $date, $this );
	}

	/**
	 * Next payment date calculation.
	 *
	 * Rules (WCS parity):
	 * - If in trial and trial end in future, next payment = trial end.
	 * - Else take the later of last payment date or stored next payment, add billing interval until > now (+2h).
	 * - Don't schedule past end date.
	 *
	 * @return string
	 */
	protected function calculate_next_payment_date() {
		$next_payment = 0;
		$start        = $this->get_time( 'start' );
		$next_stored  = $this->get_time( 'next_payment' );
		$trial_end    = $this->get_time( 'trial_end' );
		$last_payment = $this->get_time( 'last_order_date_created' );
		$end          = $this->get_time( 'end' );

		if ( $trial_end > time() && ( $this->get_payment_count() <= 1 || $this->is_parent_free() ) ) {
			$next_payment = $trial_end;
		} else {
			if ( $next_stored > $last_payment ) {
				$from = $next_stored;
			} elseif ( $last_payment > $start ) {
				$from = $last_payment;
			} else {
				$from = $start;
			}

			$next_payment = ulx_add_time( $this->get_billing_interval(), $this->get_billing_period(), $from );

			$i       = 1;
			$minimum = time() + 2 * HOUR_IN_SECONDS;
			while ( $next_payment < $minimum && $i < 3000 ) {
				$next_payment = ulx_add_time( $this->get_billing_interval(), $this->get_billing_period(), $next_payment );
				$i++;
			}
		}

		// Respect end date with a buffer: no renewal on/after the end date (minus 23 hours as WCS does).
		if ( $end && $next_payment >= ( $end - 23 * HOUR_IN_SECONDS ) ) {
			$next_payment = 0;
		}

		return $next_payment ? ulx_date_to_mysql( $next_payment ) : 0;
	}

	/**
	 * Whether the parent order was free (e.g. free trial with no sign-up fee).
	 *
	 * @return bool
	 */
	protected function is_parent_free() {
		$parent = $this->get_parent();
		return $parent && 0 == $parent->get_total(); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	/*
	|--------------------------------------------------------------------------
	| Status handling
	|--------------------------------------------------------------------------
	*/

	/**
	 * Statuses considered "ended".
	 *
	 * @return array
	 */
	public static function get_ended_statuses() {
		return apply_filters( 'ulx_subscription_ended_statuses', array( 'cancelled', 'trash', 'expired', 'switched', 'pending-cancel' ) );
	}

	/**
	 * Can the subscription move to a status?
	 *
	 * @param string $new_status Status without prefix.
	 * @return bool
	 */
	public function can_be_updated_to( $new_status ) {
		$new_status = 'wc-' === substr( $new_status, 0, 3 ) ? substr( $new_status, 3 ) : $new_status;
		$can        = false;

		switch ( $new_status ) {
			case 'pending':
				$can = $this->has_status( array( 'auto-draft', 'draft' ) );
				break;
			case 'completed':
			case 'active':
				if ( $this->payment_method_supports( 'subscription_reactivation' ) && $this->has_status( 'on-hold' ) ) {
					$end = $this->get_time( 'end' );
					$can = ( 0 === $end || $end > time() );
				} elseif ( $this->has_status( 'pending' ) ) {
					$can = true;
				} elseif ( $this->has_status( 'pending-cancel' ) && $this->get_time( 'end' ) > time() && ( $this->is_manual() || ( false === $this->payment_method_supports( 'gateway_scheduled_payments' ) && $this->payment_method_supports( 'subscription_date_changes' ) && $this->payment_method_supports( 'subscription_reactivation' ) ) ) ) {
					$can = true;
				}
				break;
			case 'failed':
			case 'on-hold':
				$can = $this->payment_method_supports( 'subscription_suspension' ) && $this->has_status( array( 'active', 'pending' ) );
				break;
			case 'pending-cancel':
				if ( $this->payment_method_supports( 'subscription_cancellation' ) ) {
					if ( $this->has_status( 'active' ) ) {
						$can = true;
					} elseif ( ! $this->needs_payment() && $this->has_status( array( 'cancelled', 'on-hold' ) ) ) {
						$can = true;
					}
				}
				break;
			case 'cancelled':
				$can = $this->payment_method_supports( 'subscription_cancellation' ) && ( $this->has_status( 'pending-cancel' ) || ! $this->has_status( self::get_ended_statuses() ) );
				break;
			case 'switched':
				$can = ! $this->has_status( self::get_ended_statuses() ) || $this->has_status( 'pending-cancel' );
				break;
			case 'expired':
				$can = ! $this->has_status( array( 'cancelled', 'trash', 'switched' ) );
				break;
			case 'trash':
				$can = $this->has_status( self::get_ended_statuses() ) || $this->can_be_updated_to( 'cancelled' );
				break;
			case 'deleted':
				$can = $this->has_status( 'trash' );
				break;
			default:
				$can = false;
		}

		return (bool) apply_filters( 'ulx_can_subscription_be_updated_to_' . $new_status, $can, $this );
	}

	/**
	 * Update status with side effects.
	 *
	 * @param string $new_status Status.
	 * @param string $note       Note.
	 * @param bool   $manual     Manual.
	 * @return bool
	 * @throws Exception When status not allowed.
	 */
	public function update_status( $new_status, $note = '', $manual = false ) {
		if ( ! $this->get_id() ) {
			return false;
		}
		$new_status = 'wc-' === substr( $new_status, 0, 3 ) ? substr( $new_status, 3 ) : $new_status;
		// Map core order statuses onto subscription statuses.
		if ( 'completed' === $new_status || 'processing' === $new_status ) {
			$new_status = 'active';
		} elseif ( 'failed' === $new_status ) {
			$new_status = 'on-hold';
		}
		$old_status = $this->get_status();

		if ( $new_status === $old_status && in_array( 'wc-' . $old_status, $this->get_valid_statuses(), true ) ) {
			return false;
		}

		do_action( 'ulx_subscription_pre_update_status', $old_status, $new_status, $this );

		if ( ! $this->can_be_updated_to( $new_status ) ) {
			$message = sprintf(
				/* translators: 1: subscription id, 2: old status, 3: new status */
				__( 'Unable to change subscription #%1$s status from %2$s to %3$s.', 'ulixes-subscriptions' ),
				$this->get_order_number(),
				ulx_get_subscription_status_name( $old_status ),
				ulx_get_subscription_status_name( $new_status )
			);
			$this->add_order_note( $message );
			do_action( 'ulx_subscription_unable_to_update_status', $this, $new_status, $old_status );
			ulx_log( $message, 'warning' );
			return false;
		}

		try {
			$this->set_status( $new_status, $note, $manual );

			switch ( $new_status ) {
				case 'pending':
					break;

				case 'pending-cancel':
					$end_date = $this->calculate_date( 'end_of_prepaid_term' );
					if ( empty( $end_date ) || ulx_date_to_time( $end_date ) < time() ) {
						$cancelled = current_time( 'mysql', true );
						$end_date  = $cancelled;
					} else {
						$cancelled = current_time( 'mysql', true );
					}
					$this->update_meta_data( '_schedule_trial_end', 0 );
					$this->delete_meta_data( '_schedule_trial_end' );
					$this->delete_meta_data( '_schedule_next_payment' );
					$this->pending_date_updates['trial_end']    = 0;
					$this->pending_date_updates['next_payment'] = 0;
					$existing_end = $this->get_time( 'end' );
					if ( $existing_end > time() ) {
						$this->update_meta_data( '_ulx_original_end', $this->get_date( 'end' ) );
					} else {
						$this->delete_meta_data( '_ulx_original_end' );
					}
					$this->update_meta_data( '_schedule_cancelled', $cancelled );
					$this->update_meta_data( '_schedule_end', $end_date );
					$this->pending_date_updates['cancelled'] = $cancelled;
					$this->pending_date_updates['end']       = $end_date;
					break;

				case 'active':
					$stored_next = $this->get_time( 'next_payment' );
					$threshold   = apply_filters( 'ulx_subscription_activation_next_payment_date_threshold', 2 * HOUR_IN_SECONDS, $stored_next, $old_status, $this );
					if ( $stored_next < time() + $threshold ) {
						$calculated = $this->calculate_date( 'next_payment' );
						if ( $calculated ) {
							$this->update_meta_data( '_schedule_next_payment', $calculated );
							$this->pending_date_updates['next_payment'] = $calculated;
						} elseif ( $stored_next < time() ) {
							$this->delete_meta_data( '_schedule_next_payment' );
							$this->pending_date_updates['next_payment'] = 0;
						}
					} else {
						do_action( 'ulx_subscription_activation_next_payment_not_recalculated', $stored_next, $old_status, $this );
					}
					// Reactivating a pending-cancel subscription removes the cancelled & end dates
					// set at cancellation (unless an end date existed from the product length).
					if ( 'pending-cancel' === $old_status ) {
						$this->delete_meta_data( '_schedule_cancelled' );
						$this->pending_date_updates['cancelled'] = 0;
						$original_end = $this->get_meta( '_ulx_original_end', true );
						if ( $original_end ) {
							$this->update_meta_data( '_schedule_end', $original_end );
							$this->pending_date_updates['end'] = $original_end;
						} else {
							$this->delete_meta_data( '_schedule_end' );
							$this->pending_date_updates['end'] = 0;
						}
						$next = $this->calculate_date( 'next_payment' );
						if ( $next ) {
							$this->update_meta_data( '_schedule_next_payment', $next );
							$this->pending_date_updates['next_payment'] = $next;
						}
					}
					ulx_make_user_active( $this->get_user_id() );
					break;

				case 'on-hold':
					$this->set_suspension_count( $this->get_suspension_count() + 1 );
					ulx_maybe_make_user_inactive( $this->get_user_id() );
					break;

				case 'cancelled':
				case 'switched':
				case 'expired':
					$now = current_time( 'mysql', true );
					$this->delete_meta_data( '_schedule_trial_end' );
					$this->delete_meta_data( '_schedule_next_payment' );
					$this->pending_date_updates['trial_end']    = 0;
					$this->pending_date_updates['next_payment'] = 0;

					$end = $this->get_time( 'end' );
					if ( 'expired' === $new_status && $end && $end <= time() ) {
						// Keep the scheduled end date.
						$now = $this->get_date( 'end' );
					}
					if ( 'cancelled' === $new_status && 'pending-cancel' === $old_status && $end ) {
						$now = $end <= time() ? $this->get_date( 'end' ) : $now;
					}
					if ( 'cancelled' === $new_status && ! $this->get_time( 'cancelled' ) ) {
						$this->update_meta_data( '_schedule_cancelled', current_time( 'mysql', true ) );
						$this->pending_date_updates['cancelled'] = current_time( 'mysql', true );
					}
					$this->update_meta_data( '_schedule_end', $now );
					$this->pending_date_updates['end'] = $now;
					ulx_maybe_make_user_inactive( $this->get_user_id() );
					break;
			}

			$this->save();
		} catch ( Exception $e ) {
			ulx_log( 'Subscription status update error: ' . $e->getMessage(), 'error' );
			$this->add_order_note( sprintf( /* translators: %s: error */ __( 'Unable to change subscription status to "%s".', 'ulixes-subscriptions' ), $new_status ) . ' ' . $e->getMessage() );
			do_action( 'ulx_subscription_unable_to_update_status', $this, $new_status, $old_status );
			return false;
		}

		return true;
	}

	/**
	 * Fire subscription specific status transition hooks.
	 */
	protected function status_transition() {
		if ( ! $this->status_transition ) {
			return;
		}
		$transition              = $this->status_transition;
		$this->status_transition = false;

		do_action( 'ulx_subscription_status_' . $transition['to'], $this );

		if ( ! empty( $transition['from'] ) && $transition['from'] !== $transition['to'] ) {
			do_action( 'ulx_subscription_status_' . $transition['from'] . '_to_' . $transition['to'], $this );
			do_action( 'ulx_subscription_status_updated', $this, $transition['to'], $transition['from'] );
			do_action( 'ulx_subscription_status_changed', $this->get_id(), $transition['from'], $transition['to'], $this );

			$note = sprintf(
				/* translators: 1: old status, 2: new status */
				__( 'Status changed from %1$s to %2$s.', 'ulixes-subscriptions' ),
				ulx_get_subscription_status_name( $transition['from'] ),
				ulx_get_subscription_status_name( $transition['to'] )
			);
			$this->add_order_note( trim( $transition['note'] . ' ' . $note ), 0, ! empty( $transition['manual'] ) );
		} elseif ( ! empty( $transition['note'] ) ) {
			$this->add_order_note( $transition['note'], 0, ! empty( $transition['manual'] ) );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Payments
	|--------------------------------------------------------------------------
	*/

	/**
	 * Does the payment method support a feature? Manual subscriptions support everything.
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function payment_method_supports( $feature ) {
		$supports = false;
		if ( $this->is_manual() ) {
			$supports = true;
		} else {
			$gateway = ulx_get_payment_gateway( $this->get_payment_method() );
			$supports = $gateway && $gateway->supports( $feature );
		}
		return (bool) apply_filters( 'ulx_subscription_payment_gateway_supports', $supports, $feature, $this );
	}

	/**
	 * Called when a renewal (or parent) payment completes.
	 *
	 * @param string $transaction_id Transaction ID.
	 * @return bool
	 */
	public function payment_complete( $transaction_id = '' ) {
		$sub_id = $this->get_id();
		if ( ! empty( self::$processing_payment_complete[ $sub_id ] ) ) {
			return false;
		}
		self::$processing_payment_complete[ $sub_id ] = true;

		$last_order = $this->get_last_order( 'all', array( 'renewal', 'switch', 'parent' ) );
		if ( $last_order && $last_order->needs_payment() ) {
			$last_order->payment_complete( $transaction_id );
		}
		$this->set_suspension_count( 0 );
		$this->delete_meta_data( '_ulx_failed_renewal_count' );
		if ( $transaction_id ) {
			$this->set_transaction_id( $transaction_id );
		}
		$this->save();

		if ( $this->can_be_updated_to( 'active' ) ) {
			$this->update_status( 'active' );
		}

		do_action( 'ulx_subscription_payment_complete', $this );
		if ( $last_order && ulx_order_contains_renewal( $last_order ) ) {
			do_action( 'ulx_subscription_renewal_payment_complete', $this, $last_order );
		}
		unset( self::$processing_payment_complete[ $sub_id ] );
		return true;
	}

	/**
	 * Called when a renewal payment fails.
	 *
	 * @param string $new_status Status to set (default on-hold).
	 */
	public function payment_failed( $new_status = 'on-hold' ) {
		$last_order = $this->get_last_order( 'all', array( 'renewal', 'switch', 'parent' ) );
		if ( $last_order && ! $last_order->has_status( 'failed' ) && $last_order->needs_payment() ) {
			remove_action( 'woocommerce_order_status_failed', array( 'ULX_Renewal_Order', 'maybe_record_subscription_payment_failed' ), 10 );
			$last_order->update_status( 'failed' );
			add_action( 'woocommerce_order_status_failed', array( 'ULX_Renewal_Order', 'maybe_record_subscription_payment_failed' ), 10, 2 );
		}

		$this->update_meta_data( '_ulx_failed_renewal_count', (int) $this->get_meta( '_ulx_failed_renewal_count', true ) + 1 );
		$this->save_meta_data();

		$new_status = apply_filters( 'ulx_subscription_payment_failed_status', $new_status, $this, $last_order );
		if ( 'cancelled' === $new_status && $this->can_be_updated_to( 'cancelled' ) ) {
			$this->update_status( 'cancelled', __( 'Subscription cancelled for failed renewal payment.', 'ulixes-subscriptions' ) );
		} elseif ( $this->can_be_updated_to( $new_status ) ) {
			$this->update_status( $new_status, __( 'Payment failed.', 'ulixes-subscriptions' ) );
		}

		do_action( 'ulx_subscription_payment_failed', $this, $new_status );
		if ( $last_order && ulx_order_contains_renewal( $last_order ) ) {
			do_action( 'ulx_subscription_renewal_payment_failed', $this, $last_order );
		}
	}

	/**
	 * Does the subscription need payment? (parent or last renewal unpaid)
	 *
	 * @return bool
	 */
	public function needs_payment() {
		$needs = false;
		if ( $this->has_status( array( 'pending', 'on-hold' ) ) ) {
			$parent = $this->get_parent();
			if ( $parent && ( $parent->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) && $parent->get_total() > 0 ) {
				$needs = true;
			} else {
				$last = $this->get_last_order( 'all', array( 'renewal' ) );
				if ( $last && $last->has_status( array( 'pending', 'failed', 'on-hold' ) ) && $last->get_total() > 0 ) {
					$needs = true;
				}
			}
		}
		return (bool) apply_filters( 'ulx_subscription_needs_payment', $needs, $this );
	}

	/**
	 * Number of payments.
	 *
	 * @param string $payment_type completed|refunded|net.
	 * @param array  $order_types  Types to include.
	 * @return int
	 */
	public function get_payment_count( $payment_type = 'completed', $order_types = array( 'parent', 'renewal', 'switch' ) ) {
		$count = 0;
		foreach ( $this->get_related_orders( 'all', $order_types ) as $order ) {
			$paid = $order->get_date_paid() || $order->has_status( wc_get_is_paid_statuses() );
			if ( 'completed' === $payment_type && $paid && ! $order->has_status( 'refunded' ) ) {
				$count++;
			} elseif ( 'refunded' === $payment_type && $order->has_status( 'refunded' ) ) {
				$count++;
			} elseif ( 'net' === $payment_type && $paid && ! $order->has_status( 'refunded' ) ) {
				$count++;
			}
		}
		return (int) apply_filters( 'ulx_subscription_payment_count', $count, $payment_type, $this );
	}

	/**
	 * Failed payments.
	 *
	 * @return int
	 */
	public function get_failed_payment_count() {
		$count = 0;
		foreach ( $this->get_related_orders( 'all', array( 'parent', 'renewal' ) ) as $order ) {
			if ( $order->has_status( 'failed' ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Total initial payment (parent order total).
	 *
	 * @return float
	 */
	public function get_total_initial_payment() {
		$parent = $this->get_parent();
		return (float) apply_filters( 'ulx_subscription_total_initial_payment', $parent ? $parent->get_total() : 0, $this );
	}

	/**
	 * Sign up fee (sum of line item sign-up fees, stored at creation).
	 *
	 * @return float
	 */
	public function get_sign_up_fee() {
		$fee = 0;
		foreach ( $this->get_items() as $item ) {
			$fee += (float) $item->get_meta( '_ulx_sign_up_fee', true ) * max( 1, $item->get_quantity() );
		}
		return (float) apply_filters( 'ulx_subscription_sign_up_fee', $fee, $this );
	}

	/**
	 * Item sign-up fee.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @param string                $tax_inclusive_or_exclusive inclusive|exclusive.
	 * @return float
	 */
	public function get_items_sign_up_fee( $item, $tax_inclusive_or_exclusive = 'exclusive_of_tax' ) {
		return (float) $item->get_meta( '_ulx_sign_up_fee', true ) * max( 1, $item->get_quantity() );
	}

	/*
	|--------------------------------------------------------------------------
	| Related orders
	|--------------------------------------------------------------------------
	*/

	/**
	 * Parent order.
	 *
	 * @return WC_Order|false
	 */
	public function get_parent() {
		$parent_id = $this->get_parent_id();
		return $parent_id ? wc_get_order( $parent_id ) : false;
	}

	/**
	 * Related orders.
	 *
	 * @param string       $return_fields ids|all.
	 * @param string|array $order_types   parent|renewal|switch|resubscribe|any.
	 * @return array
	 */
	public function get_related_orders( $return_fields = 'ids', $order_types = array( 'parent', 'renewal', 'switch' ) ) {
		$order_types = (array) $order_types;
		if ( in_array( 'any', $order_types, true ) ) {
			$order_types = array( 'parent', 'renewal', 'switch', 'resubscribe' );
		}
		$ids = array();
		foreach ( $order_types as $type ) {
			if ( 'parent' === $type ) {
				if ( $this->get_parent_id() ) {
					$ids[] = $this->get_parent_id();
				}
				continue;
			}
			$ids = array_merge( $ids, ulx_get_related_order_ids( $this->get_id(), $type ) );
		}
		$ids = array_unique( array_map( 'absint', $ids ) );
		rsort( $ids );
		$ids = apply_filters( 'ulx_subscription_related_order_ids', $ids, $this, $order_types );

		if ( 'all' === $return_fields ) {
			$orders = array();
			foreach ( $ids as $id ) {
				$o = wc_get_order( $id );
				if ( $o ) {
					$orders[ $id ] = $o;
				}
			}
			return $orders;
		}
		return $ids;
	}

	/**
	 * Most recent related order.
	 *
	 * @param string       $return_fields ids|all.
	 * @param string|array $order_types   Types.
	 * @param array        $exclude_statuses Statuses to skip.
	 * @return int|WC_Order|false
	 */
	public function get_last_order( $return_fields = 'ids', $order_types = array( 'parent', 'renewal' ), $exclude_statuses = array() ) {
		$ids = $this->get_related_orders( 'ids', $order_types );
		foreach ( $ids as $id ) {
			if ( ! empty( $exclude_statuses ) ) {
				$o = wc_get_order( $id );
				if ( ! $o || $o->has_status( $exclude_statuses ) ) {
					continue;
				}
				return 'all' === $return_fields ? $o : $id;
			}
			if ( 'all' === $return_fields ) {
				$o = wc_get_order( $id );
				return $o ? $o : false;
			}
			return $id;
		}
		return false;
	}

	/*
	|--------------------------------------------------------------------------
	| Items & display
	|--------------------------------------------------------------------------
	*/

	/**
	 * Has product (or variation).
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function has_product( $product_id ) {
		foreach ( $this->get_items() as $item ) {
			if ( (int) $item->get_product_id() === (int) $product_id || (int) $item->get_variation_id() === (int) $product_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is there only one payment for this subscription (ends after first period)?
	 *
	 * @return bool
	 */
	public function is_one_payment() {
		$is = false;
		$end = $this->get_time( 'end' );
		if ( $end ) {
			$from = max( $this->get_time( 'trial_end' ), $this->get_time( 'start' ) );
			$next = ulx_add_time( $this->get_billing_interval(), $this->get_billing_period(), $from );
			$is   = $next >= $end;
		}
		return (bool) apply_filters( 'ulx_subscription_is_one_payment', $is, $this );
	}

	/**
	 * Formatted recurring total string e.g. "$10.00 / month".
	 *
	 * @param string $tax_display Tax display.
	 * @param bool   $display_refunded Display refunded.
	 * @return string
	 */
	public function get_formatted_order_total( $tax_display = '', $display_refunded = true ) {
		$total = wc_price( $this->get_total(), array( 'currency' => $this->get_currency() ) );
		$str   = ulx_get_formatted_price_string(
			array(
				'recurring_amount'      => $total,
				'subscription_period'   => $this->get_billing_period(),
				'subscription_interval' => $this->get_billing_interval(),
			)
		);
		return apply_filters( 'ulx_subscription_formatted_order_total', $str, $this, $tax_display );
	}

	/**
	 * Line subtotal string with period.
	 *
	 * @param WC_Order_Item $item        Item.
	 * @param string        $tax_display Display.
	 * @return string
	 */
	public function get_formatted_line_subtotal( $item, $tax_display = '' ) {
		$subtotal = parent::get_formatted_line_subtotal( $item, $tax_display );
		return ulx_get_formatted_price_string(
			array(
				'recurring_amount'      => $subtotal,
				'subscription_period'   => $this->get_billing_period(),
				'subscription_interval' => $this->get_billing_interval(),
			)
		);
	}

	/**
	 * Totals for display (adds period to subtotal/total rows).
	 *
	 * @param string $tax_display Display.
	 * @return array
	 */
	public function get_order_item_totals( $tax_display = '' ) {
		$totals = parent::get_order_item_totals( $tax_display );
		if ( isset( $totals['order_total'] ) ) {
			$totals['order_total']['value'] = $this->get_formatted_order_total( $tax_display );
		}
		return apply_filters( 'ulx_subscription_order_item_totals', $totals, $this, $tax_display );
	}

	/**
	 * View URL on My Account.
	 *
	 * @return string
	 */
	public function get_view_order_url() {
		return apply_filters( 'ulx_subscription_view_url', wc_get_endpoint_url( 'view-subscription', $this->get_id(), wc_get_page_permalink( 'myaccount' ) ), $this->get_id() );
	}

	/**
	 * Edit URL.
	 *
	 * @return string
	 */
	public function get_edit_order_url() {
		return ulx_get_edit_post_link( $this->get_id() );
	}

	/**
	 * Order number (filterable, shares numbering with orders).
	 *
	 * @return string
	 */
	public function get_order_number() {
		return (string) apply_filters( 'ulx_subscription_number', $this->get_id(), $this );
	}

	/**
	 * Subscriptions never need processing.
	 *
	 * @return bool
	 */
	public function needs_processing() {
		return false;
	}

	/**
	 * Is the subscription editable in admin?
	 *
	 * @return bool
	 */
	public function is_editable() {
		$editable = $this->has_status( array( 'pending', 'draft', 'auto-draft' ) ) || ( $this->is_manual() || $this->payment_method_supports( 'subscription_amount_changes' ) );
		return (bool) apply_filters( 'ulx_subscription_is_editable', $editable, $this );
	}

	/**
	 * Billing schedule string: "Every 2nd month".
	 *
	 * @return string
	 */
	public function get_billing_schedule_string() {
		return ulx_get_formatted_price_string(
			array(
				'recurring_amount'      => '',
				'subscription_period'   => $this->get_billing_period(),
				'subscription_interval' => $this->get_billing_interval(),
			)
		);
	}

	/**
	 * Payment method title shown to customers (adds "Manual Renewal").
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public function get_payment_method_to_display( $context = 'admin' ) {
		if ( $this->is_manual() ) {
			$title = __( 'Manual Renewal', 'ulixes-subscriptions' );
		} else {
			$title = $this->get_payment_method_title();
			if ( empty( $title ) ) {
				$gw    = ulx_get_payment_gateway( $this->get_payment_method() );
				$title = $gw ? $gw->get_title() : $this->get_payment_method();
			}
		}
		/* translators: %s payment method */
		$title = ( 'customer' === $context && ! $this->is_manual() ) ? sprintf( __( 'Via %s', 'ulixes-subscriptions' ), $title ) : $title;
		return apply_filters( 'ulx_subscription_payment_method_to_display', $title, $this, $context );
	}
}
