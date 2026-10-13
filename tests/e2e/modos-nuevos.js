/**
 * "Tiendas que ve el cliente: Todas las tiendas (sin filtrar)" y "Detección del estado: GPS y, si
 * falla, seleccionar la tienda por defecto", con sus combinaciones. Tienda por defecto: Valencia.
 *
 *   - Todas + GPS / GPS-y-por-defecto: con ubicación, la tienda más cercana de todas; sin ella, la
 *     tienda por defecto. Nunca se pregunta el estado.
 *   - Todas + Preguntar / No detectar: la tienda por defecto, sin ventana.
 *   - Por estado + GPS-y-por-defecto: con ubicación, su estado y la tienda más cercana; sin ella (o
 *     lejos de todas), la tienda por defecto con su estado, sin ventana y sin consultar la IP; y no
 *     se vuelve a pedir la ubicación.
 *   - Por ciudad + GPS-y-por-defecto: sin consultar la IP.
 *
 *   node modos-nuevos.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const setting = (key, value) => wp(`eval '$s=(array)get_option("ts_settings"); $s["${key}"]="${value}"; update_option("ts_settings",$s);'`);
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());
const nameOf = id => Object.keys(LOC).find(k => String(LOC[k]) === String(id)) || String(id);
const ALL4 = '["Tienda Chacao","Tienda Delicias","Tienda San Francisco","Tienda Valencia"]';
const MARACAIBO = { latitude: 10.6427, longitude: -71.6125 };
const CARACAS = { latitude: 10.4806, longitude: -66.9036 };
const MERIDA = { latitude: 8.5897, longitude: -71.1561 };

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
async function waitFor(check, ms = 15000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (await check()) return true; await new Promise(r => setTimeout(r, 250)); } return false; }

/** geo: coordenadas (concede), null (niega) o 'silent' (nunca contesta). */
async function visit(browser, geo, opts = {}) {
  const ctx = await browser.newContext({ geolocation: geo && geo !== 'silent' ? geo : undefined, permissions: geo && geo !== 'silent' ? ['geolocation'] : [] });
  await ctx.addInitScript(silent => {
    const n = function () {};
    window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } };
    window.__geoCalls = 0;
    const orig = navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);
    navigator.geolocation.getCurrentPosition = function (ok, err, o) { window.__geoCalls++; if (!silent) return orig(ok, err, o); };
  }, geo === 'silent');
  const p = await ctx.newPage();
  const ajax = [];
  p.on('request', r => { const m = (r.postData() || '').match(/action=(ts_[a-z_]+)/); if (m) ajax.push(m[1]); });
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await waitFor(async () => { const c = Object.fromEntries((await ctx.cookies()).map(x => [x.name, x.value])); return !!(c.wcmlim_user_lat || c.ts_geo); }, opts.wait || 15000);
  await p.waitForTimeout(1500);
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForTimeout(1200);
  const c = Object.fromEntries((await ctx.cookies()).map(x => [x.name, x.value]));
  const sw = await p.$$eval('#wcmlim-change-lc-select option', o => o.filter(x => x.value !== '-1' && x.value !== '').map(x => x.textContent.trim())).catch(() => []);
  const modal = await p.$eval('#ts-state-modal', e => !e.hidden).catch(() => false);
  const geoCallsSecond = await p.evaluate(() => window.__geoCalls);
  return { ctx, p, c, sel: nameOf(c.wcmlim_selected_location_termid), sw: sw.slice().sort(), modal, ajax, geoCallsSecond };
}

(async () => {
  const saved = wp('option get ts_settings --format=json');
  setting('default_location', String(LOC['Tienda Valencia']));
  setting('ip_fallback', 'yes'); // Para comprobar que la opción nueva no la usa.
  const browser = await chromium.launch();
  try {
    // ---- Todas las tiendas ----
    setting('visibility_mode', 'all');
    for (const detect of ['gps', 'gps_default']) {
      setting('detect_state', detect);
      let r = await visit(browser, MARACAIBO);
      log(`todas + ${detect} · da su ubicación en Maracaibo: la más cercana (Delicias), ve las 4 y no se le pregunta el estado`, r.sel === 'Tienda Delicias' && JSON.stringify(r.sw) === ALL4 && !r.modal && !r.c.ts_estado, JSON.stringify({ sel: r.sel, sw: r.sw, modal: r.modal, estado: r.c.ts_estado || null }));
      await r.ctx.close();
      r = await visit(browser, CARACAS);
      log(`todas + ${detect} · en Caracas: Chacao`, r.sel === 'Tienda Chacao' && JSON.stringify(r.sw) === ALL4, r.sel);
      await r.ctx.close();
    }
    setting('detect_state', 'gps_default');
    {
      const r = await visit(browser, null);
      log('todas + gps y por defecto · niega la ubicación: tienda por defecto, ve las 4, sin ventana ni IP', r.sel === 'Tienda Valencia' && JSON.stringify(r.sw) === ALL4 && !r.modal && !r.ajax.includes('ts_ip_locate') && r.c.ts_geo === 'denied', JSON.stringify({ sel: r.sel, modal: r.modal, ajax: r.ajax, ts_geo: r.c.ts_geo }));
      log('todas + gps y por defecto · no se le vuelve a pedir la ubicación', r.geoCallsSecond === 0, 'peticiones en la 2.ª página: ' + r.geoCallsSecond);
      await r.ctx.close();
    }
    {
      const r = await visit(browser, 'silent', { wait: 20000 });
      log('todas + gps y por defecto · no contesta al aviso: a los segundos, tienda por defecto y no se vuelve a pedir', r.sel === 'Tienda Valencia' && !!r.c.ts_geo && r.geoCallsSecond === 0 && !r.ajax.includes('ts_ip_locate'), JSON.stringify({ sel: r.sel, ts_geo: r.c.ts_geo, geo2: r.geoCallsSecond }));
      await r.ctx.close();
    }
    for (const detect of ['ask', 'off']) {
      setting('detect_state', detect);
      const r = await visit(browser, MARACAIBO, { wait: 3000 });
      log(`todas + ${detect}: tienda por defecto, ve las 4, sin ventana ni petición de ubicación`, r.sel === 'Tienda Valencia' && JSON.stringify(r.sw) === ALL4 && !r.modal && r.geoCallsSecond === 0, JSON.stringify({ sel: r.sel, modal: r.modal, geo2: r.geoCallsSecond }));
      await r.ctx.close();
    }

    // ---- Por estado + GPS y, si falla, la tienda por defecto ----
    setting('visibility_mode', 'state');
    setting('detect_state', 'gps_default');
    {
      const r = await visit(browser, MARACAIBO);
      log('estado + gps y por defecto · en Maracaibo: Zulia y la más cercana (Delicias), sin ventana', r.c.ts_estado === 'ZU' && r.sel === 'Tienda Delicias' && !r.modal, JSON.stringify({ estado: r.c.ts_estado, sel: r.sel, modal: r.modal }));
      await r.ctx.close();
    }
    {
      const r = await visit(browser, null);
      log('estado + gps y por defecto · niega la ubicación: tienda por defecto con su estado (Carabobo), sin ventana ni IP', r.sel === 'Tienda Valencia' && r.c.ts_estado === 'CA' && !r.modal && !r.ajax.includes('ts_ip_locate'), JSON.stringify({ sel: r.sel, estado: r.c.ts_estado, src: r.c.ts_estado_src, modal: r.modal, ajax: r.ajax }));
      log('estado + gps y por defecto · no se le vuelve a pedir la ubicación', r.geoCallsSecond === 0, 'peticiones en la 2.ª página: ' + r.geoCallsSecond);
      await r.ctx.close();
    }
    {
      const r = await visit(browser, MERIDA);
      log('estado + gps y por defecto · en Mérida (sin tiendas a menos de 100 km): tienda por defecto, sin ventana', r.sel === 'Tienda Valencia' && !r.modal, JSON.stringify({ sel: r.sel, estado: r.c.ts_estado, modal: r.modal }));
      await r.ctx.close();
    }

    // ---- Por ciudad + GPS y, si falla, la tienda por defecto: sin IP ----
    setting('visibility_mode', 'city');
    {
      const r = await visit(browser, null);
      log('ciudad + gps y por defecto · niega la ubicación: tienda por defecto, sin consultar la IP', r.sel === 'Tienda Valencia' && JSON.stringify(r.sw) === '["Tienda Valencia"]' && !r.ajax.includes('ts_ip_locate'), JSON.stringify({ sel: r.sel, sw: r.sw, ajax: r.ajax }));
      await r.ctx.close();
    }

    // ---- Los modos de siempre no cambian (muestra) ----
    setting('visibility_mode', 'state');
    setting('detect_state', 'gps');
    setting('ip_fallback', 'no');
    {
      const r = await visit(browser, null, { wait: 4000 });
      log('sin cambios · por estado + GPS de siempre: si niega la ubicación, sale la ventana de estado', r.modal === true, JSON.stringify({ modal: r.modal }));
      await r.ctx.close();
    }
  } finally {
    await browser.close();
    wp(`option update ts_settings '${saved}' --format=json`);
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
