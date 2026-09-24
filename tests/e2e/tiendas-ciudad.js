/**
 * Modo "Tiendas que ve el cliente: por ciudad".
 *
 *   - Da su ubicación y hay tiendas en su ciudad (a menos del radio): ve sólo esas, con la más cercana.
 *   - Da su ubicación pero no hay tiendas en su ciudad: ve sólo la tienda por defecto.
 *   - No da su ubicación: ve sólo la tienda por defecto (y no se le vuelve a preguntar).
 * Se comprueba en el selector de tiendas de Multi Locations, el catálogo y la ficha de producto.
 * Tienda por defecto de la prueba: Tienda Valencia. Radio: 20 km.
 *
 *   node tiendas-ciudad.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());
const setting = (key, value) => wp(`eval '$s=(array)get_option("ts_settings"); $s["${key}"]="${value}"; update_option("ts_settings",$s);'`);

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
async function waitFor(check, timeoutMs = 15000) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeoutMs) { if (await check()) return true; await new Promise(r => setTimeout(r, 250)); }
  return false;
}
async function newCtx(browser, geo) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, geolocation: geo || undefined, permissions: geo ? ['geolocation'] : [] });
  await ctx.addInitScript(() => {
    const n = function () {};
    window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n,
      LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n },
      event: { addListener: n, trigger: n }, InfoWindow: n } };
  });
  return ctx;
}
const cookies = async ctx => Object.fromEntries((await ctx.cookies(BASE)).map(c => [c.name, c.value]));
const switcher = p => p.$$eval('#wcmlim-change-lc-select option', o => o.filter(x => x.value !== '-1' && x.value !== '').map(x => x.textContent.trim())).catch(() => []);
const nameOf = id => Object.keys(LOC).find(k => String(LOC[k]) === String(id)) || String(id);

// Primera visita: pide la ubicación, la manda y recarga (o recuerda que no la dio).
async function visit(browser, geo) {
  const ctx = await newCtx(browser, geo);
  const p = await ctx.newPage();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await waitFor(async () => { const c = await cookies(ctx); return geo ? !!c.wcmlim_user_lat : !!c.ts_geo; });
  await p.waitForTimeout(800);
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  return { ctx, p };
}

(async () => {
  const saved = wp('option get ts_settings --format=json');
  setting('visibility_mode', 'city'); setting('city_radius_km', '20'); setting('default_location', String(LOC['Tienda Valencia']));
  const browser = await chromium.launch();
  try {
    // ---- 1. No da su ubicación ----
    {
      const { ctx, p } = await visit(browser, null);
      const sw = await switcher(p); const c = await cookies(ctx);
      const modal = await p.$eval('#ts-state-modal', e => !e.hidden).catch(() => false);
      log('sin ubicación: el selector sólo ofrece la tienda por defecto, elegida', JSON.stringify(sw) === '["Tienda Valencia"]' && nameOf(c.wcmlim_selected_location_termid) === 'Tienda Valencia', JSON.stringify({ sw, sel: nameOf(c.wcmlim_selected_location_termid) }));
      log('sin ubicación: no aparece la ventana de estado y se recuerda para no volver a preguntar', !modal && c.ts_geo === 'denied', JSON.stringify({ modal, ts_geo: c.ts_geo }));
      const gpsBtn = await p.$('.ts-state-selector--city .ts-use-gps');
      log('el shortcode de la cabecera ofrece sólo "Usar mi ubicación"', !!gpsBtn && !(await p.$('#ts-state-select')));
      await p.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
      const prods = await p.$$eval('ul.products li.product .woocommerce-loop-product__title, ul.products li.product h2', els => els.map(e => e.textContent.trim()));
      log('sin ubicación: el catálogo es el de la tienda por defecto', JSON.stringify(prods) === '["Producto D (Valencia)"]', JSON.stringify(prods));
      await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
      const opts = await p.$$eval('select.select_location option', o => o.map(x => x.textContent.trim())).catch(() => []);
      log('sin ubicación: la ficha de producto no ofrece otras tiendas', !opts.some(t => /Delicias|Chacao|San Francisco/.test(t)), JSON.stringify(opts));
      await ctx.close();
    }

    // ---- 2. Da su ubicación en Maracaibo: tiendas de su ciudad ----
    {
      const { ctx, p } = await visit(browser, { latitude: 10.6427, longitude: -71.6125 });
      const sw = await switcher(p); const c = await cookies(ctx);
      log('Maracaibo: sólo las tiendas de su ciudad (Delicias y San Francisco), la más cercana elegida', JSON.stringify(sw.slice().sort()) === '["Tienda Delicias","Tienda San Francisco"]' && nameOf(c.wcmlim_selected_location_termid) === 'Tienda Delicias', JSON.stringify({ sw, sel: nameOf(c.wcmlim_selected_location_termid) }));
      await p.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
      const prods = await p.$$eval('ul.products li.product .woocommerce-loop-product__title, ul.products li.product h2', els => els.map(e => e.textContent.trim()));
      log('Maracaibo: catálogo de la tienda elegida (Delicias)', JSON.stringify(prods) === '["Producto A (Delicias y Chacao)"]', JSON.stringify(prods));
      await ctx.close();
    }

    // ---- 3. Da su ubicación en Cabimas: sin tiendas en su ciudad ----
    {
      const { ctx, p } = await visit(browser, { latitude: 10.401, longitude: -71.446 });
      const sw = await switcher(p); const c = await cookies(ctx);
      log('Cabimas (la más cercana a 28 km): sólo la tienda por defecto', JSON.stringify(sw) === '["Tienda Valencia"]' && nameOf(c.wcmlim_selected_location_termid) === 'Tienda Valencia', JSON.stringify({ sw, sel: nameOf(c.wcmlim_selected_location_termid) }));
      await ctx.close();
    }

    // ---- 4. Da su ubicación en Caracas ----
    {
      const { ctx, p } = await visit(browser, { latitude: 10.4806, longitude: -66.9036 });
      const sw = await switcher(p); const c = await cookies(ctx);
      log('Caracas: sólo Tienda Chacao', JSON.stringify(sw) === '["Tienda Chacao"]' && nameOf(c.wcmlim_selected_location_termid) === 'Tienda Chacao', JSON.stringify({ sw, sel: nameOf(c.wcmlim_selected_location_termid) }));
      await ctx.close();
    }

    // ---- 5. No la dio al principio y luego pulsa "Usar mi ubicación" ----
    {
      const { ctx, p } = await visit(browser, null);
      await ctx.grantPermissions(['geolocation']); await ctx.setGeolocation({ latitude: 10.6427, longitude: -71.6125 });
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }).catch(() => {}), p.click('.ts-use-gps')]);
      const sw = await switcher(p);
      log('"Usar mi ubicación" después: pasa a las tiendas de su ciudad', JSON.stringify(sw.slice().sort()) === '["Tienda Delicias","Tienda San Francisco"]', JSON.stringify(sw));
      await ctx.close();
    }
  } finally {
    wp(`option update ts_settings '${saved}' --format=json`);
    await browser.close();
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
