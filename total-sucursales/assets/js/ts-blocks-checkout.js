/* global wp, wc */
(function () {
	'use strict';
	if (!window.wc || !wc.blocksCheckout || !wp.plugins || !wp.element || !wp.data) {
		return;
	}
	var h = wp.element.createElement;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var Slot = wc.blocksCheckout.ExperimentalOrderShippingPackages;
	var extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;
	if (!Slot || !extensionCartUpdate) {
		return;
	}
	var NS = 'total-sucursales';

	function locate(onOk, onErr) {
		if (!navigator.geolocation) { onErr(); return; }
		navigator.geolocation.getCurrentPosition(
			function (pos) { onOk(pos.coords.latitude, pos.coords.longitude); },
			function () { onErr(); },
			{ enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 }
		);
	}

	function Panel() {
		var data = useSelect(function (select) {
			var cart = select('wc/store/cart').getCartData();
			return (cart && cart.extensions && cart.extensions[NS]) || null;
		}, []);
		var st = useState({ busy: false, msg: '' });
		var state = st[0], setState = st[1];
		if (!data) { return null; }
		var i18n = data.i18n || {};
		var children = [];

		if (data.geo_button) {
			children.push(h('div', { className: 'ts-checkout-geo ts-blocks', key: 'geo' },
				h('button', {
					type: 'button',
					className: 'components-button is-secondary ts-checkout-geo__btn',
					disabled: state.busy || data.coords_source === 'gps',
					onClick: function () {
						setState({ busy: true, msg: i18n.locating });
						locate(function (lat, lng) {
							extensionCartUpdate({ namespace: NS, data: { lat: lat, lng: lng } })
								.then(function () { setState({ busy: false, msg: '' }); })
								.catch(function () { setState({ busy: false, msg: i18n.error }); });
						}, function () { setState({ busy: false, msg: i18n.error }); });
					}
				}, data.coords_source === 'gps' ? i18n.registered : i18n.button),
				h('small', { className: 'ts-checkout-geo__hint' }, i18n.hint),
				state.msg ? h('span', { className: 'ts-checkout-geo__status' }, state.msg) : null
			));
		}

		if (data.show_info && data.packages && data.packages.length) {
			children.push(h('ul', { className: 'ts-distance-list ts-blocks', key: 'list' }, data.packages.map(function (p) {
				return h('li', { key: p.location_id },
					h('strong', null, p.location_name), ' ',
					p.distance_label
						? h('span', { className: 'ts-distance' + (p.distance_unknown ? ' ts-distance--unknown' : '') }, p.distance_label)
						: null,
					' ',
					h('span', { className: 'ts-badge ' + (p.pickup_eligible ? 'ts-badge--pickup' : 'ts-badge--national') }, p.pickup_eligible ? i18n.pickup : i18n.national)
				);
			})));
		}
		// El carrito no tiene campo de municipio: su aviso remite al checkout.
		var inCart = document.body.classList.contains('woocommerce-cart');
		var note = (inCart ? data.cart_note : data.note) || null;
		if (data.show_info && note && note.text) {
			children.push(h('small', { className: 'ts-distance-note', key: 'note' }, [note.text].concat((note.lines || []).map(function (line, i) {
				return h('span', { className: 'ts-distance-note__line', key: 'l' + i }, line);
			}))));
		}
		if (!children.length) { return null; }
		return h('div', { className: 'ts-blocks-panel' }, children);
	}

	/*
	 * Venezuela: la ciudad libre la sustituye el select de municipio y el plugin la oculta. Si otro
	 * plugin o el tema la vuelve a mostrar (y a exigir), el cliente vería "Ciudad" y "Municipio" y
	 * WooCommerce no calcularía el envío hasta que escribiera la ciudad. Aquí se copia el municipio
	 * elegido en la ciudad y se oculta el campo.
	 */
	function syncCity() {
		var store = wp.data.select('wc/store/cart');
		if (!store || !store.getCustomerData) { return; }
		var data = store.getCustomerData() || {};
		var dispatch = wp.data.dispatch('wc/store/cart');
		[['shipping', 'shippingAddress', 'setShippingAddress'], ['billing', 'billingAddress', 'setBillingAddress']].forEach(function (t) {
			var addr = data[t[1]];
			var input = document.getElementById(t[0] + '-city');
			var wrap = input ? input.closest('.wc-block-components-text-input, .wc-block-components-address-form__city') : null;
			// Sólo si el select de municipio de ese estado está en el formulario (el ajuste puede estar apagado).
			var ve = addr && addr.country === 'VE' && addr.state &&
				document.querySelector('[id^="' + t[0] + '"][id$="municipio-' + String(addr.state).toLowerCase() + '"]');
			if (wrap) { wrap.style.display = ve ? 'none' : ''; }
			if (!ve) { return; }
			var muni = addr[NS + '/municipio-' + String(addr.state).toLowerCase()];
			if (muni && addr.city !== muni && typeof dispatch[t[2]] === 'function') {
				var next = {};
				Object.keys(addr).forEach(function (k) { next[k] = addr[k]; });
				next.city = muni;
				dispatch[t[2]](next);
			}
		});
	}
	// Opciones de envío ocultas (ajuste "Ocultar las opciones de envío"): la clase de <body> la pone el
	// servidor al cargar la página y aquí se actualiza cuando cambia el carrito.
	function syncShippingVisibility() {
		var store = wp.data.select('wc/store/cart');
		var cart = store && store.getCartData ? store.getCartData() : null;
		var ext = cart && cart.extensions ? cart.extensions[NS] : null;
		if (ext && typeof ext.hide_shipping === 'boolean') {
			document.body.classList.toggle('ts-hide-shipping', ext.hide_shipping);
		}
	}
	var syncing = false;
	wp.data.subscribe(function () {
		if (syncing) { return; }
		syncing = true;
		try { syncCity(); syncShippingVisibility(); } finally { syncing = false; }
	});

	wp.plugins.registerPlugin('total-sucursales-shipping', {
		render: function () { return h(Slot, null, h(Panel)); },
		scope: 'woocommerce-checkout'
	});
})();
