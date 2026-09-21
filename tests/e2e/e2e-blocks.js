const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const BASE = 'http://127.0.0.1:8080';
const { execSync } = require('child_process');
// IDs de las sedes resueltos por nombre: el seed puede recrearlas con IDs distintos.
const LOC = JSON.parse(execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${__dirname}/wordpress eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);' 2>/dev/null`).toString().trim().split('\n').pop());
const ID = n => String(LOC[n]);

const SHOTS = __dirname + '/shots/';
const results = [];
function log(name, ok, detail) { results.push({ name, ok }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); }
async function cookies(ctx) { const c = await ctx.cookies(BASE); const o = {}; c.forEach(k => o[k.name] = k.value); return o; }
async function addToCart(page, slug, re) {
  await page.goto(BASE + '/product/' + slug + '/', { waitUntil: 'networkidle' });
  const o = await page.$$eval('select.select_location option', o => o.map(x => ({ v: x.value, t: x.textContent })));
  const p = o.find(x => re ? re.test(x.t) : x.v !== '-1');
  if (p) { await page.selectOption('select.select_location', p.v); await page.waitForTimeout(600); }
  await page.click('button.single_add_to_cart_button'); await page.waitForTimeout(2500);
  return o.map(x => x.t.trim());
}
async function fillBlockCheckout(page, state, muniRe) {
  await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
  await page.waitForSelector('#email', { timeout: 20000 });
  await page.fill('#email', 'bloques@example.com');
  await page.fill('#shipping-first_name', 'Ana'); await page.fill('#shipping-last_name', 'Blocks');
  await page.fill('#shipping-address_1', 'Calle 72 con Av. 3H');
  await page.selectOption('#shipping-state', state).catch(async () => { await page.fill('#shipping-state', state); });
  await page.waitForTimeout(1500);
  const muniSel = '[id$="municipio-' + state.toLowerCase() + '"]';
  const hasMuni = await page.$(muniSel);
  let muni = '';
  if (hasMuni) {
    const opts = await page.$$eval(muniSel + ' option', o => o.map(x => x.value));
    muni = opts.find(v => muniRe.test(v)) || opts[1];
    await page.selectOption(muniSel, muni);
  }
  const cityVisible = await page.$eval('#shipping-city', e => !!(e.offsetParent)).catch(() => false);
  await page.fill('#shipping-postcode', '4001').catch(() => {});
  await page.fill('#shipping-phone', '04140000000').catch(() => {});
  await page.waitForTimeout(3000);
  return { muni, hasMuni: !!hasMuni, cityVisible };
}
async function shippingOptions(page) {
  await page.waitForTimeout(1500);
  return page.$$eval('.wc-block-components-shipping-rates-control label, .wc-block-components-radio-control__label', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
}
async function panel(page) {
  const li = await page.$$eval('.ts-blocks-panel .ts-distance-list li', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
  const btn = await page.$eval('.ts-blocks-panel .ts-checkout-geo__btn', e => ({ text: e.textContent.trim(), disabled: e.disabled })).catch(() => null);
  return { li, btn };
}

(async () => {
  const browser = await chromium.launch();

  // ---------- B1: GPS Maracaibo, catálogo Product Collection, checkout por bloques ----------
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, geolocation: { latitude: 10.6427, longitude: -71.6125 }, permissions: ['geolocation'] });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('  [pageerror]', String(e).slice(0, 200)));
    await page.goto(BASE + '/', { waitUntil: 'networkidle' }); await page.waitForTimeout(2500); await page.waitForLoadState('networkidle');
    const ck = await cookies(ctx);
    log('B1 estado ZU por GPS (tema de bloques)', ck.ts_estado === 'ZU' && ck.wcmlim_selected_location_termid === ID('Tienda Delicias'), JSON.stringify({ ts: ck.ts_estado, selid: ck.wcmlim_selected_location_termid }));
    await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    const prods = await page.$$eval('.wp-block-woocommerce-product-collection .wp-block-post-title, .wc-block-product-template .wp-block-post-title', els => els.map(e => e.textContent.trim()));
    log('B1 Product Collection filtrado por sede Delicias', JSON.stringify(prods) === JSON.stringify(['Producto A (Delicias y Chacao)']), JSON.stringify(prods));
    await page.screenshot({ path: SHOTS + 'b1-shop-blocks.png', fullPage: true });
    const opts = await addToCart(page, 'producto-a-delicias-y-chacao', /Delicias/);
    log('B1 producto (tema de bloques) muestra select de sede de MLI sin Chacao', opts.length > 0 && !opts.some(t => /Chacao/.test(t)), JSON.stringify(opts));
    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    const cartRows = await page.$$eval('.wc-block-cart-items__row .wc-block-components-product-name', els => els.map(e => e.textContent.trim()));
    log('B1 carrito por bloques con Producto A', cartRows.length === 1 && /Producto A/.test(cartRows[0]), JSON.stringify(cartRows));
    const f = await fillBlockCheckout(page, 'ZU', /Maracaibo/);
    log('B1 checkout bloques: select de municipio por estado y ciudad libre oculta', f.hasMuni && /Maracaibo/.test(f.muni) && !f.cityVisible, JSON.stringify(f));
    const sm = await shippingOptions(page);
    const pn = await panel(page);
    log('B1 checkout bloques: Retiro en tienda ofrecido (GPS previo)', sm.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm));
    log('B1 panel Total Sucursales en bloques: Delicias 3,1 km pickup, botón "registrada"', pn.li.some(t => /Delicias.*3[,.]1 km.*Retiro/.test(t)) && pn.btn && pn.btn.disabled, JSON.stringify(pn));
    await page.screenshot({ path: SHOTS + 'b1-checkout-blocks.png', fullPage: true });
    await page.click('.wc-block-components-checkout-place-order-button');
    await page.waitForURL(/order-received/, { timeout: 40000 }).catch(() => {});
    const m = page.url().match(/order-received\/(\d+)/);
    log('B1 pedido creado desde checkout por bloques', !!m, page.url());
    await page.screenshot({ path: SHOTS + 'b1-thankyou-blocks.png', fullPage: true });
    if (m) {
        const out = execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${__dirname}/wordpress eval '$o=wc_get_order(${m[1]}); echo json_encode(["city"=>$o->get_shipping_city(),"bcity"=>$o->get_billing_city(),"pickup"=>$o->get_meta("_ts_pickup_location_name"),"src"=>$o->get_meta("_ts_coords_source"),"pk"=>$o->get_meta("_ts_packages")]);' 2>/dev/null`).toString();
      const o = JSON.parse(out.trim().split('\n').pop());
      log('B1 pedido: ciudad = municipio elegido, pickup Delicias, coords gps', /Maracaibo/.test(o.city) && /Maracaibo/.test(o.bcity) && o.pickup === 'Tienda Delicias' && o.src === 'gps', out.trim().slice(-300));
    }
    await ctx.close();
  }

  // ---------- B2: sin GPS al navegar; botón GPS dentro del checkout por bloques ----------
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('  [pageerror]', String(e).slice(0, 200)));
    await page.goto(BASE + '/', { waitUntil: 'networkidle' }); await page.waitForTimeout(1500);
    await page.selectOption('#ts-state-modal .ts-modal__select', 'ZU'); await page.click('#ts-state-modal .ts-modal__ok');
    await page.waitForTimeout(2000); await page.waitForLoadState('networkidle');
    await addToCart(page, 'producto-a-delicias-y-chacao', /Delicias/);
    await fillBlockCheckout(page, 'ZU', /Maracaibo/);
    let sm = await shippingOptions(page); let pn = await panel(page);
    log('B2 sin posición: sólo Envío nacional y botón GPS activo', sm.some(t => /Envío nacional/.test(t)) && !sm.some(t => /Retiro/.test(t)) && pn.btn && !pn.btn.disabled, JSON.stringify({ sm, pn }));
    await page.screenshot({ path: SHOTS + 'b2-checkout-blocks-no-gps.png', fullPage: true });
    await ctx.grantPermissions(['geolocation']); await ctx.setGeolocation({ latitude: 10.6427, longitude: -71.6125 });
    await page.click('.ts-blocks-panel .ts-checkout-geo__btn');
    await page.waitForTimeout(5000);
    sm = await shippingOptions(page); pn = await panel(page);
    log('B2 tras botón GPS (extensionCartUpdate): Retiro en tienda y distancia 3,1 km', sm.some(t => /Retiro en tienda/.test(t)) && pn.li.some(t => /3[,.]1 km/.test(t)), JSON.stringify({ sm, pn }));
    await page.screenshot({ path: SHOTS + 'b2-checkout-blocks-gps.png', fullPage: true });
    await ctx.close();
  }

  await browser.close();
  const fails = results.filter(r => !r.ok).length;
  console.log(`\n${results.length - fails}/${results.length} pruebas OK`);
})().catch(e => { console.error('ERROR', e); process.exit(1); });
