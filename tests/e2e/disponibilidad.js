/**
 * Producto con stock sólo en tiendas que el cliente no ve.
 *
 * WooCommerce mira el stock total: sin corregirlo, el cliente veía "6 disponibles" y un botón "Añadir
 * al carrito" que no hacía nada (Multi Locations no deja añadir sin una tienda con stock). Debe verse
 * como no disponible, con el aviso "No disponible en <sus tiendas>", y no poder añadirse.
 *
 *   node disponibilidad.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const PID = n => Number(wp(`eval 'foreach(wc_get_products(array("limit"=>-1)) as $p){ if($p->get_name()==="${n}") echo $p->get_id(); }'`).split('\n').pop());

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

async function ctxFor(browser, state) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  await ctx.addInitScript(() => {
    const n = function () {};
    window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n,
      LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n },
      event: { addListener: n, trigger: n }, InfoWindow: n } };
  });
  await ctx.addCookies([{ name: 'ts_estado', value: state, url: BASE }, { name: 'ts_estado_src', value: 'manual', url: BASE }]);
  return ctx;
}
const pageState = p => p.evaluate(() => ({
  button: !!document.querySelector('button.single_add_to_cart_button'),
  stock: ((document.querySelector('.summary .stock, .stock') || {}).textContent || '').trim(),
}));
const cartCount = async ctx => Number(((await ctx.cookies(BASE)).find(c => c.name === 'woocommerce_items_in_cart') || {}).value || 0);

(async () => {
  wp(`eval-file ${__dirname}/reset-stock.php`);
  const D = PID('Producto D (Valencia)');
  const browser = await chromium.launch();
  try {
    // ---- Zulia: Producto D sólo tiene stock en Valencia ----
    {
      const ctx = await ctxFor(browser, 'ZU');
      const p = await ctx.newPage();
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      await p.goto(BASE + '/product/producto-d-valencia/', { waitUntil: 'networkidle' });
      const st = await pageState(p);
      log('Zulia · Producto D (sólo hay en Valencia): sin "Añadir al carrito" y "No disponible en" sus tiendas', !st.button && /^No disponible en Tienda Delicias ni Tienda San Francisco/.test(st.stock), JSON.stringify(st));
      await p.goto(BASE + '/?add-to-cart=' + D, { waitUntil: 'networkidle' });
      log('Zulia · añadirlo forzando la URL tampoco lo mete en el carrito', (await cartCount(ctx)) === 0, String(await cartCount(ctx)));
      await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
      const a = await pageState(p);
      log('Zulia · Producto A (hay en Delicias): se puede comprar como siempre', a.button && !/No disponible/.test(a.stock), JSON.stringify(a));
      await ctx.close();
    }
    // ---- Carabobo: ve Valencia, donde sí hay ----
    {
      const ctx = await ctxFor(browser, 'CA');
      const p = await ctx.newPage();
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      await p.goto(BASE + '/product/producto-d-valencia/', { waitUntil: 'networkidle' });
      const st = await pageState(p);
      log('Carabobo · Producto D: disponible, con su botón', st.button && !/No disponible/.test(st.stock), JSON.stringify(st));
      await ctx.close();
    }
  } finally {
    await browser.close();
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
