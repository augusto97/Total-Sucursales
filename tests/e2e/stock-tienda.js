/**
 * Producto sin stock en la tienda del cliente, pero con stock en otras que también ve.
 *
 * Antes la ficha decía "13 disponibles" (el stock total) y "Añadir al carrito" parecía añadir pero el
 * carrito seguía vacío: Multi Locations lo intentaba en la tienda de la cabecera, fallaba y no decía
 * nada. Ahora:
 *   - el stock dice "Sin stock en {tienda}" y junto al botón sale un aviso con las tiendas que sí lo
 *     tienen y un botón para elegir otra;
 *   - "Añadir al carrito" no envía nada (resalta el aviso) hasta que se elige una tienda con stock;
 *   - eligiendo una con stock (panel lateral o desplegable) se añade con esa tienda;
 *   - si aun así llega al servidor un alta en una tienda sin stock, contesta con un aviso;
 *   - y si Multi Locations no añade y no dice por qué, sale un aviso genérico.
 *
 * Producto A tiene stock en Delicias y Chacao; la cabecera está en Tienda Valencia. Vistas "drawer"
 * (panel lateral "Disponibilidad por tienda") y "select". Se deja todo como estaba.
 *
 *   node stock-tienda.js     # con el servidor en 127.0.0.1:8080
 */
const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const MU = WP + '/wp-content/mu-plugins/ts-test-falla-silenciosa.php';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const wpSoft = a => { try { return wp(a); } catch (e) { return ''; } };

// Sólo para las pruebas: una validación que rechaza el alta sin dejar aviso.
const MU_SRC = `<?php
add_filter( 'woocommerce_add_to_cart_validation', function ( $passed ) {
	return isset( $_POST['ts_test_fail'] ) ? false : $passed;
}, 99 );
`;

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
const loc = n => wp(`eval '$t=get_term_by("name","${n}","locations"); echo $t->term_id, " ", TS_Locations::mli_index_of($t->term_id);'`).split(' ');

(async () => {
  const saved = { view: wp('option get wcmlim_backend_display_stock_view'), clear: wpSoft('option get wcmlim_clear_cart') };
  fs.mkdirSync(WP + '/wp-content/mu-plugins', { recursive: true });
  fs.writeFileSync(MU, MU_SRC);
  wp('option update wcmlim_clear_cart ""');
  const valencia = loc('Tienda Valencia');
  const chacao = loc('Tienda Chacao');
  const browser = await chromium.launch();
  const context = async (store) => {
    const ctx = await browser.newContext({ locale: 'es-VE', viewport: { width: 1280, height: 900 } });
    // Sin Google Maps, el JS de Multi Locations se corta antes de enganchar su selector.
    await ctx.addInitScript(() => { const n = function () {}; window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } }; });
    await ctx.addCookies([
      { name: 'ts_estado', value: '__ALL__', url: BASE },
      { name: 'wcmlim_selected_location_termid', value: store[0], url: BASE },
      { name: 'wcmlim_selected_location', value: store[1], url: BASE },
    ]);
    return ctx;
  };
  const cartRows = async p => {
    await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    return p.$$eval('.woocommerce-cart-form__cart-item', els => els.map(e => e.querySelector('.product-name').textContent.replace(/\s+/g, ' ').trim()));
  };
  const count = async ctx => Number(((await ctx.cookies()).find(x => x.name === 'woocommerce_items_in_cart') || {}).value || 0);
  const PROD = BASE + '/product/producto-a-delicias-y-chacao/';
  try {
    // ---- Vista "drawer" (panel lateral) ----
    wp('option update wcmlim_backend_display_stock_view drawer_view');
    {
      const ctx = await context(valencia);
      const p = await ctx.newPage();
      let adds = 0;
      p.on('request', r => { if (/action=wcmlim_ajax_add_to_cart/.test(r.postData() || '')) adds++; });
      await p.goto(PROD, { waitUntil: 'networkidle' });
      const stock = await p.$eval('.summary .stock', e => e.textContent.trim()).catch(() => '');
      log('drawer: el stock dice "Sin stock en Tienda Valencia" (no el total)', stock === 'Sin stock en Tienda Valencia', stock);
      const box = await p.$eval('.ts-store-stock', e => ({ visible: !e.hidden && e.offsetHeight > 0, text: e.textContent.replace(/\s+/g, ' ').trim() })).catch(() => ({ visible: false, text: '' }));
      log('drawer: aviso visible con las tiendas que sí lo tienen', box.visible && /Tienda Valencia/.test(box.text) && /Tienda Chacao/.test(box.text) && /Tienda Delicias/.test(box.text), box.text);
      await p.click('button.single_add_to_cart_button');
      await p.waitForTimeout(1500);
      const alert = await p.$eval('.ts-store-stock', e => e.classList.contains('ts-store-stock--alert'));
      log('drawer: "Añadir" sin elegir tienda no envía nada y resalta el aviso', adds === 0 && alert && (await count(ctx)) === 0, JSON.stringify({ adds, alert }));

      await p.click('.ts-store-stock__pick');
      await p.waitForTimeout(800);
      const open = await p.$eval('#wcmlim-sidePopup', e => e.getBoundingClientRect().width > 50).catch(() => false);
      log('drawer: "Elegir otra tienda" abre el panel de Multi Locations', open);
      const done = p.waitForResponse(r => /wcmlim_set_location_on_change/.test(r.request().postData() || ''), { timeout: 8000 }).catch(() => null);
      await p.click(`#wcmlim-sidePopup .location-stock-item[data-location-id="${chacao[0]}"]`);
      await done; await p.waitForTimeout(500);
      const hidden = await p.$eval('.ts-store-stock', e => e.hidden);
      log('drawer: al elegir Tienda Chacao el aviso se oculta', hidden);
      await p.click('button.single_add_to_cart_button');
      for (let i = 0; i < 40 && !(await count(ctx)); i++) await p.waitForTimeout(250);
      const rows = await cartRows(p);
      log('drawer: se añade con la tienda elegida (Chacao)', rows.length === 1 && /Tienda: Tienda Chacao/.test(rows[0]), JSON.stringify(rows));
      await ctx.close();
    }

    // ---- Aunque llegue al servidor: aviso en lugar de respuesta vacía ----
    {
      const ctx = await context(valencia);
      const p = await ctx.newPage();
      await p.goto(PROD, { waitUntil: 'networkidle' });
      const pid = await p.$eval('button.single_add_to_cart_button', b => b.value);
      const post = extra => p.evaluate(([pid, extra]) => new Promise(res => jQuery.post(wc_add_to_cart_params.ajax_url, Object.assign({ action: 'wcmlim_ajax_add_to_cart', security: wc_add_to_cart_params.security, product_id: pid, quantity: 1 }, extra)).always((d, s, x) => res(typeof d === 'string' ? d : JSON.stringify(d)))), [pid, extra]);
      const r1 = await post({ product_location_termid: valencia[0] });
      log('servidor: alta en tienda sin stock → {error, product_url}', /"error":true/.test(r1) && /product_url/.test(r1), r1.slice(0, 120));
      await p.goto(PROD, { waitUntil: 'networkidle' });
      const n1 = await p.$$eval('.woocommerce-error li, .woocommerce-error', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()).join(' | '));
      log('servidor: la ficha muestra el aviso con las otras tiendas', /no tiene stock en Tienda Valencia/.test(n1) && /Tienda Chacao/.test(n1), n1);

      const r2 = await post({ product_location_termid: chacao[0], ts_test_fail: 1 });
      log('servidor: si Multi Locations no añade y no dice por qué → {error, product_url}', /"error":true/.test(r2), r2.slice(0, 120));
      await p.goto(PROD, { waitUntil: 'networkidle' });
      const n2 = await p.$$eval('.woocommerce-error li, .woocommerce-error', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()).join(' | '));
      log('servidor: aviso genérico "No se pudo añadir…"', /No se pudo añadir el producto al carrito/.test(n2), n2);
      log('servidor: el carrito sigue vacío', (await count(ctx)) === 0);
      await ctx.close();
    }

    // ---- Vista "select" (desplegable) ----
    wp('option update wcmlim_backend_display_stock_view select_view');
    {
      const ctx = await context(valencia);
      const p = await ctx.newPage();
      let adds = 0;
      p.on('request', r => { if (/action=wcmlim_ajax_add_to_cart/.test(r.postData() || '')) adds++; });
      await p.goto(PROD, { waitUntil: 'networkidle' });
      const vis = await p.$eval('.ts-store-stock', e => !e.hidden && e.offsetHeight > 0).catch(() => false);
      await p.click('button.single_add_to_cart_button');
      await p.waitForTimeout(1200);
      log('select: aviso visible y "Añadir" sin tienda no envía nada', vis && adds === 0, JSON.stringify({ vis, adds }));
      const opt = await p.$$eval('select.select_location option', o => (o.find(x => /Delicias/.test(x.textContent)) || {}).value);
      const done = p.waitForResponse(r => /wcmlim_set_location_on_change/.test(r.request().postData() || ''), { timeout: 8000 }).catch(() => null);
      await p.selectOption('select.select_location', opt);
      await done; await p.waitForTimeout(500);
      const hidden = await p.$eval('.ts-store-stock', e => e.hidden);
      log('select: al elegir Tienda Delicias el aviso se oculta', hidden);
      await p.click('button.single_add_to_cart_button');
      for (let i = 0; i < 40 && !(await count(ctx)); i++) await p.waitForTimeout(250);
      const rows = await cartRows(p);
      log('select: se añade con la tienda elegida (Delicias)', rows.length === 1 && /Tienda: Tienda Delicias/.test(rows[0]), JSON.stringify(rows));
      await ctx.close();
    }

    // ---- Con stock en la tienda del cliente: nada cambia ----
    {
      const ctx = await context(chacao);
      const p = await ctx.newPage();
      await p.goto(PROD, { waitUntil: 'networkidle' });
      const box = await p.$('.ts-store-stock');
      const stock = await p.$eval('.summary .stock', e => e.textContent.trim()).catch(() => '');
      log('con stock en la tienda (Chacao): sin aviso y el stock normal', !box && !/Sin stock/.test(stock), stock);
      await ctx.close();
    }
  } finally {
    await browser.close();
    wp(`option update wcmlim_backend_display_stock_view '${saved.view}'`);
    wp(`option update wcmlim_clear_cart '${saved.clear}'`);
    try { fs.unlinkSync(MU); } catch (e) {}
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
