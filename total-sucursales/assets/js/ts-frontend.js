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

		/**
		 * Modo "por ciudad": se pide la ubicación una vez. Si la da, el servidor deja sólo las tiendas
		 * de su ciudad (o la tienda por defecto si no hay) y se recarga. Si no, ya está viendo sólo la
		 * tienda por defecto; se recuerda para no volver a preguntar en cada página.
		 */
		autoDetectCity: function () {
			if (ts_params.has_gps || /(?:^|;\s*)ts_geo=(denied|error)/.test(document.cookie)) {
				return;
			}
			TS.locate(function (lat, lng) {
				TS.setPosition(lat, lng, 'browse').done(function (res) {
					if (res && res.success && res.data.reload) { window.location.reload(); }
				});
			}, function (code) {
				var days = code === 'denied' ? 30 : 1;
				document.cookie = 'ts_geo=' + (code === 'denied' ? 'denied' : 'error') + '; path=/; max-age=' + (days * 86400) + '; SameSite=Lax';
			});
		},

		autoDetect: function () {
			if (ts_params.visibility_mode === 'city') {
				TS.autoDetectCity();
				return;
			}
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

	/**
	 * Página de producto: ciudad y dirección bajo el nombre de cada tienda de la lista de stock.
	 *
	 * Multi Locations tiene nueve plantillas para esa lista y ninguna ofrece un filtro, pero todas
	 * marcan cada tienda con .location-stock-item[data-location-id]. Se inserta una línea debajo
	 * del nombre (.location-name) o, si la plantilla no lo marca, debajo de la primera fila.
	 */
	/**
	 * Alinea la línea con el texto del nombre. Cada plantilla de MLI y cada tema ponen el nombre a
	 * una distancia distinta (radio más o menos grande, padding propio), así que se mide.
	 */
	function alignWith($line, $name) {
		if (!$name || !$name.length || !$name.is(':visible')) {
			return;
		}
		var range = document.createRange();
		range.selectNodeContents($name[0]);
		var textLeft = range.getBoundingClientRect().left;
		var diff = textLeft - $line[0].getBoundingClientRect().left;
		if (diff > 0 && diff < 200) {
			$line.css('margin-left', diff + 'px');
		}
	}

	TS.locationAddress = function () {
		var map = ts_params.location_address || {};
		if ($.isEmptyObject(map)) {
			return;
		}
		var PENDING = '.location-stock-item[data-location-id]:not([data-ts-addr])';
		var paint = function () {
			$(PENDING).each(function () {
				var $item = $(this).attr('data-ts-addr', '1');
				var line = map[String($item.attr('data-location-id')).trim()];
				if (!line) {
					return;
				}
				var $line = $('<div class="ts-loc-address"></div>').text(line);
				var $name = $item.find('.location-name').first();
				if ($name.length) {
					$line.insertAfter($name);
				} else {
					// Sin .location-name es la vista de lista: el nombre va en el <label> del radio.
					$line.insertAfter($item.children().first());
					$name = $item.find('label strong, label').first();
				}
				alignWith($line, $name);
			});
		};
		paint();
		// MLI rehace la lista al cambiar de variación.
		$(document.body).on('found_variation wcmlim_locations_loaded', function () { setTimeout(paint, 50); });
		if (window.MutationObserver) {
			// Sólo repinta si aparecen tiendas sin línea: sliders y carruseles mutan el DOM sin parar.
			var t;
			new MutationObserver(function () {
				if (!document.querySelector(PENDING)) {
					return;
				}
				clearTimeout(t);
				t = setTimeout(paint, 60);
			}).observe(document.body, { childList: true, subtree: true });
		}
	};

	$(function () {
		TS.bindModal();
		TS.bindSelector();
		TS.singleLocationView();
		TS.locationAddress();
		if (!ts_params.is_checkout) {
			TS.autoDetect();
		}
	});

})(jQuery);
