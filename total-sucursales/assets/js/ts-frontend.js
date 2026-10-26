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

		cookie: function (name) {
			var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
			return m ? decodeURIComponent(m[1]) : '';
		},

		/**
		 * Tienda del cliente. La página puede venir de la caché (LiteSpeed y similares) pintada para
		 * otro visitante, así que manda la cookie ts_tienda; ts_params sólo si no la hay.
		 */
		storeId: function () {
			return parseInt(TS.cookie('ts_tienda'), 10) || parseInt(ts_params.selected_location_id, 10) || 0;
		},

		/** ¿Ya se hizo la detección automática (o el cliente ya eligió)? */
		detected: function () {
			return TS.cookie('ts_det') === '1';
		},

		markDetected: function () {
			document.cookie = 'ts_det=1; path=/; max-age=31536000; SameSite=Lax' + (window.location.protocol === 'https:' ? '; Secure' : '');
		},

		/**
		 * El cliente está eligiendo tienda o estado (abrió un selector, tocó una tienda...): la
		 * detección automática no le cambia nada ni recarga la página mientras tanto.
		 */
		busy: false,

		watchBusy: function () {
			var sel = '.ts-sp__trigger, #ts-sp-modal, #ts-state-modal, .ts-state-select, .ts-use-gps, .ts-store-stock, ' +
				'#wcmlim-drawer_view_btn, #wcmlim-popup_view_btn, #wcmlim-sidePopup, #wcmlim-modalPopup, ' +
				'[name="select_location"], .location-stock-item, #wcmlim-change-lc-select, .wcmlim-lc-switch';
			var mark = function (e) {
				if (e.isTrusted && e.target && e.target.closest && e.target.closest(sel)) {
					TS.busy = true;
				}
			};
			['click', 'change', 'focusin', 'touchstart'].forEach(function (t) {
				document.addEventListener(t, mark, { capture: true, passive: true });
			});
			// Tocó el selector antes de que cargara el JavaScript (LiteSpeed "retrasar JS").
			if (window.tsSpPending || window.tsPickPending) {
				TS.busy = true;
			}
		},

		/** Recarga tras una detección automática, salvo que el cliente esté eligiendo. */
		autoReload: function () {
			if (!TS.busy) {
				window.location.reload();
			}
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

		setPosition: function (lat, lng, context, auto) {
			return TS.post('ts_set_position', { lat: lat, lng: lng, context: context || 'browse', auto: auto ? 1 : 0 });
		},

		/**
		 * Posición de la detección automática: el servidor no cambia una tienda que eligió el
		 * cliente, y si el cliente está eligiendo ahora no se envía.
		 */
		autoPosition: function (lat, lng) {
			if (TS.busy) {
				return $.Deferred().resolve({ success: false, busy: true }).promise();
			}
			return TS.setPosition(lat, lng, 'browse', true);
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
			if (TS.busy) {
				done(false);
				return;
			}
			TS._ipAsked = true;
			TS.post('ts_ip_locate').done(function (res) {
				if (res && res.success && res.data.reload && !TS.busy) {
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
			if (TS._defaultAsked || TS.busy) { return; }
			TS._defaultAsked = true;
			TS.post('ts_use_default', { auto: 1 }).done(function (res) {
				if (res && res.success && res.data.reload) { TS.autoReload(); }
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
				TS.autoPosition(lat, lng).done(function (res) {
					if (res && res.success && res.data.reload) { TS.autoReload(); }
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
				TS.autoPosition(lat, lng).done(function (res) {
					if (res && res.success && res.data.reload) { TS.autoReload(); }
				});
			}, function (code) {
				TS.rememberNoGps(code);
				TS.ipLocate();
			});
		},

		/**
		 * Una sola vez por visitante. La página puede venir de la caché con los datos de otro
		 * visitante (o de uno nuevo), así que no basta ts_params: la cookie ts_det dice si ya se hizo.
		 * Antes se repetía en cada página y volvía a poner la tienda del GPS o la de por defecto
		 * encima de la que el cliente había elegido, recargando la página mientras elegía.
		 */
		autoDetect: function () {
			if (TS.detected() || TS.busy) {
				return;
			}
			TS.markDetected();
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
					TS.autoPosition(lat, lng).done(function (res) {
						if (res && res.success && (res.data.reload || res.data.changed)) {
							TS.autoReload();
						} else if (!res || res.data === undefined || res.data.reason !== 'manual') {
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
				TS.autoPosition(lat, lng).done(function (res) {
					if (res && res.success && (res.data.reload || res.data.changed)) {
						TS.autoReload();
					} else if (!res || !res.success || (!res.data.state && res.data.reason !== 'manual')) {
						if (ts_params.ask_if_gps_fails && !TS.busy) { TS.showModal(); }
					}
				}).fail(function () {
					if (ts_params.ask_if_gps_fails && !TS.busy) { TS.showModal(ts_params.i18n.geo_error); }
				});
			}, function (code) {
				TS.ipLocate(function () {
					if (ts_params.ask_if_gps_fails && !TS.busy) {
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
			if (!ts_params.single_location_view || !TS.storeId()) {
				return;
			}
			var id = String(TS.storeId());
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
	 * Multi Locations cambia la tienda por AJAX desde la ficha (su desplegable o su lista) y no toca
	 * la cabecera: seguía diciendo la tienda anterior hasta recargar. Cuando el cliente elige ahí una
	 * tienda se actualiza el selector de la cabecera ([ts_tienda] y el de Multi Locations), con el
	 * aviso de "Tienda guardada". No se recarga: con el desplegable Multi Locations no volvería a
	 * marcar la tienda elegida. (El panel lateral y la ventana recargan: ver ts-store-stock.js.)
	 *
	 * Sólo tras una elección del cliente: al cargar la ficha Multi Locations "elige" él mismo la
	 * tienda guardada, y eso no debe mostrar el aviso ni costar peticiones.
	 */
	TS.headerFollowsMli = function () {
		var picked = null;
		function remember(e) {
			if (!e.isTrusted || !e.target.closest) {
				return;
			}
			var el = e.target.closest('[name="select_location"], .location-stock-item[data-location-id]');
			if (!el) {
				return;
			}
			var opt = el.tagName === 'SELECT' ? el.options[el.selectedIndex] : null;
			var item = el.closest('[data-location-id]');
			var id = parseInt(opt ? opt.getAttribute('data-lc-termid') : (item ? item.getAttribute('data-location-id') : ''), 10) || 0;
			picked = id ? { id: id, at: Date.now() } : null;
		}
		document.addEventListener('change', remember, true);
		document.addEventListener('click', remember, true);
		// Que el servidor sepa que esta la eligió el cliente (y no Multi Locations al cargar la ficha).
		$.ajaxPrefilter(function (opts) {
			if (picked && Date.now() - picked.at < 10000 && typeof opts.data === 'string' && /(^|&)action=wcmlim_set_location_on_change(&|$)/.test(opts.data)) {
				opts.data += '&ts_user=1';
			}
		});
		$(document).ajaxSuccess(function (ev, xhr, opts) {
			if (!picked || Date.now() - picked.at > 10000 || !/(^|&)action=wcmlim_set_location_on_change(&|$)/.test(String(opts && opts.data || ''))) {
				return;
			}
			var id = picked.id;
			picked = null;
			var name = TS.storeName(id);
			if (name) {
				TS.showStore({ id: id, name: name, label: $.trim($('.ts-sp__label').first().text()) || ts_params.store_label });
				return;
			}
			// Sin selector en la página que diga el nombre: se le pregunta al servidor.
			TS.post('ts_current_store').done(function (res) {
				if (res && res.success && res.data.id) {
					TS.showStore(res.data);
				}
			});
		});
	};

	/** Nombre de una tienda según la lista del selector de la cabecera (o el de Multi Locations). */
	TS.storeName = function (id) {
		return $.trim($('input[name="ts_sp_store"][value="' + id + '"]').closest('.ts-sp-store').find('.ts-sp-store__name').text()) ||
			$.trim($('#wcmlim-change-lc-select option[data-lc-term="' + id + '"]').text());
	};

	/**
	 * La página puede venir de la caché pintada con la tienda de otro visitante: la cabecera se
	 * corrige con la tienda real del cliente (cookie ts_tienda), sin aviso.
	 */
	TS.fixHeader = function () {
		var id = parseInt(TS.cookie('ts_tienda'), 10) || 0;
		var $sp = $('.ts-sp[data-store]');
		if (!id || !$sp.length || String($sp.attr('data-store')) === String(id)) {
			return;
		}
		var name = TS.storeName(id);
		if (name) {
			TS.showStore({ id: id, name: name, label: ts_params.store_label }, true);
		}
	};

	/**
	 * Multi Locations lleva su nonce en la página: si la caché la sirve con más de 12-24 h ha
	 * caducado y su "Añadir al carrito" o su cambio de tienda contestan "-1" (no hacen nada). Se
	 * pide uno nuevo y se repite la petición una vez.
	 */
	TS.mliNonceRetry = function () {
		$(document).ajaxError(function (ev, xhr, opts) {
			var data = String(opts && opts.data || '');
			if (!opts || opts._tsRetry || xhr.status !== 403 || $.trim(xhr.responseText || '') !== '-1' ||
				!/(^|&)action=wcmlim_/.test(data) || typeof URLSearchParams === 'undefined') {
				return;
			}
			// Una sola petición aunque fallen varias a la vez (la ficha hace varias al cargar).
			TS._mliNonce = TS._mliNonce || TS.post('ts_mli_nonce');
			TS._mliNonce.done(function (res) {
				if (!res || !res.success || !res.data.security) {
					return;
				}
				var n = res.data.security;
				['wc_add_to_cart_params', 'multi_inventory', 'localization'].forEach(function (k) {
					if (window[k] && typeof window[k] === 'object' && 'security' in window[k]) {
						window[k].security = n;
					}
				});
				var params = new URLSearchParams(data);
				params.set('security', n);
				$.ajax($.extend({}, opts, { data: params.toString(), _tsRetry: true }));
			});
		});
	};

	TS.showStore = function (d, quiet) {
		var $text = $('.ts-sp__text');
		$('.ts-sp[data-store]').attr('data-store', d.id);
		if ($text.length) {
			$text.empty().append($('<span class="ts-sp__label">').text(d.label), $('<strong class="ts-sp__name">').text(d.name));
			$('.ts-sp-modal__current').text(d.name);
			var $radio = $('input[name="ts_sp_store"][value="' + d.id + '"]').prop('checked', true);
			var st = $radio.closest('.ts-sp-store').attr('data-state');
			if (st) { $('.ts-sp-state').val(st); }
			if (!quiet) {
				var $t = $('.ts-sp__toast').first().prop('hidden', false);
				clearTimeout(TS.toastTimer);
				TS.toastTimer = setTimeout(function () { $t.prop('hidden', true); }, 6000);
			}
		}
		var $opt = $('#wcmlim-change-lc-select option[data-lc-term="' + d.id + '"]');
		if ($opt.length) {
			$opt.prop('selected', true);
			$('#wcmlim-change-lc-select').trigger('chosen:updated');
		}
	};

	$(function () {
		TS.watchBusy();
		TS.fixHeader();
		TS.mliNonceRetry();
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
