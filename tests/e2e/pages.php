<?php
// Uso: wp eval-file pages.php blocks|classic
$mode = isset( $args[0] ) ? $args[0] : 'blocks';
if ( 'blocks' === $mode ) {
	$r = new ReflectionClass( 'WC_Install' );
	$cart = $r->getMethod( 'get_cart_block_content' ); $cart->setAccessible( true );
	$co   = $r->getMethod( 'get_checkout_block_content' ); $co->setAccessible( true );
	wp_update_post( array( 'ID' => wc_get_page_id( 'cart' ), 'post_content' => $cart->invoke( null ) ) );
	wp_update_post( array( 'ID' => wc_get_page_id( 'checkout' ), 'post_content' => $co->invoke( null ) ) );
} else {
	wp_update_post( array( 'ID' => wc_get_page_id( 'cart' ), 'post_content' => '[woocommerce_cart]' ) );
	wp_update_post( array( 'ID' => wc_get_page_id( 'checkout' ), 'post_content' => '[woocommerce_checkout]' ) );
}
echo "pages: $mode\n";
