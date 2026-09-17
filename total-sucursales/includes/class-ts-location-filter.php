<?php
/**
 * Restringe las sucursales visibles al estado del cliente.
 *
 * MLI lee `wcmlim_exclude_locations_from_frontend` con get_option() en todos sus flujos de front
 * (switcher, popup, página de producto, carrito, closest-location...). Interceptando esa opción
 * con `pre_option_*` ocultamos las sucursales de otros estados sin tocar el plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Location_Filter {

	const OPTION = 'wcmlim_exclude_locations_from_frontend';

	/** @var bool Evita recursión al leer la opción real. */
	private static $bypass = false;

	/** @var array|null Caché por request del resultado. */
	private static $computed = null;

	public static function init() {
		add_filter( 'pre_option_' . self::OPTION, array( __CLASS__, 'filter_option' ), 10, 1 );
	}

	public static function flush_request_cache() {
		self::$computed = null;
	}

	/**
	 * Valor real guardado por el administrador (sin nuestro filtro).
	 *
	 * @return int[]
	 */
	public static function manual_exclusions() {
		self::$bypass = true;
		$raw          = get_option( self::OPTION );
		self::$bypass = false;
		return self::normalize_ids( $raw );
	}

	/**
	 * Convierte array / CSV / escalar a lista de enteros.
	 */
	public static function normalize_ids( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}
		if ( is_string( $raw ) ) {
			$maybe = maybe_unserialize( $raw );
			$raw   = is_array( $maybe ) ? $maybe : explode( ',', $raw );
		}
		$ids = array();
		foreach ( (array) $raw as $v ) {
			$v = (int) $v;
			if ( $v > 0 ) {
				$ids[] = $v;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * ¿Debe aplicarse el filtro por estado en este request?
	 */
	public static function applies() {
		if ( self::$bypass ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! apply_filters( 'ts_filter_on_rest', false ) ) {
			return false;
		}
		return (bool) apply_filters( 'ts_location_filter_applies', true );
	}

	/**
	 * IDs a excluir = exclusiones manuales + sucursales fuera del estado del cliente
	 * (sólo si el estado tiene al menos una sucursal).
	 *
	 * @return int[]
	 */
	public static function excluded_ids() {
		if ( null !== self::$computed ) {
			return self::$computed;
		}
		$manual = self::manual_exclusions();
		$state  = TS_Customer::get_state();

		$by_state = array();
		if ( '' !== $state ) {
			$in_state = TS_Locations::ids_by_state( $state );
			if ( ! empty( $in_state ) ) {
				$by_state = array_diff( TS_Locations::all_ids(), $in_state );
			}
		}

		$ids = array_values( array_unique( array_merge( $manual, $by_state ) ) );
		$ids = apply_filters( 'ts_excluded_location_ids', $ids, $state, $manual );

		self::$computed = $ids;
		return $ids;
	}

	/**
	 * Filtro pre_option. Devuelve false para dejar pasar el valor real.
	 */
	public static function filter_option( $pre ) {
		if ( ! self::applies() ) {
			return $pre;
		}
		$ids = self::excluded_ids();
		if ( empty( $ids ) ) {
			// Sin nada que excluir: dejar pasar el valor real de la opción (vacío).
			return $pre;
		}
		return $ids;
	}

	/**
	 * IDs de sucursales visibles para el cliente actual.
	 */
	public static function visible_ids() {
		return array_values( array_diff( TS_Locations::all_ids(), self::excluded_ids() ) );
	}
}
