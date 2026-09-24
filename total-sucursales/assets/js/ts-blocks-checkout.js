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
			if (data.show_note) {
				children.push(h('small', { className: 'ts-distance-note', key: 'note' }, i18n.no_position));
			}
		}
		if (!children.length) { return null; }
		return h('div', { className: 'ts-blocks-panel' }, children);
	}

	wp.plugins.registerPlugin('total-sucursales-shipping', {
		render: function () { return h(Slot, null, h(Panel)); },
		scope: 'woocommerce-checkout'
	});
})();
