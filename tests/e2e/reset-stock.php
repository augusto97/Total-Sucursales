<?php
/**
 * Repone el stock por sucursal de los productos de prueba.
 * Las corridas de e2e crean pedidos reales y van consumiendo el stock.
 */
$stocks = array(
	'Producto A (Delicias y Chacao)' => array( 'Tienda Delicias' => 10, 'Tienda Chacao' => 5 ),
	'Producto B (San Francisco)'     => array( 'Tienda San Francisco' => 8 ),
	'Producto C (Chacao)'            => array( 'Tienda Chacao' => 6 ),
	'Producto D (Valencia)'          => array( 'Tienda Valencia' => 6 ),
);
$terms = array();
foreach ( get_terms( array( 'taxonomy' => 'locations', 'hide_empty' => false ) ) as $t ) {
	$terms[ $t->name ] = $t->term_id;
}
$q = new WP_Query( array( 'post_type' => 'product', 'posts_per_page' => -1, 'post_status' => 'publish' ) );
foreach ( $q->posts as $post ) {
	if ( ! isset( $stocks[ $post->post_title ] ) ) {
		continue;
	}
	$total = 0;
	foreach ( $stocks[ $post->post_title ] as $loc => $qty ) {
		if ( isset( $terms[ $loc ] ) ) {
			update_post_meta( $post->ID, 'wcmlim_stock_at_' . $terms[ $loc ], $qty );
			$total += $qty;
		}
	}
	$p = wc_get_product( $post->ID );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $total );
	$p->set_stock_status( 'instock' );
	$p->save();
}
update_option( 'ts_catalog_version', time() );
delete_transient( 'ts_locations_index_v2' );
echo "stock repuesto\n";
