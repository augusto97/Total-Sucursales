/**
 * Sucursal por defecto para quien no elige ninguna (ajuste `default_location`).
 *
 * Reglas que se comprueban, en el orden en que manda el plugin:
 *   elección del cliente  >  sucursal más cercana por GPS  >  sucursal por defecto  >  primera visible
 * y el filtro por estado por encima de todo: una sucursal por defecto de otro estado no se aplica.
 *
 *   node sucursal-por-defecto.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());
const ID = n => LOC[n];

// El orden alfabético de MLI es Chacao, Delicias, San Francisco, Valencia: la primera visible en
// Zulia sería Delicias, así que como defecto se elige San Francisco para que la diferencia se note.
const DEFAULT_NAME = 'Tienda San Francisco';

const setDefault = id => wp(`eval '$s=(array)get_option("ts_settings",array()); $s["default_location"]=${id}; update_option("ts_settings",$s); echo "ok";'`);

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

(async () => {
  const browser = await chromium.launch();

  // Devuelve la sucursal que queda activa tras montar las cookies indicadas y cargar el inicio.
  const selectedAfter = async (cookies, geo) => {
    const ctx = await browser.newContext(geo
      ? { geolocation: geo, permissions: ['geolocation'] }
      : {});
    if (cookies.length) await ctx.addCookies(cookies.map(c => ({ ...c, url: BASE })));
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const all = await ctx.cookies();
    const termid = all.find(c => c.name === 'wcmlim_selected_location_termid');
    await ctx.close();
    return termid ? Number(termid.value) : 0;
  };
  const nameOf = id => Object.keys(LOC).find(n => LOC[n] === id) || '(ninguna)';

  setDefault(ID(DEFAULT_NAME));

  // 1. Visitante nuevo que no contesta nada: se le asigna la sucursal por defecto.
  let got = await selectedAfter([]);
  log('sin elegir nada: queda la sucursal por defecto', got === ID(DEFAULT_NAME), nameOf(got));

  // 2. "Ver todas las sucursales" sin GPS: la por defecto, no la primera alfabética.
  got = await selectedAfter([{ name: 'ts_estado', value: '__ALL__' }, { name: 'ts_estado_src', value: 'manual' }]);
  log('"ver todas las sucursales": la por defecto, no la primera de la lista', got === ID(DEFAULT_NAME), nameOf(got));

  // 3. Estado sin la sucursal por defecto: manda el filtro por estado.
  got = await selectedAfter([{ name: 'ts_estado', value: 'CA' }, { name: 'ts_estado_src', value: 'manual' }]);
  log('estado Carabobo: la por defecto (Zulia) no se cuela, queda Valencia', got === ID('Tienda Valencia'), nameOf(got));

  // 4. Una elección del cliente no se pisa.
  got = await selectedAfter([
    { name: 'ts_estado', value: 'ZU' }, { name: 'ts_estado_src', value: 'manual' },
    { name: 'wcmlim_selected_location_termid', value: String(ID('Tienda Delicias')) },
    { name: 'wcmlim_selected_location', value: '1' },
  ]);
  log('con sucursal ya elegida: no se pisa', got === ID('Tienda Delicias'), nameOf(got));

  // 5. Con GPS, la más cercana gana a la por defecto (Delicias está a 3 km de Maracaibo).
  got = await selectedAfter(
    [{ name: 'wcmlim_user_lat', value: '10.6427' }, { name: 'wcmlim_user_lng', value: '-71.6125' }],
    { latitude: 10.6427, longitude: -71.6125 }
  );
  log('con GPS: gana la más cercana, no la por defecto', got === ID('Tienda Delicias'), nameOf(got));

  // 6. El ajuste es voluntario: apagado, una carga de página no asigna sucursal por su cuenta.
  setDefault(0);
  got = await selectedAfter([{ name: 'ts_estado', value: '__ALL__' }, { name: 'ts_estado_src', value: 'manual' }]);
  log('sin sucursal por defecto: no se asigna ninguna sola (comportamiento anterior)', got === 0, nameOf(got));

  await browser.close();
  setDefault(0);
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
