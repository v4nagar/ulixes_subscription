/**
 * Ulixes Subscriptions - Admin Script
 *
 * Shows/hides subscription product fields when "Simple subscription" or "Variable subscription" is selected,
 * and dynamically updates the "Expire after" dropdown options when the billing period changes.
 */
(function ($) {
	'use strict';

	function enableVariableSubscriptionUI() {
		$('.show_if_variable').addClass('show_if_variable-subscription');
		$('.hide_if_variable').addClass('hide_if_variable-subscription');
		$('.enable_variation').addClass('show_if_variable-subscription');
		$('.variations_tab').addClass('show_if_variable-subscription');
		$('.show_if_simple').addClass('show_if_subscription');
	}

	function moveSubscriptionVariationFields() {
		$('#variable_product_options .woocommerce_variation').each(function () {
			var $variation = $(this);
			var $regularPriceRow = $variation.find('.variable_pricing');
			var $subPricing = $variation.find('.variable_subscription_pricing');
			if ($regularPriceRow.length && $subPricing.length) {
				$subPricing.insertBefore($regularPriceRow);
				$regularPriceRow.children(':first').addClass('hide_if_variable-subscription');
			}
		});
	}

	function toggleSubscriptionProductFields() {
		enableVariableSubscriptionUI();
		moveSubscriptionVariationFields();
		var productType = $('select#product-type').val();
		if ('subscription' === productType) {
			$('.show_if_subscription').show();
			$('.options_group.pricing').show();
			$('._regular_price_field').hide();
		} else if ('variable-subscription' === productType) {
			$('.show_if_variable-subscription').show();
			$('.show_if_variable').show();
			$('.enable_variation').show();
			$('.variations_tab').show();
			$('.variable_subscription_pricing').show();
			$('.hide_if_variable-subscription').hide();
			$('#variable_product_options .variable_pricing input[name^="variable_sale_price"]').closest('.form-row').removeClass('form-row-last').addClass('form-row-full');
		} else if ('variable' === productType) {
			$('.variable_subscription_pricing').hide();
			$('#variable_product_options .variable_pricing').children(':first').show();
			$('#variable_product_options .variable_pricing input[name^="variable_sale_price"]').closest('.form-row').removeClass('form-row-full').addClass('form-row-last');
		}
	}

	function updateLengthOptions($periodSelect, $lengthSelect) {
		var period = $periodSelect.val();
		if (!ulx_admin_params || !ulx_admin_params.ranges || !ulx_admin_params.ranges[period]) {
			return;
		}
		var currentVal = $lengthSelect.val();
		var ranges = ulx_admin_params.ranges[period];
		$lengthSelect.empty();
		$.each(ranges, function (val, label) {
			var $opt = $('<option></option>').attr('value', val).text(label);
			if (String(val) === String(currentVal)) {
				$opt.prop('selected', true);
			}
			$lengthSelect.append($opt);
		});
	}

	$(document).ready(function () {
		$('body').on('woocommerce-product-type-change', function () {
			toggleSubscriptionProductFields();
		});
		toggleSubscriptionProductFields();

		$(document).on('change', '#_subscription_period', function () {
			updateLengthOptions($(this), $('#_subscription_length'));
		});

		$(document).on('change', 'select[name^="variable_subscription_period["]', function () {
			var $row = $(this).closest('.variable_subscription_pricing');
			var $lengthSelect = $row.find('select[name^="variable_subscription_length["]');
			if ($lengthSelect.length) {
				updateLengthOptions($(this), $lengthSelect);
			}
		});

		$(document).on('input change', '#_subscription_price', function () {
			$('#_regular_price').val($(this).val());
		});

		$(document).on('input change', 'input[name^="variable_subscription_price["]', function () {
			var $variation = $(this).closest('.woocommerce_variation');
			$variation.find('input[name^="variable_regular_price["]').val($(this).val());
		});

		$(document).on('input change', 'input[name^="variable_regular_price["]', function () {
			var $variation = $(this).closest('.woocommerce_variation');
			$variation.find('input[name^="variable_subscription_price["]').val($(this).val());
		});

		$(document).on('woocommerce_variations_loaded woocommerce_variations_added', function () {
			toggleSubscriptionProductFields();
		});

		if ($.fn.datepicker && $('.ulx-date-picker').length) {
			$('.ulx-date-picker').datepicker({
				dateFormat: 'yy-mm-dd',
				showButtonPanel: true,
				onSelect: function (dateText) {
					var $wrap = $(this).closest('.ulx-date-time-fields');
					var $hh = $wrap.find('.ulx-hour-input');
					var $mm = $wrap.find('.ulx-minute-input');
					if (dateText && '' === $.trim($hh.val())) {
						$hh.val('23');
					}
					if (dateText && '' === $.trim($mm.val())) {
						$mm.val('59');
					}
				}
			});
		}

		function lockEndedSubscriptionStatus() {
			var isSubScreen = $('body').hasClass('post-type-shop_subscription') ||
				window.location.href.indexOf('shop_subscription') !== -1 ||
				window.location.href.indexOf('wc-orders--shop_subscription') !== -1;
			if (!isSubScreen) {
				return;
			}
			var $statusSelect = $('#order_status');
			if (!$statusSelect.length) {
				return;
			}
			var status = $statusSelect.val();
			if (status === 'wc-cancelled' || status === 'cancelled' ||
				status === 'wc-expired' || status === 'expired' ||
				status === 'wc-switched' || status === 'switched' ||
				$statusSelect.children('option').length <= 1) {
				$statusSelect.prop('disabled', true);
				$statusSelect.closest('.order_data_column').find('.wc-order-status, .select2-container').css({
					'pointer-events': 'none',
					'opacity': '0.7'
				});
				if (!$('#order_status_locked_hidden').length) {
					$('<input type="hidden" id="order_status_locked_hidden" name="order_status">')
						.val(status)
						.insertAfter($statusSelect);
				}
			}
		}

		lockEndedSubscriptionStatus();
		setTimeout(lockEndedSubscriptionStatus, 200);
		setTimeout(lockEndedSubscriptionStatus, 600);
	});
})(jQuery);

