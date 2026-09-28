/**
 * Ubicación aproximada por IP (IPinfo) como respaldo del GPS.
 *
 * IPinfo se simula con un mu-plugin (pre_http_request) y la IP del visitante sale de la cookie
 * ts_test_ip (filtro ts_client_ip); así se prueba sin red y con IP públicas conocidas:
 *   200.44.1.1 → Maracaibo · 200.44.2.2 → Cabimas · 200.44.3.3 → Caracas · 200.44.9.9 → sin resultado.
 *
 * Se comprueba: modo por ciudad (GPS negado, sin respuesta del aviso, cookie de "negado" de antes),
 * que el GPS manda sobre la IP, que la IP no se guarda como GPS (no cuenta para el retiro), el modo
 * por estado (elige el estado sin preguntar; sin resultado, pregunta), la opción desactivada, la caché
 * por IP, el nonce y que el script de IPinfo de Multi Locations (que publica el token) no se carga.
 *
 *   node ip-ubicacion.js     # con el servidor en 127.0.0.1:8080
 */
const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');

const BASE = 'http://127.0.0.1:8080';
const WP = __dirname + '/wordpress';
const MU = WP + '/wp-content/mu-plugins/ts-test-ipinfo.php';
const wp = a => execSync(`php ${__dirname}/wp-cli.phar --allow-root --path=${WP} ${a} 2>/dev/null`).toString().trim();
const LOC = JSON.parse(wp(`eval '$o=array(); foreach(get_terms(array("taxonomy"=>"locations","hide_empty"=>false)) as $t){$o[$t->name]=$t->term_id;} echo json_encode($o);'`).split('\n').pop());
const wpSoft = a => { try { return wp(a); } catch (e) { return ''; } };
const setting = (key, value) => wp(`eval '$s=(array)get_option("ts_settings"); $s["${key}"]="${value}"; update_option("ts_settings",$s);'`);
const calls = () => parseInt(wpSoft('option get ts_test_ipinfo_calls') || '0', 10) || 0;
const resetIpinfo = () => { wp('option update ts_test_ipinfo_calls 0'); wp(`eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \\"%transient%ts_ipinfo_%\\""); wp_cache_flush();'`); };

const MU_SRC = `<?php
// Sólo para las pruebas: IPinfo simulado y la IP del visitante tomada de una cookie.
add_filter( 'ts_ipinfo_hourly_limit', function ( $n ) { $l = get_option( 'ts_test_ipinfo_limit' ); return '' === $l || false === $l ? $n : (int) $l; } );
add_filter( 'ts_client_ip', function ( $ip ) { return isset( $_COOKIE['ts_test_ip'] ) ? $_COOKIE['ts_test_ip'] : $ip; } );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( 0 !== strpos( $url, 'https://ipinfo.io/' ) ) { return $pre; }
	update_option( 'ts_test_ipinfo_calls', (int) get_option( 'ts_test_ipinfo_calls', 0 ) + 1 );
	update_option( 'ts_test_ipinfo_url', $url );
	$map = array(
		'200.44.1.1' => array( 'loc' => '10.6427,-71.6125', 'city' => 'Maracaibo', 'region' => 'Zulia' ),
		'200.44.2.2' => array( 'loc' => '10.4010,-71.4460', 'city' => 'Cabimas', 'region' => 'Zulia' ),
		'200.44.3.3' => array( 'loc' => '10.4806,-66.9036', 'city' => 'Caracas', 'region' => 'Distrito Capital' ),
	);
	preg_match( '#ipinfo\\.io/([^/?]+)/json#', $url, $m );
	$ip = isset( $m[1] ) ? rawurldecode( $m[1] ) : '';
	if ( ! isset( $map[ $ip ] ) ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => json_encode( array( 'ip' => $ip, 'bogon' => true ) ), 'headers' => array(), 'cookies' => array() );
	}
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => json_encode( $map[ $ip ] + array( 'ip' => $ip, 'country' => 'VE' ) ), 'headers' => array(), 'cookies' => array() );
}, 10, 3 );
`;

const results = [];
const log = (name, ok, detail) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (detail ? ' — ' + detail : '')); };
async function waitFor(check, timeoutMs = 15000) {
  const t0 = Date.now();
  while (Date.now() - t0 < timeoutMs) { if (await check()) return true; await new Promise(r => setTimeout(r, 250)); }
  return false;
}
/**
 * geo: {latitude, longitude} concedido · null: negado · 'silent': el aviso del navegador nunca se contesta.
 */
async function newCtx(browser, ip, geo, extraCookies) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, geolocation: geo && geo !== 'silent' ? geo : undefined, permissions: geo && geo !== 'silent' ? ['geolocation'] : [] });
  await ctx.addInitScript(silent => {
    const n = function () {};
    window.google = { maps: { LatLng: n, Geocoder: function () { this.geocode = n; }, GeocoderStatus: { OK: 'OK' }, Map: n, Marker: n,
      LatLngBounds: function () { this.extend = n; }, Size: n, Point: n, places: { Autocomplete: n, AutocompleteService: n },
      event: { addListener: n, trigger: n }, InfoWindow: n } };
    if (silent) { navigator.geolocation.getCurrentPosition = function () {}; }
  }, geo === 'silent');
  const list = (extraCookies || []).slice();
  if (ip) { list.push({ name: 'ts_test_ip', value: ip }); }
  if (list.length) { await ctx.addCookies(list.map(c => Object.assign({ url: BASE }, c))); }
  return ctx;
}
const cookies = async ctx => Object.fromEntries((await ctx.cookies(BASE)).map(c => [c.name, c.value]));
const switcher = p => p.$$eval('#wcmlim-change-lc-select option', o => o.filter(x => x.value !== '-1' && x.value !== '').map(x => x.textContent.trim())).catch(() => []);
const nameOf = id => Object.keys(LOC).find(k => String(LOC[k]) === String(id)) || String(id);
const sorted = a => JSON.stringify(a.slice().sort());

// Primera visita y espera a que se resuelva (cookies de GPS, de IP o de "sin resultado").
async function visit(browser, ip, geo, opts) {
  opts = opts || {};
  const ctx = await newCtx(browser, ip, geo, opts.cookies);
  const p = await ctx.newPage();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await waitFor(async () => { const c = await cookies(ctx); return !!(c.wcmlim_user_lat || c.ts_ip_lat || c.ts_ip); }, opts.wait || 15000);
  await p.waitForTimeout(1200);
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  return { ctx, p };
}

(async () => {
  const saved = wp('option get ts_settings --format=json');
  const savedMli = { on: wpSoft('option get wcmlim_enable_autodetect_location_with_ipinfo'), token: wpSoft('option get wcmlim_ipinfo_api_token') };
  fs.mkdirSync(WP + '/wp-content/mu-plugins', { recursive: true });
  fs.writeFileSync(MU, MU_SRC);
  setting('visibility_mode', 'city'); setting('city_radius_km', '20'); setting('default_location', String(LOC['Tienda Valencia']));
  setting('ip_fallback', 'yes'); setting('ipinfo_token', '');
  wp('option update wcmlim_enable_autodetect_location_with_ipinfo on');
  wp('option update wcmlim_ipinfo_api_token SECRETO-MLI-123');
  resetIpinfo();
  const browser = await chromium.launch();
  try {
    // ---- 1. Por ciudad, GPS negado, IP de Maracaibo ----
    {
      const { ctx, p } = await visit(browser, '200.44.1.1', null);
      const sw = await switcher(p); const c = await cookies(ctx);
      log('ciudad · GPS negado · IP de Maracaibo: ve Delicias y San Francisco, con Delicias elegida', sorted(sw) === '["Tienda Delicias","Tienda San Francisco"]' && nameOf(c.wcmlim_selected_location_termid) === 'Tienda Delicias', JSON.stringify({ sw, sel: nameOf(c.wcmlim_selected_location_termid) }));
      log('la posición por IP va en sus propias cookies, no en las del GPS (no cuenta para el retiro)', !!c.ts_ip_lat && !c.wcmlim_user_lat && !c.wcmlim_user_lng && c.ts_geo === 'denied', JSON.stringify({ ts_ip_lat: c.ts_ip_lat, gps: c.wcmlim_user_lat || null, ts_geo: c.ts_geo }));
      const html = await p.content();
      log('el script de IPinfo de Multi Locations no se carga y el token no aparece en la página', !/wcmlim-autodetect-location-ipinfo/.test(html) && !/SECRETO-MLI-123/.test(html));
      log('el servidor consultó IPinfo una vez, con el token (el de Multi Locations, al no haber uno propio)', calls() === 1 && /token=SECRETO-MLI-123/.test(wpSoft('option get ts_test_ipinfo_url')), 'consultas=' + calls());
      await p.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
      const prods = await p.$$eval('ul.products li.product .woocommerce-loop-product__title, ul.products li.product h2', els => els.map(e => e.textContent.trim()));
      log('catálogo de la tienda elegida por IP (Delicias)', JSON.stringify(prods) === '["Producto A (Delicias y Chacao)"]', JSON.stringify(prods));
      await ctx.close();
    }

    // ---- 2. Misma IP, otro visitante: sale de la caché ----
    {
      const before = calls();
      const { ctx, p } = await visit(browser, '200.44.1.1', null);
      const sw = await switcher(p);
      log('misma IP otra vez: mismo resultado y sin consultar de nuevo a IPinfo (caché)', sorted(sw) === '["Tienda Delicias","Tienda San Francisco"]' && calls() === before, 'consultas=' + calls());
      await ctx.close();
    }

    // ---- 3. IP de Cabimas (sin tiendas a menos de 20 km) ----
    {
      const { ctx, p } = await visit(browser, '200.44.2.2', null);
      const sw = await switcher(p); const c = await cookies(ctx);
      log('ciudad · IP de Cabimas: sólo la tienda por defecto', JSON.stringify(sw) === '["Tienda Valencia"]' && nameOf(c.wcmlim_selected_location_termid) === 'Tienda Valencia', JSON.stringify({ sw, sel: nameOf(c.wcmlim_selected_location_termid) }));
      await ctx.close();
    }

    // ---- 4. IP sin resultado ----
    {
      const { ctx, p } = await visit(browser, '200.44.9.9', null);
      const sw = await switcher(p); const c = await cookies(ctx);
      const before = calls();
      await p.goto(BASE + '/shop/', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
      log('IP sin resultado: tienda por defecto, y se recuerda para no consultar en cada página', JSON.stringify(sw) === '["Tienda Valencia"]' && c.ts_ip === 'none' && calls() === before, JSON.stringify({ sw, ts_ip: c.ts_ip, consultas: calls() - before }));
      await ctx.close();
    }

    // ---- 5. El aviso del navegador nunca se contesta ----
    {
      const { ctx, p } = await visit(browser, '200.44.3.3', 'silent', { wait: 20000 });
      const sw = await switcher(p); const c = await cookies(ctx);
      log('ciudad · el cliente no contesta al aviso de ubicación: a los segundos se usa la IP (Caracas → Chacao)', JSON.stringify(sw) === '["Tienda Chacao"]' && !!c.ts_ip_lat, JSON.stringify({ sw, ts_ip_lat: c.ts_ip_lat }));
      await ctx.close();
    }

    // ---- 6. Ya había negado el GPS antes de activar la IP ----
    {
      const { ctx, p } = await visit(browser, '200.44.3.3', null, { cookies: [{ name: 'ts_geo', value: 'denied' }] });
      const sw = await switcher(p);
      log('ciudad · con la cookie de "GPS negado" de antes: se prueba la IP una vez', JSON.stringify(sw) === '["Tienda Chacao"]', JSON.stringify(sw));
      await ctx.close();
    }

    // ---- 7. El GPS manda sobre la IP ----
    {
      const before = calls();
      const { ctx, p } = await visit(browser, '200.44.1.1', { latitude: 10.4806, longitude: -66.9036 });
      const sw = await switcher(p); const c = await cookies(ctx);
      log('con GPS (Caracas) e IP de Maracaibo: manda el GPS y no se consulta la IP', JSON.stringify(sw) === '["Tienda Chacao"]' && !c.ts_ip_lat && calls() === before, JSON.stringify({ sw, ts_ip_lat: c.ts_ip_lat || null, consultas: calls() - before }));
      await ctx.close();
    }

    // ---- 8. GPS después de la IP ----
    {
      const { ctx, p } = await visit(browser, '200.44.3.3', null);
      await ctx.grantPermissions(['geolocation']); await ctx.setGeolocation({ latitude: 10.6427, longitude: -71.6125 });
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 }).catch(() => {}), p.click('.ts-use-gps')]);
      const sw = await switcher(p);
      log('IP de Caracas y luego "Usar mi ubicación" en Maracaibo: pasa a las tiendas de Maracaibo', sorted(sw) === '["Tienda Delicias","Tienda San Francisco"]', JSON.stringify(sw));
      await ctx.close();
    }

    // ---- 9. Nonce ----
    {
      const ctx = await newCtx(browser, '200.44.1.1', null);
      const r = await ctx.request.post(BASE + '/wp-admin/admin-ajax.php', { form: { action: 'ts_ip_locate', nonce: 'x' } });
      log('la consulta por IP exige el nonce de la página', r.status() === 403, 'HTTP ' + r.status());
      await ctx.close();
    }

    // ---- 9b. Tope de consultas por hora (IP falsificadas en X-Forwarded-For) ----
    {
      wp('option update ts_test_ipinfo_limit 0');
      const before = calls();
      const { ctx, p } = await visit(browser, '200.44.8.8', null, { wait: 4000 });
      const sw = await switcher(p); const c = await cookies(ctx);
      log('tope por hora alcanzado: no se consulta IPinfo, tienda por defecto y no se marca "sin resultado" (reintenta luego)', calls() === before && JSON.stringify(sw) === '["Tienda Valencia"]' && !c.ts_ip && !c.ts_ip_lat, JSON.stringify({ consultas: calls() - before, sw, ts_ip: c.ts_ip || null }));
      await ctx.close();
      wpSoft('option delete ts_test_ipinfo_limit');
    }

    // ---- 10. Opción desactivada ----
    {
      setting('ip_fallback', 'no');
      const before = calls();
      const { ctx, p } = await visit(browser, '200.44.1.1', null);
      const sw = await switcher(p); const c = await cookies(ctx);
      const html = await p.content();
      log('desactivada: sin ubicación ve sólo la tienda por defecto y no se consulta IPinfo', JSON.stringify(sw) === '["Tienda Valencia"]' && !c.ts_ip_lat && calls() === before, JSON.stringify({ sw, consultas: calls() - before }));
      log('desactivada: el script de Multi Locations vuelve a cargarse (es su ajuste)', /wcmlim-autodetect-location-ipinfo/.test(html));
      await ctx.close();
      setting('ip_fallback', 'yes');
    }

    // ---- 11. Modo por estado ----
    setting('visibility_mode', 'state'); setting('detect_state', 'gps'); setting('ask_if_gps_fails', 'yes'); setting('ipinfo_token', 'PROPIO-456');
    resetIpinfo();
    {
      const { ctx, p } = await visit(browser, '200.44.1.1', null);
      const c = await cookies(ctx);
      const modal = await p.$eval('#ts-state-modal', e => !e.hidden).catch(() => false);
      log('estado · GPS negado · IP de Maracaibo: elige Zulia sin preguntar', c.ts_estado === 'ZU' && c.ts_estado_src === 'ip' && !modal, JSON.stringify({ estado: c.ts_estado, src: c.ts_estado_src, modal }));
      log('estado · con token propio, se usa ése', /token=PROPIO-456/.test(wpSoft('option get ts_test_ipinfo_url')));
      const sw = await switcher(p);
      log('estado · el selector ofrece las tiendas de Zulia', sw.length > 0 && sw.every(t => /Delicias|San Francisco/.test(t)), JSON.stringify(sw));
      await ctx.close();
    }
    {
      const ctx = await newCtx(browser, '200.44.9.9', null);
      const p = await ctx.newPage();
      await p.goto(BASE + '/', { waitUntil: 'networkidle' });
      const shown = await waitFor(() => p.$eval('#ts-state-modal', e => !e.hidden).catch(() => false), 10000);
      const c = await cookies(ctx);
      log('estado · IP sin resultado: se le pregunta el estado como antes', shown && !c.ts_estado, JSON.stringify({ modal: shown, estado: c.ts_estado || null }));
      await ctx.close();
    }
  } finally {
    wp(`option update ts_settings '${saved}' --format=json`);
    savedMli.on ? wp(`option update wcmlim_enable_autodetect_location_with_ipinfo '${savedMli.on}'`) : wpSoft('option delete wcmlim_enable_autodetect_location_with_ipinfo');
    savedMli.token ? wp(`option update wcmlim_ipinfo_api_token '${savedMli.token}'`) : wpSoft('option delete wcmlim_ipinfo_api_token');
    try { fs.unlinkSync(MU); } catch (e) {}
    wpSoft('option delete ts_test_ipinfo_calls'); wpSoft('option delete ts_test_ipinfo_limit'); wpSoft('option delete ts_test_ipinfo_url');
    await browser.close();
  }
  const ok = results.filter(Boolean).length;
  console.log(`\n${ok}/${results.length} pruebas OK`);
  process.exit(ok === results.length ? 0 : 1);
})();
