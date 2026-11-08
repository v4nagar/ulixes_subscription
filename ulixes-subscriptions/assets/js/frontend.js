/**
 * Ulixes Subscriptions - Frontend Script
 */
(function ($) {
	'use strict';

	$(document).on('change', '.ulx-auto-renew-checkbox', function () {
		var $checkbox = $(this);
		var subId = $checkbox.data('subscription-id');
		var enableAuto = $checkbox.is(':checked') ? 'yes' : 'no';

		$checkbox.prop('disabled', true);

		$.post(
			ulx_params.ajax_url,
			{
				action: 'ulx_toggle_auto_renewal',
				security: ulx_params.auto_renew_nonce,
				subscription_id: subId,
				enable_auto_renew: enableAuto
			},
			function (response) {
				$checkbox.prop('disabled', false);
				if (response && response.success && response.data.payment_method) {
					$('.ulx-payment-method-display').text(response.data.payment_method);
				} else {
					$checkbox.prop('checked', ! $checkbox.is(':checked'));
					window.alert(ulx_params.auto_renew_error);
				}
			}
		).fail(function () {
			$checkbox.prop('disabled', false);
			$checkbox.prop('checked', ! $checkbox.is(':checked'));
			window.alert(ulx_params.auto_renew_error);
		});
	});

	$(document).on('click', '.subscription_details a.cancel', function (e) {
		if (!window.confirm(ulx_params.confirm_cancel)) {
			e.preventDefault();
		}
	});

	$(document).on('click', '.subscription_details a.suspend', function (e) {
		if (!window.confirm(ulx_params.confirm_suspend)) {
			e.preventDefault();
		}
	});
})(jQuery);

