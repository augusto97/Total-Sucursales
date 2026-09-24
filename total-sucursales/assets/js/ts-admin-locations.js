/* global jQuery, tsLocAdmin */
/**
 * Ficha de la tienda en Multi Locations: el campo "City" pasa a ser un desplegable de municipios del
 * estado elegido (sólo en Venezuela). Ver TS_Location_Admin.
 */
(function ($) {
	'use strict';
	var D = window.tsLocAdmin;
	if (!D) { return; }

	$(function () {
		var $city = $('#locality');
		var $state = $('#administrative_area_level_1');
		var $country = $('#country');
		if (!$city.length || !$state.length) { return; }

		// En el formulario de Multi Locations la ciudad va antes que el país y el estado: se pasa
		// detrás del estado, que es de donde sale la lista de municipios.
		var $cityRow = $city.closest('tr, .form-field');
		var $stateRow = $state.closest('tr, .form-field');
		if ($cityRow.length && $stateRow.length && !$cityRow.is($stateRow)) {
			$cityRow.insertAfter($stateRow);
		}
		var $label = $('label[for="locality"]');
		var labelCity = $label.text();

		var OTHER = '__other__';
		var $sel = $('<select id="ts-municipio-select" class="form-control"></select>');
		var $wrap = $('<div class="ts-municipio-wrap"></div>')
			.append($sel)
			.append($('<p class="description"></p>').text(D.i18n.hint));
		var $key = $('<input type="hidden" name="ts_municipio">').val(D.current || '');
		$city.before($wrap).after($key);

		function norm(s) {
			return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
		}
		function stateCode() { return String($state.val() || '').toUpperCase(); }
		function isVE() { var c = $country.val(); return !$country.length || !c || c === 'VE'; }
		function list() { return (isVE() && D.states[stateCode()]) || []; }
		function find(key) { return list().filter(function (m) { return m.key === key; })[0] || null; }
		function cityOf(m) { return m.capital || m.name; }
		// "Municipio Lagunillas (Ciudad Ojeda)", "Lagunillas" o "Ciudad Ojeda" → clave.
		function match(text) {
			var t = String(text || '').replace(/^\s*(municipio|municipality)\s+/i, '');
			var cap = '';
			var m = t.match(/^(.*?)\s*\((.*)\)\s*$/);
			if (m) { t = m[1]; cap = m[2]; }
			var n = norm(t), c = norm(cap), l = list(), i;
			if (!n) { return ''; }
			for (i = 0; i < l.length; i++) { if (norm(l[i].name) === n) { return l[i].key; } }
			for (i = 0; i < l.length; i++) {
				if (l[i].capital && (norm(l[i].capital) === n || (c && norm(l[i].capital) === c))) { return l[i].key; }
			}
			return '';
		}

		var built = null;
		// keep: conservar lo que ya tenía la tienda (al abrir la ficha); si no, se empieza de cero
		// porque cambió el estado o el país.
		function build(keep) {
			built = stateCode() + '|' + ($country.val() || '');
			if (!isVE()) {
				$wrap.hide(); $city.show(); $key.val('');
				$label.text(labelCity);
				return;
			}
			$label.text(D.i18n.label);
			$sel.empty();
			if (!stateCode() || !list().length) {
				$sel.append($('<option value=""></option>').text(D.i18n.state_first)).prop('disabled', true);
				$wrap.show(); $city.hide(); $key.val('');
				if (!keep) { $city.val(''); }
				return;
			}
			$sel.prop('disabled', false).append($('<option value=""></option>').text(D.i18n.choose));
			list().forEach(function (m) {
				var label = (m.capital && norm(m.capital) !== norm(m.name)) ? m.name + ' (' + m.capital + ')' : m.name;
				$sel.append($('<option></option>').val(m.key).text(label));
			});
			$sel.append($('<option></option>').val(OTHER).text(D.i18n.other));

			var want = keep ? (find($key.val()) ? $key.val() : match($city.val())) : '';
			if (want) {
				$sel.val(want); $key.val(want); $city.hide();
			} else if (keep && $.trim($city.val())) {
				// Ciudad escrita a mano que no es ningún municipio: se respeta.
				$sel.val(OTHER); $key.val(''); $city.show();
			} else {
				$sel.val(''); $key.val(''); $city.hide();
				if (!keep) { $city.val(''); }
			}
			$wrap.show();
		}

		$sel.on('change', function () {
			var v = $sel.val();
			if (v === OTHER) {
				$key.val('');
				$city.val('').show().trigger('focus');
			} else {
				var m = find(v);
				$key.val(m ? m.key : '');
				$city.val(m ? cityOf(m) : '').hide();
			}
			// Multi Locations valida el formulario al escribir en la ciudad.
			$city.trigger('input');
		});
		$state.on('change', function () { build(false); });
		$country.on('change', function () { setTimeout(function () { build(false); }, 0); });
		// Multi Locations carga los estados por AJAX al abrir la ficha y marca el guardado después.
		if (window.MutationObserver) {
			new MutationObserver(function () {
				if (built !== stateCode() + '|' + ($country.val() || '')) { build(true); }
			}).observe($state[0], { childList: true, attributes: true });
		}
		// Alta de tienda: WordPress vacía el formulario tras crearla por AJAX.
		$(document).ajaxSuccess(function (e, xhr, settings) {
			if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=add-tag') !== -1) {
				$key.val(''); build(false);
			}
		});
		// Por si Multi Locations fija el estado sin avisar: se revisa al rato y al abrir el desplegable.
		function recheck() {
			if (built !== stateCode() + '|' + ($country.val() || '')) { build(true); }
		}
		setTimeout(recheck, 1500);
		$sel.on('mousedown focus', recheck);
		build(true);
	});
})(jQuery);
