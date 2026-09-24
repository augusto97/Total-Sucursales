<?php
/**
 * Integración con Advanced Shipping (wp-conditions): condiciones "Total Sucursales".
 *
 * Slugs y clases (WAS resuelve la clase por convención de nombre):
 *   ts_sede            → WPC_Ts_Sede_Condition
 *   ts_distancia_sede  → WPC_Ts_Distancia_Sede_Condition
 *   ts_sede_pickup     → WPC_Ts_Sede_Pickup_Condition
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_WAS_Conditions {

	public static function init() {
		// WAS carga wp-conditions en plugins_loaded; nosotros vamos con prioridad 20, pero por si acaso esperamos a init.
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
	}

	public static function register() {
		if ( ! class_exists( 'WPC_Condition' ) ) {
			return;
		}
		require_once TS_PLUGIN_DIR . 'includes/integrations/conditions/class-wpc-ts-sede-condition.php';
		require_once TS_PLUGIN_DIR . 'includes/integrations/conditions/class-wpc-ts-distancia-sede-condition.php';
		require_once TS_PLUGIN_DIR . 'includes/integrations/conditions/class-wpc-ts-sede-pickup-condition.php';

		add_filter( 'was_conditions', array( __CLASS__, 'add_to_dropdown' ) );
		add_filter( 'wp-conditions\registered_conditions', array( __CLASS__, 'register_instances' ) );
		add_filter( 'was_shipping_rate', array( __CLASS__, 'tag_rate' ), 10, 3 );
	}

	/**
	 * Marca la tarifa de una regla de Advanced Shipping como retiro o envío nacional, igual que hace el
	 * método propio, para que el pedido sepa qué eligió el cliente (y no sólo dónde podía retirar).
	 *
	 * Una regla es de retiro si una de sus condiciones pide "Sucursal elegible para retiro = Sí" (o
	 * "No es No"); de envío nacional si pide lo contrario. Las reglas sin esa condición no se marcan.
	 *
	 * @param array  $rate    Argumentos de la tarifa.
	 * @param array  $package Paquete.
	 * @param object $method  Método de WAS (por zonas) o el método antiguo, cuyas reglas son entradas.
	 */
	public static function tag_rate( $rate, $package, $method ) {
		if ( ! is_array( $rate ) ) {
			return $rate;
		}
		$conditions = null;
		if ( is_object( $method ) && ! empty( $method->instance_id ) && method_exists( $method, 'get_instance_option' ) ) {
			$conditions = $method->get_instance_option( 'conditions' );
		}
		if ( empty( $conditions ) && isset( $rate['id'] ) && is_numeric( $rate['id'] ) ) {
			$conditions = get_post_meta( (int) $rate['id'], '_was_shipping_method_conditions', true );
		}
		$mode = self::rule_mode( $conditions );
		if ( '' === $mode ) {
			return $rate;
		}
		$rate['meta_data'] = array_merge( (array) ( $rate['meta_data'] ?? array() ), array(
			'_ts_mode'        => $mode,
			'_ts_location_id' => isset( $package['ts_location_id'] ) ? (int) $package['ts_location_id'] : 0,
		) );
		return $rate;
	}

	/**
	 * @param mixed $groups Grupos de condiciones de WAS: [ grupo => [ n => [condition, operator, value] ] ].
	 * @return string pickup | national | ''
	 */
	private static function rule_mode( $groups ) {
		foreach ( (array) $groups as $group ) {
			foreach ( (array) $group as $c ) {
				if ( ! is_array( $c ) || 'ts_sede_pickup' !== ( $c['condition'] ?? '' ) ) {
					continue;
				}
				$yes = 'yes' === ( $c['value'] ?? '' );
				$is  = '!=' !== ( $c['operator'] ?? '==' );
				return ( $yes === $is ) ? 'pickup' : 'national';
			}
		}
		return '';
	}

	public static function add_to_dropdown( $conditions ) {
		$group = __( 'Total Sucursales', 'total-sucursales' );
		$conditions[ $group ] = array(
			'ts_sede_pickup'    => __( 'Sucursal elegible para retiro', 'total-sucursales' ),
			'ts_distancia_sede' => __( 'Distancia del cliente a la sucursal (km)', 'total-sucursales' ),
			'ts_sede'           => __( 'Sucursal del paquete', 'total-sucursales' ),
		);
		return $conditions;
	}

	public static function register_instances( $conditions ) {
		$conditions[] = new WPC_Ts_Sede_Condition();
		$conditions[] = new WPC_Ts_Distancia_Sede_Condition();
		$conditions[] = new WPC_Ts_Sede_Pickup_Condition();
		return $conditions;
	}

	/**
	 * Paquete desde los argumentos de match (WAS pasa ['context'=>'was','package'=>$package]).
	 * Si el paquete no fue enriquecido (p. ej. otro plugin lo reconstruyó), lo enriquecemos al vuelo.
	 */
	public static function package_from_args( $args ) {
		$package = is_array( $args ) && isset( $args['package'] ) && is_array( $args['package'] ) ? $args['package'] : array();
		if ( ! array_key_exists( 'ts_location_id', $package ) && ! empty( $package ) ) {
			$enriched = TS_Packages::enrich_packages( array( $package ) );
			$package  = $enriched[0];
		}
		return $package;
	}
}
