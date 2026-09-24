<?php
/**
 * Municipios de Venezuela para el retiro por municipio.
 *
 * Los datos vienen del plugin States and Municipalities of Venezuela (SMV): 335 municipios con el
 * formato "Municipio Maracaibo (Maracaibo)", es decir, prefijo traducido + nombre + (capital). Ese
 * texto es el que queda como ciudad del cliente en el checkout.
 *
 * Cada municipio se identifica con una clave estable ESTADO:nombre ("ZU:maracaibo") que no depende
 * del idioma ni de acentos, para poder comparar el municipio del cliente con los que acepta cada
 * tienda.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Municipios {

	/** Opción: term_id de la tienda => claves de municipio que pueden retirar allí. */
	const OPTION = 'ts_pickup_municipios';

	/** @var array|null */
	private static $index = null;

	/**
	 * Municipios por estado: [ 'ZU' => [ 'ZU:maracaibo' => ['name' => 'Maracaibo', 'capital' => 'Maracaibo'], ... ] ].
	 *
	 * @return array<string,array<string,array{name:string,capital:string}>>
	 */
	public static function all() {
		if ( null !== self::$index ) {
			return self::$index;
		}
		self::$index = array();
		$raw         = class_exists( 'TS_Blocks' ) ? TS_Blocks::municipalities() : array();
		foreach ( (array) $raw as $state => $list ) {
			$state = strtoupper( (string) $state );
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $label ) {
				$parsed = self::parse( $label );
				if ( '' === $parsed['name'] ) {
					continue;
				}
				self::$index[ $state ][ self::make_key( $state, $parsed['name'] ) ] = $parsed;
			}
		}
		return self::$index;
	}

	public static function has_data() {
		return ! empty( self::all() );
	}

	/**
	 * "Municipio Maracaibo (Maracaibo)" => [ name => Maracaibo, capital => Maracaibo ].
	 * También acepta el texto sin prefijo o sin capital, y "Chaguaramas(Chaguaramas)" sin espacio.
	 *
	 * @return array{name:string,capital:string}
	 */
	public static function parse( $label ) {
		$label = trim( wp_strip_all_tags( (string) $label ) );
		$prefixes = array_unique( array_filter( array(
			'Municipio',
			'Municipality',
			__( 'Municipality', 'states-and-municipalities-of-venezuela-for-woocommerce' ),
		) ) );
		foreach ( $prefixes as $p ) {
			if ( 0 === stripos( $label, $p . ' ' ) ) {
				$label = trim( substr( $label, strlen( $p ) + 1 ) );
				break;
			}
		}
		$capital = '';
		if ( preg_match( '/^(.*?)\s*\((.*)\)\s*$/u', $label, $m ) ) {
			$label   = trim( $m[1] );
			$capital = trim( $m[2] );
		}
		return array( 'name' => $label, 'capital' => $capital );
	}

	public static function make_key( $state, $name ) {
		return strtoupper( (string) $state ) . ':' . ts_key( $name );
	}

	/**
	 * Clave del municipio a partir del estado y de la "ciudad" que dio el cliente.
	 *
	 * La ciudad puede ser el texto de SMV ("Municipio Lagunillas (Ciudad Ojeda)") o un texto libre
	 * ("Maracaibo", "Ciudad Ojeda") si el tema no usa su desplegable: se busca por nombre del municipio
	 * y, si no, por su capital.
	 *
	 * @return string Clave, o '' si no se reconoce.
	 */
	public static function resolve( $state, $city ) {
		$state = ts_normalize_state( $state );
		$city  = trim( (string) $city );
		if ( '' === $state || '' === $city ) {
			return '';
		}
		$list = self::all();
		if ( empty( $list[ $state ] ) ) {
			return '';
		}
		$parsed = self::parse( $city );
		$name   = ts_key( $parsed['name'] );
		$cap    = ts_key( $parsed['capital'] );

		foreach ( $list[ $state ] as $key => $m ) {
			if ( ts_key( $m['name'] ) === $name ) {
				return $key;
			}
		}
		// Texto libre: "Ciudad Ojeda" es la capital del municipio Lagunillas.
		foreach ( $list[ $state ] as $key => $m ) {
			$mc = ts_key( $m['capital'] );
			if ( '' !== $mc && ( $mc === $name || ( '' !== $cap && $mc === $cap ) ) ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Nombre legible de una clave: "Maracaibo (ZU)".
	 */
	public static function label( $key, $with_state = true ) {
		list( $state ) = array_pad( explode( ':', (string) $key, 2 ), 1, '' );
		$list = self::all();
		$name = isset( $list[ $state ][ $key ] ) ? $list[ $state ][ $key ]['name'] : (string) $key;
		return $with_state ? $name . ' (' . $state . ')' : $name;
	}

	/**
	 * Municipio del cliente según el destino del paquete (dirección de envío o, si no envía a otra
	 * dirección, la de facturación: WooCommerce ya copia una en la otra).
	 */
	public static function from_destination( $destination ) {
		if ( ! is_array( $destination ) ) {
			return '';
		}
		if ( isset( $destination['country'] ) && '' !== $destination['country'] && 'VE' !== strtoupper( $destination['country'] ) ) {
			return '';
		}
		return self::resolve( $destination['state'] ?? '', $destination['city'] ?? '' );
	}

	/* ---------------------------------------------------------------------
	 * Municipios por tienda
	 * ------------------------------------------------------------------ */

	/**
	 * Municipios configurados por tienda (sólo las tiendas que el administrador guardó).
	 *
	 * @return array<int,string[]>
	 */
	public static function saved() {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Municipio que se propone por defecto para una tienda: el que coincide con la ciudad de su ficha.
	 *
	 * Se usa la ciudad y no el nombre de la tienda: "SANTA RITA" puede estar en Maracaibo aunque
	 * exista un municipio Santa Rita.
	 *
	 * @return string[]
	 */
	public static function default_for_location( $term_id ) {
		$loc = TS_Locations::get( $term_id );
		if ( ! $loc || '' === $loc['state'] || '' === trim( (string) $loc['city'] ) ) {
			return array();
		}
		$key = self::resolve( $loc['state'], $loc['city'] );
		return '' !== $key ? array( $key ) : array();
	}

	/**
	 * Municipios desde los que se puede retirar en una tienda.
	 *
	 * @return string[]
	 */
	public static function for_location( $term_id ) {
		$saved = self::saved();
		if ( array_key_exists( (int) $term_id, $saved ) ) {
			return array_values( array_filter( (array) $saved[ (int) $term_id ], 'strlen' ) );
		}
		return self::default_for_location( $term_id );
	}

	public static function is_configured( $term_id ) {
		return array_key_exists( (int) $term_id, self::saved() );
	}

	/**
	 * Limpia lo que llega del formulario de ajustes: sólo claves de municipio conocidas.
	 *
	 * @param mixed $raw [ term_id => [ claves ] ].
	 * @return array<int,string[]>
	 */
	public static function sanitize( $raw ) {
		$out   = array();
		$known = array();
		foreach ( self::all() as $state => $list ) {
			foreach ( array_keys( $list ) as $k ) {
				$known[ $k ] = true;
			}
		}
		foreach ( (array) $raw as $term_id => $keys ) {
			$term_id = absint( $term_id );
			if ( ! $term_id ) {
				continue;
			}
			$clean = array();
			foreach ( (array) $keys as $k ) {
				$k = sanitize_text_field( (string) $k );
				if ( isset( $known[ $k ] ) ) {
					$clean[ $k ] = $k;
				}
			}
			$out[ $term_id ] = array_values( $clean );
		}
		return $out;
	}
}
