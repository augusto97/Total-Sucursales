/**
 * Selector de tiendas de Multi Locations ([wcmlim_locations_switch]) con un tema de bloques.
 *
 * El shortcode imprime su HTML con echo en lugar de devolverlo. Los temas de bloques generan la
 * plantilla (cabecera incluida) antes de escribir el <head>, así que el selector salía antes del
 * <!DOCTYPE html>: arriba de la página, sin estilos, y con el navegador en modo de compatibilidad.
 *
 * Activa twentytwentyfive, crea una página con el shortcode en un bloque "Shortcode" y comprueba que
 * la página empieza por el doctype y el selector está dentro del <body>, una sola vez. Deja el tema
 * como estaba.
 *
 *   node shortcode-cabecera.js     # con el servidor en 127.0.0.1:8080
 */
const { execSync } = require('child_process');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };

(async () => {
  const theme = wp('theme list --status=active --field=name');
  wp('theme activate twentytwentyfive');
  const page = wp(`post create --post_type=page --post_status=publish --post_title="Selector" --post_content='<!-- wp:shortcode -->[wcmlim_locations_switch]<!-- /wp:shortcode -->' --porcelain`);
  // Como en una cabecera: el bloque Shortcode dentro de una parte de plantilla.
  const part = wp(`post create --post_type=wp_template_part --post_status=publish --post_name=ts-test-sc --post_title="TS test" --post_content='<!-- wp:shortcode -->[wcmlim_locations_switch]<!-- /wp:shortcode -->' --porcelain`);
  wp(`post term set ${part} wp_theme twentytwentyfive`);
  const page2 = wp(`post create --post_type=page --post_status=publish --post_title="Selector en parte" --post_content='<!-- wp:template-part {"slug":"ts-test-sc","theme":"twentytwentyfive"} /-->' --porcelain`);
  try {
    for (const [label, id] of [['bloque Shortcode en la página', page], ['bloque Shortcode en una parte de plantilla (cabecera)', page2]]) {
      const r = await fetch(`${BASE}/?page_id=${id}`, { headers: { Cookie: 'ts_estado=__ALL__' } });
      const html = await r.text();
      const start = html.replace(/^\s+/, '').slice(0, 15);
      log(`${label}: la página empieza por <!DOCTYPE html>`, /^<!DOCTYPE html>/i.test(start), JSON.stringify(start));
      const body = html.indexOf('<body');
      const sw = html.indexOf('id="lc-switch-form"');
      const n = (html.match(/id="lc-switch-form"/g) || []).length;
      log(`${label}: el selector está dentro del <body>, una vez`, body > 0 && sw > body && n === 1, JSON.stringify({ body, selector: sw, veces: n }));
      const from = html.lastIndexOf('<div class="main-cont">', sw);
      const to = html.indexOf('</form>', sw);
      const box = from > 0 && to > 0 ? html.slice(from, to) : '';
      const before = html.slice(Math.max(0, from - 40), from);
      log(`${label}: sin <p> ni <br> añadidos dentro o alrededor del selector`, box !== '' && !/<p[\s>]|<\/p>|<br\s*\/?>/.test(box) && !/<p[^>]*>\s*$/.test(before), JSON.stringify({ p: (box.match(/<p[\s>]/g) || []).length, antes: before.replace(/\s+/g, ' ').slice(-25) }));
    }
  } finally {
    wp(`post delete ${page} ${page2} ${part} --force`);
    wp(`theme activate ${theme || 'twentytwentyone'}`);
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
