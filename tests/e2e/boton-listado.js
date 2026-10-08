/**
 * Botón "Añadir al carrito" de los listados con Multi Locations.
 *
 * Multi Locations sustituye el botón de los listados (filtro woocommerce_loop_add_to_cart_link) por
 * uno suyo de texto: se pierde el del tema o del bloque (el de GreenShift lleva un icono SVG y sus
 * clases) y la tarjeta se descuadra. Total Sucursales conserva el original y le añade la tienda.
 *
 * Un mu-plugin de prueba (que se borra al terminar) pinta, con el shortcode [ts_test_boton], un botón
 * como el de GreenShift pasado por ese filtro.
 *
 *   node boton-listado.js     # con el servidor en 127.0.0.1:8080
 */
const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const MU = WP + '/wp-content/mu-plugins/ts-test-boton.php';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();

const MU_SRC = `<?php
// Sólo para las pruebas: un botón de listado como el de GreenShift, pasado por el filtro de WooCommerce.
add_shortcode( 'ts_test_boton', function ( $atts ) {
	$p = wc_get_product( (int) $atts['id'] );
	$html = sprintf(
		'<a href="%s" data-quantity="1" class="product_type_simple add_to_cart_button ajax_add_to_cart wp-element-button" data-product_id="%d" aria-label="%s" rel="nofollow"><span class="gspb-buttonbox-textwrap"> <span class="woobtnicon"><svg class="ts-test-icon" width="16" height="16" viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg></span></span></a>',
		esc_url( $p->add_to_cart_url() ), $p->get_id(), esc_attr( 'Añadir al carrito: "' . $p->get_name() . '"' )
	);
	return '<div class="ts-test-card">' . apply_filters( 'woocommerce_loop_add_to_cart_link', $html, $p, array() ) . '</div>';
} );
`;

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

(async () => {
  fs.mkdirSync(WP + '/wp-content/mu-plugins', { recursive: true });
  fs.writeFileSync(MU, MU_SRC);
  const pid = wp(`eval 'echo get_page_by_path("producto-c-chacao", OBJECT, "product")->ID;'`);
  const [tid, idx] = wp(`eval '$t=get_term_by("name","Tienda Chacao","locations"); echo $t->term_id, " ", TS_Locations::mli_index_of($t->term_id);'`).split(' ');
  const page = wp(`post create --post_type=page --post_status=publish --post_title="Botones" --post_content='[ts_test_boton id="${pid}"]' --porcelain`);
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext();
    await ctx.addInitScript(() => { const n = function () {}; window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } }; });
    await ctx.addCookies([
      { name: 'ts_estado', value: '__ALL__', url: BASE },
      { name: 'wcmlim_selected_location_termid', value: tid, url: BASE },
      { name: 'wcmlim_selected_location', value: idx, url: BASE },
    ]);
    const p = await ctx.newPage();
    await p.goto(`${BASE}/?page_id=${page}`, { waitUntil: 'networkidle' });
    const a = await p.$eval('.ts-test-card a', e => ({ cls: e.className, svg: !!e.querySelector('svg.ts-test-icon'), wrap: !!e.querySelector('.gspb-buttonbox-textwrap'), text: e.textContent.trim(), termid: e.getAttribute('data-location_termid'), aria: e.getAttribute('aria-label') }));
    log('el botón conserva su icono SVG y su contenido (no pasa a texto)', a.svg && a.wrap && a.text === '', JSON.stringify({ svg: a.svg, wrap: a.wrap, text: a.text }));
    const cls = a.cls.split(/\s+/);
    log('conserva sus clases y lleva la de Multi Locations, sin la de AJAX de WooCommerce', cls.includes('wp-element-button') && cls.includes('wcmlim_ajax_add_to_cart') && !cls.includes('ajax_add_to_cart'), a.cls);
    log('lleva la tienda elegida y un aria-label bien formado', a.termid === tid && /^Añadir al carrito: "Producto C/.test(a.aria || ''), JSON.stringify({ termid: a.termid, aria: a.aria }));
    const count = async () => Number(((await ctx.cookies()).find(x => x.name === 'woocommerce_items_in_cart') || {}).value || 0);
    await p.click('.ts-test-card a');
    for (let i = 0; i < 40 && !(await count()); i++) await p.waitForTimeout(250);
    await p.waitForTimeout(1500);
    await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    const rows = await p.$$eval('.woocommerce-cart-form__cart-item', els => els.map(e => ({ name: e.querySelector('.product-name').textContent.replace(/\s+/g, ' ').trim(), qty: (e.querySelector('input.qty') || {}).value })));
    log('al pulsarlo se añade una vez, con la tienda elegida', rows.length === 1 && rows[0].qty === '1' && /Tienda: Tienda Chacao/.test(rows[0].name), JSON.stringify(rows));
  } finally {
    await browser.close();
    wp(`post delete ${page} --force`);
    try { fs.unlinkSync(MU); } catch (e) {}
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
