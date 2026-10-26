/**
 * Con caché de página (LiteSpeed Cache en el sitio): la misma página para todos los visitantes.
 *
 * En producción LiteSpeed servía a todos la página generada para un visitante nuevo: con la tienda
 * por defecto en la cabecera, "aún no eligió" en ts_params y nonces de días. Resultado:
 *   - la detección automática (GPS y, si falla, tienda por defecto) se repetía en cada página y
 *     volvía a poner la tienda del GPS o la de por defecto encima de la que eligió el cliente,
 *     recargando la página (y cerrando el selector) mientras elegía;
 *   - la cabecera decía siempre la tienda por defecto;
 *   - con el nonce caducado no se guardaba la tienda ni se añadía al carrito.
 *
 * Aquí se simula esa caché: cada página se pide una vez sin cookies (como un visitante nuevo) y a
 * los navegadores de la prueba se les sirve siempre esa copia; las peticiones AJAX sí van al
 * servidor. Ajustes como en el sitio: "Todas las tiendas", "GPS y, si falla, la tienda por
 * defecto", selector [ts_tienda] en la cabecera y la vista "panel lateral" de Multi Locations.
 *
 * Al final, con LiteSpeed Cache instalado (sin servidor LiteSpeed), que su ajuste "Vary Cookies"
 * queda con las cookies de la tienda.
 *
 *   node cache-pagina.js     # con el servidor en 127.0.0.1:8080
 */
const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const MU = WP + '/wp-content/mu-plugins/ts-test-cabecera.php';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const wpSoft = a => { try { return wp(a); } catch (e) { return ''; } };
const setting = (key, value) => wp(`eval '$s=(array)get_option("ts_settings"); $s["${key}"]="${value}"; update_option("ts_settings",$s);'`);
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=array($t->term_id, TS_Locations::mli_index_of($t->term_id));} echo json_encode($o);'`).split('\n').pop());
const ID = n => String(LOC[n][0]);
const NAME = id => Object.keys(LOC).find(k => String(LOC[k][0]) === String(id)) || String(id);

const MARACAIBO = { latitude: 10.6427, longitude: -71.6125 };
const HOME = BASE + '/';
const PROD = BASE + '/product/producto-a-delicias-y-chacao/';

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

// Cabecera con el selector de tienda en todas las páginas, como en el sitio.
const MU_SRC = `<?php
add_action( 'wp_body_open', function () {
	echo '<div class="ts-test-header">' . do_shortcode( '[ts_tienda]' ) . '</div>';
} );
`;

const CACHE = {};
async function prime(urls, mutate) {
  for (const u of urls) {
    const r = await fetch(u);
    let html = await r.text();
    if (mutate) html = mutate(html);
    CACHE[u] = html;
  }
}

/**
 * Navegador de un visitante. geo: coordenadas (GPS concedido), 'silent' (el aviso del GPS nunca se
 * contesta) o null (sin GPS). Las páginas salen de CACHE; lo demás, del servidor.
 */
async function visitor(browser, geo, cookies = [], opts = {}) {
  const ctx = await browser.newContext({
    geolocation: geo && geo !== 'silent' ? geo : undefined,
    permissions: geo && geo !== 'silent' ? ['geolocation'] : [],
    viewport: { width: 1280, height: 900 },
  });
  await ctx.addInitScript(silent => {
    const n = function () {};
    window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } };
    if (silent) { navigator.geolocation.getCurrentPosition = function () {}; }
  }, geo === 'silent');
  if (cookies.length) await ctx.addCookies(cookies.map(c => ({ ...c, url: BASE })));
  const served = [];
  await ctx.route('**/*', async route => {
    const req = route.request();
    const url = req.url().split('#')[0];
    if (req.resourceType() === 'document' && req.method() === 'GET' && CACHE[url]) {
      served.push(url);
      return route.fulfill({ status: 200, contentType: 'text/html; charset=UTF-8', body: CACHE[url] });
    }
    if (opts.delayPicker && /ts-store-picker\.js/.test(url)) {
      await new Promise(r => setTimeout(r, opts.delayPicker));
    }
    return route.continue();
  });
  const p = await ctx.newPage();
  const ajax = [];
  p.on('request', r => { const m = (r.postData() || '').match(/action=(ts_[a-z_]+|wcmlim_ajax_add_to_cart)/); if (m) ajax.push(m[1]); });
  let navs = 0;
  p.on('framenavigated', f => { if (f === p.mainFrame()) navs++; });
  const cookie = async n => ((await ctx.cookies()).find(c => c.name === n) || {}).value;
  const header = () => p.$eval('.ts-test-header .ts-sp__name', e => e.textContent.trim()).catch(() => '');
  return { ctx, p, ajax, served, cookie, header, navs: () => navs };
}

async function pickInHeader(v, store) {
  await v.p.click('.ts-test-header .ts-sp__trigger');
  await v.p.waitForSelector('#ts-sp-modal:not([hidden])', { timeout: 5000 });
  await v.p.check(`#ts-sp-modal input[name="ts_sp_store"][value="${ID(store)}"]`);
  await Promise.all([v.p.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }).catch(() => null), v.p.click('#ts-sp-modal .ts-sp-modal__save')]);
}

(async () => {
  const saved = {
    settings: wp('option get ts_settings --format=json'),
    view: wp('option get wcmlim_backend_display_stock_view'),
    clear: wpSoft('option get wcmlim_clear_cart'),
  };
  fs.mkdirSync(WP + '/wp-content/mu-plugins', { recursive: true });
  fs.writeFileSync(MU, MU_SRC);
  setting('visibility_mode', 'all');
  setting('detect_state', 'gps_default');
  setting('default_location', ID('Tienda Valencia'));
  setting('ip_fallback', 'no');
  setting('store_picker', 'yes');
  wp('option update wcmlim_backend_display_stock_view drawer_view');
  wp('option update wcmlim_clear_cart ""');
  const browser = await chromium.launch();
  try {
    await prime([HOME, PROD]);
    const cachedHeader = (CACHE[HOME].match(/ts-sp__name">([^<]*)/) || [])[1];
    log('la copia en caché es la de un visitante nuevo (tienda por defecto, sin elección)', cachedHeader === 'Tienda Valencia' && /"has_choice":""/.test(CACHE[HOME]), cachedHeader);

    // ---- 1. GPS concedido: detecta una vez; luego elige otra tienda y nada se la cambia ----
    {
      const v = await visitor(browser, MARACAIBO);
      await v.p.goto(HOME, { waitUntil: 'networkidle' });
      await v.p.waitForTimeout(3000); await v.p.waitForLoadState('networkidle');
      log('GPS: la primera visita detecta la tienda más cercana (Delicias) y la cabecera la muestra pese a la caché', (await v.cookie('ts_tienda')) === ID('Tienda Delicias') && (await v.header()) === 'Tienda Delicias', JSON.stringify({ ts_tienda: NAME(await v.cookie('ts_tienda')), header: await v.header() }));

      await pickInHeader(v, 'Tienda Chacao');
      const before = v.ajax.length;
      for (const u of [HOME, PROD, HOME]) {
        await v.p.goto(u, { waitUntil: 'networkidle' });
        await v.p.waitForTimeout(2500);
      }
      const auto = v.ajax.slice(before).filter(a => /ts_set_position|ts_use_default|ts_ip_locate/.test(a));
      log('GPS: elige Chacao a mano y, en las páginas siguientes (de la caché), no se vuelve a detectar', auto.length === 0 && (await v.cookie('wcmlim_selected_location_termid')) === ID('Tienda Chacao'), JSON.stringify({ auto, tienda: NAME(await v.cookie('wcmlim_selected_location_termid')) }));
      log('GPS: la cabecera dice Chacao aunque la página en caché diga Valencia', (await v.header()) === 'Tienda Chacao', await v.header());

      // Panel lateral de la ficha (de la caché): elegir Delicias.
      await v.p.goto(PROD, { waitUntil: 'networkidle' });
      await v.p.click('#wcmlim-drawer_view_btn'); await v.p.waitForTimeout(600);
      await Promise.all([v.p.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }).catch(() => null), v.p.click(`#wcmlim-sidePopup .location-stock-item[data-location-id="${ID('Tienda Delicias')}"]`)]);
      await v.p.waitForTimeout(1500);
      log('ficha en caché: elegir Delicias en el panel lateral la guarda y la cabecera la muestra', (await v.cookie('wcmlim_selected_location_termid')) === ID('Tienda Delicias') && (await v.header()) === 'Tienda Delicias', JSON.stringify({ tienda: NAME(await v.cookie('wcmlim_selected_location_termid')), header: await v.header() }));
      await v.ctx.close();
    }

    // ---- 2. GPS sin contestar: el selector no se cierra mientras elige, y nada pisa su elección ----
    {
      const v = await visitor(browser, 'silent');
      await v.p.goto(HOME, { waitUntil: 'networkidle' });
      await v.p.click('.ts-test-header .ts-sp__trigger');
      await v.p.waitForSelector('#ts-sp-modal:not([hidden])', { timeout: 5000 });
      await v.p.waitForTimeout(10000); // más que la espera de 8 s del GPS
      const open = await v.p.$eval('#ts-sp-modal', e => !e.hidden).catch(() => false);
      log('sin respuesta del GPS: con el selector abierto pasan los 8 s y no se cierra ni recarga', open && v.navs() === 1 && !v.ajax.includes('ts_use_default'), JSON.stringify({ open, navs: v.navs(), ajax: v.ajax }));
      await v.p.check(`#ts-sp-modal input[name="ts_sp_store"][value="${ID('Tienda Chacao')}"]`);
      await Promise.all([v.p.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }).catch(() => null), v.p.click('#ts-sp-modal .ts-sp-modal__save')]);
      for (const u of [PROD, HOME]) {
        await v.p.goto(u, { waitUntil: 'networkidle' });
        await v.p.waitForTimeout(9500);
      }
      log('sin respuesta del GPS: elige Chacao y las páginas siguientes no le ponen la tienda por defecto', (await v.cookie('wcmlim_selected_location_termid')) === ID('Tienda Chacao') && !v.ajax.includes('ts_use_default') && (await v.header()) === 'Tienda Chacao', JSON.stringify({ tienda: NAME(await v.cookie('wcmlim_selected_location_termid')), ajax: v.ajax, header: await v.header() }));
      await v.ctx.close();
    }

    // ---- 3. Visitante nuevo que no toca nada: detección una sola vez ----
    {
      const v = await visitor(browser, 'silent');
      await v.p.goto(HOME, { waitUntil: 'networkidle' });
      await v.p.waitForTimeout(9500); await v.p.waitForLoadState('networkidle');
      const first = v.ajax.filter(a => a === 'ts_use_default').length;
      for (const u of [PROD, HOME]) {
        await v.p.goto(u, { waitUntil: 'networkidle' });
        await v.p.waitForTimeout(9500);
      }
      const total = v.ajax.filter(a => a === 'ts_use_default').length;
      log('sin GPS: la tienda por defecto se pone una vez (no en cada página)', first === 1 && total === 1 && (await v.cookie('wcmlim_selected_location_termid')) === ID('Tienda Valencia'), JSON.stringify({ first, total }));
      await v.ctx.close();
    }

    // ---- 4. Cliente de antes (tienda guardada, sin las cookies nuevas) con GPS ----
    {
      const v = await visitor(browser, MARACAIBO, [
        { name: 'wcmlim_selected_location_termid', value: ID('Tienda Chacao') },
        { name: 'wcmlim_selected_location', value: String(LOC['Tienda Chacao'][1]) },
      ]);
      await v.p.goto(HOME, { waitUntil: 'networkidle' });
      await v.p.waitForTimeout(3500); await v.p.waitForLoadState('networkidle');
      log('cliente que ya tenía tienda: el GPS no se la cambia por la más cercana', (await v.cookie('wcmlim_selected_location_termid')) === ID('Tienda Chacao') && v.navs() === 1, JSON.stringify({ tienda: NAME(await v.cookie('wcmlim_selected_location_termid')), navs: v.navs(), ajax: v.ajax }));
      await v.ctx.close();
    }

    // ---- 5. Nonces caducados en la página en caché ----
    {
      const stale = html => html.replace(/("nonce":")[a-f0-9]+(")/g, '$1ffffffffff$2').replace(/("security":")[a-f0-9]+(")/g, '$1ffffffffff$2');
      await prime([HOME, PROD], stale);
      const v = await visitor(browser, null, [{ name: 'ts_det', value: '1' }]);
      await v.p.goto(HOME, { waitUntil: 'networkidle' });
      await pickInHeader(v, 'Tienda Chacao');
      log('nonce caducado: guardar la tienda en la cabecera funciona', (await v.cookie('wcmlim_selected_location_termid')) === ID('Tienda Chacao'), NAME(await v.cookie('wcmlim_selected_location_termid')));
      await v.p.goto(PROD, { waitUntil: 'networkidle' });
      const count = async () => Number((await v.cookie('woocommerce_items_in_cart')) || 0);
      await v.p.click('button.single_add_to_cart_button');
      for (let i = 0; i < 40 && !(await count()); i++) await v.p.waitForTimeout(250);
      log('nonce de Multi Locations caducado: "Añadir al carrito" pide uno nuevo y añade', (await count()) === 1, JSON.stringify({ cart: await count(), ajax: v.ajax }));
      await v.ctx.close();
      await prime([HOME, PROD]);
    }

    // ---- 6. JavaScript retrasado (LiteSpeed "retrasar JS"): el primer toque no se pierde ----
    {
      const v = await visitor(browser, null, [{ name: 'ts_det', value: '1' }], { delayPicker: 2500 });
      await v.p.goto(HOME, { waitUntil: 'domcontentloaded' });
      await v.p.click('.ts-test-header .ts-sp__trigger');
      await v.p.waitForTimeout(4000);
      const open = await v.p.$eval('#ts-sp-modal', e => !e.hidden).catch(() => false);
      log('toque en el selector antes de que cargue su JavaScript: se abre al cargar', open);
      await v.ctx.close();
    }

    // ---- 7. Servidor: la detección automática no cambia una tienda elegida ----
    {
      const v = await visitor(browser, null, [{ name: 'ts_det', value: '1' }]);
      await v.p.goto(HOME, { waitUntil: 'networkidle' });
      await pickInHeader(v, 'Tienda Chacao');
      const post = data => v.p.evaluate(d => new Promise(res => jQuery.post(ts_params.ajax_url, d).always(r => res(r))), data);
      const r1 = await post({ action: 'ts_use_default', auto: 1 });
      const r2 = await post({ action: 'ts_set_position', lat: MARACAIBO.latitude, lng: MARACAIBO.longitude, context: 'browse', auto: 1 });
      log('servidor: "tienda por defecto" y GPS automáticos no tocan la tienda elegida a mano', (await v.cookie('wcmlim_selected_location_termid')) === ID('Tienda Chacao') && r1.data.reason === 'manual' && r2.data.reason === 'manual', JSON.stringify({ r1: r1.data, r2: r2.data }));
      const r3 = await post({ action: 'ts_set_position', lat: MARACAIBO.latitude, lng: MARACAIBO.longitude, context: 'browse' });
      log('servidor: "usar mi ubicación" pulsado por el cliente sí la cambia (a la más cercana)', (await v.cookie('wcmlim_selected_location_termid')) === ID('Tienda Delicias'), JSON.stringify(r3.data && r3.data.nearest));
      const ajaxPost = origin => fetch(BASE + '/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Origin: origin }, body: 'action=ts_use_default&auto=1' }).then(r => r.status);
      const own = await ajaxPost(BASE);
      const other = await ajaxPost('https://otro-sitio.example');
      log('servidor: sin nonce acepta peticiones del propio sitio y rechaza las de otro', own === 200 && other === 403, JSON.stringify({ own, other }));
      await v.ctx.close();
    }

    // ---- 8. LiteSpeed Cache: "Vary Cookies" con las cookies de la tienda ----
    if (fs.existsSync(WP + '/wp-content/plugins/litespeed-cache')) {
      wp('plugin activate litespeed-cache');
      try {
        wpSoft('option delete ts_litespeed_vary_synced');
        const curr = wp(`eval 'echo implode(",", apply_filters("litespeed_vary_curr_cookies", array()));'`);
        log('LiteSpeed: cada página varía por la tienda y el estado (X-LiteSpeed-Vary)', /wcmlim_selected_location_termid/.test(curr) && /ts_estado/.test(curr), curr);
        const conf = wp(`eval 'wp_set_current_user(1); TS_LiteSpeed::sync_setting(); echo implode(",", (array) \\LiteSpeed\\Conf::cls()->conf("cache-vary_cookies"));'`);
        log('LiteSpeed: su ajuste "Vary Cookies" queda con las cookies de la tienda', /wcmlim_selected_location_termid/.test(conf) && /ts_estado/.test(conf), conf);
      } finally {
        wpSoft(`eval '\\LiteSpeed\\Conf::cls()->update_confs(array("cache-vary_cookies" => array()));'`);
        wpSoft('option delete ts_litespeed_vary_synced');
        wp('plugin deactivate litespeed-cache');
      }
    } else {
      console.log('(sin LiteSpeed Cache en el entorno: se salta su comprobación)');
    }
  } finally {
    await browser.close();
    wp(`option update ts_settings '${saved.settings}' --format=json`);
    wp(`option update wcmlim_backend_display_stock_view '${saved.view}'`);
    wp(`option update wcmlim_clear_cart '${saved.clear}'`);
    try { fs.unlinkSync(MU); } catch (e) {}
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
