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
	}

	public static function add_to_dropdown( $conditions ) {
		$group = __( 'Total Sucursales', 'total-sucursales' );
		$conditions[ $group ] = array(
			'ts_sede_pickup'    => __( 'Sucursal elegible para pickup (radio)', 'total-sucursales' ),
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
