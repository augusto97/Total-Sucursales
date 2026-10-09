/**
 * Selector de tienda de la cabecera ([ts_tienda]): botón "Tu tienda: …" con ventana.
 *
 * Comprueba el botón, la ventana (estado → tiendas con ciudad y dirección), cambiar a una tienda de
 * otro estado (queda elegida, cambia el estado y sale "Tienda guardada"), el aviso de carrito de otra
 * tienda (pasa el carrito y quita lo que no hay) y que, con el ajuste "Selector de tienda de la
 * cabecera con el diseño de Total Sucursales", [wcmlim_locations_switch] muestre el mismo botón.
 * Deja capturas en logs/selector-*.png.
 *
 *   node selector-tienda.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const setting = (key, value) => wp(`eval '$s=(array)get_option("ts_settings"); $s["${key}"]="${value}"; update_option("ts_settings",$s);'`);
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
const stub = () => { const n = function () {}; window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } }; };

(async () => {
  require('fs').mkdirSync(__dirname + '/logs', { recursive: true });
  const saved = wp('option get ts_settings --format=json');
  const page = wp(`post create --post_type=page --post_status=publish --post_title="Tienda" --post_content='[ts_tienda]' --porcelain`);
  const page2 = wp(`post create --post_type=page --post_status=publish --post_title="Tienda MLI" --post_content='[wcmlim_locations_switch]' --porcelain`);
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1200, height: 800 } });
    await ctx.addInitScript(stub);
    await ctx.addCookies([
      { name: 'ts_estado', value: 'ZU', url: BASE }, { name: 'ts_estado_src', value: 'manual', url: BASE },
      { name: 'wcmlim_selected_location_termid', value: String(LOC['Tienda Delicias']), url: BASE },
    ]);
    const p = await ctx.newPage();
    const ck = async () => Object.fromEntries((await ctx.cookies()).map(x => [x.name, x.value]));
    const url = `${BASE}/?page_id=${page}`;

    // ---- 1. Botón ----
    await p.goto(url, { waitUntil: 'networkidle' });
    const btn = await p.$eval('.ts-sp__trigger', e => e.innerText.replace(/\s+/g, ' ').trim()).catch(() => '');
    log('botón: "Tu tienda" y la tienda actual', /Tu tienda/.test(btn) && /Tienda Delicias/.test(btn), btn);
    await (await p.$('.ts-sp')).screenshot({ path: __dirname + '/logs/selector-boton.png' });

    // ---- 2. Ventana ----
    await p.click('.ts-sp__trigger');
    await p.waitForSelector('#ts-sp-modal:not([hidden])');
    const st = await p.$$eval('.ts-sp-state option', o => o.map(x => x.textContent.trim()));
    const shown = async () => p.$$eval('.ts-sp-store:not([hidden]) .ts-sp-store__name', e => e.map(x => x.textContent.trim()));
    const checked = await p.$eval('input[name=ts_sp_store]:checked', e => e.closest('.ts-sp-store').querySelector('.ts-sp-store__name').textContent.trim()).catch(() => '');
    const addr = await p.$$eval('.ts-sp-store:not([hidden]) .ts-sp-store__address', e => e.map(x => x.textContent.trim()));
    log('ventana: estados con tiendas y las del estado actual, con la actual marcada', st.length === 3 && JSON.stringify((await shown()).sort()) === '["Tienda Delicias","Tienda San Francisco"]' && checked === 'Tienda Delicias', JSON.stringify({ st, shown: await shown(), checked }));
    log('ventana: ciudad · dirección bajo cada tienda', addr.length === 2 && addr.every(a => /·/.test(a)), JSON.stringify(addr));
    await p.waitForTimeout(400);
    await p.screenshot({ path: __dirname + '/logs/selector-ventana.png' });
    await p.keyboard.press('Escape');
    log('Escape cierra la ventana', await p.$eval('#ts-sp-modal', e => e.hidden));

    // ---- 3. Cambiar a una tienda de otro estado ----
    await p.click('.ts-sp__trigger');
    const dc = await p.$$eval('.ts-sp-state option', o => (o.find(x => /Capital/.test(x.textContent)) || {}).value);
    await p.selectOption('.ts-sp-state', dc);
    log('al cambiar de estado se muestran sus tiendas', JSON.stringify(await shown()) === '["Tienda Chacao"]', JSON.stringify(await shown()));
    await p.check(`input[name=ts_sp_store][value="${LOC['Tienda Chacao']}"]`);
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('.ts-sp-modal__save')]);
    let c = await ck();
    const btn2 = await p.$eval('.ts-sp__trigger', e => e.innerText.replace(/\s+/g, ' ').trim()).catch(() => '');
    const toast = await p.$eval('.ts-sp__toast', e => !e.hidden && e.innerText.replace(/\s+/g, ' ').trim()).catch(() => false);
    log('guardar: queda Tienda Chacao, con su estado, y sale "Tienda guardada"', c.wcmlim_selected_location_termid === String(LOC['Tienda Chacao']) && c.ts_estado === 'DC' && /Tienda Chacao/.test(btn2) && /Tienda guardada/.test(toast || ''), JSON.stringify({ termid: c.wcmlim_selected_location_termid, estado: c.ts_estado, btn2, toast }));
    await (await p.$('.ts-sp')).scrollIntoViewIfNeeded();
    const bb = await (await p.$('.ts-sp')).boundingBox();
    await p.screenshot({ path: __dirname + '/logs/selector-guardada.png', clip: { x: 0, y: Math.max(0, bb.y - 20), width: 600, height: 160 } });
    const hdr = await p.$eval('#wcmlim-change-lc-select', e => e.options[e.selectedIndex] ? e.options[e.selectedIndex].text.trim() : '').catch(() => null);
    log('Multi Locations también la tiene elegida', hdr === null || hdr === 'Tienda Chacao', String(hdr));
    await ctx.close();

    // ---- 4. Carrito de otra tienda ----
    for (const [slug, store, product, keeps] of [['producto-a-delicias-y-chacao', 'Delicias', 'Producto A', true], ['producto-b-san-francisco', 'San Francisco', 'Producto B', false]]) {
      const ctx2 = await browser.newContext({ viewport: { width: 1200, height: 800 } });
      await ctx2.addInitScript(stub);
      await ctx2.addCookies([{ name: 'ts_estado', value: '__ALL__', url: BASE }]);
      const q = await ctx2.newPage();
      const count = async () => Number(((await ctx2.cookies()).find(x => x.name === 'woocommerce_items_in_cart') || {}).value || 0);
      await q.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
      const v = await q.$$eval('select.select_location option', (o, s) => (o.find(x => x.textContent.includes(s)) || {}).value, store);
      const done = q.waitForResponse(r => /wcmlim_set_location_on_change/.test(r.request().postData() || ''), { timeout: 8000 }).catch(() => null);
      await q.selectOption('select.select_location', v); await done; await q.waitForLoadState('networkidle');
      await q.waitForFunction(() => { const b = document.querySelector('button.single_add_to_cart_button'); return b && !b.disabled; }, null, { timeout: 10000 }).catch(() => {});
      await q.click('button.single_add_to_cart_button');
      for (let i = 0; i < 40 && !(await count()); i++) await q.waitForTimeout(250);
      await q.goto(url, { waitUntil: 'networkidle' });
      await q.click('.ts-sp__trigger');
      if (await q.$('.ts-sp-state')) {
        const dc2 = await q.$$eval('.ts-sp-state option', o => (o.find(x => /Capital/.test(x.textContent)) || {}).value);
        await q.selectOption('.ts-sp-state', dc2);
      }
      await q.check(`input[name=ts_sp_store][value="${LOC['Tienda Chacao']}"]`);
      await q.click('.ts-sp-modal__save');
      await q.waitForSelector('.ts-sp-modal__conflict:not([hidden])', { timeout: 8000 }).catch(() => {});
      const conflict = await q.$eval('.ts-sp-modal__conflict', e => !e.hidden && e.innerText.replace(/\s+/g, ' ').trim()).catch(() => false);
      const listed = /se quitarán/.test(conflict || '') && conflict.includes(product);
      log(`carrito de ${store}: avisa en la ventana${keeps ? '' : ' y lista ' + product + ' (no hay en Chacao)'}`, new RegExp('Tu carrito tiene productos de Tienda ' + store).test(conflict || '') && /Tienda Chacao/.test(conflict || '') && listed === !keeps, String(conflict));
      if (!keeps) { await q.waitForTimeout(300); await q.screenshot({ path: __dirname + '/logs/selector-carrito.png' }); }
      await Promise.all([q.waitForNavigation({ waitUntil: 'networkidle' }), q.click('.ts-sp-modal__save')]);
      await q.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
      const rows = await q.$$eval('.woocommerce-cart-form__cart-item .product-name', e => e.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
      log(keeps ? 'al confirmar: el producto pasa a Tienda Chacao' : 'al confirmar: se quita el producto que no hay en Tienda Chacao', keeps ? rows.length === 1 && /Tienda: Tienda Chacao/.test(rows[0]) : rows.length === 0, JSON.stringify(rows));
      await ctx2.close();
    }

    // ---- 4b. Móvil: una fila con buscador y selector no se sale de la pantalla ----
    {
      const row = wp(`post create --post_type=page --post_status=publish --post_title="Movil" --post_content='<div class="ts-test-row" style="display:flex;align-items:center;gap:8px"><input type="search" placeholder="¿Que estás buscando?" style="flex:1 1 auto;min-width:0;height:44px">[ts_tienda]</div>' --porcelain`);
      const ctxm = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
      await ctxm.addInitScript(stub);
      await ctxm.addCookies([{ name: 'ts_estado', value: '__ALL__', url: BASE }, { name: 'wcmlim_selected_location_termid', value: String(LOC['Tienda San Francisco']), url: BASE }]);
      const m = await ctxm.newPage();
      await m.goto(`${BASE}/?page_id=${row}`, { waitUntil: 'networkidle' });
      const geo = await m.evaluate(() => {
        const sp = document.querySelector('.ts-test-row .ts-sp').getBoundingClientRect();
        const name = document.querySelector('.ts-test-row .ts-sp__name');
        return { right: Math.round(sp.right), vw: window.innerWidth, scroll: document.documentElement.scrollWidth, cut: name.scrollWidth > name.clientWidth, text: name.textContent };
      });
      log('móvil: el selector cabe en la pantalla (el nombre se corta con "…" si hace falta)', geo.right <= geo.vw && geo.scroll <= geo.vw, JSON.stringify(geo));
      await (await m.$('.ts-test-row')).screenshot({ path: __dirname + '/logs/selector-movil.png' });
      await m.click('.ts-test-row .ts-sp__trigger');
      await m.waitForSelector('#ts-sp-modal:not([hidden])'); await m.waitForTimeout(400);
      const box = await m.$eval('.ts-sp-modal__box', e => { const r = e.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), vw: window.innerWidth, vh: window.innerHeight }; });
      log('móvil: la ventana ocupa el ancho de la pantalla, desde abajo', box.left === 0 && box.right === box.vw && box.bottom === box.vh, JSON.stringify(box));
      await m.screenshot({ path: __dirname + '/logs/selector-movil-ventana.png' });
      await ctxm.close();
      wp(`post delete ${row} --force`);
    }

    // ---- 5. [wcmlim_locations_switch] con el ajuste ----
    setting('store_picker', 'yes');
    const html = await (await fetch(`${BASE}/?page_id=${page2}`)).text();
    const body = html.slice(html.indexOf('<body'));
    log('con el ajuste, [wcmlim_locations_switch] muestra el botón nuevo', /class="ts-sp__trigger"/.test(body) && !/id="lc-switch-form"[\s\S]*<\/main>/.test(body), '');
  } finally {
    await browser.close();
    wp(`option update ts_settings '${saved}' --format=json`);
    wp(`post delete ${page} ${page2} --force`);
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
