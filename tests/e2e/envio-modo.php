<?php
/**
 * Cambia el motor de envío de la zona "Venezuela" para las pruebas.
 *
 *   wp eval-file envio-modo.php propio [national_for_pickup=yes|no]
 *       Añade (o reconfigura) el método "Total Sucursales" en la zona: retiro 0, envío nacional 5.
 *   wp eval-file envio-modo.php was
 *       Quita el método "Total Sucursales" de la zona (las reglas de Advanced Shipping siguen ahí).
 *
 * Activar o desactivar el plugin Advanced Shipping se hace aparte con `wp plugin`.
 */

$mode = isset( $args[0] ) ? $args[0] : 'propio';
$opts = array();
foreach ( array_slice( (array) $args, 1 ) as $a ) {
	if ( false !== strpos( $a, '=' ) ) {
		list( $k, $v ) = explode( '=', $a, 2 );
		$opts[ $k ] = $v;
	}
}

$zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	if ( 'Venezuela' === $z['zone_name'] ) {
		$zone = new WC_Shipping_Zone( $z['id'] );
	}
}
if ( ! $zone ) {
	echo "sin zona Venezuela\n";
	return;
}

// Quitar las instancias previas del método propio.
global $wpdb;
$ids = $wpdb->get_col( $wpdb->prepare(
	"SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d AND method_id = %s",
	$zone->get_id(),
	'total_sucursales'
) );
foreach ( $ids as $id ) {
	$zone->delete_shipping_method( (int) $id );
	delete_option( 'woocommerce_total_sucursales_' . (int) $id . '_settings' );
}

if ( 'propio' === $mode ) {
	$instance_id = $zone->add_shipping_method( 'total_sucursales' );
	update_option( 'woocommerce_total_sucursales_' . $instance_id . '_settings', array(
		'pickup_title'        => 'Retiro en tienda',
		'pickup_cost'         => '0',
		'national_title'      => 'Envío nacional',
		'national_cost'       => '5',
		'national_for_pickup' => isset( $opts['national_for_pickup'] ) ? $opts['national_for_pickup'] : 'no',
		'tax_status'          => 'none',
	) );
	echo "modo propio (instancia $instance_id)\n";
} else {
	echo "modo was\n";
}
WC_Cache_Helper::get_transient_version( 'shipping', true );
