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

	function toggleSubscriptionProductFields() {
		enableVariableSubscriptionUI();
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

		$(document).on('woocommerce_variations_loaded woocommerce_variations_added', function () {
			toggleSubscriptionProductFields();
		});
	});
})(jQuery);

