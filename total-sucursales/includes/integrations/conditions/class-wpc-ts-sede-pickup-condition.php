<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPC_Ts_Sede_Pickup_Condition' ) ) {

	/**
	 * Condición: el paquete pertenece a LA sucursal elegible para pickup
	 * (la más cercana al cliente dentro del radio configurado). Sólo una por pedido.
	 */
	class WPC_Ts_Sede_Pickup_Condition extends WPC_Condition {

		public function __construct() {
			$this->name        = __( 'Sucursal elegible para pickup', 'total-sucursales' );
			$this->slug        = 'ts_sede_pickup';
			$this->group       = __( 'Total Sucursales', 'total-sucursales' );
			$this->description = sprintf(
				/* translators: %s radio en km */
				__( '"Sí" únicamente para la sucursal más cercana al cliente dentro del radio configurado (%s km). Usa "Sí" en la regla de retiro en tienda y "No" en la de envío nacional.', 'total-sucursales' ),
				TS_Settings::radius_km()
			);
			parent::__construct();
		}

		public function get_available_operators() {
			return array(
				'==' => __( 'Es', 'total-sucursales' ),
				'!=' => __( 'No es', 'total-sucursales' ),
			);
		}

		public function get_value_field_args() {
			return array(
				'type'    => 'select',
				'class'   => array( 'wpc-value' ),
				'options' => array(
					'yes' => __( 'Sí (dentro del radio, la más cercana)', 'total-sucursales' ),
					'no'  => __( 'No (envío nacional)', 'total-sucursales' ),
				),
			);
		}

		public function match( $match, $operator, $value, $args = array() ) {
			$package  = TS_WAS_Conditions::package_from_args( $args );
			$eligible = ! empty( $package['ts_pickup_eligible'] );
			$wanted   = ( 'yes' === $value );

			if ( '==' === $operator ) {
				return $eligible === $wanted;
			}
			if ( '!=' === $operator ) {
				return $eligible !== $wanted;
			}
			return $match;
		}
	}
}
