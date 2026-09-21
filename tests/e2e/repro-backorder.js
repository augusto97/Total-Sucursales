/**
 * Regresión de la 0.2.4: el parche de wcmlim_get_quantity_attributes respondía
 * {status:"Failed!"} sin envolverlo en "data", y wcmlim-public.js reventaba con
 * "Cannot read properties of undefined (reading 'backorder')" en cada cambio de sede.
 *
 * Aquí se ejecuta el manejador success() real de Multi Locations (recortado de su propio
 * fichero, no una copia a mano) contra las respuestas reales del servidor.
 *
 *   node tests/e2e/repro-backorder.js       # con el servidor en 127.0.0.1:8080
 */
const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const MLI = WP + '/wp-content/plugins/WooCommerce-Multi-Locations-Inventory-Management/public/js/wcmlim-public.js';

// El cuerpo de success() va desde "if (response.data.backorder) {" hasta su cierre.
const lines = fs.readFileSync(MLI, 'utf8').split('\n');
const start = lines.findIndex(l => l.includes('if (response.data.backorder)'));
if (start < 0) { console.error('No se encontró el manejador en wcmlim-public.js'); process.exit(1); }
let depth = 0, end = start;
for (let i = start; i < lines.length; i++) {
  depth += (lines[i].match(/{/g) || []).length - (lines[i].match(/}/g) || []).length;
  if (depth === 0) { end = i; break; }
}
const body = lines.slice(start, end + 1).join('\n');

const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const productId = wp('post list --post_type=product --format=ids').split(/\s+/)[0];
const pageId = wp('post list --post_type=page --format=ids').split(/\s+/)[0];

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext()).newPage();
  await page.goto(BASE + '/?page_id=' + pageId, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => !!window.jQuery);

  const run = (pid) => page.evaluate(async ({ pid, body }) => {
    const res = await fetch('/wp-admin/admin-ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=wcmlim_get_quantity_attributes&selectedLocation=0&currentProductId=' + pid,
    });
    const text = await res.text();
    let response;
    try { response = JSON.parse(text); } catch (e) { return { status: res.status, error: 'respuesta no-JSON: ' + text.slice(0, 120) }; }
    try {
      new Function('response', 'location', 'localization', 'jQuery', body)(response, 0, { is_hide_stock_count: 'off' }, window.jQuery);
    } catch (e) { return { status: res.status, error: e.name + ': ' + e.message }; }
    return { status: res.status, error: null, keys: Object.keys(response.data || {}) };
  }, { pid, body });

  for (const [label, pid] of [['producto real', productId], ['página (no es producto)', pageId], ['id inexistente', 999999]]) {
    const r = await run(pid);
    log(`${label}: sin error en el success() de Multi Locations`, r.status === 200 && !r.error, r.error || 'HTTP ' + r.status);
  }

  // La forma rota de la 0.2.4 debe seguir fallando: si no, esta prueba no prueba nada.
  const old = await page.evaluate(({ body }) => {
    try {
      new Function('response', 'location', 'localization', 'jQuery', body)({ status: 'Failed!' }, 0, { is_hide_stock_count: 'off' }, window.jQuery);
    } catch (e) { return e.message; }
    return null;
  }, { body });
  log('la respuesta sin "data" sí rompía (control)', !!old && old.includes('backorder'), old || 'no falló');

  await browser.close();
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
