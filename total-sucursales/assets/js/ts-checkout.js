/* global ts_params, jQuery */
(function ($) {
	'use strict';

	if (typeof ts_params === 'undefined' || !window.TotalSucursales) {
		return;
	}
	var TS = window.TotalSucursales;

	$(document).on('click', '.ts-checkout-geo__btn', function (e) {
		e.preventDefault();
		var $btn = $(this).prop('disabled', true);
		var $st = $btn.closest('td').find('.ts-checkout-geo__status').text(ts_params.i18n.locating);

		TS.locate(function (lat, lng) {
			TS.setPosition(lat, lng, 'checkout').done(function (res) {
				if (res && res.success) {
					$st.text(ts_params.i18n.located);
					$(document.body).trigger('update_checkout');
				} else {
					$st.text(ts_params.i18n.checkout_error);
					$btn.prop('disabled', false);
				}
			}).fail(function () {
				$st.text(ts_params.i18n.checkout_error);
				$btn.prop('disabled', false);
			});
		}, function (code) {
			$st.text(ts_params.i18n.checkout_error);
			$btn.prop('disabled', false);
		});
	});

})(jQuery);
