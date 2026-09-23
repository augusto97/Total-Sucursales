<?php
/**
 * Método de envío propio: retiro en tienda o envío nacional, sin Advanced Shipping.
 *
 * Se añade a una zona como cualquier método de WooCommerce (WooCommerce > Ajustes > Envío). Para
 * cada paquete (uno por tienda) ofrece:
 *   - "Retiro en tienda" si es el paquete elegible para retiro: la tienda más cercana al cliente
 *     dentro del radio configurado (lo decide TS_Packages);
 *   - "Envío nacional" en los demás, y opcionalmente también en el elegible.
 *
 * Es lo mismo que hacen las dos reglas de Advanced Shipping de la guía, sin necesitar ese plugin.
 * Si Advanced Shipping está activo se pueden seguir usando sus reglas; lo que no conviene es poner
 * los dos en la misma zona, porque el cliente vería las tarifas repetidas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Shipping_Method extends WC_Shipping_Method {

	const ID = 'total_sucursales';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Total Sucursales: retiro o envío nacional', 'total-sucursales' );
		$this->method_description = __( 'Ofrece "Retiro en tienda" en la tienda más cercana al cliente dentro del radio de Total Sucursales, y "Envío nacional" en las demás. No necesita Advanced Shipping.', 'total-sucursales' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();
	}

	private function init() {
		$this->instance_form_fields = array(
			'pickup_title'        => array(
				'title'       => __( 'Nombre del retiro', 'total-sucursales' ),
				'type'        => 'text',
				'default'     => __( 'Retiro en tienda', 'total-sucursales' ),
				'desc_tip'    => true,
				'description' => __( 'Lo que ve el cliente en la tienda donde puede retirar.', 'total-sucursales' ),
			),
			'pickup_cost'         => array(
				'title'       => __( 'Costo del retiro', 'total-sucursales' ),
				'type'        => 'price',
				'default'     => '0',
				'placeholder' => wc_format_localized_price( 0 ),
			),
			'national_title'      => array(
				'title'       => __( 'Nombre del envío', 'total-sucursales' ),
				'type'        => 'text',
				'default'     => __( 'Envío nacional', 'total-sucursales' ),
			),
			'national_cost'       => array(
				'title'       => __( 'Costo del envío', 'total-sucursales' ),
				'type'        => 'price',
				'default'     => '0',
				'placeholder' => wc_format_localized_price( 0 ),
				'desc_tip'    => true,
				'description' => __( 'Déjalo en 0 si el envío se cobra aparte.', 'total-sucursales' ),
			),
			'national_for_pickup' => array(
				'title'       => __( 'Envío también donde se puede retirar', 'total-sucursales' ),
				'type'        => 'checkbox',
				'label'       => __( 'Ofrecer también el envío en la tienda donde el cliente puede retirar, para que elija', 'total-sucursales' ),
				'default'     => 'no',
			),
			'tax_status'          => array(
				'title'   => __( 'Impuestos', 'total-sucursales' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'none',
				'options' => array(
					'taxable' => __( 'Sujeto a impuestos', 'total-sucursales' ),
					'none'    => _x( 'Ninguno', 'Tax status', 'total-sucursales' ),
				),
			),
		);

		// Título en la lista de métodos de la zona: los dos nombres, para que se vea que hace ambas cosas.
		$this->title      = $this->text_option( 'pickup_title', __( 'Retiro en tienda', 'total-sucursales' ) )
			. ' / ' . $this->text_option( 'national_title', __( 'Envío nacional', 'total-sucursales' ) );
		$this->tax_status = $this->get_option( 'tax_status', 'none' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * @param array $package Paquete de WooCommerce, ya enriquecido por TS_Packages.
	 */
	public function calculate_shipping( $package = array() ) {
		$eligible    = ! empty( $package['ts_pickup_eligible'] );
		$location_id = isset( $package['ts_location_id'] ) ? (int) $package['ts_location_id'] : 0;

		if ( $eligible ) {
			$this->add_rate( array(
				'id'        => $this->get_rate_id( 'pickup' ),
				'label'     => $this->text_option( 'pickup_title', __( 'Retiro en tienda', 'total-sucursales' ) ),
				'cost'      => $this->cost_option( 'pickup_cost' ),
				'package'   => $package,
				'meta_data' => array(
					'_ts_mode'        => 'pickup',
					'_ts_location_id' => $location_id,
				),
			) );
		}

		if ( ! $eligible || 'yes' === $this->get_option( 'national_for_pickup', 'no' ) ) {
			$this->add_rate( array(
				'id'        => $this->get_rate_id( 'national' ),
				'label'     => $this->text_option( 'national_title', __( 'Envío nacional', 'total-sucursales' ) ),
				'cost'      => $this->cost_option( 'national_cost' ),
				'package'   => $package,
				'meta_data' => array(
					'_ts_mode'        => 'national',
					'_ts_location_id' => $location_id,
				),
			) );
		}
	}

	private function text_option( $key, $fallback ) {
		$v = trim( (string) $this->get_option( $key, '' ) );
		return '' !== $v ? $v : $fallback;
	}

	private function cost_option( $key ) {
		$v = wc_format_decimal( $this->get_option( $key, '0' ) );
		return ( '' === $v || ! is_numeric( $v ) ) ? 0 : max( 0, (float) $v );
	}

	/**
	 * ¿Hay alguna zona con este método activado?
	 */
	public static function is_in_any_zone() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}
		$zones   = WC_Shipping_Zones::get_zones();
		$zones[] = array( 'zone_id' => 0 ); // "Resto del mundo".
		foreach ( $zones as $z ) {
			$zone = WC_Shipping_Zones::get_zone( isset( $z['zone_id'] ) ? $z['zone_id'] : ( isset( $z['id'] ) ? $z['id'] : 0 ) );
			if ( ! $zone ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( self::ID === $method->id ) {
					return true;
				}
			}
		}
		return false;
	}
}
