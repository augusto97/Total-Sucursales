<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPC_Ts_Distancia_Sede_Condition' ) ) {

	/**
	 * Condición: distancia (km) entre el cliente y la sucursal del paquete.
	 * Sin posición conocida del cliente la condición nunca coincide.
	 */
	class WPC_Ts_Distancia_Sede_Condition extends WPC_Condition {

		public function __construct() {
			$this->name        = __( 'Distancia a la sucursal (km)', 'total-sucursales' );
			$this->slug        = 'ts_distancia_sede';
			$this->group       = __( 'Total Sucursales', 'total-sucursales' );
			$this->description = __( 'Distancia en línea recta (km) desde la posición del cliente (GPS o dirección geocodificada) hasta la sucursal del paquete. Si no se conoce la posición, no coincide.', 'total-sucursales' );
			parent::__construct();
		}

		public function get_available_operators() {
			return array(
				'<=' => __( 'Menor o igual que', 'total-sucursales' ),
				'>=' => __( 'Mayor o igual que', 'total-sucursales' ),
			);
		}

		public function get_value_field_args() {
			return array(
				'type'        => 'number',
				'class'       => array( 'wpc-value' ),
				'placeholder' => (string) TS_Settings::radius_km(),
				'custom_attributes' => array( 'min' => 0, 'step' => '0.1' ),
			);
		}

		public function get_value( $value ) {
			return (float) str_replace( ',', '.', (string) $value );
		}

		public function match( $match, $operator, $value, $args = array() ) {
			$package  = TS_WAS_Conditions::package_from_args( $args );
			$distance = isset( $package['ts_distance_km'] ) ? $package['ts_distance_km'] : null;
			if ( null === $distance ) {
				return false;
			}
			$value    = $this->get_value( $value );
			$distance = (float) $distance;

			if ( '<=' === $operator ) {
				return $distance <= $value;
			}
			if ( '>=' === $operator ) {
				return $distance >= $value;
			}
			return $match;
		}
	}
}
