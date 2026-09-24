<?php
/**
 * Pruebas de la regla de retiro (TS_Packages::enrich_packages) sin navegador.
 *
 *   wp eval-file regla-retiro.php
 *
 * Construye paquetes a mano (una tienda por paquete) y comprueba, para cada combinación de
 * criterio (radio / municipio / ambos) y alcance (una tienda / todas), qué tiendas ofrecen retiro
 * y por qué. La posición del cliente y la tienda elegida se simulan con las cookies que lee el
 * plugin. Deja los ajustes como estaban.
 */

$ids = array();
foreach ( get_terms( array( 'taxonomy' => 'locations', 'hide_empty' => false ) ) as $t ) {
	$ids[ $t->name ] = (int) $t->term_id;
}
$DEL = $ids['Tienda Delicias'];
$SF  = $ids['Tienda San Francisco'];
$CHA = $ids['Tienda Chacao'];

$saved_settings = get_option( 'ts_settings' );
$saved_munis    = get_option( TS_Municipios::OPTION, null );
$results        = array();

$set = function ( $criterion, $scope ) {
	$s = (array) get_option( 'ts_settings', array() );
	$s['pickup_criterion'] = $criterion;
	$s['pickup_scope']     = $scope;
	update_option( 'ts_settings', $s );
	// TS_Settings guarda en memoria: se fuerza la relectura.
	$r = new ReflectionProperty( 'TS_Settings', 'cache' );
	$r->setAccessible( true );
	$r->setValue( null, null );
};
$gps = function ( $lat = null, $lng = null ) {
	unset( $_COOKIE['wcmlim_user_lat'], $_COOKIE['wcmlim_user_lng'] );
	if ( null !== $lat ) {
		$_COOKIE['wcmlim_user_lat'] = (string) $lat;
		$_COOKIE['wcmlim_user_lng'] = (string) $lng;
	}
};
$selected = function ( $term_id ) {
	unset( $_COOKIE['wcmlim_selected_location_termid'] );
	if ( $term_id ) {
		$_COOKIE['wcmlim_selected_location_termid'] = (string) $term_id;
	}
};
$run = function ( array $locs, $state, $city ) {
	$packages = array();
	foreach ( $locs as $id ) {
		$packages[] = array(
			'contents'         => array(),
			'shipping_term_id' => $id,
			'destination'      => array( 'country' => 'VE', 'state' => $state, 'city' => $city ),
		);
	}
	$out = array();
	foreach ( TS_Packages::enrich_packages( $packages ) as $p ) {
		$out[ TS_Locations::name( $p['ts_location_id'] ) ] = $p['ts_pickup_eligible'] ? $p['ts_pickup_reason'] : '-';
	}
	return $out;
};
$check = function ( $name, $got, $want ) use ( &$results ) {
	$ok        = $got === $want;
	$results[] = $ok;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ' — ' . wp_json_encode( $got ) . ( $ok ? '' : ' (esperado ' . wp_json_encode( $want ) . ')' ) . "\n";
};

// Delicias acepta Maracaibo y San Francisco (área metropolitana); San Francisco, sólo su municipio.
update_option( TS_Municipios::OPTION, array( $DEL => array( 'ZU:maracaibo', 'ZU:sanfrancisco' ), $SF => array( 'ZU:sanfrancisco' ) ) );
$MARACAIBO = 'Municipio Maracaibo (Maracaibo)';
$CABIMAS   = 'Municipio Cabimas (Cabimas)';

// ---- Criterio "municipio o radio" (por defecto) ----
$set( 'both', 'one' ); $gps(); $selected( 0 );
$check( 'municipio o radio · sin GPS · Maracaibo → retiro por municipio', $run( array( $DEL ), 'ZU', $MARACAIBO ), array( 'Tienda Delicias' => 'municipio' ) );
$check( 'municipio o radio · sin GPS · Cabimas (sin tienda) → envío', $run( array( $DEL ), 'ZU', $CABIMAS ), array( 'Tienda Delicias' => '-' ) );
$check( 'municipio o radio · sin GPS · texto libre "Maracaibo"', $run( array( $DEL ), 'ZU', 'Maracaibo' ), array( 'Tienda Delicias' => 'municipio' ) );
$check( 'municipio o radio · área metropolitana: San Francisco retira en Delicias', $run( array( $DEL ), 'ZU', 'Municipio San Francisco (San Francisco)' ), array( 'Tienda Delicias' => 'municipio' ) );
$gps( 10.6427, -71.6125 ); // 3,1 km de Delicias.
$check( 'municipio o radio · GPS a 3 km · Cabimas → retiro por radio', $run( array( $DEL ), 'ZU', $CABIMAS ), array( 'Tienda Delicias' => 'radio' ) );
$gps( 10.1757, -68.0028 ); // Valencia, a 400 km.
$check( 'municipio o radio · GPS lejos pero municipio Maracaibo → retiro por municipio', $run( array( $DEL ), 'ZU', $MARACAIBO ), array( 'Tienda Delicias' => 'municipio' ) );

// ---- Sólo radio (el comportamiento de antes) ----
$set( 'radius', 'one' ); $gps();
$check( 'sólo radio · sin GPS · Maracaibo → envío', $run( array( $DEL ), 'ZU', $MARACAIBO ), array( 'Tienda Delicias' => '-' ) );
$gps( 10.6427, -71.6125 );
$check( 'sólo radio · GPS a 3 km → retiro por radio', $run( array( $DEL ), 'ZU', $CABIMAS ), array( 'Tienda Delicias' => 'radio' ) );

// ---- Sólo municipio ----
$set( 'municipio', 'one' );
$check( 'sólo municipio · GPS a 3 km pero Cabimas → envío', $run( array( $DEL ), 'ZU', $CABIMAS ), array( 'Tienda Delicias' => '-' ) );
$gps();
$check( 'sólo municipio · sin GPS · Maracaibo → retiro', $run( array( $DEL ), 'ZU', $MARACAIBO ), array( 'Tienda Delicias' => 'municipio' ) );
$check( 'sólo municipio · tienda sin municipios (Chacao vacío) → envío', ( function () use ( $run, $CHA ) {
	update_option( TS_Municipios::OPTION, array_replace( (array) get_option( TS_Municipios::OPTION ), array( $CHA => array() ) ) );
	return $run( array( $CHA ), 'DC', 'Municipio Libertador (Caracas)' );
} )(), array( 'Tienda Chacao' => '-' ) );

// ---- Alcance: dos tiendas del pedido que califican ----
$both_sf = 'Municipio San Francisco (San Francisco)'; // Lo aceptan Delicias y San Francisco.
$set( 'municipio', 'one' ); $gps(); $selected( $SF );
$check( 'una tienda · sin GPS · gana la elegida en el selector', $run( array( $DEL, $SF ), 'ZU', $both_sf ), array( 'Tienda Delicias' => '-', 'Tienda San Francisco' => 'municipio' ) );
$selected( 0 );
$check( 'una tienda · sin GPS ni elegida · gana la primera', $run( array( $DEL, $SF ), 'ZU', $both_sf ), array( 'Tienda Delicias' => 'municipio', 'Tienda San Francisco' => '-' ) );
$gps( 10.6427, -71.6125 ); $selected( $SF ); // Delicias a 3,1 km; San Francisco más lejos.
$check( 'una tienda · con GPS · gana la más cercana aunque haya otra elegida', $run( array( $DEL, $SF ), 'ZU', $both_sf ), array( 'Tienda Delicias' => 'municipio', 'Tienda San Francisco' => '-' ) );
$set( 'municipio', 'all' );
$check( 'todas · las dos tiendas ofrecen retiro', $run( array( $DEL, $SF ), 'ZU', $both_sf ), array( 'Tienda Delicias' => 'municipio', 'Tienda San Francisco' => 'municipio' ) );
$check( 'todas · sólo las que califican', $run( array( $DEL, $SF ), 'ZU', $MARACAIBO ), array( 'Tienda Delicias' => 'municipio', 'Tienda San Francisco' => '-' ) );

// ---- Restaurar ----
$gps(); $selected( 0 );
update_option( 'ts_settings', $saved_settings );
if ( null === $saved_munis ) {
	delete_option( TS_Municipios::OPTION );
} else {
	update_option( TS_Municipios::OPTION, $saved_munis );
}
$ok = count( array_filter( $results ) );
echo "\n{$ok}/" . count( $results ) . " pruebas OK\n";
