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
		 * Respaldo por IP (IPinfo, consultado por el servidor): sólo si está activado y no se probó ya
		 * con este visitante. done(reloading) se llama si no hace falta recargar.
		 */
		ipLocate: function (done) {
			done = done || function () {};
			if (!ts_params.ip_fallback || ts_params.has_ip || TS._ipAsked || ts_params.detect_state === 'gps_default') {
				done(false);
				return;
			}
			TS._ipAsked = true;
			TS.post('ts_ip_locate').done(function (res) {
				if (res && res.success && res.data.reload) {
					window.location.reload();
					return;
				}
				done(false);
			}).fail(function () { done(false); });
		},

		/**
		 * Pide la ubicación al navegador y, si el cliente la niega, falla o no contesta en unos
		 * segundos, prueba con la IP. onGps(lat, lng) / onNoGps(code) como TS.locate.
		 */
		locateWithFallback: function (onGps, onNoGps) {
			var settled = false;
			var timer = ts_params.ip_fallback && !ts_params.has_ip && ts_params.detect_state !== 'gps_default' ? setTimeout(function () {
				// El aviso del navegador sigue sin respuesta: la IP mientras tanto.
				TS.ipLocate();
			}, 8000) : null;
			TS.locate(function (lat, lng) {
				if (settled) { return; }
				settled = true;
				clearTimeout(timer);
				onGps(lat, lng);
			}, function (code) {
				if (settled) { return; }
				settled = true;
				clearTimeout(timer);
				onNoGps(code);
			});
		},

		/**
		 * Recuerda que el cliente no dio su ubicación, para no volver a pedírsela en cada página.
		 */
		rememberNoGps: function (code) {
			var days = code === 'denied' ? 30 : 1;
			document.cookie = 'ts_geo=' + (code === 'denied' ? 'denied' : 'error') + '; path=/; max-age=' + (days * 86400) + '; SameSite=Lax';
		},

		gpsRefused: function () {
			return /(?:^|;\s*)ts_geo=(denied|error)/.test(document.cookie);
		},

		/**
		 * "GPS y, si falla, la tienda por defecto": la tienda por defecto, sin ventana ni IP.
		 */
		useDefault: function () {
			if (TS._defaultAsked) { return; }
			TS._defaultAsked = true;
			TS.post('ts_use_default').done(function (res) {
				if (res && res.success && res.data.reload) { window.location.reload(); }
			});
		},

		/**
		 * Pide la ubicación y, si el cliente la niega, falla o no contesta en unos segundos, llama a
		 * onNoGps(code) una sola vez ("timeout" si no contestó). Sin IP.
		 */
		locateOrTimeout: function (onGps, onNoGps) {
			var settled = false;
			var timer = setTimeout(function () {
				if (settled) { return; }
				settled = true;
				onNoGps('timeout');
			}, 8000);
			TS.locate(function (lat, lng) {
				if (settled) { return; }
				settled = true;
				clearTimeout(timer);
				onGps(lat, lng);
			}, function (code) {
				if (settled) { return; }
				settled = true;
				clearTimeout(timer);
				onNoGps(code);
			});
		},

		/**
		 * Modo "todas las tiendas": nunca se pregunta el estado. Con detección por GPS se elige la
		 * tienda más cercana; si el cliente no da la ubicación, "GPS" prueba con la IP (si está
		 * activada) y "GPS y, si falla, la tienda por defecto" asigna la tienda por defecto. Con
		 * "Preguntar" o "No detectar" se queda la tienda por defecto (la pone el servidor).
		 */
		autoDetectAll: function () {
			var mode = ts_params.detect_state;
			if ((mode !== 'gps' && mode !== 'gps_default') || ts_params.has_gps) {
				return;
			}
			var onPosition = function (lat, lng) {
				TS.setPosition(lat, lng, 'browse').done(function (res) {
					if (res && res.success && res.data.reload) { window.location.reload(); }
				});
			};
			if (mode === 'gps_default') {
				if (TS.gpsRefused()) { return; } // Ya tiene la tienda por defecto.
				TS.locateOrTimeout(onPosition, function (code) {
					TS.rememberNoGps(code === 'timeout' ? 'error' : code);
					TS.useDefault();
				});
				return;
			}
			if (TS.gpsRefused()) {
				TS.ipLocate();
				return;
			}
			TS.locateWithFallback(onPosition, function (code) {
				TS.rememberNoGps(code);
				TS.ipLocate();
			});
		},

		/**
		 * Modo "por ciudad": se pide la ubicación una vez. Si la da, el servidor deja sólo las tiendas
		 * de su ciudad (o la tienda por defecto si no hay) y se recarga. Si no, se prueba con la IP (si
		 * está activado) y, si tampoco, sigue viendo sólo la tienda por defecto; se recuerda para no
		 * volver a preguntar en cada página.
		 */
		autoDetectCity: function () {
			if (ts_params.has_gps) {
				return;
			}
			if (/(?:^|;\s*)ts_geo=(denied|error)/.test(document.cookie)) {
				TS.ipLocate(); // Ya dijo que no al GPS: la IP, una vez.
				return;
			}
			TS.locateWithFallback(function (lat, lng) {
				TS.setPosition(lat, lng, 'browse').done(function (res) {
					if (res && res.success && res.data.reload) { window.location.reload(); }
				});
			}, function (code) {
				TS.rememberNoGps(code);
				TS.ipLocate();
			});
		},

		autoDetect: function () {
			if (ts_params.visibility_mode === 'city') {
				TS.autoDetectCity();
				return;
			}
			if (ts_params.visibility_mode === 'all') {
				TS.autoDetectAll();
				return;
			}
			if (ts_params.has_choice || ts_params.detect_state === 'off') {
				return;
			}
			if (ts_params.detect_state === 'ask') {
				TS.showModal();
				return;
			}
			if (ts_params.detect_state === 'gps_default') {
				// GPS: estado y tienda más cercana. Si no la da, falla, no contesta o está lejos de
				// todas las tiendas: la tienda por defecto, sin ventana ni IP.
				if (TS.gpsRefused()) {
					TS.useDefault();
					return;
				}
				TS.locateOrTimeout(function (lat, lng) {
					TS.setPosition(lat, lng, 'browse').done(function (res) {
						if (res && res.success && (res.data.reload || res.data.changed)) {
							window.location.reload();
						} else {
							TS.useDefault();
						}
					}).fail(function () { TS.useDefault(); });
				}, function (code) {
					TS.rememberNoGps(code === 'timeout' ? 'error' : code);
					TS.useDefault();
				});
				return;
			}
			// gps (y, si no la da, la IP antes de preguntarle)
			TS.locateWithFallback(function (lat, lng) {
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
				TS.ipLocate(function () {
					if (ts_params.ask_if_gps_fails) {
						TS.showModal(code === 'denied' ? ts_params.i18n.geo_denied : ts_params.i18n.geo_error);
					}
				});
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

	/**
	 * "Añadir al carrito" de Multi Locations: toma la cantidad del primer campo de cantidad de la
	 * página, no del producto pulsado, así que en un listado con "- 1 +" en cada tarjeta se añadía la
	 * cantidad de otro producto. Se manda la del formulario del botón pulsado.
	 */
	TS.loopQuantity = function () {
		var last = null;
		document.addEventListener('click', function (e) {
			var b = e.target && e.target.closest ? e.target.closest('.single_add_to_cart_button, .wcmlim_ajax_add_to_cart') : null;
			if (b) { last = b; }
		}, true);
		$.ajaxPrefilter(function (opts) {
			if (!last || typeof opts.data !== 'string' || opts.data.indexOf('action=wcmlim_ajax_add_to_cart') === -1 || typeof URLSearchParams === 'undefined') {
				return;
			}
			var form = last.closest('form.cart');
			var qty = form ? form.querySelector('input.qty, input[name="quantity"]') : null;
			if (!qty || !qty.value) { return; }
			var params = new URLSearchParams(opts.data);
			params.set('quantity', qty.value);
			opts.data = params.toString();
		});
	};

	/**
	 * Multi Locations cambia la tienda por AJAX desde la ficha (panel lateral "Disponibilidad por
	 * tienda", desplegable, lista) y no toca la cabecera: seguía diciendo la tienda anterior hasta
	 * recargar. Tras su cambio se pide la tienda elegida y se actualizan el selector de la cabecera
	 * ([ts_tienda] y el de Multi Locations), con el aviso de "Tienda guardada". No se recarga: con
	 * el desplegable Multi Locations no volvería a marcar la tienda elegida.
	 */
	TS.headerFollowsMli = function () {
		$(document).ajaxSuccess(function (ev, xhr, opts) {
			if (!/(^|&)action=wcmlim_set_location_on_change(&|$)/.test(String(opts && opts.data || ''))) {
				return;
			}
			TS.post('ts_current_store').done(function (res) {
				if (res && res.success && res.data.id) {
					TS.showStore(res.data);
				}
			});
		});
	};

	TS.showStore = function (d) {
		var $text = $('.ts-sp__text');
		if ($text.length) {
			$text.empty().append($('<span class="ts-sp__label">').text(d.label), $('<strong class="ts-sp__name">').text(d.name));
			$('.ts-sp-modal__current').text(d.name);
			var $radio = $('input[name="ts_sp_store"][value="' + d.id + '"]').prop('checked', true);
			var st = $radio.closest('.ts-sp-store').attr('data-state');
			if (st) { $('.ts-sp-state').val(st); }
			var $t = $('.ts-sp__toast').first().prop('hidden', false);
			clearTimeout(TS.toastTimer);
			TS.toastTimer = setTimeout(function () { $t.prop('hidden', true); }, 6000);
		}
		var $opt = $('#wcmlim-change-lc-select option[data-lc-term="' + d.id + '"]');
		if ($opt.length) {
			$opt.prop('selected', true);
			$('#wcmlim-change-lc-select').trigger('chosen:updated');
		}
	};

	$(function () {
		TS.headerFollowsMli();
		TS.loopQuantity();
		TS.bindModal();
		TS.bindSelector();
		TS.singleLocationView();
		TS.locationAddress();
		if (!ts_params.is_checkout) {
			TS.autoDetect();
		}
	});

})(jQuery);
