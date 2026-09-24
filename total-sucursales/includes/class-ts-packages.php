<?php
/**
 * Enriquecimiento de los paquetes de envío con sucursal, distancia y elegibilidad de pickup.
 *
 * Claves añadidas a cada paquete:
 *   ts_location_id      int|null   Sucursal del paquete (split de MLI o sucursal única de los items).
 *   ts_location_name    string
 *   ts_distance_km      float|null Distancia cliente → sucursal.
 *   ts_pickup_eligible  bool       La tienda de este paquete ofrece retiro (ver criterio y alcance abajo).
 *   ts_pickup_reason    string     'municipio' | 'radio' | '' : por qué ofrece retiro.
 *   ts_pickup_qualifies bool       Cumple el criterio, aunque con alcance "una tienda" no sea la elegida.
 *   ts_customer_municipio string   Clave del municipio del cliente (ZU:maracaibo) o ''.
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
			if ( $id > 0 && TS_Locations::get( $id ) ) { // MLI guarda -1 cuando no se eligió sede.
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

	/**
	 * Decide qué paquetes (uno por tienda) ofrecen retiro.
	 *
	 * Criterio (ajuste pickup_criterion), qué hace que una tienda califique:
	 *   - radius:    el cliente está dentro del radio (hace falta su posición: GPS o dirección).
	 *   - municipio: el municipio del cliente está entre los que acepta la tienda. No necesita GPS.
	 *   - both:      cualquiera de los dos (por defecto).
	 *
	 * Alcance (ajuste pickup_scope), cuántas de las que califican ofrecen retiro:
	 *   - one: sólo una por pedido: la más cercana si se conoce la posición; si no, la tienda que el
	 *          cliente tiene elegida en el selector; si no, la primera.
	 *   - all: todas las que califican.
	 */
	public static function enrich_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}
		$coords    = TS_Customer::get_coords();
		$radius    = TS_Settings::radius_km();
		$criterion = TS_Settings::pickup_criterion();
		$scope     = TS_Settings::pickup_scope();
		$by_radius = 'municipio' !== $criterion;
		$by_muni   = 'radius' !== $criterion;

		$candidates = array();
		$customer   = '';

		foreach ( $packages as $key => $package ) {
			$loc = self::package_location( $package );

			$packages[ $key ]['ts_location_id']        = $loc['id'];
			$packages[ $key ]['ts_location_name']      = $loc['id'] ? TS_Locations::name( $loc['id'] ) : '';
			$packages[ $key ]['ts_mixed_locations']    = $loc['mixed'];
			$packages[ $key ]['ts_distance_km']        = null;
			$packages[ $key ]['ts_pickup_eligible']    = false;
			$packages[ $key ]['ts_pickup_qualifies']   = false;
			$packages[ $key ]['ts_pickup_reason']      = '';

			if ( ! isset( $packages[ $key ]['destination'] ) || ! is_array( $packages[ $key ]['destination'] ) ) {
				$packages[ $key ]['destination'] = array();
			}
			$packages[ $key ]['destination']['ts_lat']           = $coords ? round( $coords['lat'], 5 ) : null;
			$packages[ $key ]['destination']['ts_lng']           = $coords ? round( $coords['lng'], 5 ) : null;
			$packages[ $key ]['destination']['ts_coords_source'] = $coords ? $coords['source'] : 'none';

			$muni = TS_Municipios::from_destination( $packages[ $key ]['destination'] );
			$packages[ $key ]['ts_customer_municipio'] = $muni;
			if ( '' !== $muni ) {
				$customer = $muni;
			}

			if ( ! $loc['id'] ) {
				continue;
			}

			$d = null;
			if ( $coords ) {
				$d = TS_Locations::distance_km( $loc['id'], $coords['lat'], $coords['lng'] );
				if ( null !== $d ) {
					$packages[ $key ]['ts_distance_km'] = round( $d, 3 );
				}
			}

			$muni_ok   = $by_muni && '' !== $muni && in_array( $muni, TS_Municipios::for_location( $loc['id'] ), true );
			$radius_ok = $by_radius && null !== $d && $d <= $radius;

			if ( $muni_ok || $radius_ok ) {
				$packages[ $key ]['ts_pickup_qualifies'] = true;
				$candidates[ $key ] = array(
					'location_id' => (int) $loc['id'],
					'distance'    => $d,
					'reason'      => $muni_ok ? 'municipio' : 'radio',
				);
			}
		}

		$eligible = array();
		if ( 'all' === $scope ) {
			$eligible = array_keys( $candidates );
		} elseif ( ! empty( $candidates ) ) {
			$best_key = self::pick_one( $candidates );
			/**
			 * Permite cambiar qué paquete recibe el retiro cuando sólo puede ser uno.
			 * Devolver null para que ninguno lo reciba.
			 */
			$best_key = apply_filters( 'ts_pickup_eligible_package_key', $best_key, $packages, $coords, $radius );
			if ( null !== $best_key && isset( $packages[ $best_key ] ) ) {
				$eligible = array( $best_key );
			}
		}

		foreach ( $eligible as $key ) {
			$packages[ $key ]['ts_pickup_eligible'] = true;
			$packages[ $key ]['ts_pickup_reason']   = isset( $candidates[ $key ] ) ? $candidates[ $key ]['reason'] : 'radio';
		}

		self::$last = array(
			'coords'             => $coords,
			'radius'             => $radius,
			'criterion'          => $criterion,
			'scope'              => $scope,
			'customer_municipio' => $customer,
			'packages'           => array_map( function ( $p ) {
				return array(
					'location_id'     => $p['ts_location_id'],
					'location_name'   => $p['ts_location_name'],
					'distance_km'     => $p['ts_distance_km'],
					'pickup_eligible' => $p['ts_pickup_eligible'],
					'pickup_reason'   => $p['ts_pickup_reason'],
					'qualifies'       => $p['ts_pickup_qualifies'],
					'mixed'           => $p['ts_mixed_locations'],
					'customer_municipio' => $p['ts_customer_municipio'],
				);
			}, $packages ),
		);

		return $packages;
	}

	/**
	 * La única tienda con retiro cuando varias califican: la más cercana si se conoce la distancia;
	 * si no, la que el cliente tiene elegida en el selector; si no, la primera.
	 *
	 * @param array<int|string,array{location_id:int,distance:float|null,reason:string}> $candidates
	 * @return int|string
	 */
	private static function pick_one( array $candidates ) {
		$best = null;
		foreach ( $candidates as $key => $c ) {
			if ( null !== $c['distance'] && ( null === $best || $c['distance'] < $candidates[ $best ]['distance'] ) ) {
				$best = $key;
			}
		}
		if ( null !== $best ) {
			return $best;
		}
		$selected = TS_Customer::selected_mli_location_id();
		foreach ( $candidates as $key => $c ) {
			if ( $selected && $c['location_id'] === (int) $selected ) {
				return $key;
			}
		}
		reset( $candidates );
		return key( $candidates );
	}

	/**
	 * Qué poner junto a cada tienda en el panel del checkout (clásico y bloques).
	 *
	 * @param array $p Fila de summary()['packages'].
	 * @return array{label:string,unknown:bool}
	 */
	public static function row_label( array $p ) {
		if ( null !== $p['distance_km'] ) {
			return array( 'label' => ts_format_km( $p['distance_km'] ), 'unknown' => false );
		}
		if ( ! empty( $p['pickup_eligible'] ) && 'municipio' === ( $p['pickup_reason'] ?? '' ) ) {
			return array( 'label' => TS_Texts::get( 'in_your_municipio' ), 'unknown' => false );
		}
		if ( ! empty( $p['qualifies'] ) ) {
			// Cumplía pero el retiro se lo quedó otra tienda del pedido: la distancia no es el motivo.
			return array( 'label' => '', 'unknown' => false );
		}
		$criterion = TS_Settings::pickup_criterion();
		if ( 'municipio' === $criterion ) {
			return array( 'label' => '', 'unknown' => false ); // Sin radio la distancia no pinta nada.
		}
		if ( 'radius' !== $criterion && '' === (string) ( $p['customer_municipio'] ?? '' ) ) {
			return array( 'label' => '', 'unknown' => false ); // Falta el municipio: lo dice el aviso.
		}
		return array( 'label' => TS_Texts::get( 'unknown_distance' ), 'unknown' => true );
	}

	/**
	 * Aviso bajo la lista de tiendas cuando ninguna ofrece retiro y el cliente puede hacer algo para
	 * conseguirlo.
	 *
	 * - Sin municipio, si el criterio lo usa y alguna tienda del pedido acepta municipios: se le pide
	 *   que lo elija y se listan los municipios de cada tienda, para que sepa de antemano si le sirve.
	 *   En el carrito no hay campo de municipio, así que el texto remite al checkout.
	 * - Con municipio (o sin tiendas con municipios) y sin posición, si el criterio usa el radio: se le
	 *   pide la posición (sólo en el checkout, que es donde está el botón).
	 *
	 * @param array  $summary summary().
	 * @param string $context checkout | cart.
	 * @return array{text:string,lines:string[]}
	 */
	public static function pickup_note( array $summary, $context = 'checkout' ) {
		$out      = array( 'text' => '', 'lines' => array() );
		$packages = (array) ( $summary['packages'] ?? array() );
		foreach ( $packages as $p ) {
			if ( ! empty( $p['pickup_eligible'] ) ) {
				return $out;
			}
		}
		$criterion = TS_Settings::pickup_criterion();
		$has_muni  = '' !== (string) ( $summary['customer_municipio'] ?? '' );

		if ( 'radius' !== $criterion && ! $has_muni ) {
			$seen = array();
			foreach ( $packages as $p ) {
				if ( empty( $p['location_id'] ) || isset( $seen[ $p['location_id'] ] ) ) {
					continue;
				}
				$seen[ $p['location_id'] ] = true;
				$keys = TS_Municipios::for_location( $p['location_id'] );
				if ( $keys ) {
					$out['lines'][] = strtr( TS_Texts::get( 'pickup_for' ), array(
						'{tienda}'     => $p['location_name'],
						'{municipios}' => self::join_names( $keys ),
					) );
				}
			}
			if ( $out['lines'] ) {
				if ( 'cart' === $context ) {
					$out['text'] = TS_Texts::get( 'cart_choose_municipio' );
				} else {
					$out['text'] = ( 'municipio' === $criterion || ! empty( $summary['coords'] ) )
						? TS_Texts::get( 'choose_municipio' )
						: TS_Texts::get( 'choose_municipio_gps' );
				}
				return $out;
			}
		}
		if ( 'checkout' === $context && empty( $summary['coords'] ) && 'municipio' !== $criterion ) {
			$out['text'] = TS_Texts::get( 'no_position' );
		}
		return $out;
	}

	/**
	 * "Maracaibo, Cabimas y San Francisco"; con muchos, los primeros y "y N más".
	 *
	 * @param string[] $keys Claves de municipio.
	 */
	private static function join_names( array $keys, $max = 8 ) {
		$names = array_map( function ( $k ) {
			return TS_Municipios::label( $k, false );
		}, array_values( $keys ) );
		$extra = count( $names ) - $max;
		if ( $extra > 0 ) {
			$names = array_slice( $names, 0, $max );
			/* translators: %d número de municipios que no se nombran */
			$names[] = sprintf( _n( '%d más', '%d más', $extra, 'total-sucursales' ), $extra );
		}
		if ( count( $names ) < 2 ) {
			return implode( '', $names );
		}
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' ' . __( 'y', 'total-sucursales' ) . ' ' . $last;
	}

	/**
	 * El botón "Usar mi ubicación" del checkout sólo tiene sentido si el radio cuenta y compartir la
	 * posición puede cambiar algo: si el cliente ya tiene retiro (por su municipio) y aún no la compartió,
	 * pedírsela es ruido. Con la posición ya compartida se sigue mostrando, como "Ubicación registrada".
	 */
	public static function geo_button_enabled() {
		if ( ! TS_Settings::is_yes( 'checkout_geo_button' ) || 'municipio' === TS_Settings::pickup_criterion() ) {
			return false;
		}
		$coords = TS_Customer::get_coords();
		if ( $coords && 'gps' === $coords['source'] ) {
			return true;
		}
		foreach ( (array) ( self::summary()['packages'] ?? array() ) as $p ) {
			if ( ! empty( $p['pickup_eligible'] ) ) {
				return false;
			}
		}
		return true;
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
