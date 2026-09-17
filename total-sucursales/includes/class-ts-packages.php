<?php
/**
 * Enriquecimiento de los paquetes de envío con sucursal, distancia y elegibilidad de pickup.
 *
 * Claves añadidas a cada paquete:
 *   ts_location_id      int|null   Sucursal del paquete (split de MLI o sucursal única de los items).
 *   ts_location_name    string
 *   ts_distance_km      float|null Distancia cliente → sucursal.
 *   ts_pickup_eligible  bool       true sólo para la sucursal más cercana dentro del radio.
 *   ts_mixed_locations  bool       El paquete contiene items de varias sucursales (sin split).
 *   destination[ts_lat|ts_lng|ts_coords_source]
 *
 * Al formar parte del paquete, estos datos entran en el hash de caché de tarifas de WooCommerce:
 * cambiar de posición o de sucursal fuerza el recálculo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Packages {

	/** @var array Último resultado calculado, para mostrarlo en el checkout. */
	private static $last = array();

	public static function init() {
		// Después del split de MLI (prioridad 10).
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'enrich_packages' ), 50 );
	}

	public static function last_summary() {
		return self::$last;
	}

	/**
	 * Sucursal de un paquete según su contenido.
	 *
	 * @return array{id:int|null,mixed:bool}
	 */
	public static function package_location( array $package ) {
		if ( ! empty( $package['shipping_term_id'] ) ) {
			return array( 'id' => (int) $package['shipping_term_id'], 'mixed' => false );
		}
		$ids = array();
		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			$id = isset( $item['select_location']['location_termId'] ) ? (int) $item['select_location']['location_termId'] : 0;
			if ( $id ) {
				$ids[ $id ] = true;
			}
		}
		$ids = array_keys( $ids );
		if ( 1 === count( $ids ) ) {
			return array( 'id' => $ids[0], 'mixed' => false );
		}
		if ( empty( $ids ) ) {
			// Sin datos de MLI: usar la sucursal seleccionada en la navegación como último recurso.
			$sel = TS_Customer::selected_mli_location_id();
			return array( 'id' => $sel ? $sel : null, 'mixed' => false );
		}
		return array( 'id' => null, 'mixed' => true );
	}

	public static function enrich_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}
		$coords = TS_Customer::get_coords();
		$radius = TS_Settings::radius_km();

		$best_key  = null;
		$best_dist = null;

		foreach ( $packages as $key => $package ) {
			$loc = self::package_location( $package );

			$packages[ $key ]['ts_location_id']     = $loc['id'];
			$packages[ $key ]['ts_location_name']   = $loc['id'] ? TS_Locations::name( $loc['id'] ) : '';
			$packages[ $key ]['ts_mixed_locations'] = $loc['mixed'];
			$packages[ $key ]['ts_distance_km']     = null;
			$packages[ $key ]['ts_pickup_eligible'] = false;

			if ( ! isset( $packages[ $key ]['destination'] ) || ! is_array( $packages[ $key ]['destination'] ) ) {
				$packages[ $key ]['destination'] = array();
			}
			$packages[ $key ]['destination']['ts_lat']           = $coords ? round( $coords['lat'], 5 ) : null;
			$packages[ $key ]['destination']['ts_lng']           = $coords ? round( $coords['lng'], 5 ) : null;
			$packages[ $key ]['destination']['ts_coords_source'] = $coords ? $coords['source'] : 'none';

			if ( $coords && $loc['id'] ) {
				$d = TS_Locations::distance_km( $loc['id'], $coords['lat'], $coords['lng'] );
				if ( null !== $d ) {
					$packages[ $key ]['ts_distance_km'] = round( $d, 3 );
					if ( $d <= $radius && ( null === $best_dist || $d < $best_dist ) ) {
						$best_dist = $d;
						$best_key  = $key;
					}
				}
			}
		}

		/**
		 * Permite cambiar qué paquete recibe pickup (por defecto: el más cercano dentro del radio).
		 * Devolver null para que ninguno sea elegible.
		 */
		$best_key = apply_filters( 'ts_pickup_eligible_package_key', $best_key, $packages, $coords, $radius );

		if ( null !== $best_key && isset( $packages[ $best_key ] ) ) {
			$packages[ $best_key ]['ts_pickup_eligible'] = true;
		}

		self::$last = array(
			'coords'   => $coords,
			'radius'   => $radius,
			'packages' => array_map( function ( $p ) {
				return array(
					'location_id'     => $p['ts_location_id'],
					'location_name'   => $p['ts_location_name'],
					'distance_km'     => $p['ts_distance_km'],
					'pickup_eligible' => $p['ts_pickup_eligible'],
					'mixed'           => $p['ts_mixed_locations'],
				);
			}, $packages ),
		);

		return $packages;
	}

	/**
	 * Recalcula el resumen sin depender de un cálculo previo (para mostrar en checkout).
	 */
	public static function summary() {
		if ( ! empty( self::$last ) ) {
			return self::$last;
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->get_shipping_packages(); // dispara el filtro
		}
		return self::$last;
	}
}
