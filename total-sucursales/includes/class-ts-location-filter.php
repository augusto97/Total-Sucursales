<?php
/**
 * Restringe las sucursales visibles al estado del cliente o, en el modo "por ciudad", a las de su
 * ciudad (o sólo a la tienda por defecto).
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

	/** @var bool Permite a TS_Locations leer la lista completa de términos. */
	public static $bypass_terms = false;

	public static function init() {
		add_filter( 'pre_option_' . self::OPTION, array( __CLASS__, 'filter_option' ), 10, 1 );
		// MLI mezcla llamadas a get_terms() con y sin `exclude` y guarda índices posicionales en cookies.
		// Filtrar también en get_terms() garantiza que todas sus rutas vean la misma lista.
		add_filter( 'get_terms_args', array( __CLASS__, 'filter_terms_args' ), 10, 2 );
	}

	/**
	 * Aplica la exclusión a cualquier consulta de la taxonomía `locations` en el front.
	 */
	public static function filter_terms_args( $args, $taxonomies ) {
		if ( self::$bypass_terms || ! self::applies() ) {
			return $args;
		}
		if ( ! in_array( 'locations', (array) $taxonomies, true ) ) {
			return $args;
		}
		$ids = self::excluded_ids();
		if ( empty( $ids ) ) {
			return $args;
		}
		$current         = isset( $args['exclude'] ) ? self::normalize_ids( $args['exclude'] ) : array();
		$args['exclude'] = array_values( array_unique( array_merge( $current, $ids ) ) );
		return $args;
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
		// Nunca filtrar fuera del front: WP-CLI, cron e importadores deben ver todas las sucursales.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! apply_filters( 'ts_filter_on_rest', false ) ) {
			return false;
		}
		// Multi Locations puede forzar una única sucursal (invitados o usuario con sede asignada).
		// En ese caso filtrar por estado sólo puede dejar el selector vacío. Los demás avisos
		// (grupos, autodetección) no fuerzan una sede única y no deben desactivar el filtro.
		if ( class_exists( 'TS_Debug' ) ) {
			$conflicts = TS_Debug::mli_conflicts();
			foreach ( array( 'wcmlim_enable_restrict_guestuser_location', 'wcmlim_enable_userspecific_location' ) as $key ) {
				if ( isset( $conflicts[ $key ] ) ) {
					return false;
				}
			}
		}

		return (bool) apply_filters( 'ts_location_filter_applies', true );
	}

	/**
	 * IDs a excluir = exclusiones manuales + sucursales fuera del estado del cliente
	 * (sólo si el estado tiene al menos una sucursal visible).
	 *
	 * El filtro nunca deja la tienda sin sucursales: si aplicarlo ocultaría todas, se descarta
	 * el filtro por estado y se respetan sólo las exclusiones manuales de Multi Locations.
	 *
	 * @return int[]
	 */
	public static function excluded_ids() {
		if ( null !== self::$computed ) {
			return self::$computed;
		}
		$manual  = self::manual_exclusions();
		$all     = TS_Locations::all_ids();
		$state   = TS_Customer::get_state();

		$by_state = array();
		if ( 'city' === TS_Settings::visibility_mode() && ! empty( $all ) ) {
			$by_state = array_diff( $all, self::city_visible_ids( array_diff( $all, $manual ) ) );
		} elseif ( '' !== $state && ! empty( $all ) ) {
			// Las sucursales que el administrador ocultó en Multi Locations no cuentan como
			// "sucursales del estado": si la única del estado está oculta, no se filtra nada.
			$in_state = array_diff( TS_Locations::ids_by_state( $state ), $manual );
			if ( ! empty( $in_state ) ) {
				$by_state = array_diff( $all, $in_state );
			}
		}

		$ids = array_values( array_unique( array_merge( $manual, $by_state ) ) );

		// Red de seguridad: nunca dejar cero sucursales visibles.
		if ( ! empty( $all ) && ! array_diff( $all, $ids ) ) {
			$ids = $manual;
			if ( ! array_diff( $all, $ids ) ) {
				$ids = array();
			}
		}

		$ids = apply_filters( 'ts_excluded_location_ids', $ids, $state, $manual );

		self::$computed = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		return self::$computed;
	}

	/**
	 * Modo "por ciudad": tiendas que ve el cliente.
	 *   - Con su ubicación (GPS) y tiendas a menos del radio de la ciudad: esas.
	 *   - Si no: sólo la tienda por defecto.
	 *   - Sin tienda por defecto configurada (o si está oculta en Multi Locations): todas, para no dejar
	 *     la tienda vacía; los ajustes lo avisan.
	 *
	 * @param int[] $candidates Tiendas no ocultas en Multi Locations.
	 * @return int[]
	 */
	public static function city_visible_ids( array $candidates ) {
		$candidates = array_values( array_map( 'intval', $candidates ) );
		$coords     = TS_Customer::get_coords();
		if ( $coords && 'gps' === $coords['source'] ) {
			$radius = TS_Settings::city_radius_km();
			$near   = array();
			foreach ( $candidates as $id ) {
				$d = TS_Locations::distance_km( $id, $coords['lat'], $coords['lng'] );
				if ( null !== $d && $d <= $radius ) {
					$near[] = $id;
				}
			}
			if ( $near ) {
				return $near;
			}
		}
		$default = TS_Settings::default_location_id();
		if ( $default && in_array( $default, $candidates, true ) ) {
			return array( $default );
		}
		return $candidates;
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
