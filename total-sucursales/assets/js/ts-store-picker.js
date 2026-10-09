/**
 * Selector de tienda de la cabecera ([ts_tienda]): abre la ventana, filtra las tiendas por estado,
 * guarda la tienda (avisando si el carrito es de otra) y muestra "Tienda guardada" tras recargar.
 */
(function ($) {
	'use strict';

	var dataEl = document.getElementById('ts-sp-data');
	var $m = $('#ts-sp-modal');
	if (!dataEl || !$m.length) {
		return;
	}
	var D = JSON.parse(dataEl.textContent);
	// Al final del <body>: dentro de una cabecera con transform u overflow quedaría recortada.
	document.body.appendChild($m[0]);

	var lastFocus = null;
	var pendingMove = false;

	function status(text) { $m.find('.ts-sp-modal__status').text(text || ''); }

	function fill(tpl, map) {
		return String(tpl).replace(/\{(\w+)\}/g, function (all, k) { return map[k] !== undefined ? map[k] : all; });
	}

	function resetConflict() {
		pendingMove = false;
		$m.find('.ts-sp-modal__conflict').prop('hidden', true);
		$m.find('.ts-sp-modal__cancel').prop('hidden', true);
		$m.find('.ts-sp-modal__save').text(D.i18n.save);
		status('');
	}

	function filterState() {
		var $sel = $m.find('.ts-sp-state');
		if (!$sel.length) { return; }
		var st = $sel.val();
		$m.find('.ts-sp-store').each(function () {
			this.hidden = this.getAttribute('data-state') !== st;
		});
	}

	function visibleChecked() {
		return $m.find('input[name="ts_sp_store"]:checked').filter(function () {
			return !$(this).closest('.ts-sp-store').prop('hidden');
		});
	}

	function open() {
		lastFocus = document.activeElement;
		$m.prop('hidden', false);
		$('html').addClass('ts-sp-open');
		filterState();
		resetConflict();
		var $first = visibleChecked();
		if (!$first.length) { $first = $m.find('.ts-sp-state'); }
		if (!$first.length) { $first = $m.find('.ts-sp-store:not([hidden]) input').first(); }
		$first.trigger('focus');
	}

	function close() {
		$m.prop('hidden', true);
		$('html').removeClass('ts-sp-open');
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	function saved() {
		try { window.sessionStorage.setItem('ts_sp_saved', '1'); } catch (e) {}
		window.location.reload();
	}

	function save() {
		var id = visibleChecked().val();
		if (!id) {
			status(D.i18n.pick);
			return;
		}
		var $b = $m.find('.ts-sp-modal__save').prop('disabled', true);
		$.post(D.ajax_url, { action: 'ts_pick_store', nonce: D.nonce, term_id: id, move: pendingMove ? 1 : 0 })
			.done(function (res) {
				if (!res || !res.success) {
					status(D.i18n.error);
					return;
				}
				if (res.data.conflict) {
					pendingMove = true;
					var $c = $m.find('.ts-sp-modal__conflict').prop('hidden', false);
					var text = fill(D.i18n.conflict, { actual: res.data.current || '', nueva: res.data.target });
					var $ul = $c.find('ul').empty();
					if (res.data.unavailable && res.data.unavailable.length) {
						text += ' ' + fill(D.i18n.unavailable, { nueva: res.data.target });
						$.each(res.data.unavailable, function (i, u) {
							$('<li>').text(u.name + ' × ' + u.quantity).appendTo($ul);
						});
					}
					$c.find('p').text(text);
					$b.text(D.i18n.confirm);
					$m.find('.ts-sp-modal__cancel').prop('hidden', false);
					return;
				}
				if (res.data.saved) {
					saved();
				}
			})
			.fail(function () { status(D.i18n.error); })
			.always(function () { $b.prop('disabled', false); });
	}

	$(document).on('click', '.ts-sp__trigger', function (e) {
		e.preventDefault();
		open();
	});
	$m.on('click', '.ts-sp-modal__close', close);
	$m.on('click', function (e) { if (e.target === $m[0]) { close(); } });
	$(document).on('keydown', function (e) { if (e.key === 'Escape' && !$m.prop('hidden')) { close(); } });
	$m.on('change', '.ts-sp-state', function () { filterState(); resetConflict(); });
	$m.on('change', 'input[name="ts_sp_store"]', resetConflict);
	$m.on('click', '.ts-sp-modal__cancel', resetConflict);
	$m.on('click', '.ts-sp-modal__save', save);
	$m.on('click', '.ts-sp-modal__gps', function () {
		var T = window.TotalSucursales;
		if (!T) { return; }
		status(D.i18n.locating);
		T.locate(function (lat, lng) {
			T.setPosition(lat, lng, 'browse').done(saved).fail(function () { status(D.i18n.geo_error); });
		}, function () {
			status(D.i18n.geo_error);
		});
	});

	// "Tienda guardada" tras la recarga.
	var justSaved = false;
	try {
		justSaved = window.sessionStorage.getItem('ts_sp_saved') === '1';
		window.sessionStorage.removeItem('ts_sp_saved');
	} catch (e) {}
	if (justSaved) {
		var $t = $('.ts-sp__toast').first().prop('hidden', false);
		setTimeout(function () { $t.prop('hidden', true); }, 6000);
	}
	$(document).on('click', '.ts-sp__toast-close', function () {
		$(this).closest('.ts-sp__toast').prop('hidden', true);
	});
})(jQuery);
