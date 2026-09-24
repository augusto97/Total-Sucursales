/**
 * Municipio en la ficha de la tienda (Multi Locations → Locations).
 *
 * Multi Locations sólo trae un campo de texto "City"; Total Sucursales lo cambia por un desplegable
 * de municipios del estado. Se comprueba en el admin que:
 *   - la ficha de una tienda abre con su municipio elegido (deducido de la ciudad escrita);
 *   - elegir otro municipio rellena la ciudad con la de ese municipio y, al guardar, la tienda
 *     queda con ese municipio (y el retiro por municipio lo usa);
 *   - cambiar de estado cambia la lista; "Otra ciudad" deja escribirla a mano;
 *   - una ciudad escrita que no es ningún municipio se respeta;
 *   - en el alta de una tienda, sin estado el desplegable pide elegirlo primero.
 * Deja las tiendas como estaban.
 *
 *   node ficha-municipio.js     # con el servidor en 127.0.0.1:8080 (usuario admin / admin)
 */
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());
const meta = id => JSON.parse(wp(`eval 'echo json_encode(array("locality"=>get_term_meta(${id},"wcmlim_locality",true),"state"=>get_term_meta(${id},"wcmlim_administrative_area_level_1",true),"ts"=>get_term_meta(${id},"ts_municipio",true),"zip"=>get_term_meta(${id},"wcmlim_postal_code",true),"def"=>TS_Municipios::default_for_location(${id})));'`).split('\n').pop());

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

(async () => {
  const DEL = LOC['Tienda Delicias'];
  const CHA = LOC['Tienda Chacao'];
  const before = { del: meta(DEL), cha: meta(CHA) };
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const ui = () => page.evaluate(() => {
    const s = document.getElementById('ts-municipio-select');
    const c = document.getElementById('locality');
    const k = document.querySelector('input[name="ts_municipio"]');
    return {
      has: !!s, disabled: s ? s.disabled : null, value: s ? s.value : null,
      options: s ? s.options.length : 0, first: s && s.options[0] ? s.options[0].textContent : '',
      city: c ? c.value : null, cityVisible: c ? c.offsetParent !== null : null, key: k ? k.value : null,
    };
  });
  const edit = async id => {
    await page.goto(`${BASE}/wp-admin/term.php?taxonomy=locations&tag_ID=${id}&post_type=product`, { waitUntil: 'networkidle' });
    await page.waitForSelector('#ts-municipio-select', { timeout: 15000 }).catch(() => {});
  };
  // Multi Locations no habilita "Actualizar" sin código postal (y las tiendas de prueba no tienen).
  const save = async () => {
    if (!(await page.inputValue('#postal_code'))) { await page.fill('#postal_code', '4001'); }
    await page.dispatchEvent('#postal_code', 'input');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('.edit-tag-actions input[type=submit]'),
    ]);
  };

  try {
    await page.goto(BASE + '/wp-login.php');
    await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
    // Tras entrar, el escritorio redirige en bucle en este entorno: no se espera a esa navegación.
    await page.click('#wp-submit'); await page.waitForLoadState('networkidle').catch(() => {});

    // ---- 1. La ficha abre con el municipio deducido de la ciudad ----
    await edit(DEL);
    let u = await ui();
    log('ficha de Delicias: desplegable de municipios con Maracaibo elegido y la ciudad oculta', u.has && u.value === 'ZU:maracaibo' && u.key === 'ZU:maracaibo' && u.cityVisible === false && u.options > 20, JSON.stringify(u));

    // ---- 2. Elegir otro municipio rellena la ciudad con su capital ----
    await page.selectOption('#ts-municipio-select', 'ZU:lagunillas');
    u = await ui();
    log('elegir Lagunillas pone la ciudad "Ciudad Ojeda"', u.city === 'Ciudad Ojeda' && u.key === 'ZU:lagunillas', JSON.stringify(u));

    // ---- 3. Cambiar de estado cambia la lista ----
    await page.selectOption('#administrative_area_level_1', 'MI');
    u = await ui();
    const miranda = await page.$$eval('#ts-municipio-select option', o => o.map(x => x.value));
    log('cambiar a Miranda: municipios de Miranda, sin elegir, ciudad vacía', miranda.includes('MI:chacao') && !miranda.some(v => v.startsWith('ZU:')) && u.value === '' && u.city === '' && u.key === '', JSON.stringify({ u, n: miranda.length }));

    // ---- 4. "Otra ciudad" deja escribir ----
    await page.selectOption('#ts-municipio-select', '__other__');
    u = await ui();
    log('"Otra ciudad": aparece el campo de texto y no queda municipio', u.cityVisible === true && u.key === '', JSON.stringify(u));

    // ---- 5. Guardar con otro municipio ----
    await page.selectOption('#administrative_area_level_1', 'ZU');
    await page.selectOption('#ts-municipio-select', 'ZU:sanfrancisco');
    await save();
    const m = meta(DEL);
    log('guardar: la tienda queda con San Francisco (municipio y ciudad) y el retiro lo usa', m.ts === 'ZU:sanfrancisco' && m.locality === 'San Francisco' && m.state === 'ZU' && JSON.stringify(m.def) === '["ZU:sanfrancisco"]', JSON.stringify(m));
    await edit(DEL);
    u = await ui();
    log('al volver a abrir la ficha sale San Francisco', u.value === 'ZU:sanfrancisco', JSON.stringify(u));

    // ---- 6. Una ciudad escrita que no es un municipio se respeta ----
    wp(`eval 'update_term_meta(${CHA},"wcmlim_locality","Los Palos Grandes"); delete_term_meta(${CHA},"ts_municipio"); TS_Locations::flush_cache();'`);
    await edit(CHA);
    u = await ui();
    log('ciudad que no es municipio: "Otra ciudad" con el texto visible', u.value === '__other__' && u.cityVisible === true && u.city === 'Los Palos Grandes', JSON.stringify(u));

    // ---- 7. Alta de tienda ----
    await page.goto(`${BASE}/wp-admin/edit-tags.php?taxonomy=locations&post_type=product`, { waitUntil: 'networkidle' });
    await page.waitForSelector('#ts-municipio-select', { timeout: 15000 }).catch(() => {});
    u = await ui();
    const needState = u.has && (u.disabled === true || u.options > 1);
    log('alta de tienda: el desplegable está (pide el estado o lista los municipios)', needState, JSON.stringify(u));
  } catch (e) {
    log('ERROR ' + e.message.split('\n')[0], false);
  } finally {
    for (const [id, b] of [[DEL, before.del], [CHA, before.cha]]) {
      wp(`eval 'update_term_meta(${id},"wcmlim_locality",${JSON.stringify(b.locality)}); update_term_meta(${id},"wcmlim_postal_code",${JSON.stringify(b.zip)}); update_term_meta(${id},"wcmlim_administrative_area_level_1",${JSON.stringify(b.state)}); ${b.ts ? `update_term_meta(${id},"ts_municipio",${JSON.stringify(b.ts)});` : `delete_term_meta(${id},"ts_municipio");`} TS_Locations::flush_cache();'`);
    }
    await browser.close();
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
