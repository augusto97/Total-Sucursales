<?php
/**
 * Funciones auxiliares globales.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distancia Haversine en kilómetros.
 */
function ts_haversine_km( $lat1, $lng1, $lat2, $lng2 ) {
	$lat1 = (float) $lat1;
	$lng1 = (float) $lng1;
	$lat2 = (float) $lat2;
	$lng2 = (float) $lng2;

	$earth = 6371.0;
	$dlat  = deg2rad( $lat2 - $lat1 );
	$dlng  = deg2rad( $lng2 - $lng1 );
	$a     = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
	$c     = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

	return $earth * $c;
}

/**
 * Valida un par de coordenadas.
 */
function ts_valid_coords( $lat, $lng ) {
	if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
		return false;
	}
	$lat = (float) $lat;
	$lng = (float) $lng;
	if ( 0.0 === $lat && 0.0 === $lng ) {
		return false;
	}
	return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

/**
 * Establece una cookie (servidor) y la refleja en $_COOKIE para el request actual.
 * Mismo comportamiento que setLocation() de MLI (HttpOnly, path /).
 */
function ts_set_cookie( $name, $value, $expire_seconds = null ) {
	if ( null === $expire_seconds ) {
		$expire_seconds = (int) apply_filters( 'ts_cookie_lifetime', DAY_IN_SECONDS * 30 );
	}
	if ( ! headers_sent() ) {
		setcookie( $name, (string) $value, time() + $expire_seconds, '/', '', is_ssl(), true );
	}
	$_COOKIE[ $name ] = (string) $value;
}

/**
 * Borra una cookie.
 */
function ts_delete_cookie( $name ) {
	if ( ! headers_sent() ) {
		setcookie( $name, '', time() - YEAR_IN_SECONDS, '/', '', is_ssl(), true );
	}
	unset( $_COOKIE[ $name ] );
}

/**
 * Lee una cookie (null si no existe o vale '-1', igual que getLocation() de MLI).
 */
function ts_get_cookie( $name ) {
	if ( ! isset( $_COOKIE[ $name ] ) ) {
		return null;
	}
	$value = wp_unslash( $_COOKIE[ $name ] );
	return ( '-1' === $value || '' === $value ) ? null : $value;
}

/**
 * Lista de estados de Venezuela (código => nombre) desde WooCommerce.
 */
function ts_get_ve_states() {
	if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
		return array();
	}
	$states = WC()->countries->get_states( 'VE' );
	return is_array( $states ) ? $states : array();
}

/**
 * Nombre de un estado por código.
 */
function ts_state_name( $code ) {
	$states = ts_get_ve_states();
	return isset( $states[ $code ] ) ? $states[ $code ] : $code;
}

/**
 * Clave comparable de un texto: sin acentos, minúsculas, sólo letras y números.
 */
function ts_key( $text ) {
	$text = remove_accents( (string) $text );
	$text = strtolower( trim( $text ) );
	return preg_replace( '/[^a-z0-9]+/', '', $text );
}

/**
 * Mapa de equivalencias para reconocer un estado: código y nombre apuntan al mismo código.
 *
 * @return array<string,string>
 */
function ts_state_lookup() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	foreach ( ts_get_ve_states() as $code => $name ) {
		$map[ strtoupper( $code ) ] = $code;
		$key = ts_key( $name );
		if ( '' !== $key ) {
			$map[ $key ] = $code;
		}
	}
	return $map;
}

/**
 * Normaliza un estado a su código de WooCommerce.
 *
 * Acepta el código ("ZU"), el formato país:estado ("VE:ZU") y el nombre completo con o sin
 * acentos ("Zulia", "Anzoategui"), porque el autocompletado de direcciones de Multi Locations
 * guarda el nombre largo del estado en lugar del código.
 */
function ts_normalize_state( $code ) {
	$raw = trim( (string) $code );
	if ( '' === $raw ) {
		return '';
	}
	// Algunas instalaciones guardan "VE:ZU".
	foreach ( array( ':', '|' ) as $sep ) {
		if ( false !== strpos( $raw, $sep ) ) {
			$parts = explode( $sep, $raw );
			$raw   = trim( end( $parts ) );
		}
	}
	$map = ts_state_lookup();
	$up  = strtoupper( $raw );
	if ( isset( $map[ $up ] ) ) {
		return $map[ $up ];
	}
	$key = ts_key( $raw );
	if ( '' !== $key && isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}
	return $up;
}

/**
 * Formatea kilómetros para mostrar.
 */
function ts_format_km( $km ) {
	if ( null === $km ) {
		return '';
	}
	return number_format_i18n( (float) $km, 1 ) . ' km';
}
