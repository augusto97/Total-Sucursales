/**
 * Precio por tienda en el carrito con los decimales de WooCommerce.
 *
 * Con el precio por tienda de Multi Locations activado ("wcmlim_enable_price"), su filtro del precio
 * de cada línea del carrito devolvía el número tal cual (39.6712), sin el formato de WooCommerce:
 * con 2 decimales configurados salían 3 o 4, y sin símbolo de moneda.
 *
 * Se activa el precio por tienda, se da a Producto C un precio de 4 decimales en Tienda Chacao, se
 * añade al carrito y se mira la columna de precio. Se deja todo como estaba.
 *
 *   node precio-decimales.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const wpSoft = a => { try { return wp(a); } catch (e) { return ''; } };

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

(async () => {
  const pid = wp(`eval 'echo get_page_by_path("producto-c-chacao", OBJECT, "product")->ID;'`);
  const [tid, idx] = wp(`eval '$t=get_term_by("name","Tienda Chacao","locations"); echo $t->term_id, " ", TS_Locations::mli_index_of($t->term_id);'`).split(' ');
  const saved = {
    enable: wpSoft('option get wcmlim_enable_price'),
    decimals: wp('option get woocommerce_price_num_decimals'),
    meta: wpSoft(`post meta get ${pid} wcmlim_regular_price_at_${tid}`),
  };
  wp('option update wcmlim_enable_price on');
  wp('option update woocommerce_price_num_decimals 2');
  wp(`post meta update ${pid} wcmlim_regular_price_at_${tid} 39.6712`);
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext();
    await ctx.addInitScript(() => { const n = function () {}; window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n, LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n }, event: { addListener: n, trigger: n }, InfoWindow: n } }; });
    await ctx.addCookies([
      { name: 'ts_estado', value: '__ALL__', url: BASE },
      { name: 'wcmlim_selected_location_termid', value: tid, url: BASE },
      { name: 'wcmlim_selected_location', value: idx, url: BASE },
    ]);
    const p = await ctx.newPage();
    await p.goto(BASE + '/product/producto-c-chacao/', { waitUntil: 'networkidle' });
    const opt = await p.$$eval('select.select_location option', o => (o.find(x => /Chacao/.test(x.textContent)) || {}).value).catch(() => null);
    if (opt) {
      const done = p.waitForResponse(r => /wcmlim_set_location_on_change/.test(r.request().postData() || ''), { timeout: 8000 }).catch(() => null);
      await p.selectOption('select.select_location', opt);
      await done; await p.waitForLoadState('networkidle');
    }
    await p.waitForFunction(() => { const b = document.querySelector('button.single_add_to_cart_button'); return b && !b.disabled; }, null, { timeout: 10000 }).catch(() => {});
    const count = async () => Number(((await ctx.cookies()).find(x => x.name === 'woocommerce_items_in_cart') || {}).value || 0);
    await p.click('button.single_add_to_cart_button');
    for (let i = 0; i < 40 && !(await count()); i++) await p.waitForTimeout(250);
    await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    const cell = await p.$eval('.woocommerce-cart-form__cart-item .product-price', e => ({ text: e.textContent.replace(/\s+/g, ' ').trim(), html: e.innerHTML })).catch(() => ({ text: '', html: '' }));
    log('carrito: el precio por tienda sale con 2 decimales y moneda (39.67, no 39.6712)', /39[.,]67/.test(cell.text) && !/39[.,]671/.test(cell.text) && /woocommerce-Price-amount/.test(cell.html), cell.text);
    const sub = await p.$eval('.woocommerce-cart-form__cart-item .product-subtotal', e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
    log('carrito: el subtotal de la línea usa el precio de la tienda', /39[.,]67/.test(sub), sub);
  } finally {
    await browser.close();
    saved.enable ? wp(`option update wcmlim_enable_price '${saved.enable}'`) : wpSoft('option delete wcmlim_enable_price');
    wp(`option update woocommerce_price_num_decimals '${saved.decimals}'`);
    saved.meta ? wp(`post meta update ${pid} wcmlim_regular_price_at_${tid} '${saved.meta}'`) : wpSoft(`post meta delete ${pid} wcmlim_regular_price_at_${tid}`);
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
