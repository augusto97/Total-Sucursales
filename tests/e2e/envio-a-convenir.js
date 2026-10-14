/**
 * Envío nacional sin costo: "A convenir" en lugar de "Gratis".
 *
 * El costo del envío nacional se acuerda aparte (WhatsApp...), así que a 0 no debe anunciarse como
 * gratis. Con el método propio y el envío nacional a 0 se comprueba el checkout clásico ("Envío
 * nacional: A convenir"), la página de gracias, el checkout por bloques (opción y resumen) y que el
 * retiro en tienda sigue diciendo "Gratis". Deja el envío y el tema como estaban.
 *
 *   node envio-a-convenir.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
async function waitFor(check, ms = 15000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (await check()) return true; await new Promise(r => setTimeout(r, 250)); } return false; }
async function newCtx(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  await ctx.addInitScript(() => { const n = function () {}; window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } }; });
  await ctx.addCookies([{ name: 'ts_estado', value: 'ZU', url: BASE }, { name: 'ts_estado_src', value: 'manual', url: BASE }]);
  return ctx;
}
async function addToCart(page, slug, re) {
  await page.goto(BASE + '/product/' + slug + '/', { waitUntil: 'networkidle' });
  const o = await page.$$eval('select.select_location option', o => o.map(x => ({ v: x.value, t: x.textContent })));
  const pick = o.find(x => re.test(x.t));
  if (pick) {
    const done = page.waitForResponse(r => /wcmlim_set_location_on_change/.test(r.request().postData() || ''), { timeout: 10000 }).catch(() => null);
    await page.selectOption('select.select_location', pick.v); await done; await page.waitForLoadState('networkidle');
  }
  const count = async () => Number(((await page.context().cookies()).find(c => c.name === 'woocommerce_items_in_cart') || {}).value || 0);
  for (let attempt = 0; attempt < 2 && !(await count()); attempt++) {
    await page.waitForFunction(() => { const b = document.querySelector('button.single_add_to_cart_button'); return b && !b.disabled; }, null, { timeout: 10000 }).catch(() => {});
    await page.click('button.single_add_to_cart_button');
    await waitFor(async () => (await count()) > 0, 10000);
  }
}
const FREE = /\b(gratis|free)\b/i;

(async () => {
  const theme = wp('theme list --status=active --field=name');
  wp(`eval-file ${__dirname}/reset-stock.php`);
  wp('plugin deactivate woocommerce-advanced-shipping');
  wp(`eval-file ${__dirname}/envio-modo.php propio national_for_pickup=yes national_cost=0`);
  const browser = await chromium.launch();
  try {
    // ---- Checkout clásico (municipio sin retiro: sólo envío nacional) ----
    {
      const ctx = await newCtx(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', /Delicias/);
      await p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
      await p.fill('#billing_first_name', 'Luis'); await p.fill('#billing_last_name', 'Pérez');
      await p.fill('#billing_address_1', 'Av. 5 de Julio');
      await p.selectOption('#billing_state', 'ZU'); await p.waitForTimeout(800);
      const cities = await p.$$eval('#billing_city option', o => o.map(x => x.value));
      await p.selectOption('#billing_city', cities.find(v => /Cabimas/.test(v)) || cities[1]);
      await p.fill('#billing_postcode', '4001'); await p.fill('#billing_phone', '04140000000'); await p.fill('#billing_email', 'luis@example.com');
      await p.waitForTimeout(2500);
      const sm = await p.$$eval('#shipping_method li label, tr.woocommerce-shipping-totals td', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
      log('checkout clásico: "Envío nacional: A convenir", sin "Gratis"', sm.some(t => /Envío nacional: A convenir/.test(t)) && !sm.some(t => FREE.test(t)), JSON.stringify(sm));
      await p.check('#payment_method_cod').catch(() => {});
      await p.click('#place_order');
      await p.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
      const rows = await p.$$eval('.woocommerce-table--order-details tfoot tr', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
      log('página de gracias: el envío dice "Envío nacional: A convenir"', rows.some(t => /Envío nacional: A convenir/.test(t)) && !rows.some(t => FREE.test(t)), JSON.stringify(rows));
      await ctx.close();
    }

    // ---- Checkout por bloques ----
    wp('theme activate twentytwentyfive');
    wp(`eval-file ${__dirname}/pages.php blocks`);
    for (const [muni, label] of [[/Cabimas/, 'sólo envío nacional'], [/Maracaibo/, 'retiro y envío nacional']]) {
      const ctx = await newCtx(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', /Delicias/);
      await p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
      await p.waitForSelector('#email', { timeout: 20000 });
      await p.fill('#email', 'bloques@example.com');
      await p.fill('#shipping-first_name', 'Ana'); await p.fill('#shipping-last_name', 'Blocks');
      await p.fill('#shipping-address_1', 'Calle 72');
      await p.selectOption('#shipping-state', 'ZU').catch(async () => { await p.fill('#shipping-state', 'ZU'); });
      await p.waitForTimeout(1500);
      const sel = '[id$="municipio-zu"]';
      if (await p.$(sel)) { const v = (await p.$$eval(sel + ' option', o => o.map(x => x.value))).find(x => muni.test(x)); await p.selectOption(sel, v); }
      await p.fill('#shipping-postcode', '4001').catch(() => {}); await p.fill('#shipping-phone', '04140000000').catch(() => {});
      await p.waitForTimeout(3500);
      // Elegir el envío nacional (con retiro disponible, el retiro viene elegido).
      const nat = await p.$$('.wc-block-components-radio-control__option');
      for (const o of nat) { if (/Envío nacional/.test(await o.textContent())) { await o.click(); break; } }
      await p.waitForTimeout(2500);
      const opts = await p.$$eval('.wc-block-components-shipping-rates-control .wc-block-components-radio-control__option', els => els.map(e => ({ label: (e.querySelector('.wc-block-components-radio-control__label') || {}).textContent, price: ((e.querySelector('.wc-block-components-radio-control__secondary-label') || {}).textContent || '').trim() })));
      const total = await p.$eval('.wc-block-components-totals-shipping .wc-block-components-totals-item__value', e => e.textContent.trim()).catch(() => '');
      const national = opts.find(o => /Envío nacional/.test(o.label || '')) || {};
      const pickup = opts.find(o => /Retiro/.test(o.label || ''));
      log(`bloques (${label}): la opción de envío nacional dice "A convenir"`, national.price === 'A convenir', JSON.stringify(opts));
      log(`bloques (${label}): el resumen del pedido dice "A convenir", no "Gratis"`, total === 'A convenir', total);
      if (pickup) log('bloques: el retiro en tienda sigue diciendo "Gratis"', FREE.test(pickup.price), JSON.stringify(pickup));
      if (!pickup) await p.screenshot({ path: __dirname + '/logs/envio-a-convenir-bloques.png', fullPage: false });
      await ctx.close();
    }
  } finally {
    await browser.close();
    wp(`theme activate ${theme || 'twentytwentyone'}`);
    wp(`eval-file ${__dirname}/pages.php ${theme === 'twentytwentyfive' ? 'blocks' : 'classic'}`);
    wp(`eval-file ${__dirname}/envio-modo.php was`);
    wp('plugin activate woocommerce-advanced-shipping');
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
