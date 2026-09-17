<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WPC_Ts_Sede_Condition' ) ) {

	/**
	 * Condición: la sucursal del paquete es X.
	 * A diferencia de la condición "Locations" de MLI, compara la sucursal del PAQUETE
	 * (no todo el carrito) y usa term_id (no índices posicionales).
	 */
	class WPC_Ts_Sede_Condition extends WPC_Condition {

		public function __construct() {
			$this->name        = __( 'Sucursal del paquete', 'total-sucursales' );
			$this->slug        = 'ts_sede';
			$this->group       = __( 'Total Sucursales', 'total-sucursales' );
			$this->description = __( 'Compara la sucursal de la que sale este paquete (con "dividir paquetes por sucursal" de MLI se evalúa por sucursal; sin dividir, sólo coincide si todo el carrito es de esa sucursal).', 'total-sucursales' );
			parent::__construct();
		}

		public function get_available_operators() {
			$ops = parent::get_available_operators();
			unset( $ops['>='], $ops['<='] );
			return $ops;
		}

		public function get_value_field_args() {
			$options = array();
			foreach ( TS_Locations::all() as $id => $l ) {
				$label = $l['name'];
				if ( '' !== $l['state'] ) {
					$label .= ' (' . ts_state_name( $l['state'] ) . ')';
				}
				$options[ $id ] = $label;
			}
			asort( $options );
			return array(
				'type'    => 'select',
				'class'   => array( 'wpc-value', 'wc-enhanced-select' ),
				'options' => $options,
			);
		}

		public function match( $match, $operator, $value, $args = array() ) {
			$package = TS_WAS_Conditions::package_from_args( $args );
			$current = isset( $package['ts_location_id'] ) ? (int) $package['ts_location_id'] : 0;
			$value   = (int) $value;

			if ( '==' === $operator ) {
				return $current > 0 && $current === $value;
			}
			if ( '!=' === $operator ) {
				return $current !== $value;
			}
			return $match;
		}
	}
}
