/* global ts_params, jQuery */
(function ($) {
	'use strict';

	if (typeof ts_params === 'undefined') {
		return;
	}

	var TS = window.TotalSucursales = {

		post: function (action, data) {
			return $.post(ts_params.ajax_url, $.extend({ action: action, nonce: ts_params.nonce }, data || {}));
		},

		setState: function (state) {
			return TS.post('ts_set_state', { state: state }).done(function (res) {
				if (res && res.success && res.data.reload) {
					window.location.reload();
				}
			});
		},

		/**
		 * Pide la posición al navegador. onOk(lat, lng) / onErr(code)
		 */
		locate: function (onOk, onErr) {
			if (!navigator.geolocation) {
				onErr('unsupported');
				return;
			}
			navigator.geolocation.getCurrentPosition(
				function (pos) { onOk(pos.coords.latitude, pos.coords.longitude); },
				function (err) { onErr(err && err.code === 1 ? 'denied' : 'error'); },
				{ enableHighAccuracy: true, timeout: 10000, maximumAge: 5 * 60 * 1000 }
			);
		},

		setPosition: function (lat, lng, context) {
			return TS.post('ts_set_position', { lat: lat, lng: lng, context: context || 'browse' });
		},

		showModal: function (statusText) {
			var $m = $('#ts-state-modal');
			if (!$m.length) { return; }
			if (statusText) { $m.find('.ts-modal__status').text(statusText); }
			$m.prop('hidden', false).addClass('is-open');
		},

		hideModal: function () {
			$('#ts-state-modal').prop('hidden', true).removeClass('is-open');
		},

		autoDetect: function () {
			if (ts_params.has_choice || ts_params.detect_state === 'off') {
				return;
			}
			if (ts_params.detect_state === 'ask') {
				TS.showModal();
				return;
			}
			// gps
			TS.locate(function (lat, lng) {
				TS.setPosition(lat, lng, 'browse').done(function (res) {
					if (res && res.success && (res.data.reload || res.data.changed)) {
						window.location.reload();
					} else if (!res || !res.success || !res.data.state) {
						if (ts_params.ask_if_gps_fails) { TS.showModal(); }
					}
				}).fail(function () {
					if (ts_params.ask_if_gps_fails) { TS.showModal(ts_params.i18n.geo_error); }
				});
			}, function (code) {
				if (ts_params.ask_if_gps_fails) {
					TS.showModal(code === 'denied' ? ts_params.i18n.geo_denied : ts_params.i18n.geo_error);
				}
			});
		},

		bindModal: function () {
			var $m = $('#ts-state-modal');
			if (!$m.length) { return; }
			$m.on('click', '.ts-modal__ok', function () {
				var v = $m.find('.ts-modal__select').val();
				if (!v) { $m.find('.ts-modal__select').focus(); return; }
				TS.setState(v);
			});
			$m.on('click', '.ts-modal__gps', function () {
				var $st = $m.find('.ts-modal__status').text(ts_params.i18n.locating);
				TS.locate(function (lat, lng) {
					TS.setPosition(lat, lng, 'browse').done(function (res) {
						if (res && res.success && res.data.state) {
							window.location.reload();
						} else {
							$st.text(ts_params.i18n.geo_error);
						}
					});
				}, function (code) {
					$st.text(code === 'denied' ? ts_params.i18n.geo_denied : ts_params.i18n.geo_error);
				});
			});
		},

		bindSelector: function () {
			$(document).on('change', '.ts-state-select', function () {
				TS.setState($(this).val() || '__all__');
			});
			$(document).on('click', '.ts-use-gps', function () {
				var $b = $(this).prop('disabled', true);
				TS.locate(function (lat, lng) {
					TS.setPosition(lat, lng, 'browse').done(function () { window.location.reload(); });
				}, function () {
					$b.prop('disabled', false);
					window.alert(ts_params.i18n.geo_error);
				});
			});
		},

		/**
		 * Página de producto: dejar sólo la sucursal seleccionada en los <select> de MLI.
		 */
		singleLocationView: function () {
			if (!ts_params.single_location_view || !ts_params.selected_location_id) {
				return;
			}
			var id = String(ts_params.selected_location_id);
			var prune = function () {
				$('select.select_location option[data-lc-termid]').each(function () {
					var tid = String($(this).attr('data-lc-termid') || '').trim();
					if (tid && tid !== id) { $(this).remove(); }
				});
			};
			prune();
			// MLI repuebla el select al cambiar variación.
			$(document.body).on('found_variation wcmlim_locations_loaded', function () { setTimeout(prune, 50); });
		}
	};

	$(function () {
		TS.bindModal();
		TS.bindSelector();
		TS.singleLocationView();
		if (!ts_params.is_checkout) {
			TS.autoDetect();
		}
	});

})(jQuery);
