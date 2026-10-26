/**
 * Ficha de un producto sin stock en la tienda del cliente (pero sí en otras): ver TS_Store_Stock.
 *
 * "Añadir al carrito" no hace nada hasta que se elige en el selector de Multi Locations una tienda
 * con stock: resalta el aviso. El botón del aviso abre ese selector. Elegir tienda en su panel
 * lateral o su ventana guarda la tienda como el selector de la cabecera y recarga la ficha.
 */
(function ($) {
	'use strict';

	function cookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}

	/** Tienda elegida en el formulario: {id, qty} o null si no se eligió ninguna ahí. */
	function chosen(form) {
		var sel = form.querySelector('select[name="select_location"]');
		if (sel) {
			var o = sel.options[sel.selectedIndex];
			if (!o || o.value === '' || o.value === '-1') {
				return null;
			}
			return { id: parseInt(o.getAttribute('data-lc-termid'), 10) || 0, qty: parseFloat(o.getAttribute('data-lc-qty')) || 0 };
		}
		var r = form.querySelector('input[name="select_location"]:checked');
		if (r) {
			var item = r.closest('[data-location-id]');
			return { id: item ? parseInt(item.getAttribute('data-location-id'), 10) || 0 : 0, qty: parseFloat(r.getAttribute('data-lc-qty')) || 0 };
		}
		return null;
	}

	function canBuy(form, box) {
		var ok = [];
		try { ok = JSON.parse(box.getAttribute('data-ok') || '[]'); } catch (e) {}
		var c = chosen(form);
		var id = c ? c.id : parseInt(cookie('ts_tienda') || cookie('wcmlim_selected_location_termid'), 10) || 0;
		return ok.indexOf(id) !== -1 || !!(c && c.qty > 0);
	}

	function refresh() {
		$('.ts-store-stock').each(function () {
			var form = this.closest('form');
			var buy = form && canBuy(form, this);
			this.hidden = !!buy;
			$('.ts-store-no-stock').toggle(!buy);
		});
	}

	function openPicker() {
		var t = document.querySelector('#wcmlim-drawer_view_btn, #wcmlim-popup_view_btn');
		if (t) {
			t.click();
			return true;
		}
		var el = document.querySelector('form.cart select[name="select_location"], form.cart input[name="select_location"]');
		if (el) {
			($(el).closest('.Wcmlim_container')[0] || el).scrollIntoView({ block: 'center', behavior: 'smooth' });
			if (el.tagName === 'SELECT') {
				el.focus();
			}
			return true;
		}
		t = document.querySelector('.ts-sp__trigger');
		if (t) {
			t.click();
			return true;
		}
		return false;
	}

	// Antes que el clic de Multi Locations (delegado en document): fase de captura en window.
	window.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.single_add_to_cart_button');
		var form = btn && btn.closest('form');
		var box = form && form.querySelector('.ts-store-stock');
		if (!box || canBuy(form, box)) {
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		box.hidden = false;
		box.classList.remove('ts-store-stock--alert');
		void box.offsetWidth; // reinicia la animación
		box.classList.add('ts-store-stock--alert');
		box.scrollIntoView({ block: 'center', behavior: 'smooth' });
	}, true);

	/**
	 * Tienda elegida en el panel lateral o la ventana de Multi Locations ("Disponibilidad por
	 * tienda"): Multi Locations la cambiaba por AJAX sin recargar, así que la ficha seguía con el
	 * stock y el aviso de la tienda anterior (y sin poder comprar). Se guarda como en el selector de
	 * la cabecera: si el carrito es de otra tienda pregunta si se mueve, y recarga con "Tienda
	 * guardada". Sólo si está el selector de la cabecera ([ts_tienda]), que trae ese flujo.
	 */
	window.addEventListener('click', function (e) {
		var T = window.TotalSucursales;
		var item = e.target.closest && e.target.closest('#wcmlim-sidePopup .location-stock-item[data-location-id], #wcmlim-modalPopup .location-stock-item[data-location-id]');
		// Sólo clics del cliente: al cargar, Multi Locations "pulsa" la tienda elegida.
		if (!item || !e.isTrusted || !T || !T.pickStore) {
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		$('#wcmlim-sidePopup').css('width', '0px');
		$('#wcmlim-modalPopup').css('display', 'none');
		var id = parseInt(item.getAttribute('data-location-id'), 10) || 0;
		// La tienda real del cliente (la página puede venir de la caché con la de otro visitante).
		var current = parseInt(cookie('ts_tienda'), 10) || parseInt(window.ts_store_stock && ts_store_stock.current, 10) || 0;
		if (!id || id === current) {
			return;
		}
		T.pickStore(id).done(function (res) {
			if (!res || !res.success) { window.location.reload(); }
		}).fail(function () { window.location.reload(); });
	}, true);

	$(document).on('click', '.ts-store-stock__pick', function () {
		window.tsPickPending = 0;
		openPicker();
	});
	$(document).on('change', 'form.cart [name="select_location"]', refresh);
	// Multi Locations guarda la tienda elegida por AJAX (y fija las cookies en la respuesta).
	$(document).ajaxComplete(function (ev, xhr, opts) {
		if (/wcmlim_set_location_on_change/.test(String(opts && opts.data || ''))) {
			refresh();
		}
	});
	$(function () {
		if (!document.querySelector('#wcmlim-drawer_view_btn, #wcmlim-popup_view_btn, form.cart [name="select_location"], .ts-sp__trigger')) {
			$('.ts-store-stock__pick').hide();
		}
		refresh();
		// Pulsó "Elegir otra tienda" antes de que cargara este JavaScript (LiteSpeed "retrasar JS").
		if (window.tsPickPending) {
			window.tsPickPending = 0;
			setTimeout(openPicker, 300);
		}
	});
})(jQuery);
