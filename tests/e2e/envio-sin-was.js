/**
 * Envío sin Advanced Shipping: el método propio "Total Sucursales" en la zona decide entre retiro en
 * tienda y envío nacional.
 *
 * Desactiva Advanced Shipping, pone el método propio en la zona (retiro 0, envío nacional 5), recorre
 * los casos y lo deja todo como estaba.
 *
 *   node envio-sin-was.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());

const MARACAIBO = { latitude: 10.6427, longitude: -71.6125 };
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
async function home(page, withGps) {
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  if (withGps) {
    await waitFor(async () => ((await page.context().cookies()).find(c => c.name === 'ts_estado') || {}).value === 'ZU', 10000);
    await page.waitForLoadState('networkidle');
  }
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
async function fillCheckout(page) {
  await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
  await page.fill('#billing_first_name', 'Luis'); await page.fill('#billing_last_name', 'Pérez');
  await page.fill('#billing_address_1', 'Av. 5 de Julio');
  await page.selectOption('#billing_state', 'ZU'); await page.waitForTimeout(800);
  const cities = await page.$$eval('#billing_city option', o => o.map(x => x.value));
  await page.selectOption('#billing_city', cities.find(v => /Maracaibo/.test(v)) || cities[1]);
  await page.fill('#billing_postcode', '4001'); await page.fill('#billing_phone', '04140000000'); await page.fill('#billing_email', 'luis@example.com');
  await page.waitForTimeout(1500);
  await page.waitForSelector('#order_review', { state: 'visible' });
}
async function shipping(page) {
  await page.waitForTimeout(1200);
  return page.$$eval('#shipping_method li label, tr.woocommerce-shipping-totals td', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
}
async function placeOrder(page) {
  await page.check('#payment_method_cod').catch(() => {});
  await page.click('#place_order');
  await page.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
  const m = page.url().match(/order-received\/(\d+)/);
  return m ? Number(m[1]) : 0;
}
const orderShipping = id => JSON.parse(wp(`eval '$o=wc_get_order(${id}); $out=array(); foreach($o->get_items("shipping") as $i){ $out[]=array("method"=>$i->get_method_id(),"title"=>$i->get_method_title(),"total"=>(float)$i->get_total(),"mode"=>$i->get_meta("_ts_mode")); } echo json_encode($out);'`).split('\n').pop());

(async () => {
  // ---- Preparación: sin Advanced Shipping, método propio en la zona ----
  wp(`eval-file ${__dirname}/reset-stock.php`);
  wp('plugin deactivate woocommerce-advanced-shipping');
  wp(`eval-file ${__dirname}/envio-modo.php propio`);
  const browser = await chromium.launch();

  try {
    // ---- 1. Admin: sin errores y sin pedir Advanced Shipping ----
    {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const p = await ctx.newPage();
      await p.goto(BASE + '/wp-login.php');
      await p.fill('#user_login', 'admin'); await p.fill('#user_pass', 'admin'); await p.click('#wp-submit');
      await p.waitForLoadState('networkidle');
      await p.goto(BASE + '/wp-admin/admin.php?page=wc-settings&tab=total_sucursales', { waitUntil: 'networkidle' });
      const body = await p.textContent('body');
      log('ajustes cargan sin Advanced Shipping y el estado reconoce el método propio', !/critical error|Fatal error/i.test(body) && /Decide entre retiro y envío nacional sin Advanced Shipping/.test(body), '');
      const notices = await p.$$eval('.notice', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
      log('ya no se avisa de que falta Advanced Shipping', !notices.some(t => /Advanced Shipping/.test(t) && /faltan plugins/i.test(t)) && !notices.some(t => /añade el método de envío/i.test(t)), JSON.stringify(notices.filter(t => /Total Sucursales/.test(t))));
      await p.goto(BASE + '/wp-admin/admin.php?page=wc-settings&tab=shipping', { waitUntil: 'networkidle' });
      const zones = await p.textContent('body');
      log('la zona lista el método «Total Sucursales»', /Total Sucursales: retiro o envío nacional/.test(zones), '');

      // Sin el método en ninguna zona, el aviso sí aparece.
      wp(`eval-file ${__dirname}/envio-modo.php was`);
      await p.goto(BASE + '/wp-admin/admin.php?page=wc-settings&tab=total_sucursales', { waitUntil: 'networkidle' });
      const notices2 = await p.$$eval('.notice', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
      log('sin método ni Advanced Shipping: se avisa de añadir el método', notices2.some(t => /añade el método de envío/i.test(t)), '');
      wp(`eval-file ${__dirname}/envio-modo.php propio`);
      await ctx.close();
    }

    // ---- 2. GPS en Maracaibo, Delicias a 3 km: sólo retiro ----
    {
      const ctx = await newCtx(browser, MARACAIBO);
      const p = await ctx.newPage();
      await home(p, true);
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await fillCheckout(p);
      const sm = await shipping(p);
      log('dentro del radio: "Retiro en tienda" y nada más', sm.some(t => /Retiro en tienda/.test(t)) && !sm.some(t => /Envío nacional/.test(t)), JSON.stringify(sm));
      const id = await placeOrder(p);
      const lines = id ? orderShipping(id) : [];
      log('pedido: línea de envío del método propio en modo retiro', lines.length === 1 && lines[0].method === 'total_sucursales' && lines[0].mode === 'pickup' && lines[0].total === 0, JSON.stringify({ id, lines }));
      await ctx.close();
    }

    // ---- 3. Sin posición: envío nacional con su costo ----
    {
      const ctx = await newCtx(browser, null);
      await ctx.addCookies([{ name: 'ts_estado', value: 'ZU', url: BASE }, { name: 'ts_estado_src', value: 'manual', url: BASE }]);
      const p = await ctx.newPage();
      await home(p, false);
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await fillCheckout(p);
      const sm = await shipping(p);
      log('sin posición: sólo "Envío nacional" a $5', sm.some(t => /Envío nacional.*5[.,]00/.test(t)) && !sm.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm));
      await ctx.close();
    }

    // ---- 4. Dos tiendas en el mismo pedido: retiro en una, envío en la otra ----
    wp('option update wcmlim_clear_cart ""'); wp('option update wcmlim_enable_split_packages on');
    {
      const ctx = await newCtx(browser, MARACAIBO);
      const p = await ctx.newPage();
      await home(p, true);
      await p.selectOption('#ts-state-select', ''); await p.waitForTimeout(2000); await p.waitForLoadState('networkidle');
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      const sw = await p.$$eval('#wcmlim-change-lc-select option', o => o.map(x => ({ v: x.value, t: x.textContent.trim() })));
      await p.selectOption('#wcmlim-change-lc-select', sw.find(x => /Chacao/.test(x.t)).v);
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.evaluate(() => document.getElementById('lc-switch-form').submit())]);
      await addToCart(p, 'producto-c-chacao', 'Chacao');
      await fillCheckout(p);
      const sm = await shipping(p);
      log('dos paquetes: Delicias con retiro, Chacao con envío nacional', sm.some(t => /Retiro en tienda/.test(t)) && sm.some(t => /Envío nacional.*5[.,]00/.test(t)), JSON.stringify(sm));
      const id = await placeOrder(p);
      const lines = id ? orderShipping(id) : [];
      log('pedido con dos líneas de envío (retiro 0 + nacional 5)', lines.length === 2 && lines.some(l => l.mode === 'pickup' && l.total === 0) && lines.some(l => l.mode === 'national' && l.total === 5), JSON.stringify({ id, lines }));
      await ctx.close();
    }
    wp('option update wcmlim_clear_cart on'); wp('option update wcmlim_enable_split_packages ""');

    // ---- 5. Opción de ofrecer también el envío donde se puede retirar ----
    wp(`eval-file ${__dirname}/envio-modo.php propio national_for_pickup=yes`);
    {
      const ctx = await newCtx(browser, MARACAIBO);
      const p = await ctx.newPage();
      await home(p, true);
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await fillCheckout(p);
      const sm = await shipping(p);
      log('con "envío también donde se puede retirar": elige entre los dos', sm.some(t => /Retiro en tienda/.test(t)) && sm.some(t => /Envío nacional/.test(t)), JSON.stringify(sm));
      await ctx.close();
    }
    wp(`eval-file ${__dirname}/envio-modo.php propio`);

    // ---- 6. Checkout por bloques ----
    const pagesBefore = /wp:woocommerce\/checkout/.test(wp(`eval 'echo get_post_field("post_content", wc_get_page_id("checkout"));'`)) ? 'blocks' : 'classic';
    wp(`eval-file ${__dirname}/pages.php blocks`);
    {
      const ctx = await newCtx(browser, MARACAIBO);
      const p = await ctx.newPage();
      await home(p, true);
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
      await p.waitForSelector('.wc-block-components-shipping-rates-control, .wc-block-checkout__shipping-option', { timeout: 20000 }).catch(() => {});
      await p.waitForTimeout(1500);
      const rates = await p.$$eval('.wc-block-components-shipping-rates-control label, .wc-block-components-radio-control__option', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
      log('checkout por bloques: "Retiro en tienda" sin Advanced Shipping', rates.some(t => /Retiro en tienda/.test(t)) && !rates.some(t => /Envío nacional/.test(t)), JSON.stringify(rates));
      await ctx.close();
    }
    wp(`eval-file ${__dirname}/pages.php ${pagesBefore}`);
  } finally {
    // ---- Dejarlo todo como estaba ----
    wp(`eval-file ${__dirname}/envio-modo.php was`);
    wp('plugin activate woocommerce-advanced-shipping');
    wp('option update wcmlim_clear_cart on'); wp('option update wcmlim_enable_split_packages ""');
    await browser.close();
  }

  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
