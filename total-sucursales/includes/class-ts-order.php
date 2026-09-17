<?php
/**
 * Persistencia en el pedido y visualización en el admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Order {

	public static function init() {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_meta' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_display' ) );
	}

	public static function save_meta( $order, $data ) {
		$coords  = TS_Customer::get_coords();
		$summary = TS_Packages::summary();

		$order->update_meta_data( '_ts_customer_state', TS_Customer::get_state() );
		if ( $coords ) {
			$order->update_meta_data( '_ts_customer_lat', $coords['lat'] );
			$order->update_meta_data( '_ts_customer_lng', $coords['lng'] );
			$order->update_meta_data( '_ts_coords_source', $coords['source'] );
		}
		$order->update_meta_data( '_ts_radius_km', TS_Settings::radius_km() );

		$pickup = null;
		$rows   = array();
		foreach ( (array) ( $summary['packages'] ?? array() ) as $p ) {
			if ( ! $p['location_id'] ) {
				continue;
			}
			$rows[] = array(
				'location_id'     => $p['location_id'],
				'location_name'   => $p['location_name'],
				'distance_km'     => $p['distance_km'],
				'pickup_eligible' => $p['pickup_eligible'],
			);
			if ( $p['pickup_eligible'] ) {
				$pickup = $p['location_id'];
			}
		}
		$order->update_meta_data( '_ts_packages', $rows );
		$order->update_meta_data( '_ts_pickup_location_id', $pickup );
		$order->update_meta_data( '_ts_pickup_location_name', $pickup ? TS_Locations::name( $pickup ) : '' );
	}

	public static function admin_display( $order ) {
		$rows = $order->get_meta( '_ts_packages' );
		if ( empty( $rows ) && ! $order->get_meta( '_ts_customer_state' ) ) {
			return;
		}
		echo '<div class="ts-order-meta"><h3>' . esc_html__( 'Total Sucursales', 'total-sucursales' ) . '</h3>';
		$state = $order->get_meta( '_ts_customer_state' );
		if ( $state && '__ALL__' !== $state ) {
			echo '<p><strong>' . esc_html__( 'Estado del cliente:', 'total-sucursales' ) . '</strong> ' . esc_html( ts_state_name( $state ) ) . '</p>';
		}
		$lat = $order->get_meta( '_ts_customer_lat' );
		$lng = $order->get_meta( '_ts_customer_lng' );
		if ( $lat && $lng ) {
			printf(
				'<p><strong>%s</strong> <a href="%s" target="_blank" rel="noopener">%s, %s</a> <em>(%s)</em></p>',
				esc_html__( 'Posición:', 'total-sucursales' ),
				esc_url( 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=15/' . $lat . '/' . $lng ),
				esc_html( $lat ),
				esc_html( $lng ),
				esc_html( $order->get_meta( '_ts_coords_source' ) )
			);
		}
		if ( ! empty( $rows ) ) {
			echo '<ul>';
			foreach ( (array) $rows as $p ) {
				printf(
					'<li>%s — %s — %s</li>',
					esc_html( $p['location_name'] ),
					null !== $p['distance_km'] ? esc_html( ts_format_km( $p['distance_km'] ) ) : esc_html__( 'sin distancia', 'total-sucursales' ),
					$p['pickup_eligible'] ? '<strong>' . esc_html__( 'PICKUP', 'total-sucursales' ) . '</strong>' : esc_html__( 'Envío nacional', 'total-sucursales' )
				);
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
