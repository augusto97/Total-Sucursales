<?php
// Seed de datos de prueba para Total Sucursales.
update_option( 'woocommerce_default_country', 'VE:ZU' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_store_address', 'Av. 5 de Julio' );
update_option( 'woocommerce_store_city', 'Maracaibo' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_ship_to_countries', 'specific' );
update_option( 'woocommerce_specific_ship_to_countries', array( 'VE' ) );
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_specific_allowed_countries', array( 'VE' ) );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_checkout_login_reminder', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true, 'completed' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Pago contra entrega', 'enable_for_virtual' => 'no' ) );
update_option( 'woocommerce_shipping_cost_requires_address', 'no' );

// Páginas clásicas.
foreach ( array( 'cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]', 'myaccount' => '[woocommerce_my_account]' ) as $key => $sc ) {
	$id = wc_get_page_id( $key );
	if ( $id > 0 ) {
		wp_update_post( array( 'ID' => $id, 'post_content' => $sc, 'post_status' => 'publish' ) );
	}
}
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// MLI
update_option( 'wcmlim_show_location_selection', 'on' );
update_option( 'wcmlim_hide_outofstock_products', 'on' );
update_option( 'wcmlim_clear_cart', 'on' );          // Restrict to One Location (variante A)
update_option( 'wcmlim_enable_split_packages', '' );
update_option( 'wcmlim_set_location_cookie_time', 7 );
update_option( 'wcmlim_backend_display_stock_view', 'select_view' );
update_option( 'wcmlim_instock_button_text', 'In stock' );
update_option( 'wcmlim_soldout_button_text', 'Sold out' );
update_option( 'wcmlim_onbackorder_button_text', 'On backorder' );

// Sucursales
$locs = array(
	'Tienda Delicias'      => array( 'ZU', 10.6650, -71.6300, 'Maracaibo' ),
	'Tienda San Francisco' => array( 'ZU', 10.5500, -71.6600, 'San Francisco' ),
	'Tienda Chacao'        => array( 'DC', 10.4950, -66.8530, 'Caracas' ),
	'Tienda Valencia'      => array( 'CA', 10.1800, -68.0000, 'Valencia' ),
);
$ids = array();
foreach ( $locs as $name => $d ) {
	$t = term_exists( $name, 'locations' );
	if ( ! $t ) {
		$t = wp_insert_term( $name, 'locations' );
	}
	$id = (int) ( is_array( $t ) ? $t['term_id'] : $t );
	$ids[ $name ] = $id;
	update_term_meta( $id, 'wcmlim_administrative_area_level_1', $d[0] );
	update_term_meta( $id, 'wcmlim_country', 'VE' );
	update_term_meta( $id, 'wcmlim_lat', $d[1] );
	update_term_meta( $id, 'wcmlim_lng', $d[2] );
	update_term_meta( $id, 'wcmlim_locality', $d[3] );
	update_term_meta( $id, 'wcmlim_street_number', 'Calle 1' );
	update_term_meta( $id, 'wcmlim_route', 'Av. Principal' );
	update_term_meta( $id, 'wcmlim_phone', '0261-0000000' );
	update_term_meta( $id, 'wcmlim_start_time', '09:00' );
	update_term_meta( $id, 'wcmlim_end_time', '18:00' );
}

// Productos
$products = array(
	'Producto A (Delicias y Chacao)' => array( 'Tienda Delicias' => 10, 'Tienda Chacao' => 5 ),
	'Producto B (San Francisco)'     => array( 'Tienda San Francisco' => 8 ),
	'Producto C (Chacao)'            => array( 'Tienda Chacao' => 3 ),
	'Producto D (Valencia)'          => array( 'Tienda Valencia' => 6 ),
);
foreach ( $products as $name => $stocks ) {
	$existing = get_page_by_title( $name, OBJECT, 'product' );
	$p = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( '20' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( array_sum( $stocks ) );
	$p->set_stock_status( 'instock' );
	$p->set_status( 'publish' );
	$p->set_weight( '1' );
	$pid = $p->save();
	$term_ids = array();
	foreach ( $stocks as $lname => $qty ) {
		$term_ids[] = $ids[ $lname ];
		update_post_meta( $pid, 'wcmlim_stock_at_' . $ids[ $lname ], $qty );
	}
	wp_set_object_terms( $pid, $term_ids, 'locations' );
}

// Zona de envío Venezuela con dos reglas de Advanced Shipping.
$zone_id = 0;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	if ( 'Venezuela' === $z['zone_name'] ) { $zone_id = $z['id']; }
}
if ( ! $zone_id ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'Venezuela' );
	$zone->add_location( 'VE', 'country' );
	$zone_id = $zone->save();
}
$zone = new WC_Shipping_Zone( $zone_id );
if ( empty( $zone->get_shipping_methods() ) ) {
	$rules = array(
		array( 'Retiro en tienda', '0', 'ts_sede_pickup', '==', 'yes' ),
		array( 'Envío nacional', '5', 'ts_sede_pickup', '==', 'no' ),
	);
	foreach ( $rules as $r ) {
		$instance_id = $zone->add_shipping_method( 'advanced_shipping' );
		update_option( 'woocommerce_advanced_shipping_' . $instance_id . '_settings', array(
			'title'           => $r[0],
			'shipping_cost'   => $r[1],
			'handling_fee'    => '',
			'cost_per_weight' => '',
			'cost_per_item'   => '',
			'tax'             => 'not_taxable',
			'conditions'      => array( 1 => array( 1 => array( 'condition' => $r[2], 'operator' => $r[3], 'value' => $r[4] ) ) ),
		) );
	}
}

// Total Sucursales: sin geocodificador (no hay red hacia Nominatim en este entorno).
update_option( 'ts_settings', array( 'radius_km' => 10, 'detect_state' => 'gps', 'geocoder' => 'none', 'checkout_geo_button' => 'yes', 'show_distance_info' => 'yes', 'single_location_view' => 'no' ) );
delete_transient( 'ts_locations_index' );

echo "Sucursales: " . wp_json_encode( $ids ) . "\nZona: $zone_id\n";
