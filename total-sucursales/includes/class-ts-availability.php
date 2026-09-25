<?php
/**
 * Producto sin stock en ninguna de las tiendas que ve el cliente.
 *
 * WooCommerce mira el stock total (la suma de todas las tiendas): si sólo hay en una tienda de otra
 * ciudad o estado, para él el producto está disponible y enseña "Añadir al carrito", pero Multi
 * Locations no deja añadirlo sin una tienda con stock y el botón no hace nada. Aquí, para ese cliente,
 * el producto pasa a "no disponible": sin botón de compra y con un aviso claro ("No disponible en
 * ...", texto editable).
 *
 * Mismo criterio que el filtro de catálogo: cuenta como disponible en una tienda si tiene stock (> 0)
 * o si el producto admite reservas y la tienda no las niega. Los productos que no gestionan stock, o
 * que no usan el stock por tienda de Multi Locations, no se tocan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Availability {

	/** @var array<int,bool> Caché por request: id => ¿no disponible para este cliente? */
	private static $cache = array();

	public static function init() {
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'is_in_stock' ), 20, 2 );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'availability_text' ), 20, 2 );
		// Multi Locations reescribe el HTML del stock con su propio texto ("Agotado"): va después.
		add_filter( 'woocommerce_get_stock_html', array( __CLASS__, 'stock_html' ), 20, 2 );
	}

	public static function stock_html( $html, $product = null ) {
		if ( ! $product instanceof WC_Product || ! self::unavailable_here( $product ) ) {
			return $html;
		}
		return '<p class="stock out-of-stock ts-not-available-here">' . esc_html( self::availability_text( '', $product ) ) . '</p>';
	}

	public static function is_in_stock( $in_stock, $product ) {
		if ( ! $in_stock || ! $product instanceof WC_Product ) {
			return $in_stock;
		}
		return self::unavailable_here( $product ) ? false : $in_stock;
	}

	public static function availability_text( $text, $product ) {
		if ( ! $product instanceof WC_Product || ! self::unavailable_here( $product ) ) {
			return $text;
		}
		return strtr( TS_Texts::get( 'not_available_here' ), array( '{tiendas}' => self::visible_names() ) );
	}

	/**
	 * ¿Tiene stock (o admite reservas) en otras tiendas pero en ninguna de las que ve el cliente?
	 */
	public static function unavailable_here( WC_Product $product ) {
		$id = $product->get_id();
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		self::$cache[ $id ] = false;
		if ( ! TS_Location_Filter::applies() || ! TS_Location_Filter::excluded_ids() ) {
			return false; // Admin, o el cliente ve todas las tiendas: nada que corregir.
		}
		if ( $product->is_type( 'variable' ) ) {
			$children = $product->get_children();
			if ( ! $children ) {
				return false;
			}
			foreach ( $children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child || ! self::unavailable_here( $child ) ) {
					return false;
				}
			}
			return self::$cache[ $id ] = true;
		}
		if ( ! $product->managing_stock() ) {
			return false;
		}
		$pid     = $product->get_id();
		$visible = TS_Location_Filter::visible_ids();
		$all     = TS_Locations::all_ids();
		$uses    = false; // ¿Usa el stock por tienda de Multi Locations?
		foreach ( $all as $loc ) {
			if ( '' !== (string) get_post_meta( $pid, 'wcmlim_stock_at_' . $loc, true ) ) {
				$uses = true;
				break;
			}
		}
		if ( ! $uses ) {
			return false;
		}
		$backorders = $product->backorders_allowed();
		foreach ( $visible as $loc ) {
			if ( (float) get_post_meta( $pid, 'wcmlim_stock_at_' . $loc, true ) > 0 ) {
				return false;
			}
			if ( $backorders && 'no' !== strtolower( (string) get_post_meta( $pid, 'wcmlim_allow_backorder_at_' . $loc, true ) ) ) {
				return false;
			}
		}
		return self::$cache[ $id ] = true;
	}

	/**
	 * "LA LIMPIA, SANTA RITA ni TOTAL NORTE" (las tiendas que ve el cliente).
	 */
	private static function visible_names() {
		$names = array_values( array_filter( array_map( array( 'TS_Locations', 'name' ), TS_Location_Filter::visible_ids() ) ) );
		if ( count( $names ) < 2 ) {
			return implode( '', $names );
		}
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' ' . __( 'ni', 'total-sucursales' ) . ' ' . $last;
	}
}
