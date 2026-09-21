<?php
// Reproduce el escenario del cliente y prueba hipótesis sobre por qué el switcher queda vacío.
function ts_reset_locations() {
	foreach ( get_terms( array( 'taxonomy' => 'locations', 'hide_empty' => false ) ) as $t ) {
		wp_delete_term( $t->term_id, 'locations' );
	}
	delete_transient( 'ts_locations_index' );
}
function ts_mk( $name, $state, $lat, $lng ) {
	$t  = wp_insert_term( $name, 'locations' );
	$id = (int) ( is_array( $t ) ? $t['term_id'] : $t );
	update_term_meta( $id, 'wcmlim_administrative_area_level_1', $state );
	update_term_meta( $id, 'wcmlim_country', 'VE' );
	if ( $lat ) { update_term_meta( $id, 'wcmlim_lat', $lat ); update_term_meta( $id, 'wcmlim_lng', $lng ); }
	return $id;
}
function ts_visible( $state_cookie ) {
	$_COOKIE['ts_estado'] = $state_cookie;
	TS_Location_Filter::flush_request_cache();
	TS_Locations::flush_cache();
	$terms = TS_Locations::mli_term_list();
	return wp_list_pluck( $terms, 'name' );
}

ts_reset_locations();
$ids = array(
	'LA LIMPIA'       => ts_mk( 'LA LIMPIA', 'ZU', 10.665, -71.63 ),
	'SANTA RITA'      => ts_mk( 'SANTA RITA', 'ZU', 10.53, -71.52 ),
	'TOTAL NORTE'     => ts_mk( 'TOTAL NORTE', 'ZU', 10.70, -71.61 ),
	'PARQUE VALENCIA' => ts_mk( 'PARQUE VALENCIA', 'CA', 10.18, -68.00 ),
);
echo "IDs: " . wp_json_encode( $ids ) . "\n\n";

echo "--- H0: sin exclusiones manuales, estados como códigos ---\n";
update_option( 'wcmlim_exclude_locations_from_frontend', array() );
echo "  estado CA  => " . wp_json_encode( ts_visible( 'CA' ) ) . "\n";
echo "  estado ZU  => " . wp_json_encode( ts_visible( 'ZU' ) ) . "\n";
echo "  todas      => " . wp_json_encode( ts_visible( '__ALL__' ) ) . "\n\n";

echo "--- H1: PARQUE VALENCIA en 'Hide Locations From Frontend' ---\n";
update_option( 'wcmlim_exclude_locations_from_frontend', array( $ids['PARQUE VALENCIA'] ) );
echo "  estado CA  => " . wp_json_encode( ts_visible( 'CA' ) ) . "\n";
echo "  todas      => " . wp_json_encode( ts_visible( '__ALL__' ) ) . "\n\n";

echo "--- H2: PARQUE VALENCIA sin estado (autocompletado dejó el campo vacío) ---\n";
update_option( 'wcmlim_exclude_locations_from_frontend', array() );
update_term_meta( $ids['PARQUE VALENCIA'], 'wcmlim_administrative_area_level_1', '' );
echo "  estado CA  => " . wp_json_encode( ts_visible( 'CA' ) ) . "\n";
echo "  todas      => " . wp_json_encode( ts_visible( '__ALL__' ) ) . "\n\n";

echo "--- H3: estado guardado como NOMBRE ('Carabobo') en vez de código ---\n";
update_term_meta( $ids['PARQUE VALENCIA'], 'wcmlim_administrative_area_level_1', 'Carabobo' );
echo "  estado CA  => " . wp_json_encode( ts_visible( 'CA' ) ) . "\n";
echo "  todas      => " . wp_json_encode( ts_visible( '__ALL__' ) ) . "\n";
