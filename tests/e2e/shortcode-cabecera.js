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
  try {
    const r = await fetch(`${BASE}/?page_id=${page}`, { headers: { Cookie: 'ts_estado=__ALL__' } });
    const html = await r.text();
    const start = html.replace(/^\s+/, '').slice(0, 15);
    log('la página empieza por <!DOCTYPE html> (nada de Multi Locations antes)', /^<!DOCTYPE html>/i.test(start), JSON.stringify(start));
    const body = html.indexOf('<body');
    const sw = html.indexOf('id="lc-switch-form"');
    const n = (html.match(/id="lc-switch-form"/g) || []).length;
    log('el selector de tiendas está dentro del <body>, una vez', body > 0 && sw > body && n === 1, JSON.stringify({ body, selector: sw, veces: n }));
  } finally {
    wp(`post delete ${page} --force`);
    wp(`theme activate ${theme || 'twentytwentyone'}`);
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
