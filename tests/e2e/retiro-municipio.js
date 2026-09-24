/**
 * Retiro por municipio, de punta a punta en el navegador.
 *
 * Configuración de partida: Delicias acepta Maracaibo y San Francisco (área metropolitana); la tienda
 * San Francisco, su municipio. Criterio "municipio o radio" y alcance "una tienda" (los de fábrica).
 *
 *   node retiro-municipio.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

// `wp option get/delete` salen con error si la opción no existe: aquí eso no es un fallo.
const wpSoft = a => { try { return wp(a); } catch (e) { return ''; } };
const setting = (key, value) => wp(`eval '$s=(array)get_option("ts_settings"); $s["${key}"]="${value}"; update_option("ts_settings",$s);'`);
const setMunis = map => {
  const f = require('os').tmpdir() + '/ts-munis-' + process.pid + '.json';
  require('fs').writeFileSync(f, JSON.stringify(map));
  wp(`eval 'update_option("ts_pickup_municipios", json_decode(file_get_contents("${f}"), true));'`);
};

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
// Cliente sin GPS que eligió Zulia en la ventana de estado.
async function guestZulia(browser) {
  const ctx = await newCtx(browser, null);
  await ctx.addCookies([{ name: 'ts_estado', value: 'ZU', url: BASE }, { name: 'ts_estado_src', value: 'manual', url: BASE }]);
  return ctx;
}
async function addToCart(page, slug, locMatch) {
  await page.goto(BASE + '/product/' + slug + '/', { waitUntil: 'networkidle' });
  const opt = await page.$$eval('select.select_location option', o => o.map(x => ({ v: x.value, t: x.textContent })));
  const pick = opt.find(x => new RegExp(locMatch).test(x.t));
  if (pick) {
    const done = page.waitForResponse(r => /wcmlim_set_location_on_change/.test(r.request().postData() || ''), { timeout: 10000 }).catch(() => null);
    await page.selectOption('select.select_location', pick.v);
    await done;
    await page.waitForLoadState('networkidle');
    await page.waitForFunction(() => { const b = document.querySelector('button.single_add_to_cart_button'); return b && !b.disabled; }, null, { timeout: 10000 }).catch(() => {});
  }
  const count = async () => Number(((await page.context().cookies()).find(c => c.name === 'woocommerce_items_in_cart') || {}).value || 0);
  const before = await count();
  await page.click('button.single_add_to_cart_button');
  await waitFor(async () => (await count()) > before);
}
// Checkout clásico con el municipio indicado (texto de SMV que contiene `muni`).
async function checkout(page, muni) {
  await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
  await page.fill('#billing_first_name', 'Rosa'); await page.fill('#billing_last_name', 'Díaz');
  await page.fill('#billing_address_1', 'Calle 72');
  await page.selectOption('#billing_state', 'ZU'); await page.waitForTimeout(800);
  await page.fill('#billing_postcode', '4001'); await page.fill('#billing_phone', '04140000000'); await page.fill('#billing_email', 'rosa@example.com');
  await setMuni(page, muni);
}
// Cambia el municipio y espera a que el checkout se recalcule (AJAX update_order_review).
async function setMuni(page, muni) {
  const cities = await page.$$eval('#billing_city option', o => o.map(x => x.value));
  const value = cities.find(v => v.includes(muni));
  const done = page.waitForResponse(r => /update_order_review/.test(r.url()), { timeout: 15000 }).catch(() => null);
  await page.selectOption('#billing_city', value);
  const resp = await done;
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);
  return !!resp;
}
async function shipping(page) {
  return page.$$eval('#shipping_method li label, tr.woocommerce-shipping-totals td', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
}
async function panel(page) {
  const li = await page.$$eval('.ts-distance-list li', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
  const note = await page.$eval('.ts-distance-note', e => e.textContent.trim()).catch(() => '');
  const geo = await page.$('.ts-checkout-geo__btn');
  return { li, note, geo: !!geo };
}
async function placeOrder(page) {
  await page.check('#payment_method_cod').catch(() => {});
  await page.click('#place_order');
  await page.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
  const m = page.url().match(/order-received\/(\d+)/);
  return m ? Number(m[1]) : 0;
}
const orderMeta = id => JSON.parse(wp(`eval '$o=wc_get_order(${id}); echo json_encode(array("muni"=>$o->get_meta("_ts_customer_municipio"),"crit"=>$o->get_meta("_ts_pickup_criterion"),"pk"=>$o->get_meta("_ts_packages"),"ids"=>$o->get_meta("_ts_pickup_location_ids"),"ship"=>array_values(array_map(function($i){return $i->get_method_title();}, $o->get_items("shipping")))));'`).split('\n').pop());

(async () => {
  wp(`eval-file ${__dirname}/reset-stock.php`);
  const saved = { settings: wp('option get ts_settings --format=json'), munis: wpSoft('option get ts_pickup_municipios --format=json') };
  const pagesBefore = /wp:woocommerce\/checkout/.test(wp(`eval 'echo get_post_field("post_content", wc_get_page_id("checkout"));'`)) ? 'blocks' : 'classic';
  wp(`eval-file ${__dirname}/pages.php classic`);
  setting('pickup_criterion', 'both'); setting('pickup_scope', 'one');
  setMunis({ [LOC['Tienda Delicias']]: ['ZU:maracaibo', 'ZU:sanfrancisco'], [LOC['Tienda San Francisco']]: ['ZU:sanfrancisco'] });
  const browser = await chromium.launch();

  try {
    // ---- 1. Sin GPS, municipio con tienda: retiro por municipio ----
    {
      const ctx = await guestZulia(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await checkout(p, 'Maracaibo');
      const sm = await shipping(p); const pn = await panel(p);
      log('sin GPS · municipio Maracaibo → "Retiro en tienda"', sm.some(t => /Retiro en tienda/.test(t)) && !sm.some(t => /Envío nacional/.test(t)), JSON.stringify(sm));
      log('panel: "en tu municipio", sin aviso de posición ni botón GPS (ya tiene retiro)', pn.li.some(t => /Delicias.*en tu municipio.*Retiro en tienda/.test(t)) && pn.note === '' && !pn.geo, JSON.stringify(pn));

      // ---- 2. Cambiar de municipio recalcula el checkout ----
      const r1 = await setMuni(p, 'Cabimas');
      const sm2 = await shipping(p); const pn2 = await panel(p);
      log('cambiar a Cabimas (sin tienda) recalcula → sólo "Envío nacional"', r1 && sm2.some(t => /Envío nacional/.test(t)) && !sm2.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm2));
      log('en Cabimas sin GPS: aviso y botón para compartir la posición', /posición/.test(pn2.note) && pn2.geo, JSON.stringify(pn2));
      const r2 = await setMuni(p, 'San Francisco (San Francisco)');
      const sm3 = await shipping(p);
      log('área metropolitana: San Francisco también retira en Delicias', r2 && sm3.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm3));

      const id = await placeOrder(p);
      const meta = id ? orderMeta(id) : {};
      log('pedido guarda municipio, criterio y motivo del retiro', meta.muni === 'ZU:sanfrancisco' && meta.crit === 'both' && (meta.pk || []).some(x => x.pickup_eligible && x.pickup_reason === 'municipio') && (meta.ship || []).some(t => /Retiro en tienda/.test(t)), JSON.stringify(meta));
      await ctx.close();
    }

    // ---- 2b. Elegir el municipio con la dirección aún incompleta también recalcula ----
    // WooCommerce no recalcula mientras falte un campo de dirección obligatorio; el plugin fuerza el
    // recálculo al elegir el municipio porque con el retiro por municipio éste decide por sí solo.
    {
      const ctx = await guestZulia(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
      await p.selectOption('#billing_state', 'ZU'); await p.waitForTimeout(800);
      const sent = await setMuni(p, 'Maracaibo'); // Sin dirección ni código postal.
      const sm = await shipping(p);
      log('municipio elegido con la dirección incompleta → recalcula y ofrece retiro', sent && sm.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm));
      await ctx.close();
    }

    // ---- 3. Sólo radio: el municipio ya no basta (comportamiento anterior) ----
    setting('pickup_criterion', 'radius');
    {
      const ctx = await guestZulia(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await checkout(p, 'Maracaibo');
      const sm = await shipping(p); const pn = await panel(p);
      log('sólo radio · sin GPS · Maracaibo → "Envío nacional"', sm.some(t => /Envío nacional/.test(t)) && !sm.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm));
      log('sólo radio: botón GPS disponible y aviso de posición', pn.geo && /posición/.test(pn.note), JSON.stringify(pn));
      await ctx.close();
    }

    // ---- 4. Sólo municipio: el GPS no cuenta y el botón desaparece ----
    setting('pickup_criterion', 'municipio');
    {
      const ctx = await newCtx(browser, { latitude: 10.6427, longitude: -71.6125 }); // 3,1 km de Delicias.
      const p = await ctx.newPage();
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      await waitFor(async () => ((await ctx.cookies()).find(c => c.name === 'ts_estado') || {}).value === 'ZU', 10000);
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await checkout(p, 'Cabimas');
      const sm = await shipping(p); const pn = await panel(p);
      log('sólo municipio · GPS a 3 km pero Cabimas → "Envío nacional"', sm.some(t => /Envío nacional/.test(t)) && !sm.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm));
      log('sólo municipio: sin botón GPS ni aviso de posición', !pn.geo && pn.note === '', JSON.stringify(pn));
      await ctx.close();
    }

    // ---- 5. Dos tiendas en el pedido: una sola con retiro, o todas ----
    setting('pickup_criterion', 'both');
    wp('option update wcmlim_clear_cart ""'); wp('option update wcmlim_enable_split_packages on');
    const twoStores = async () => {
      const ctx = await guestZulia(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      const sw = await p.$$eval('#wcmlim-change-lc-select option', o => o.map(x => ({ v: x.value, t: x.textContent.trim() })));
      // El selector de MLI envía su formulario solo al cambiar (si su JS arrancó); si no, se envía a mano.
      const nav = p.waitForNavigation({ waitUntil: 'networkidle', timeout: 8000 }).then(() => true).catch(() => false);
      await p.selectOption('#wcmlim-change-lc-select', sw.find(x => /San Francisco/.test(x.t)).v);
      if (!(await nav)) {
        await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.evaluate(() => document.getElementById('lc-switch-form').submit())]);
      }
      await addToCart(p, 'producto-b-san-francisco', 'San Francisco');
      await checkout(p, 'San Francisco (San Francisco)'); // Lo aceptan las dos tiendas.
      const sm = await shipping(p); const pn = await panel(p);
      await ctx.close();
      return { sm, pn };
    };
    setting('pickup_scope', 'one');
    {
      const { sm, pn } = await twoStores();
      const pickups = pn.li.filter(t => /Retiro en tienda/.test(t));
      // Sin GPS decide la tienda elegida en el selector: San Francisco (la última que se eligió).
      log('una tienda por pedido: retiro sólo en la elegida, envío en la otra', pickups.length === 1 && /San Francisco/.test(pickups[0]) && sm.some(t => /Envío nacional/.test(t)), JSON.stringify(pn.li));
    }
    setting('pickup_scope', 'all');
    {
      const { sm, pn } = await twoStores();
      const pickups = pn.li.filter(t => /Retiro en tienda/.test(t));
      log('todas las que cumplen: retiro en las dos tiendas', pickups.length === 2 && !sm.some(t => /Envío nacional/.test(t)), JSON.stringify(pn.li));
    }
    wp('option update wcmlim_clear_cart on'); wp('option update wcmlim_enable_split_packages ""');
    setting('pickup_scope', 'one');

    // ---- 6. Checkout por bloques ----
    wp(`eval-file ${__dirname}/pages.php blocks`);
    {
      const ctx = await guestZulia(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
      await p.waitForSelector('#email', { timeout: 20000 });
      await p.fill('#email', 'rosa@example.com');
      await p.fill('#shipping-first_name', 'Rosa'); await p.fill('#shipping-last_name', 'Díaz');
      await p.fill('#shipping-address_1', 'Calle 72');
      await p.selectOption('#shipping-state', 'ZU').catch(async () => { await p.fill('#shipping-state', 'ZU'); });
      await p.fill('#shipping-postcode', '4001').catch(() => {});
      await p.fill('#shipping-phone', '04140000000').catch(() => {});
      await p.waitForTimeout(1500);
      const muniSel = '[id^="shipping"][id$="municipio-zu"]';
      await p.waitForSelector(muniSel, { timeout: 15000 });
      const pickBlocks = async (muni) => {
        const val = await p.$$eval(muniSel + ' option', (o, m) => (o.find(x => x.value.includes(m)) || {}).value, muni);
        await p.selectOption(muniSel, val);
        // El checkout por bloques recalcula los envíos por la Store API al cambiar la dirección.
        await p.waitForResponse(r => /wc\/store\/v1\/(batch|cart\/update-customer)/.test(r.url()), { timeout: 15000 }).catch(() => null);
        await p.waitForLoadState('networkidle');
        await p.waitForTimeout(1500);
        return p.$$eval('.wc-block-components-shipping-rates-control label, .wc-block-components-radio-control__option', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
      };
      const a = await pickBlocks('Maracaibo');
      log('bloques · sin GPS · municipio Maracaibo → "Retiro en tienda"', a.some(t => /Retiro en tienda/.test(t)) && !a.some(t => /Envío nacional/.test(t)), JSON.stringify(a));
      const b = await pickBlocks('Cabimas');
      log('bloques · cambiar a Cabimas recalcula → "Envío nacional"', b.some(t => /Envío nacional/.test(t)) && !b.some(t => /Retiro en tienda/.test(t)), JSON.stringify(b));
      await ctx.close();
    }
  } finally {
    wp(`eval-file ${__dirname}/pages.php ${pagesBefore}`);
    wp(`option update ts_settings '${saved.settings}' --format=json`);
    if (saved.munis) wp(`option update ts_pickup_municipios '${saved.munis}' --format=json`); else wpSoft('option delete ts_pickup_municipios');
    wp('option update wcmlim_clear_cart on'); wp('option update wcmlim_enable_split_packages ""');
    await browser.close();
  }

  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
