/**
 * Ulixes Subscriptions - Frontend Script
 */
(function ($) {
	'use strict';

	$(document).on('change', '.ulixsu-auto-renew-checkbox', function () {
		var $checkbox = $(this);
		var subId = $checkbox.data('subscription-id');
		var enableAuto = $checkbox.is(':checked') ? 'yes' : 'no';

		$checkbox.prop('disabled', true);

		$.post(
			ulixsu_params.ajax_url,
			{
				action: 'ulixsu_toggle_auto_renewal',
				security: ulixsu_params.auto_renew_nonce,
				subscription_id: subId,
				enable_auto_renew: enableAuto
			},
			function (response) {
				$checkbox.prop('disabled', false);
				if (response && response.success && response.data.payment_method) {
					$('.ulixsu-payment-method-display').text(response.data.payment_method);
				} else {
					$checkbox.prop('checked', ! $checkbox.is(':checked'));
					window.alert(ulixsu_params.auto_renew_error);
				}
			}
		).fail(function () {
			$checkbox.prop('disabled', false);
			$checkbox.prop('checked', ! $checkbox.is(':checked'));
			window.alert(ulixsu_params.auto_renew_error);
		});
	});

	$(document).on('click', '.subscription_details a.cancel', function (e) {
		if (!window.confirm(ulixsu_params.confirm_cancel)) {
			e.preventDefault();
		}
	});

	$(document).on('click', '.subscription_details a.suspend', function (e) {
		if (!window.confirm(ulixsu_params.confirm_suspend)) {
			e.preventDefault();
		}
	});

	// Dynamically update button text on variable subscription product pages when selecting current plan vs upgrade/downgrade plan.
	$(document).on('found_variation', 'form.variations_form', function (event, variation) {
		var $form = $(this);
		var $btn = $form.find('.single_add_to_cart_button');
		if (!$btn.data('ulixsu-default-text')) {
			$btn.data('ulixsu-default-text', $btn.text());
		}
		if (variation && variation.ulixsu_button_text) {
			$btn.text(variation.ulixsu_button_text);
		}
	});

	$(document).on('reset_data', 'form.variations_form', function () {
		var $form = $(this);
		var $btn = $form.find('.single_add_to_cart_button');
		if ($btn.data('ulixsu-default-text')) {
			$btn.text($btn.data('ulixsu-default-text'));
		}
	});
})(jQuery);

