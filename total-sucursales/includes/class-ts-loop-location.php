<?php
/**
 * Selector de tiendas de Multi Locations sólo en la ficha del producto.
 *
 * Multi Locations engancha su selector ("Disponibilidad por tienda") a
 * woocommerce_before_add_to_cart_button, así que sale en cualquier sitio que pinte el formulario de
 * compra de un producto: listados con cantidad y botón de compra, vistas rápidas, productos
 * relacionados de algunos temas... En una tarjeta de producto no cabe y se ve mal.
 *
 * Con "Selector de tiendas sólo en la ficha del producto" (activado por defecto):
 *   - Fuera de la ficha del propio producto el selector no se dibuja.
 *   - Al añadir al carrito desde ahí, Multi Locations recibe la tienda elegida en la cabecera (sin
 *     selector no sabe de qué tienda es y contesta "selecciona una tienda").
 *   - Multi Locations toma la cantidad del primer campo de cantidad de la página, no del producto
 *     pulsado; ts-frontend.js manda la del formulario del botón (ver loopQuantity).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Loop_Location {

	/** @var callable|null Callback de Multi Locations quitado mientras se pinta un formulario. */
	private static $removed = null;

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		// Antes que el selector de Multi Locations (10) y que el parche de TS_Compat (1).
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'start' ), 0 );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'end' ), 12 );
		foreach ( array( 'wp_ajax_wcmlim_ajax_add_to_cart', 'wp_ajax_nopriv_wcmlim_ajax_add_to_cart' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'fill_location' ), 0 );
		}
	}

	public static function enabled() {
		return TS_Settings::is_yes( 'selector_only_single' );
	}

	/**
	 * ¿Se está pintando el formulario de compra del producto de la ficha abierta?
	 */
	public static function is_main_product_form() {
		$product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}
		$id = $product instanceof WC_Product ? $product->get_id() : ( isset( $GLOBALS['post'] ) ? (int) $GLOBALS['post']->ID : 0 );
		return (int) $id === (int) get_queried_object_id();
	}

	public static function start() {
		if ( self::is_main_product_form() ) {
			return;
		}
		$cb = self::mli_callback();
		if ( $cb ) {
			remove_action( 'woocommerce_before_add_to_cart_button', $cb, 10 );
			self::$removed = $cb;
		}
	}

	public static function end() {
		if ( self::$removed ) {
			add_action( 'woocommerce_before_add_to_cart_button', self::$removed, 10 );
			self::$removed = null;
		}
	}

	/**
	 * Sin selector, el "Añadir al carrito" de Multi Locations llega sin tienda: se completa con la
	 * elegida en la cabecera. Si el cliente eligió una en un selector, no se toca.
	 */
	public static function fill_location() {
		// phpcs:disable WordPress.Security.NonceVerification -- Multi Locations valida la petición.
		$tid = isset( $_POST['product_location_termid'] ) ? sanitize_text_field( wp_unslash( $_POST['product_location_termid'] ) ) : '';
		if ( '' !== $tid && 'undefined' !== $tid && '0' !== $tid ) {
			return;
		}
		$current = TS_Customer::selected_mli_location_id();
		if ( ! $current || ! TS_Locations::get( $current ) ) {
			return;
		}
		$index = TS_Locations::mli_index_of( $current );
		$_POST['product_location_termid'] = (string) $current;
		$_POST['product_location']        = TS_Locations::name( $current );
		$_POST['product_location_key']    = null === $index ? '' : (string) $index;
		// Que Multi Locations las calcule para esta tienda (lo hace si llegan vacías).
		foreach ( array( 'product_location_qty', 'product_location_regular_price', 'product_location_sale_price' ) as $k ) {
			$v = isset( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : '';
			if ( 'undefined' === $v ) {
				$_POST[ $k ] = '';
			}
		}
		// phpcs:enable
	}

	/**
	 * El callback de Multi Locations (Wcmlim_Public::wcmlim_display_location) tal como está enganchado.
	 */
	private static function mli_callback() {
		global $wp_filter;
		if ( empty( $wp_filter['woocommerce_before_add_to_cart_button']->callbacks[10] ) ) {
			return null;
		}
		foreach ( $wp_filter['woocommerce_before_add_to_cart_button']->callbacks[10] as $hook ) {
			$fn = $hook['function'];
			if ( is_array( $fn ) && is_object( $fn[0] ) && 'wcmlim_display_location' === $fn[1] ) {
				return $fn;
			}
		}
		return null;
	}
}
