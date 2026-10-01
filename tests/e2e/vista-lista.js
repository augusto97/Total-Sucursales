/**
 * Elegir tienda en la ficha con la vista de lista de Multi Locations (botones de radio) y
 * "Restrict to One Location", y la posición guardada de la tienda.
 *
 * - Al pulsar una tienda, su clear-cart.js pide wcmlim_ajax_cart_count sin tienda y Multi Locations
 *   borraba la posición guardada y dejaba elegida la primera tienda de la lista: la tienda pulsada
 *   no quedaba y la cabecera pasaba a "Seleccionar".
 * - La cabecera y la ficha marcan la tienda por su posición en la lista (wcmlim_selected_location);
 *   si esa posición queda vieja, la tienda sigue elegida pero sale "Seleccionar".
 *
 *   node vista-lista.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=array($t->term_id, TS_Locations::mli_index_of($t->term_id));} echo json_encode($o);'`).split('\n').pop());

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
const stub = () => { const n = function () {}; window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } }; };

(async () => {
  const view = wp('option get wcmlim_backend_display_stock_view');
  wp('option update wcmlim_backend_display_stock_view list_view');
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext();
    await ctx.addInitScript(stub);
    await ctx.addCookies([{ name: 'ts_estado', value: '__ALL__', url: BASE }]);
    const p = await ctx.newPage();
    const ck = async () => Object.fromEntries((await ctx.cookies()).map(x => [x.name, x.value]));
    const header = () => p.$eval('#wcmlim-change-lc-select', e => e.options[e.selectedIndex] ? e.options[e.selectedIndex].text.trim() : '').catch(() => '');
    const confirmDialogs = async () => {
      for (let k = 0; k < 3; k++) {
        try {
          const b = await p.waitForSelector('.swal2-confirm', { timeout: 3000 }).catch(() => null);
          if (!b) return;
          await b.click();
        } catch (e) { /* la página recargó mientras tanto */ }
        await p.waitForTimeout(1200); await p.waitForLoadState('networkidle').catch(() => {});
      }
    };
    async function pick(store) {
      if (!/producto-a-delicias-y-chacao/.test(p.url())) await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
      const ids = await p.$$eval('input[name=select_location][type=radio]', r => r.map(x => x.closest('[data-location-id]').getAttribute('data-location-id')));
      const i = ids.indexOf(String(LOC[store][0]));
      const done = p.waitForResponse(r => /wcmlim_set_location_on_change/.test(r.request().postData() || ''), { timeout: 6000 }).catch(() => null);
      await (await p.$$('input[name=select_location][type=radio]'))[i].click({ force: true });
      await done; await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
    }
    const count = async () => Number((await ck()).woocommerce_items_in_cart || 0);

    // ---- 1. Elegir una tienda en la lista: queda elegida, con su posición ----
    await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
    await pick('Tienda Delicias');
    let c = await ck();
    log('lista: al pulsar Delicias queda Delicias (no la primera de la lista)', c.wcmlim_selected_location_termid === String(LOC['Tienda Delicias'][0]) && c.wcmlim_selected_location === String(LOC['Tienda Delicias'][1]), JSON.stringify({ termid: c.wcmlim_selected_location_termid, idx: c.wcmlim_selected_location }));
    await p.waitForFunction(() => { const b = document.querySelector('button.single_add_to_cart_button'); return b && !b.disabled; }, null, { timeout: 10000 }).catch(() => {});
    await p.click('button.single_add_to_cart_button');
    for (let i = 0; i < 40 && !(await count()); i++) await p.waitForTimeout(250);

    // ---- 2. Con el carrito de Delicias, pasar a Chacao ----
    await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
    await pick('Tienda Chacao');
    await confirmDialogs();
    c = await ck();
    log('lista: con carrito, pasar a Chacao deja Chacao elegida', c.wcmlim_selected_location_termid === String(LOC['Tienda Chacao'][0]) && c.wcmlim_selected_location === String(LOC['Tienda Chacao'][1]), JSON.stringify({ termid: c.wcmlim_selected_location_termid, idx: c.wcmlim_selected_location }));
    await p.goto(BASE + '/', { waitUntil: 'networkidle' });
    log('cabecera: muestra la tienda nueva, no "Seleccionar"', (await header()) === 'Tienda Chacao', await header());
    await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    const rows = await p.$$eval('.woocommerce-cart-form__cart-item .product-name', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
    log('carrito: todo pasó a la tienda nueva', rows.length > 0 && rows.every(t => /Tienda: Tienda Chacao/.test(t)), JSON.stringify(rows));
    await ctx.close();

    // ---- 3. Posición guardada vieja: se corrige al cargar la página ----
    const ctx2 = await browser.newContext();
    await ctx2.addInitScript(stub);
    const wrong = (LOC['Tienda Valencia'][1] + 1) % 4;
    await ctx2.addCookies([
      { name: 'ts_estado', value: '__ALL__', url: BASE },
      { name: 'wcmlim_selected_location_termid', value: String(LOC['Tienda Valencia'][0]), url: BASE },
      { name: 'wcmlim_selected_location', value: String(wrong), url: BASE },
    ]);
    const p2 = await ctx2.newPage();
    await p2.goto(BASE + '/', { waitUntil: 'networkidle' });
    const hdr = await p2.$eval('#wcmlim-change-lc-select', e => e.options[e.selectedIndex] ? e.options[e.selectedIndex].text.trim() : '').catch(() => '');
    const c2 = Object.fromEntries((await ctx2.cookies()).map(x => [x.name, x.value]));
    log('posición vieja: la cabecera muestra la tienda elegida y la posición se corrige', hdr === 'Tienda Valencia' && c2.wcmlim_selected_location === String(LOC['Tienda Valencia'][1]), JSON.stringify({ hdr, idx: c2.wcmlim_selected_location }));
    await ctx2.close();
  } finally {
    await browser.close();
    wp(`option update wcmlim_backend_display_stock_view ${view || 'select_view'}`);
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
