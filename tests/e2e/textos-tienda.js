/**
 * Textos en español y "tienda" en lugar de "location", y ciudad + dirección bajo cada tienda.
 *
 * Recorre lo que ve el cliente: ventana de estado, ficha de producto (lista y desplegable de MLI),
 * selector de la cabecera, carrito, pedido y los diálogos de SweetAlert de Multi Locations.
 *
 *   node textos-tienda.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
// Elegir tienda en el desplegable de MLI dispara un AJAX que fija la tienda; si se pulsa "Añadir al
// carrito" antes de que termine, MLI rechaza el alta. Se espera a esa respuesta, no a un reloj.
async function pickLocation(p, option) {
  const done = p.waitForResponse(r => r.url().includes('admin-ajax.php') && /wcmlim_set_location_on_change/.test(r.request().postData() || ''), { timeout: 10000 }).catch(() => null);
  await p.selectOption('select.select_location', option);
  await done;
  // Con "vaciar carrito al cambiar de tienda" MLI desactiva el botón de compra hasta que responde
  // wcmlim_ajax_cart_count: un clic antes de eso no hace nada.
  await p.waitForLoadState('networkidle');
  await p.waitForFunction(() => { const b = document.querySelector('button.single_add_to_cart_button'); return b && !b.disabled; }, null, { timeout: 10000 }).catch(() => {});
}
async function addToCart(p) {
  const ctx = p.context();
  const count = async () => Number(((await ctx.cookies()).find(x => x.name === 'woocommerce_items_in_cart') || {}).value || 0);
  const before = await count();
  const enabled = () => p.waitForFunction(() => { const b = document.querySelector('button.single_add_to_cart_button'); return b && !b.disabled; }, null, { timeout: 10000 }).catch(() => {});
  // Un intento más si el primer clic cayó mientras MLI aún tenía el botón bloqueado (como haría el
  // cliente). Si tampoco añade, la prueba del carrito falla igual.
  for (let attempt = 0; attempt < 2 && (await count()) <= before; attempt++) {
    await enabled();
    await p.click('button.single_add_to_cart_button');
    for (let i = 0; i < 40 && (await count()) <= before; i++) await p.waitForTimeout(250);
  }
}
const ENGLISH = /\b(Location|Locations|In Stock|Sold Out|Stock Information|Select Location|Yes, Change Location|Cancel)\b/;

(async () => {
  wp(`eval-file ${__dirname}/reset-stock.php`);
  const view = wp('option get wcmlim_backend_display_stock_view');
  const browser = await chromium.launch();

  const newCtx = async (cookies = []) => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    // Sin clave de Google Maps el módulo de MLI se corta antes de registrar sus handlers.
    await ctx.addInitScript(() => {
      const n = function () {};
      window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n,
        LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n },
        event: { addListener: n, trigger: n }, InfoWindow: n } };
    });
    if (cookies.length) await ctx.addCookies(cookies.map(c => ({ ...c, url: BASE })));
    return ctx;
  };

  // ---- 1. Ventana de estado (textos propios) ----
  {
    const ctx = await newCtx();
    const p = await ctx.newPage();
    await p.goto(BASE + '/', { waitUntil: 'networkidle' });
    const modal = await p.$eval('#ts-state-modal', e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
    log('ventana de estado: habla de tiendas, no de sucursales', /ver todas las tiendas/.test(modal) && /las tiendas y el catálogo/.test(modal) && !/sucursal/i.test(modal), modal.slice(0, 140));
    const sw = await p.$eval('.wcmlim-lc-switch', e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
    log('selector de la cabecera: "Tienda:" y "Seleccionar"', /Tienda:/.test(sw) && /Seleccionar/.test(sw) && !ENGLISH.test(sw), sw.slice(0, 120));
    await ctx.close();
  }

  // ---- 2. Ficha de producto en vista de lista ----
  wp('option update wcmlim_backend_display_stock_view list_view');
  {
    const ctx = await newCtx([{ name: 'ts_estado', value: '__ALL__' }]);
    const p = await ctx.newPage();
    await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
    await p.waitForTimeout(600);
    const box = await p.$eval('.Wcmlim_container', e => e.innerText.replace(/\s+/g, ' ').trim()).catch(() => '');
    // La vista de lista enseña cantidades ("5 In Stock") y su CSS capitaliza cada palabra.
    log('ficha (lista): "Disponibilidad por tienda" y "N disponibles", sin inglés', /Disponibilidad por tienda/.test(box) && /\b\d+ disponibles?\b/i.test(box) && !ENGLISH.test(box), box.slice(0, 160));
    const addr = await p.$$eval('.location-stock-item[data-location-id]', els => els.map(e => [e.querySelector('strong, .location-name') ? e.querySelector('strong, .location-name').textContent.trim() : '', (e.querySelector('.ts-loc-address') || {}).textContent || '']));
    log('ficha (lista): ciudad · dirección bajo cada tienda', addr.length === 2 && addr.every(([, a]) => /^(Caracas|Maracaibo) · Calle 1 Av\. Principal$/.test(a)), JSON.stringify(addr));
    await ctx.close();
  }

  // ---- 3. Ficha en vista de desplegable (la de las pruebas) ----
  wp('option update wcmlim_backend_display_stock_view select_view');
  {
    const ctx = await newCtx([{ name: 'ts_estado', value: '__ALL__' }]);
    const p = await ctx.newPage();
    await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
    await p.waitForTimeout(600);
    const opts = await p.$$eval('select.select_location option', o => o.map(x => x.textContent.trim()));
    log('ficha (desplegable): "- Selecciona una tienda -" y stock en español', opts[0] === '- Selecciona una tienda -' && opts.slice(1).every(t => /Disponible|Agotado/.test(t)) && !opts.some(t => ENGLISH.test(t)), JSON.stringify(opts));

    // ---- 4. Carrito y pedido ----
    await pickLocation(p, { index: 1 });
    await addToCart(p);
    const afterAdd = { url: p.url(), notices: await p.$$eval('.woocommerce-error li, .woocommerce-message, .woocommerce-info', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim().slice(0, 140))).catch(() => []), items: ((await ctx.cookies()).find(x => x.name === 'woocommerce_items_in_cart') || {}).value || 0, sel: await p.$eval('select.select_location', e => e.value).catch(() => '?') };
    await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    const cart = await p.$$eval('.woocommerce-cart-form__cart-item .product-name', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim()));
    if (!cart.length) console.log('  [diagnóstico tras añadir]', JSON.stringify(afterAdd));
    log('carrito: "Tienda: <nombre>"', cart.length === 1 && /Tienda: Tienda (Delicias|Chacao)/.test(cart[0]) && !/Location/.test(cart[0]), JSON.stringify(cart));

    // Pedido: la clave "Location" guardada en la línea se muestra como "Tienda".
    await p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    await p.fill('#billing_first_name', 'Ana'); await p.fill('#billing_last_name', 'Gómez');
    await p.fill('#billing_address_1', 'Av. 5 de Julio');
    await p.selectOption('#billing_state', 'ZU'); await p.waitForTimeout(800);
    const cities = await p.$$eval('#billing_city option', o => o.map(x => x.value));
    await p.selectOption('#billing_city', cities.find(v => /Maracaibo/.test(v)) || cities[1]);
    await p.fill('#billing_postcode', '4001'); await p.fill('#billing_phone', '04140000000'); await p.fill('#billing_email', 'ana@example.com');
    await p.waitForTimeout(1500);
    await p.check('#payment_method_cod').catch(() => {});
    // Título del método de pago editable ("Solicitud" en lugar de "Método de pago").
    wp(`eval '$s=(array)get_option("ts_settings"); $s["txt_payment_label"]="Solicitud"; update_option("ts_settings",$s);'`);
    await p.click('#place_order');
    await p.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
    const meta = await p.$$eval('.wc-item-meta li, .woocommerce-table--order-details .wc-item-meta', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
    log('pedido: la línea dice "Tienda:", no "Location:"', /order-received/.test(p.url()) && meta.some(t => /^Tienda: Tienda/.test(t)) && !meta.some(t => /Location/.test(t)), JSON.stringify(meta));
    const overview = await p.$eval('.woocommerce-order-overview__payment-method', e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
    const totals = await p.$$eval('.woocommerce-table--order-details tfoot th', els => els.map(e => e.textContent.trim())).catch(() => []);
    const oid = (p.url().match(/order-received\/(\d+)/) || [])[1];
    const mail = oid ? wp(`eval '$e=WC()->mailer()->emails["WC_Email_Customer_Processing_Order"]; $e->object=wc_get_order(${oid}); echo (false!==strpos(wp_strip_all_tags($e->get_content_html()),"Solicitud:"))?"si":"no";'`) : '';
    wp(`eval '$s=(array)get_option("ts_settings"); unset($s["txt_payment_label"]); update_option("ts_settings",$s);'`);
    log('página de gracias y correo: "Solicitud:" en lugar de "Método de pago:"', /^Solicitud:/.test(overview) && totals.includes('Solicitud:') && !totals.some(t => /Payment|pago/i.test(t)) && /si$/.test(mail), JSON.stringify({ overview, totals, mail }));
    await ctx.close();
  }

  // ---- 5. Diálogos de MLI ----
  {
    const ctx = await newCtx([{ name: 'ts_estado', value: '__ALL__' }]);
    const p = await ctx.newPage();
    await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
    const del = await p.$$eval('select.select_location option', o => o.map(x => ({ v: x.value, t: x.textContent })).find(x => /Delicias/.test(x.t)));
    await pickLocation(p, del.v);
    await addToCart(p);

    const fire = async (termName) => {
      // Al cerrar un diálogo MLI recarga la página por su cuenta: se espera a que termine.
      await p.goto(BASE + '/', { waitUntil: 'networkidle' }).catch(() => {});
      await p.waitForLoadState('networkidle');
      await p.waitForSelector('#wcmlim-change-lc-select');
      const v = await p.$$eval('#wcmlim-change-lc-select option', (o, n) => (o.find(x => x.textContent.includes(n)) || {}).value, termName);
      await p.evaluate(val => window.jQuery('#wcmlim-change-lc-select').val(val).trigger('change'), v);
      await p.waitForSelector('.swal2-popup', { timeout: 8000 }).catch(() => {});
      const d = await p.evaluate(() => {
        const q = s => (document.querySelector(s) || {}).textContent || '';
        return { title: q('.swal2-title'), text: q('.swal2-html-container') || q('#swal2-content'), ok: q('.swal2-confirm'), cancel: q('.swal2-cancel') };
      });
      await p.evaluate(() => window.Swal && window.Swal.close()).catch(() => {});
      await p.waitForTimeout(1500);
      return d;
    };

    // Producto A hay en Chacao: diálogo de cambio de tienda.
    const d1 = await fire('Chacao');
    log('diálogo de cambio de tienda en español', /¿Cambiar de tienda\?/.test(d1.title) && /^Tu carrito tiene productos de Tienda Delicias\. ¿Quieres pasarlos todos a Tienda Chacao\?$/.test(d1.text.trim()) && d1.ok === 'Sí, cambiar de tienda' && d1.cancel === 'Cancelar', JSON.stringify(d1));

    // Producto A no hay en Valencia: diálogo de productos no disponibles.
    const d2 = await fire('Valencia');
    log('diálogo de productos no disponibles en español', /Productos no disponibles/.test(d2.title) && /^Estos productos no están disponibles en Tienda Valencia/.test(d2.text.trim()) && /Cant\.: 1/.test(d2.text) && /Sí, quitar y actualizar/.test(d2.ok) && d2.cancel === 'Cancelar', JSON.stringify(d2));

    // Un texto editado en los ajustes se usa en el diálogo.
    wp(`eval '$s=(array)get_option("ts_settings"); $s["txt_mli_change_confirm"]="Sí, llévame a {x}"; $s["txt_mli_change_text"]="De {actual} a {nueva}"; update_option("ts_settings",$s);'`);
    const d3 = await fire('Chacao');
    log('texto del diálogo editado en ajustes', d3.text.trim() === 'De Tienda Delicias a Tienda Chacao' && d3.ok === 'Sí, llévame a {x}', JSON.stringify(d3));
    wp(`eval '$s=(array)get_option("ts_settings"); unset($s["txt_mli_change_confirm"],$s["txt_mli_change_text"]); update_option("ts_settings",$s);'`);
    await ctx.close();
  }

  // ---- 6. Carrito por bloques: MLI añade " | Location : X" junto al precio desde su JS ----
  const pagesBefore = /wp:woocommerce\/cart/.test(wp(`eval 'echo get_post_field("post_content", wc_get_page_id("cart"));'`)) ? 'blocks' : 'classic';
  wp(`eval-file ${__dirname}/pages.php blocks`);
  {
    const ctx = await newCtx([{ name: 'ts_estado', value: '__ALL__' }]);
    const p = await ctx.newPage();
    await p.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
    await pickLocation(p, { index: 1 });
    await addToCart(p);
    await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    await p.waitForSelector('.wc-block-components-product-price', { timeout: 15000 }).catch(() => {});
    await p.waitForTimeout(800);
    const price = await p.$$eval('.wc-block-components-product-price', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
    log('carrito por bloques: "| Tienda: <nombre>" junto al precio', price.some(t => /\| Tienda: Tienda (Chacao|Delicias)$/.test(t)) && !price.some(t => /Location/.test(t)), JSON.stringify(price));
    await ctx.close();
  }
  wp(`eval-file ${__dirname}/pages.php ${pagesBefore}`);

  // ---- 7. Un texto personalizado en MLI se respeta ----
  const prev = wp('option get wcmlim_soldout_button_text');
  wp('option update wcmlim_soldout_button_text "Sin existencias"');
  const got = wp(`eval 'echo get_option("wcmlim_soldout_button_text");'`);
  log('texto personalizado en MLI: no se pisa', got === 'Sin existencias', got);
  wp('option update wcmlim_soldout_button_text "Sold out"');
  const back = wp(`eval 'echo get_option("wcmlim_soldout_button_text");'`);
  log('texto de fábrica de MLI: sale en español', back === 'Agotado', back);
  wp(`option update wcmlim_soldout_button_text "${prev}"`);

  await browser.close();
  wp(`option update wcmlim_backend_display_stock_view ${view || 'select_view'}`);
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
