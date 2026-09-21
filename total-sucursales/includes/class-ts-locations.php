<?php
/**
 * Repositorio de sucursales (taxonomía `locations` de MLI) con caché.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Locations {

	const TRANSIENT = 'ts_locations_index_v2';

	/** @var array|null */
	private static $index = null;

	public static function init() {
		foreach ( array( 'created_locations', 'edited_locations', 'delete_locations' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}
	}

	public static function flush_cache() {
		self::$index = null;
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Todas las sucursales (padre 0), indexadas por term_id.
	 *
	 * @return array<int,array{id:int,name:string,slug:string,state:string,city:string,lat:float|null,lng:float|null,has_coords:bool,address:string,phone:string,hours:string}>
	 */
	public static function all() {
		if ( null !== self::$index ) {
			return self::$index;
		}
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			self::$index = self::hydrate( $cached );
			return self::$index;
		}

		$index = array();
		if ( taxonomy_exists( 'locations' ) ) {
			TS_Location_Filter::$bypass_terms = true;
			$terms = get_terms( array(
				'taxonomy'   => 'locations',
				'hide_empty' => false,
				'parent'     => 0,
			) );
			TS_Location_Filter::$bypass_terms = false;
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$index[ (int) $term->term_id ] = self::build_row( $term );
				}
			}
		}

		set_transient( self::TRANSIENT, $index, HOUR_IN_SECONDS * 12 );
		self::$index = self::hydrate( $index );
		return self::$index;
	}

	/**
	 * Normaliza el estado de cada sucursal al leer.
	 *
	 * El transient guarda el valor tal cual lo escribió Multi Locations; la conversión a código
	 * de WooCommerce se hace en cada request para que un caché creado antes de que WooCommerce
	 * cargara sus estados no deje códigos mal resueltos.
	 */
	private static function hydrate( array $index ) {
		foreach ( $index as $id => $row ) {
			$raw                    = isset( $row['state_raw'] ) ? $row['state_raw'] : '';
			$index[ $id ]['state']  = ts_normalize_state( $raw );
		}
		return $index;
	}

	private static function build_row( $term ) {
		$id    = (int) $term->term_id;
		$lat   = get_term_meta( $id, 'wcmlim_lat', true );
		$lng   = get_term_meta( $id, 'wcmlim_lng', true );
		$state = get_term_meta( $id, 'wcmlim_administrative_area_level_1', true );
		if ( '' === $state ) {
			$state = get_term_meta( $id, 'wcmlim_country_state', true ); // rama alternativa de guardado en MLI
		}
		$has = ts_valid_coords( $lat, $lng );
		$state = trim( (string) $state );

		$street = trim( get_term_meta( $id, 'wcmlim_street_number', true ) . ' ' . get_term_meta( $id, 'wcmlim_route', true ) );
		if ( '' === $street ) {
			$street = (string) get_term_meta( $id, 'wcmlim_street_address', true );
		}
		$city = (string) get_term_meta( $id, 'wcmlim_locality', true );
		if ( '' === $city ) {
			$city = (string) get_term_meta( $id, 'wcmlim_city', true );
		}

		return array(
			'id'         => $id,
			'name'       => $term->name,
			'slug'       => $term->slug,
			'state_raw'  => $state,
			'state'      => ts_normalize_state( $state ),
			'city'       => $city,
			'lat'        => $has ? (float) $lat : null,
			'lng'        => $has ? (float) $lng : null,
			'has_coords' => $has,
			'address'    => $street,
			'phone'      => (string) get_term_meta( $id, 'wcmlim_phone', true ),
			'hours'      => trim( get_term_meta( $id, 'wcmlim_start_time', true ) . ' - ' . get_term_meta( $id, 'wcmlim_end_time', true ), ' -' ),
		);
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	public static function name( $id ) {
		$l = self::get( $id );
		return $l ? $l['name'] : '';
	}

	public static function all_ids() {
		return array_keys( self::all() );
	}

	/**
	 * IDs de sucursales de un estado.
	 */
	public static function ids_by_state( $state ) {
		$state = ts_normalize_state( $state );
		if ( '' === $state ) {
			return array();
		}
		$ids = array();
		foreach ( self::all() as $id => $l ) {
			if ( $l['state'] === $state ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Sucursales sin estado o sin coordenadas, para el diagnóstico del panel de ajustes.
	 *
	 * @return array{no_state:int[],no_coords:int[]}
	 */
	public static function incomplete() {
		$no_state  = array();
		$no_coords = array();
		foreach ( self::all() as $id => $l ) {
			if ( '' === $l['state'] ) {
				$no_state[] = $id;
			}
			if ( ! $l['has_coords'] ) {
				$no_coords[] = $id;
			}
		}
		return array( 'no_state' => $no_state, 'no_coords' => $no_coords );
	}

	/**
	 * Estados (código => nombre) que tienen al menos una sucursal.
	 */
	public static function states_with_locations() {
		$names = ts_get_ve_states();
		$out   = array();
		foreach ( self::all() as $l ) {
			if ( '' !== $l['state'] && ! isset( $out[ $l['state'] ] ) ) {
				$out[ $l['state'] ] = isset( $names[ $l['state'] ] ) ? $names[ $l['state'] ] : $l['state'];
			}
		}
		asort( $out );
		return $out;
	}

	/**
	 * Distancia en km desde unas coordenadas a la sucursal. null si la sucursal no tiene coordenadas.
	 */
	public static function distance_km( $id, $lat, $lng ) {
		$l = self::get( $id );
		if ( ! $l || ! $l['has_coords'] || ! ts_valid_coords( $lat, $lng ) ) {
			return null;
		}
		return ts_haversine_km( $lat, $lng, $l['lat'], $l['lng'] );
	}

	/**
	 * Sucursal más cercana. Devuelve array{id,distance} o null.
	 *
	 * @param float      $lat
	 * @param float      $lng
	 * @param array|null $only_ids Limitar a estos IDs.
	 */
	public static function nearest( $lat, $lng, $only_ids = null ) {
		if ( ! ts_valid_coords( $lat, $lng ) ) {
			return null;
		}
		$best = null;
		foreach ( self::all() as $id => $l ) {
			if ( is_array( $only_ids ) && ! in_array( $id, $only_ids, true ) ) {
				continue;
			}
			if ( ! $l['has_coords'] ) {
				continue;
			}
			$d = ts_haversine_km( $lat, $lng, $l['lat'], $l['lng'] );
			if ( null === $best || $d < $best['distance'] ) {
				$best = array( 'id' => $id, 'distance' => $d );
			}
		}
		return $best;
	}

	/**
	 * Lista de términos tal como la construye MLI para calcular el índice de la cookie
	 * `wcmlim_selected_location` (mismos argumentos: parent 0, hide_empty false, exclude = opción).
	 *
	 * @return WP_Term[]
	 */
	public static function mli_term_list() {
		if ( ! taxonomy_exists( 'locations' ) ) {
			return array();
		}
		$args = array( 'taxonomy' => 'locations', 'hide_empty' => false, 'parent' => 0 );
		$excl = get_option( 'wcmlim_exclude_locations_from_frontend' ); // pasa por nuestro filtro pre_option
		$excl = TS_Location_Filter::normalize_ids( $excl );
		if ( ! empty( $excl ) ) {
			$args['exclude'] = $excl;
		}
		$terms = get_terms( $args );
		return is_wp_error( $terms ) ? array() : array_values( $terms );
	}

	/**
	 * Índice posicional de una sucursal en la lista de MLI (o null).
	 */
	public static function mli_index_of( $term_id ) {
		foreach ( self::mli_term_list() as $k => $term ) {
			if ( (int) $term->term_id === (int) $term_id ) {
				return $k;
			}
		}
		return null;
	}

	/**
	 * La inversa de mli_index_of(): la sucursal que ocupa esa posición en la lista de MLI.
	 *
	 * @param string|int $index Índice posicional tal y como viaja en las cookies de MLI.
	 * @return int term_id, o 0 si la posición no existe.
	 */
	public static function mli_term_at( $index ) {
		if ( ! is_numeric( $index ) || (int) $index < 0 ) {
			return 0;
		}
		$list = self::mli_term_list();
		$key  = (int) $index;
		return isset( $list[ $key ] ) ? (int) $list[ $key ]->term_id : 0;
	}
}
