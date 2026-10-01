/**
 * Vista rápida de producto dentro de una página (como el bloque "Product Quick View" de GreenShift).
 *
 * Multi Locations dibuja su selector de tiendas con el producto de la entrada actual ($post), que
 * ahí es la página: wc_get_product() devuelve false y la página (y el editor, que la precarga por la
 * API REST) muere con "Call to a member function get_price_html() on false".
 *
 * Un mu-plugin de prueba añade el shortcode [ts_test_vista_rapida id="…"], que hace lo mismo que esa
 * vista rápida: fija el producto y pinta su formulario de compra. Se comprueba la página publicada,
 * la API REST (lo que precarga el editor) y que, sin producto, la página no se rompe. Se borra todo
 * al terminar.
 *
 *   node vista-rapida.js     # con el servidor en 127.0.0.1:8080
 */
const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const MU = WP + '/wp-content/mu-plugins/ts-test-vista-rapida.php';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const setting = (key, value) => wp(`eval '$s=(array)get_option("ts_settings"); $s["${key}"]="${value}"; update_option("ts_settings",$s);'`);

const MU_SRC = `<?php
// Sólo para las pruebas: imita una vista rápida de producto dentro de una página.
add_shortcode( 'ts_test_vista_rapida', function ( $atts ) {
	global $product;
	$id      = isset( $atts['id'] ) ? (int) $atts['id'] : 0;
	$product = $id ? wc_get_product( $id ) : null;
	ob_start();
	echo '<div class="ts-test-qv">';
	if ( $product ) {
		woocommerce_template_single_add_to_cart();
	} else {
		do_action( 'woocommerce_before_add_to_cart_button' );
	}
	echo '</div>';
	return ob_get_clean();
} );
`;

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
const get = async url => { const r = await fetch(url); return { status: r.status, body: await r.text() }; };
const fatal = b => /Fatal error|Uncaught Error|critical error|error crítico/i.test(b);

(async () => {
  fs.mkdirSync(WP + '/wp-content/mu-plugins', { recursive: true });
  fs.writeFileSync(MU, MU_SRC);
  const pid = wp(`eval 'echo get_page_by_path("producto-a-delicias-y-chacao", OBJECT, "product")->ID;'`);
  const page = wp(`post create --post_type=page --post_status=publish --post_title="Vista rapida" --post_content='[ts_test_vista_rapida id="${pid}"]' --porcelain`);
  const empty = wp(`post create --post_type=page --post_status=publish --post_title="Vista rapida vacia" --post_content='[ts_test_vista_rapida]' --porcelain`);
  const pidC = wp(`eval 'echo get_page_by_path("producto-c-chacao", OBJECT, "product")->ID;'`);
  const two = wp(`post create --post_type=page --post_status=publish --post_title="Listado" --post_content='[ts_test_vista_rapida id="${pid}"][ts_test_vista_rapida id="${pidC}"]' --porcelain`);
  const saved = wp('option get ts_settings --format=json');
  try {
    // ---- Por defecto: el selector de tiendas sólo en la ficha del producto ----
    const list = await get(`${BASE}/?page_id=${two}`);
    const forms = list.body.match(/<div class="ts-test-qv">[\s\S]*?<\/form>/g) || [];
    log('listado: sin el selector de tiendas en las tarjetas', forms.length === 2 && forms.every(f => !/select_location|Disponibilidad por tienda|Stock Information/.test(f)), forms.length + ' formularios');
    const prodPage = await get(`${BASE}/?p=${pid}&post_type=product`);
    log('ficha del producto: el selector sigue', /select_location/.test(prodPage.body));

    {
      const chacao = wp(`eval '$t=get_term_by("name","Tienda Chacao","locations"); echo $t->term_id, " ", TS_Locations::mli_index_of($t->term_id);'`).split(' ');
      const browser = await chromium.launch();
      const ctx = await browser.newContext();
      await ctx.addInitScript(() => { const n = function () {}; window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } }; });
      await ctx.addCookies([
        { name: 'ts_estado', value: '__ALL__', url: BASE },
        { name: 'wcmlim_selected_location_termid', value: chacao[0], url: BASE },
        { name: 'wcmlim_selected_location', value: chacao[1], url: BASE },
      ]);
      const p = await ctx.newPage();
      await p.goto(`${BASE}/?page_id=${two}`, { waitUntil: 'networkidle' });
      const cards = await p.$$('.ts-test-qv form.cart');
      await cards[1].$eval('input.qty', e => { e.value = '2'; });
      const count = async () => Number(((await ctx.cookies()).find(x => x.name === 'woocommerce_items_in_cart') || {}).value || 0);
      await (await cards[1].$('button.single_add_to_cart_button')).click();
      for (let i = 0; i < 40 && !(await count()); i++) await p.waitForTimeout(250);
      await p.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
      const rows = await p.$$eval('.woocommerce-cart-form__cart-item', els => els.map(e => ({ name: e.querySelector('.product-name').textContent.replace(/\s+/g, ' ').trim(), qty: (e.querySelector('input.qty') || {}).value })));
      log('listado: "Añadir" del 2.º producto con cantidad 2 → ese producto, 2 unidades, con la tienda de la cabecera', rows.length === 1 && /Producto C/.test(rows[0].name) && /Tienda: Tienda Chacao/.test(rows[0].name) && rows[0].qty === '2', JSON.stringify(rows));
      await browser.close();
    }

    // ---- Con el selector también fuera de la ficha (ajuste desactivado) ----
    setting('selector_only_single', 'no');
    const front = await get(`${BASE}/?page_id=${page}`);
    log('página con vista rápida: carga sin error fatal', front.status === 200 && !fatal(front.body), 'HTTP ' + front.status);
    const qv = (front.body.match(/<div class="ts-test-qv">([\s\S]*?)<\/form>/) || [])[1] || '';
    log('ajuste desactivado: el selector sale, con las tiendas del producto mostrado', /Tienda Delicias/.test(qv) && /Tienda Chacao/.test(qv), qv.replace(/\s+/g, ' ').slice(0, 160));
    const stock = (front.body.match(/<p class="stock[^"]*">([^<]*)<\/p>/) || [])[1] || '';
    log('el stock de Multi Locations sale en español ("N disponibles", no "N In Stock.")', /^\d+ disponibles?$/.test(stock.trim()), stock);

    const rest = await get(`${BASE}/?rest_route=/wp/v2/pages/${page}`);
    log('API REST de la página (lo que precarga el editor): sin error fatal', rest.status === 200 && !fatal(rest.body), 'HTTP ' + rest.status);

    const none = await get(`${BASE}/?page_id=${empty}`);
    log('sin producto que mostrar: la página no se rompe', none.status === 200 && !fatal(none.body), 'HTTP ' + none.status);

    const prod = await get(`${BASE}/?p=${pid}&post_type=product`);
    log('la ficha normal del producto sigue con su selector', prod.status === 200 && /select_location/.test(prod.body), 'HTTP ' + prod.status);
  } finally {
    wp(`option update ts_settings '${saved}' --format=json`);
    wp(`post delete ${page} ${empty} ${two} --force`);
    try { fs.unlinkSync(MU); } catch (e) {}
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
