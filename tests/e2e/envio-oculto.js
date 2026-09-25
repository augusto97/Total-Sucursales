/**
 * "Ocultar las opciones de envío" salvo en las tiendas autorizadas.
 *
 * Con el ajuste activado y sin la tienda del carrito entre las autorizadas, el cliente no ve las
 * opciones de envío (carrito y checkout, clásico y por bloques), pero el pedido sigue recibiendo
 * "Retiro en tienda" si puede retirar (elegido solo) o "Envío nacional". En las tiendas autorizadas se
 * ve todo como siempre, y el retiro queda elegido por defecto cuando aparece.
 *
 *   node envio-oculto.js     # con el servidor en 127.0.0.1:8080
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

const setArr = (key, arr) => wp(`eval '$s=(array)get_option("ts_settings"); $s["${key}"]=${JSON.stringify(arr).replace(/\[/g, 'array(').replace(/\]/g, ')')}; update_option("ts_settings",$s);'`);
// Filas de envío del resumen del checkout / carrito clásico: texto (aunque estén ocultas) y si se ven.
const shipRows = p => p.$$eval('tr.woocommerce-shipping-totals, tr.ts-distance-info, tr.ts-cart-pickup-note', els => els.map(e => ({ cls: e.className, text: e.textContent.replace(/\s+/g, ' ').trim(), visible: e.offsetParent !== null })));
const chosenText = p => p.evaluate(() => {
  const c = document.querySelector('#shipping_method input:checked') || document.querySelector('#shipping_method input[type=hidden]');
  if (!c) return '';
  const l = document.querySelector('label[for="' + c.id + '"]');
  return (l ? l.textContent : '').replace(/\s+/g, ' ').trim();
});

// Tras comprar: filas de totales de la página de gracias, recuadro "Dónde retirar" y si el correo lleva envío.
async function thankyouAndMail(p, id) {
  const rows = await p.$$eval('.woocommerce-table--order-details tfoot th', els => els.map(e => e.textContent.trim())).catch(() => []);
  const where = await p.$eval('.ts-pickup-where', e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
  const mail = id ? wp(`eval '$e=WC()->mailer()->emails["WC_Email_Customer_Processing_Order"]; $e->object=wc_get_order(${id}); $h=wp_strip_all_tags($e->get_content_html()); echo (false!==stripos($h,"Shipping:")||false!==stripos($h,"Envío:"))?"con-envio":"sin-envio";'`).split('\n').pop() : '';
  return { rows, where, mail };
}

(async () => {
  wp(`eval-file ${__dirname}/reset-stock.php`);
  const saved = { settings: wp('option get ts_settings --format=json'), munis: wpSoft('option get ts_pickup_municipios --format=json') };
  const pagesBefore = /wp:woocommerce\/checkout/.test(wp(`eval 'echo get_post_field("post_content", wc_get_page_id("checkout"));'`)) ? 'blocks' : 'classic';
  wp(`eval-file ${__dirname}/pages.php classic`);
  setting('pickup_criterion', 'both'); setting('pickup_scope', 'one');
  setMunis({ [LOC['Tienda Delicias']]: ['ZU:maracaibo', 'ZU:sanfrancisco'] });
  setting('hide_shipping_ui', 'yes'); setArr('shipping_visible_locations', []);
  const browser = await chromium.launch();

  try {
    // ---- 1. Tienda no autorizada: nada de envío a la vista; por debajo, retiro o envío nacional ----
    {
      const ctx = await guestZulia(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
      const cart = await shipRows(p);
      log('carrito: la fila de envío (y el aviso de municipio) no se ven', cart.length > 0 && cart.every(r => !r.visible), JSON.stringify(cart));

      await checkout(p, 'Maracaibo');
      let rows = await shipRows(p);
      let chosen = await chosenText(p);
      log('checkout · Maracaibo: opciones de envío ocultas y por debajo "Retiro en tienda"', rows.length > 0 && rows.every(r => !r.visible) && /Retiro en tienda/.test(chosen) && !rows.some(r => /Envío nacional/.test(r.text) && /woocommerce-shipping-totals/.test(r.cls)), JSON.stringify({ rows, chosen }));

      await setMuni(p, 'Cabimas');
      rows = await shipRows(p); chosen = await chosenText(p);
      log('checkout · Cabimas: ocultas y por debajo "Envío nacional"', rows.every(r => !r.visible) && /Envío nacional/.test(chosen), JSON.stringify({ rows, chosen }));

      await setMuni(p, 'Maracaibo');
      chosen = await chosenText(p);
      log('volver a Maracaibo: vuelve el retiro, elegido solo', /Retiro en tienda/.test(chosen), chosen);
      const id = await placeOrder(p);
      const meta = id ? orderMeta(id) : {};
      log('pedido con el envío oculto: por debajo queda "Retiro en tienda"', (meta.ship || []).some(t => /Retiro en tienda/.test(t)), JSON.stringify({ ship: meta.ship }));
      const after = await thankyouAndMail(p, id);
      log('página de gracias y correo: tampoco muestran el envío (ni "Dónde retirar")', after.rows.length > 0 && !after.rows.some(t => /Shipping|Envío/i.test(t)) && !after.where && after.mail === 'sin-envio', JSON.stringify(after));
      await ctx.close();
    }

    // ---- 2. Tienda autorizada: todo a la vista, como siempre ----
    setArr('shipping_visible_locations', [LOC['Tienda Delicias']]);
    {
      const ctx = await guestZulia(browser);
      const p = await ctx.newPage();
      await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
      await checkout(p, 'Maracaibo');
      const rows = await shipRows(p);
      log('tienda autorizada: las opciones de envío se ven', rows.some(r => /woocommerce-shipping-totals/.test(r.cls) && r.visible && /Retiro en tienda/.test(r.text)), JSON.stringify(rows));
      const id = await placeOrder(p);
      const after = await thankyouAndMail(p, id);
      log('tienda autorizada: la página de gracias y el correo sí muestran el envío', after.rows.some(t => /Shipping|Envío/i.test(t)) && /Tienda Delicias/.test(after.where) && after.mail === 'con-envio', JSON.stringify(after));
      await ctx.close();
    }

    // ---- 3. Método propio ofreciendo también el envío donde se puede retirar ----
    wp('plugin deactivate woocommerce-advanced-shipping');
    wp(`eval-file ${__dirname}/envio-modo.php propio national_for_pickup=yes`);
    try {
      // Autorizada: el cliente ve las dos; al pasar de Cabimas a Maracaibo queda elegido el retiro.
      {
        const ctx = await guestZulia(browser);
        const p = await ctx.newPage();
        await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
        await checkout(p, 'Cabimas');
        const before = await chosenText(p);
        await setMuni(p, 'Maracaibo');
        const labels = await p.$$eval('#shipping_method li label', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
        const chosen = await chosenText(p);
        log('autorizada · retiro y envío a la vista; al aparecer el retiro queda elegido', /Envío nacional/.test(before) && labels.length === 2 && /Retiro en tienda/.test(chosen), JSON.stringify({ before, labels, chosen }));
        await ctx.close();
      }
      // No autorizada: por debajo sólo el retiro (el cliente no podría elegir).
      setArr('shipping_visible_locations', []);
      {
        const ctx = await guestZulia(browser);
        const p = await ctx.newPage();
        await addToCart(p, 'producto-a-delicias-y-chacao', 'Delicias');
        await checkout(p, 'Maracaibo');
        const inputs = await p.$$eval('#shipping_method input', els => els.map(e => e.value));
        const chosen = await chosenText(p);
        log('no autorizada · puede retirar: sólo queda el retiro, sin el envío nacional', inputs.length === 1 && /pickup/.test(inputs[0]) && /Retiro en tienda/.test(chosen), JSON.stringify({ inputs, chosen }));
        await ctx.close();
      }
    } finally {
      wp(`eval-file ${__dirname}/envio-modo.php was`);
      wp('plugin activate woocommerce-advanced-shipping');
    }

    // ---- 4. Checkout por bloques ----
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
      await p.waitForTimeout(1500);
      const muniSel = '[id^="shipping"][id$="municipio-zu"]';
      await p.waitForSelector(muniSel, { timeout: 15000 });
      const val = await p.$$eval(muniSel + ' option', o => (o.find(x => x.value.includes('Maracaibo')) || {}).value);
      await p.selectOption(muniSel, val);
      await p.waitForResponse(r => /wc\/store\/v1\/(batch|cart\/update-customer)/.test(r.url()), { timeout: 15000 }).catch(() => null);
      await p.waitForLoadState('networkidle'); await p.waitForTimeout(1500);
      const st = await p.evaluate(() => {
        const vis = sel => { const e = document.querySelector(sel); return e ? e.offsetParent !== null : null; };
        return {
          bodyClass: document.body.classList.contains('ts-hide-shipping'),
          methods: vis('.wp-block-woocommerce-checkout-shipping-methods-block'),
          totalsShipping: vis('.wc-block-components-totals-shipping'),
          selected: (document.querySelector('.wc-block-components-shipping-rates-control input:checked + *, .wc-block-components-radio-control__option-checked') || {}).textContent || '',
          rates: [...document.querySelectorAll('.wc-block-components-shipping-rates-control .wc-block-components-radio-control__label, .wc-block-components-shipping-rates-control label')].map(e => e.textContent.trim()),
        };
      });
      log('bloques · no autorizada: bloque de opciones y línea de envío del resumen ocultos', st.bodyClass && st.methods === false && st.totalsShipping !== true, JSON.stringify(st));
      await p.fill('#shipping-phone', '04140000000').catch(() => {});
      await p.click('.wc-block-components-checkout-place-order-button');
      await p.waitForURL(/order-received/, { timeout: 40000 }).catch(() => {});
      const totals = await p.$eval('.wc-block-order-confirmation-totals, .woocommerce-table--order-details', e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
      const where = await p.$('.ts-pickup-where');
      log('bloques · confirmación del pedido: sin línea de envío ni "Dónde retirar"', /order-received/.test(p.url()) && totals !== '' && !/Shipping|Envío/i.test(totals) && !where, JSON.stringify({ url: p.url(), totals: totals.slice(0, 200) }));
      const oid = (p.url().match(/order-received\/(\d+)/) || [])[1];
      const om = oid ? orderMeta(oid) : {};
      log('bloques · por debajo el pedido lleva "Retiro en tienda" (sin cobrar envío)', (om.ship || []).some(t => /Retiro en tienda/.test(t)) && om.muni === 'ZU:maracaibo', JSON.stringify({ ship: om.ship, muni: om.muni }));
      await ctx.close();
    }
  } finally {
    wp(`eval-file ${__dirname}/pages.php ${pagesBefore}`);
    wp(`option update ts_settings '${saved.settings}' --format=json`);
    if (saved.munis) wp(`option update ts_pickup_municipios '${saved.munis}' --format=json`); else wpSoft('option delete ts_pickup_municipios');
    await browser.close();
  }

  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
