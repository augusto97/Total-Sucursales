const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const BASE = 'http://127.0.0.1:8080';
const { execSync } = require('child_process');
// IDs de las sedes resueltos por nombre: el seed puede recrearlas con IDs distintos.
const LOC = JSON.parse(execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${__dirname}/wordpress eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);' 2>/dev/null`).toString().trim().split('\n').pop());
const ID = n => String(LOC[n]);
// Repone el stock por sucursal: cada corrida crea pedidos y lo consume.
execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${__dirname}/wordpress eval-file ${__dirname}/reset-stock.php 2>/dev/null`);

const SHOTS = __dirname + '/shots/';
const results = [];
function log(name, ok, detail) { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); }

async function ctxWithGeo(browser, geo, grant = true) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'es-VE', geolocation: geo || undefined, permissions: grant && geo ? ['geolocation'] : [] });
  ctx.on('console', m => { if (m.type() === 'error') console.log('  [console.error]', m.text().slice(0, 200)); });
  return ctx;
}
async function cookies(ctx) { const c = await ctx.cookies(BASE); const o = {}; c.forEach(k => o[k.name] = k.value); return o; }
async function switcherLocations(page) {
  // MLI switcher: intentamos varios selectores conocidos
  await page.waitForTimeout(1200);
  const texts = await page.$$eval('#wcmlim-change-lc-select option', els => els.map(e => e.textContent.trim()).filter(Boolean));
  return texts;
}
async function shopProducts(page) {
  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  return page.$$eval('ul.products li.product .woocommerce-loop-product__title, ul.products li.product h2', els => els.map(e => e.textContent.trim()));
}
async function fillCheckout(page, state = 'ZU', cityContains = 'Maracaibo') {
  await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
  await page.fill('#billing_first_name', 'Juan');
  await page.fill('#billing_last_name', 'Pérez');
  await page.fill('#billing_address_1', 'Av. 5 de Julio, Edif. Test');
  await page.selectOption('#billing_state', state);
  await page.waitForTimeout(800);
  const opts = await page.$$eval('#billing_city option', o => o.map(x => x.value));
  const city = opts.find(v => v.includes(cityContains)) || opts[1];
  await page.selectOption('#billing_city', city);
  await page.fill('#billing_postcode', '4001');
  await page.fill('#billing_phone', '04140000000');
  await page.fill('#billing_email', 'juan@example.com');
  await page.waitForTimeout(1500);
  await page.waitForSelector('#order_review', { state: 'visible' });
  return city;
}
async function addToCart(page, slug, locMatch) {
  await page.goto(BASE + '/product/' + slug + '/', { waitUntil: 'networkidle' });
  const opt = await page.$$eval('select.select_location option', o => o.map(x => ({ v: x.value, t: x.textContent })));
  const pick = opt.find(x => locMatch ? new RegExp(locMatch).test(x.t) : x.v !== '-1');
  if (pick) { await page.selectOption('select.select_location', pick.v); await page.waitForTimeout(800); }
  await page.click('button.single_add_to_cart_button');
  await page.waitForTimeout(2500);
  return opt.map(x => x.t.trim());
}
async function shippingMethods(page) {
  await page.waitForTimeout(1500);
  const labels = await page.$$eval('#shipping_method li label, tr.woocommerce-shipping-totals td label, tr.woocommerce-shipping-totals td', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
  return labels;
}
async function distanceInfo(page) {
  const li = await page.$$eval('.ts-distance-list li', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
  const note = await page.$eval('.ts-distance-note', e => e.textContent.trim()).catch(() => '');
  return { li, note };
}

(async () => {
  const browser = await chromium.launch();

  // ---------- Escenario 1: GPS en Maracaibo (Zulia) ----------
  {
    const ctx = await ctxWithGeo(browser, { latitude: 10.6427, longitude: -71.6125 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500); // AJAX ts_set_position + reload
    await page.waitForLoadState('networkidle');
    const ck = await cookies(ctx);
    log('E1 cookie ts_estado=ZU por GPS', ck.ts_estado === 'ZU', JSON.stringify({ ts_estado: ck.ts_estado, src: ck.ts_estado_src, sel: ck.wcmlim_selected_location, selid: ck.wcmlim_selected_location_termid, lat: ck.wcmlim_user_lat }));
    log('E1 sucursal seleccionada = Delicias', ['29'].includes(ck.wcmlim_selected_location_termid), ck.wcmlim_selected_location_termid);
    const modalVisible = await page.$eval('#ts-state-modal', e => !e.hidden).catch(() => false);
    log('E1 modal no visible', !modalVisible);
    const sel = await page.$eval('#ts-state-select', e => e.value).catch(() => 'n/a');
    log('E1 selector de estado muestra ZU', sel === 'ZU', sel);
    const sw = await switcherLocations(page);
    log('E1 switcher sólo sedes de Zulia', sw.length > 0 && sw.every(t => /Delicias|San Francisco|Select|Selecc|Location|Ubic/i.test(t)) && !sw.some(t => /Chacao|Valencia/.test(t)), JSON.stringify(sw));
    await page.screenshot({ path: SHOTS + 'e1-home.png', fullPage: true });
    const prods = await shopProducts(page);
    log('E1 tienda muestra sólo productos con stock en Delicias', JSON.stringify(prods) === JSON.stringify(['Producto A (Delicias y Chacao)']), JSON.stringify(prods));
    await page.screenshot({ path: SHOTS + 'e1-shop.png', fullPage: true });
    // Producto A
    await page.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
    await page.screenshot({ path: SHOTS + 'e1-product.png', fullPage: true });
    const opts = await page.$$eval('select.select_location option', o => o.map(x => x.textContent.trim()));
    log('E1 producto: select de sedes sin Chacao', opts.length > 0 && !opts.some(t => /Chacao/.test(t)), JSON.stringify(opts));
    // add to cart (seleccionar Delicias si hace falta)
    const delOpt = await page.$$eval('select.select_location option', o => o.map(x => ({ v: x.value, t: x.textContent })).find(x => /Delicias/.test(x.t)));
    if (delOpt) { await page.selectOption('select.select_location', delOpt.v); await page.waitForTimeout(800); }
    await page.click('button.single_add_to_cart_button');
    await page.waitForTimeout(2500);
    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    const cartItems = await page.$$eval('.woocommerce-cart-form__cart-item .product-name', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
    log('E1 carrito con Producto A y sede', cartItems.length === 1 && /Producto A/.test(cartItems[0]), JSON.stringify(cartItems));
    await page.screenshot({ path: SHOTS + 'e1-cart.png', fullPage: true });
    // Checkout
    const city = await fillCheckout(page, 'ZU', 'Maracaibo');
    let sm = await shippingMethods(page);
    log('E1 checkout: municipio de SMV seleccionado', /Maracaibo/.test(city), city);
    await page.screenshot({ path: SHOTS + 'e1-checkout-before-gps.png', fullPage: true });
    // sin geocoder: sin posición → envío nacional (pero las cookies GPS ya existen por la navegación → pickup)
    log('E1 checkout con GPS previo: Retiro en tienda ofrecido', sm.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm));
    const di = await distanceInfo(page);
    log('E1 info de distancia: Delicias ~3 km PICKUP', di.li.some(t => /Delicias/.test(t) && /3[,.]\d km/.test(t) && /Retiro/i.test(t)), JSON.stringify(di));
    await page.screenshot({ path: SHOTS + 'e1-checkout.png', fullPage: true });
    // Realizar pedido
    await page.check('#payment_method_cod').catch(() => {});
    await page.click('#place_order');
    await page.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
    const url = page.url();
    log('E1 pedido creado', /order-received/.test(url), url);
    await page.screenshot({ path: SHOTS + 'e1-thankyou.png', fullPage: true });
    const m = url.match(/order-received\/(\d+)/);
    if (m) { require('fs').writeFileSync(__dirname + '/last_order.txt', m[1]); }
    await ctx.close();
  }

  // ---------- Escenario 2: GPS en Caracas (Distrito Capital) ----------
  {
    const ctx = await ctxWithGeo(browser, { latitude: 10.5000, longitude: -66.9100 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500); await page.waitForLoadState('networkidle');
    const ck = await cookies(ctx);
    log('E2 cookie ts_estado=DC por GPS', ck.ts_estado === 'DC', JSON.stringify({ ts_estado: ck.ts_estado, selid: ck.wcmlim_selected_location_termid }));
    const prods = await shopProducts(page);
    log('E2 tienda muestra productos de Chacao (A y C)', prods.length === 2 && prods.every(p => /Producto A|Producto C/.test(p)), JSON.stringify(prods));
    await page.screenshot({ path: SHOTS + 'e2-shop.png', fullPage: true });
    await ctx.close();
  }

  // ---------- Escenario 3: GPS lejos de toda sede (Mérida) → modal ----------
  {
    const ctx = await ctxWithGeo(browser, { latitude: 8.5897, longitude: -71.1561 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    const ck = await cookies(ctx);
    const modalVisible = await page.$eval('#ts-state-modal', e => !e.hidden).catch(() => false);
    log('E3 lejos de sedes: sin estado y modal visible', !ck.ts_estado && modalVisible, JSON.stringify({ ts_estado: ck.ts_estado, modalVisible }));
    await page.screenshot({ path: SHOTS + 'e3-modal.png', fullPage: true });
    // elegir "Otro estado / todas"
    await page.selectOption('#ts-state-modal .ts-modal__select', '__all__');
    await page.click('#ts-state-modal .ts-modal__ok');
    await page.waitForTimeout(2000); await page.waitForLoadState('networkidle');
    const ck2 = await cookies(ctx);
    const sw = await switcherLocations(page);
    log('E3 "todas": cookie __ALL__ y switcher con las 4 sedes', ck2.ts_estado === '__ALL__' && /Chacao/.test(sw.join('|')) && /Delicias/.test(sw.join('|')), JSON.stringify({ ts: ck2.ts_estado, sw }));
    await page.screenshot({ path: SHOTS + 'e3-all.png', fullPage: true });
    await ctx.close();
  }

  // ---------- Escenario 4: permiso denegado → modal → elegir Carabobo ----------
  {
    const ctx = await ctxWithGeo(browser, null, false);
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const modalVisible = await page.$eval('#ts-state-modal', e => !e.hidden).catch(() => false);
    log('E4 sin GPS: modal visible', modalVisible);
    await page.selectOption('#ts-state-modal .ts-modal__select', 'CA');
    await page.click('#ts-state-modal .ts-modal__ok');
    await page.waitForTimeout(2000); await page.waitForLoadState('networkidle');
    const ck = await cookies(ctx);
    const prods = await shopProducts(page);
    log('E4 Carabobo manual: cookie CA, sede Valencia, tienda con Producto D', ck.ts_estado === 'CA' && ck.wcmlim_selected_location_termid === ID('Tienda Valencia') && JSON.stringify(prods) === JSON.stringify(['Producto D (Valencia)']), JSON.stringify({ ck: ck.ts_estado, selid: ck.wcmlim_selected_location_termid, prods }));
    // cambiar estado por el selector del header a Zulia
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.selectOption('#ts-state-select', 'ZU');
    await page.waitForTimeout(2000); await page.waitForLoadState('networkidle');
    const ck2 = await cookies(ctx);
    log('E4 cambio a Zulia por selector: sede reasignada a una de Zulia', ck2.ts_estado === 'ZU' && [ID('Tienda Delicias'), ID('Tienda San Francisco')].includes(ck2.wcmlim_selected_location_termid), JSON.stringify({ ck: ck2.ts_estado, sel: ck2.wcmlim_selected_location, selid: ck2.wcmlim_selected_location_termid }));
    // checkout sin GPS y sin geocoder → envío nacional
    await addToCart(page, 'producto-a-delicias-y-chacao');
    await fillCheckout(page, 'ZU', 'Maracaibo');
    const sm = await shippingMethods(page);
    log('E4 checkout sin posición: sólo Envío nacional', sm.some(t => /Envío nacional/.test(t)) && !sm.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm));
    const di = await distanceInfo(page);
    log('E4 nota "no conocemos tu posición"', /posición/.test(di.note), JSON.stringify(di));
    await page.screenshot({ path: SHOTS + 'e4-checkout-no-gps.png', fullPage: true });
    // ahora dar permiso GPS (Maracaibo) y usar el botón del checkout
    await ctx.grantPermissions(['geolocation']); await ctx.setGeolocation({ latitude: 10.6427, longitude: -71.6125 });
    await page.click('.ts-checkout-geo__btn');
    await page.waitForTimeout(3500);
    const sm2 = await shippingMethods(page);
    log('E4 botón GPS en checkout: aparece Retiro en tienda', sm2.some(t => /Retiro en tienda/.test(t)), JSON.stringify(sm2));
    await page.screenshot({ path: SHOTS + 'e4-checkout-gps.png', fullPage: true });
    await ctx.close();
  }

  // ---------- Escenario 5: variante B (split de paquetes, dos sedes) ----------
  {
    const wp = (cmd) => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${__dirname}/wordpress ${cmd} 2>/dev/null`).toString();
    wp('option update wcmlim_clear_cart ""'); wp('option update wcmlim_enable_split_packages on');
    const ctx = await ctxWithGeo(browser, { latitude: 10.6427, longitude: -71.6125 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' }); await page.waitForTimeout(2500); await page.waitForLoadState('networkidle');
    // "todas las sedes" para poder comprar en Chacao también
    await page.selectOption('#ts-state-select', ''); await page.waitForTimeout(2000); await page.waitForLoadState('networkidle');
    const optsA = await addToCart(page, 'producto-a-delicias-y-chacao', 'Delicias');
    // Cambiar la sede activa de MLI a Chacao con su propio switcher (formulario POST)
    await page.goto(BASE + '/', { waitUntil: 'networkidle' }); await page.waitForTimeout(1000);
    const sw = await page.$$eval('#wcmlim-change-lc-select option', o => o.map(x => ({ v: x.value, t: x.textContent.trim() })));
    await page.selectOption('#wcmlim-change-lc-select', sw.find(x => /Chacao/.test(x.t)).v);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.evaluate(() => document.getElementById('lc-switch-form').submit())]);
    const ckSw = await cookies(ctx);
    log('E5 switcher MLI: sede activa Chacao', ckSw.wcmlim_selected_location_termid === ID('Tienda Chacao'), JSON.stringify({ sel: ckSw.wcmlim_selected_location, selid: ckSw.wcmlim_selected_location_termid }));
    const optsC = await addToCart(page, 'producto-c-chacao', 'Chacao');
    log('E5 producto A lista todas las sedes con stock', optsA.some(t => /Chacao/.test(t)) && optsA.some(t => /Delicias/.test(t)), JSON.stringify(optsA));
    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    const cartItems = await page.$$eval('.woocommerce-cart-form__cart-item .product-name', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
    log('E5 carrito con A (Delicias) y C (Chacao)', cartItems.length === 2, JSON.stringify(cartItems));
    await fillCheckout(page, 'ZU', 'Maracaibo');
    await page.waitForTimeout(1500);
    const pkgTitles = await page.$$eval('#order_review tr.woocommerce-shipping-totals th, #order_review tr.shipping th', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
    const sm = await shippingMethods(page);
    const di = await distanceInfo(page);
    log('E5 dos paquetes: Delicias→Retiro en tienda, Chacao→Envío nacional', /Delicias/.test(pkgTitles.join('|')) && /Chacao/.test(pkgTitles.join('|')) && sm.some(t => /Retiro en tienda/.test(t)) && sm.some(t => /Envío nacional/.test(t)), JSON.stringify({ pkgTitles, sm }));
    log('E5 info de distancias: Delicias pickup, Chacao nacional (~500 km)', di.li.some(t => /Delicias.*Retiro/.test(t)) && di.li.some(t => /Chacao.*5\d\d[,.]\d km.*nacional/i.test(t)), JSON.stringify(di));
    await page.screenshot({ path: SHOTS + 'e5-checkout-split.png', fullPage: true });
    await page.check('#payment_method_cod').catch(() => {});
    await page.click('#place_order');
    await page.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
    const m = page.url().match(/order-received\/(\d+)/);
    log('E5 pedido creado con dos envíos', !!m, page.url());
    await page.screenshot({ path: SHOTS + 'e5-thankyou.png', fullPage: true });
    await ctx.close();
    wp('option update wcmlim_clear_cart on'); wp('option update wcmlim_enable_split_packages ""');

    // ---------- Admin: pedido y regla de Advanced Shipping ----------
    const actx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    const ap = await actx.newPage();
    await ap.goto(BASE + '/wp-login.php');
    await ap.fill('#user_login', 'admin'); await ap.fill('#user_pass', 'admin'); await ap.click('#wp-submit');
    await ap.waitForLoadState('networkidle');
    if (m) {
      await ap.goto(BASE + '/wp-admin/admin.php?page=wc-orders&action=edit&id=' + m[1], { waitUntil: 'networkidle' }).catch(() => {});
      if (!(await ap.$('.ts-order-meta'))) { await ap.goto(BASE + '/wp-admin/post.php?post=' + m[1] + '&action=edit', { waitUntil: 'networkidle' }); }
      const meta = await ap.$eval('.ts-order-meta', e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
      log('Admin pedido: panel Total Sucursales con PICKUP en Delicias', /Delicias.*PICKUP/.test(meta) && /Chacao.*nacional/i.test(meta), meta.slice(0, 200));
      await ap.screenshot({ path: SHOTS + 'admin-order.png', fullPage: true });
    }
    await ap.goto(BASE + '/wp-admin/admin.php?page=wc-settings&tab=shipping&zone_id=1', { waitUntil: 'networkidle' });
    await ap.screenshot({ path: SHOTS + 'admin-zone.png', fullPage: true });
    await ap.goto(BASE + '/wp-admin/admin.php?page=wc-settings&tab=shipping&instance_id=1', { waitUntil: 'networkidle' });
    await ap.waitForTimeout(1500);
    const condOpts = await ap.$$eval('select.wpc-condition option', o => o.map(x => x.textContent.trim()));
    const condVal = await ap.$eval('select.wpc-condition', e => e.value).catch(() => '');
    const valSel = await ap.$eval('select.wpc-value', e => e.options[e.selectedIndex] && e.options[e.selectedIndex].textContent.trim()).catch(() => '');
    log('Admin WAS: condiciones Total Sucursales en el dropdown y regla guardada', condOpts.some(t => /elegible para pickup/.test(t)) && condVal === 'ts_sede_pickup' && /Sí/.test(valSel), JSON.stringify({ condVal, valSel, has: condOpts.filter(t => /Sucursal|Distancia/.test(t)) }));
    await ap.screenshot({ path: SHOTS + 'admin-was-rule.png', fullPage: true });
    await ap.goto(BASE + '/wp-admin/admin.php?page=wc-settings&tab=total_sucursales', { waitUntil: 'networkidle' });
    await ap.screenshot({ path: SHOTS + 'admin-settings.png', fullPage: true });
    await actx.close();
  }

  await browser.close();
  const fails = results.filter(r => !r.ok).length;
  console.log(`\n${results.length - fails}/${results.length} pruebas OK`);
})().catch(e => { console.error('ERROR', e); process.exit(1); });
