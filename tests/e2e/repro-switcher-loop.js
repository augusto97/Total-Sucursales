/**
 * Bucle de recargas y diálogo "¿Cambiar de tienda?" del selector de sucursal.
 *
 * Multi Locations consulta wcmlim_ajax_cart_count en cada evento "change" del selector, incluidos
 * los que dispara su propio JavaScript al cargar la página, y ese handler no comprueba si la
 * sucursal pedida es la que ya estaba activa:
 *
 *   - misma sucursal  -> die() con respuesta vacía -> su JS recarga la página -> vuelve a pasar.
 *   - "Select" (-1)   -> la sucursal de destino es null, todo el carrito le parece de otra sucursal
 *                        y saca el diálogo con la misma sucursal a los dos lados.
 *
 * Esta prueba comprueba las tres transiciones con el selector real:
 *
 *   node tests/e2e/repro-switcher-loop.js   # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();

  // Sin clave de Google Maps el "ReferenceError: google is not defined" corta el módulo de Multi
  // Locations antes de registrar el handler del selector, así que se stubea lo imprescindible.
  await ctx.addInitScript(() => {
    const noop = function () {};
    window.google = { maps: {
      LatLng: function () {}, Geocoder: function () { this.geocode = noop; },
      GeocoderStatus: { OK: 'OK' }, Map: function () {}, Marker: function () {},
      LatLngBounds: function () { this.extend = noop; }, Size: function () {}, Point: function () {},
      places: { Autocomplete: function () {}, AutocompleteService: function () {} },
      event: { addListener: noop, trigger: noop }, InfoWindow: function () {},
    } };
  });
  await ctx.addCookies([{ name: 'ts_estado', value: 'ZU', url: BASE }]);

  const page = await ctx.newPage();

  // Carrito con un artículo de Delicias: es la condición que dispara el diálogo.
  await page.goto(BASE + '/product/producto-a-delicias-y-chacao/', { waitUntil: 'networkidle' });
  await page.selectOption('#select_location', { index: 1 }).catch(() => {});
  await page.waitForTimeout(500);
  await page.click('.single_add_to_cart_button').catch(() => {});
  await page.waitForTimeout(1500);

  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const opts = await page.$$eval('#wcmlim-change-lc-select option', els => els.map(o => ({ v: o.value, t: o.textContent.trim(), term: o.getAttribute('data-lc-term') })));
  const same = opts.find(o => String(o.term) === String(LOC['Tienda Delicias']));
  const other = opts.find(o => o.v !== '-1' && o !== same);
  const ck = (await ctx.cookies()).find(c => c.name === 'wcmlim_selected_location_termid');
  log('el carrito quedó en Delicias y el selector la marca', !!same && !!ck && ck.value === String(LOC['Tienda Delicias']), JSON.stringify({ opts, cookie: ck && ck.value }));

  const fire = async (value) => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' }).catch(() => {});
    let reloads = 0;
    const onNav = () => reloads++;
    page.on('framenavigated', onNav);
    await page.evaluate(v => window.jQuery('#wcmlim-change-lc-select').val(v).trigger('change'), value);
    await page.waitForTimeout(3000);
    const swal = await page.$$eval('.swal2-container', els => els.map(e => e.textContent.replace(/\s+/g, ' ').trim())).catch(() => ['(contexto destruido: la página se recargó)']);
    page.off('framenavigated', onNav);
    await page.evaluate(() => window.Swal && window.Swal.close && window.Swal.close()).catch(() => {});
    return { reloads, swal };
  };

  const r1 = await fire(same ? same.v : '0');
  log('cambiar a la sucursal ya activa: ni recarga ni diálogo', r1.reloads === 0 && r1.swal.length === 0, JSON.stringify(r1));

  const r2 = await fire('-1');
  log('dejar el selector en "Select": ni recarga ni diálogo', r2.reloads === 0 && r2.swal.length === 0, JSON.stringify(r2));

  // Un cambio de verdad tiene que seguir avisando: si no, la prueba pasaría con el aviso desactivado.
  const r3 = await fire(other ? other.v : '1');
  log('cambiar a otra sucursal: Multi Locations sigue avisando (control)', r3.swal.length === 1, JSON.stringify(r3).slice(0, 220));

  await browser.close();
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
