/**
 * Traduce los textos que Multi Locations tiene fijos en su JavaScript y en alguna plantilla.
 *
 * - Diálogos: se envuelve Swal.fire() (SweetAlert2) y se traducen title, text, html y los botones.
 * - Plantillas: opciones "- Select Location -" de sus <select>, el botón de su popup y el
 *   " | Location : X" que su carrito por bloques añade al nombre del producto.
 *
 * El diccionario llega de PHP (TS_MLI_I18n) con los textos editables ya resueltos.
 */
(function () {
	'use strict';

	var cfg = window.ts_mli_i18n;
	if (!cfg) {
		return;
	}
	var exact = cfg.exact || {};
	var patterns = (cfg.patterns || []).map(function (p) {
		try {
			return { re: new RegExp(p.re), groups: p.groups || [], es: p.es };
		} catch (e) {
			return null;
		}
	}).filter(Boolean);

	function translate(str) {
		if (typeof str !== 'string' || str === '') {
			return str;
		}
		var key = str.trim();
		if (Object.prototype.hasOwnProperty.call(exact, key)) {
			// Conserva los espacios de alrededor: algunas plantillas los usan de separador.
			return str.replace(key, exact[key]);
		}
		for (var i = 0; i < patterns.length; i++) {
			var m = key.match(patterns[i].re);
			if (!m) {
				continue;
			}
			var out = patterns[i].es;
			patterns[i].groups.forEach(function (name, idx) {
				var val = m[idx + 1] || '';
				if (name === 'lista' && cfg.qty) {
					val = val.split(cfg.qty[0]).join(cfg.qty[1]);
				}
				out = out.split('{' + name + '}').join(val);
			});
			// Conserva los espacios de alrededor (p. ej. " | Tienda: X" pegado al precio).
			return str.match(/^\s*/)[0] + out + str.match(/\s*$/)[0];
		}
		return str;
	}

	/* ---------- Diálogos (SweetAlert2) ---------- */

	var FIELDS = ['title', 'text', 'html', 'confirmButtonText', 'cancelButtonText', 'denyButtonText'];

	function wrap(swal) {
		if (!swal || swal.__tsWrapped || typeof swal.fire !== 'function') {
			return swal;
		}
		var fire = swal.fire;
		swal.fire = function (opts) {
			var args = Array.prototype.slice.call(arguments);
			if (opts && typeof opts === 'object') {
				var copy = {};
				for (var k in opts) {
					if (Object.prototype.hasOwnProperty.call(opts, k)) {
						copy[k] = opts[k];
					}
				}
				FIELDS.forEach(function (f) {
					if (typeof copy[f] === 'string') {
						copy[f] = translate(copy[f]);
					}
				});
				// SweetAlert pone "OK" y "Cancel" si no se indican.
				if (copy.confirmButtonText === undefined && exact.OK) {
					copy.confirmButtonText = exact.OK;
				}
				if (copy.showCancelButton && copy.cancelButtonText === undefined && exact.Cancel) {
					copy.cancelButtonText = exact.Cancel;
				}
				args[0] = copy;
			} else {
				// Forma corta Swal.fire(título, texto, icono).
				args = args.map(function (a) { return typeof a === 'string' ? translate(a) : a; });
			}
			return fire.apply(this, args);
		};
		swal.__tsWrapped = true;
		return swal;
	}

	// MLI carga SweetAlert por su cuenta y puede hacerlo después que este archivo.
	var current = wrap(window.Swal);
	try {
		Object.defineProperty(window, 'Swal', {
			configurable: true,
			get: function () { return current; },
			set: function (v) { current = wrap(v); },
		});
	} catch (e) {
		var tries = 0;
		var iv = setInterval(function () {
			wrap(window.Swal);
			if (++tries > 40) {
				clearInterval(iv);
			}
		}, 250);
	}

	/* ---------- Etiquetas de stock ---------- */

	// La lista de stock de la ficha lee sus textos (In Stock, Sold Out...) con una consulta SQL
	// directa a wp_options, así que el filtro de opciones de PHP no llega. Sólo se traduce el texto
	// de fábrica en inglés: si el administrador lo cambió en MLI, se deja como lo escribió.
	var STOCK = cfg.stock || {};
	var STOCK_RULES = [
		[/^(\s*)(\d+)\s+In Stock\.?(\s*)$/i, function (m, a, n, b) { return a + n + ' ' + (n === '1' ? STOCK.unit_one : STOCK.unit_many) + b; }],
		[/\bAvailable on backorder\b|\bOn backorder\b/gi, function () { return STOCK.backorder; }],
		[/\bIn Stock\b/gi, function () { return STOCK.instock; }],
		[/\bSold Out\b|\bOut of Stock\b/gi, function () { return STOCK.soldout; }],
	];
	var STOCK_SELECTORS = [
		'.wclim-views_subinfo',
		'.stock-display',
		'select.select_location option',
		'#globMsg',
		'#losm',
		'#locsoldImg',
		'#locstockImg',
		'.Wcmlim_container p.stock',
		'.summary p.stock',
	].join(',');

	function translateStock(str) {
		if (!STOCK.instock || typeof str !== 'string' || !/stock|backorder|sold out/i.test(str)) {
			return str;
		}
		for (var i = 0; i < STOCK_RULES.length; i++) {
			var rule = STOCK_RULES[i];
			if (rule[0].global) {
				str = str.replace(rule[0], rule[1]);
			} else if (rule[0].test(str)) {
				return str.replace(rule[0], rule[1]);
			}
		}
		return str;
	}

	/* ---------- Plantillas ---------- */

	var SELECTORS = [
		'select.select_location option',
		'#wcmlim-change-lc-select option',
		'.wcmlim-lc-select option',
		'#set-def-store-popup-btn',
		'.wc-block-components-product-name',
		'.wc-block-components-product-price',
		'.wc-block-components-product-metadata__description p',
	].join(',');

	var ALL = SELECTORS + ',' + STOCK_SELECTORS;

	function translateNode(el) {
		var stock = el.matches(STOCK_SELECTORS);
		var plain = el.matches(SELECTORS);
		// Sólo nodos de texto directos: no se toca el HTML interno.
		for (var i = 0; i < el.childNodes.length; i++) {
			var n = el.childNodes[i];
			if (n.nodeType === 3 && n.nodeValue.trim()) {
				var t = n.nodeValue;
				if (plain) {
					t = translate(t);
				}
				if (stock) {
					t = translateStock(t);
				}
				if (t !== n.nodeValue) {
					n.nodeValue = t;
				}
			}
		}
	}

	function sweep(root) {
		var scope = root && root.querySelectorAll ? root : document;
		if (scope.matches && scope.matches(ALL)) {
			translateNode(scope);
		}
		var els = scope.querySelectorAll(ALL);
		for (var i = 0; i < els.length; i++) {
			translateNode(els[i]);
		}
	}

	function start() {
		sweep(document);
		if (!window.MutationObserver) {
			return;
		}
		// Los carritos y selectores de MLI se rehacen por AJAX; se traduce sólo lo que cambia.
		new MutationObserver(function (list) {
			for (var i = 0; i < list.length; i++) {
				var m = list[i];
				if (m.type === 'characterData' && m.target.parentElement && m.target.parentElement.matches(ALL)) {
					translateNode(m.target.parentElement);
				}
				for (var j = 0; j < m.addedNodes.length; j++) {
					var node = m.addedNodes[j];
					if (node.nodeType === 1) {
						sweep(node);
					} else if (node.nodeType === 3 && node.parentElement && node.parentElement.matches(ALL)) {
						translateNode(node.parentElement);
					}
				}
			}
		}).observe(document.documentElement, { childList: true, subtree: true, characterData: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}

	// Para pruebas y para otros scripts.
	window.tsMliTranslate = translate;
	window.tsMliTranslateStock = translateStock;
})();
