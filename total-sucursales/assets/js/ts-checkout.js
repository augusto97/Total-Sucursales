/* global ts_params, jQuery */
(function ($) {
	'use strict';

	if (typeof ts_params === 'undefined' || !window.TotalSucursales) {
		return;
	}
	var TS = window.TotalSucursales;

	// WooCommerce no recalcula el checkout clásico mientras quede vacío algún campo de dirección
	// obligatorio, así que el retiro aparecería al terminar de escribir la dirección y no al elegir el
	// municipio. Con el retiro por municipio el municipio decide por sí solo: se recalcula al elegirlo.
	if (ts_params.pickup_by_municipio) {
		$(document.body).on('change', 'select#billing_city, select#shipping_city', function () {
			if ($(this).val()) {
				$(document.body).trigger('update_checkout');
			}
		});
	}

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
