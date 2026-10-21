<?php
/**
 * Producto sin stock en la tienda del cliente, pero con stock en otras tiendas que también ve.
 *
 * WooCommerce mira el stock total, así que la ficha decía "13 disponibles" y enseñaba "Añadir al
 * carrito" normal. Multi Locations, al pulsar, intenta añadirlo en la tienda elegida (sin stock),
 * falla sin decir nada y el botón parece que añade pero el carrito sigue vacío.
 *
 * En la ficha de ese producto:
 *   - El stock dice "Sin stock en {tienda}" (texto editable).
 *   - Junto al botón de compra sale un aviso con las tiendas que sí lo tienen y un botón para elegir
 *     otra (abre el selector de Multi Locations: panel lateral, ventana, desplegable o lista).
 *   - "Añadir al carrito" no hace nada hasta que se elige una tienda con stock: resalta el aviso.
 * Y, venga de donde venga el "Añadir al carrito" de Multi Locations (ficha, listados, vistas
 * rápidas), si la tienda no tiene stock se contesta con un aviso en lugar de fallar en silencio; si
 * lo tiene, sus validaciones miran esa tienda y no la de la cabecera.
 *
 * Mismo criterio que TS_Availability (stock > 0, o reservas admitidas y la tienda no las niega). Los
 * productos que no usan el stock por tienda de Multi Locations no se tocan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Store_Stock {

	/** @var array<int,array|false> Caché por request: id de producto => estado. */
	private static $cache = array();

	public static function init() {
		// Después de TS_Availability (20) y de la traducción del stock de Multi Locations (15).
		add_filter( 'woocommerce_get_stock_html', array( __CLASS__, 'stock_html' ), 25, 2 );
		// Tras el selector de Multi Locations (10) y TS_Loop_Location (12).
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_notice' ), 15 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		// Después de TS_Loop_Location::fill_location (0), que pone la tienda si no llega ninguna.
		foreach ( array( 'wp_ajax_wcmlim_ajax_add_to_cart', 'wp_ajax_nopriv_wcmlim_ajax_add_to_cart' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'guard_add_to_cart' ), 1 );
		}
	}

	/**
	 * Para el producto de la ficha abierta: array( 'store' => id, 'ok' => ids con stock ) si la tienda
	 * del cliente no lo tiene y otra que ve sí; si no, null.
	 */
	public static function status( $product ) {
		if ( ! $product instanceof WC_Product || $product->is_type( 'variable' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}
		if ( (int) $product->get_id() !== (int) get_queried_object_id() ) {
			return null;
		}
		$id = $product->get_id();
		if ( ! isset( self::$cache[ $id ] ) ) {
			self::$cache[ $id ] = self::compute( $product );
		}
		return self::$cache[ $id ] ? self::$cache[ $id ] : null;
	}

	private static function compute( WC_Product $product ) {
		if ( ! TS_Location_Filter::applies() || ! TS_Availability::uses_store_stock( $product ) ) {
			return false;
		}
		$store = TS_Customer::selected_mli_location_id();
		if ( ! $store || ! TS_Locations::get( $store ) || TS_Availability::available_at( $product, $store ) ) {
			return false;
		}
		$ok = self::stores_with_stock( $product, $store );
		return $ok ? array( 'store' => $store, 'ok' => $ok ) : false;
	}

	/**
	 * Tiendas que ve el cliente (menos $except) donde se puede comprar el producto.
	 */
	private static function stores_with_stock( WC_Product $product, $except ) {
		$ok = array();
		foreach ( TS_Location_Filter::visible_ids() as $loc ) {
			if ( (int) $loc !== (int) $except && TS_Availability::available_at( $product, $loc ) ) {
				$ok[] = (int) $loc;
			}
		}
		return $ok;
	}

	public static function stock_html( $html, $product = null ) {
		$st = self::status( $product );
		if ( ! $st ) {
			return $html;
		}
		$text = strtr( TS_Texts::get( 'store_no_stock' ), array( '{tienda}' => TS_Locations::name( $st['store'] ) ) );
		return '<p class="stock out-of-stock ts-store-no-stock">' . esc_html( $text ) . '</p>';
	}

	public static function render_notice() {
		if ( ! TS_Loop_Location::is_main_product_form() ) {
			return;
		}
		$st = self::status( isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null );
		if ( ! $st ) {
			return;
		}
		echo '<div class="ts-store-stock" role="alert" data-ok="' . esc_attr( wp_json_encode( $st['ok'] ) ) . '">'
			. '<p class="ts-store-stock__text">' . esc_html( self::notice_text( $st['store'], $st['ok'] ) ) . '</p>'
			. '<button type="button" class="ts-store-stock__pick button">' . esc_html( TS_Texts::get( 'store_no_stock_pick' ) ) . '</button>'
			. '</div>';
	}

	private static function notice_text( $store, $ok ) {
		$name = TS_Locations::name( $store );
		if ( ! $ok ) {
			return strtr( TS_Texts::get( 'not_available_here' ), array( '{tiendas}' => $name ) );
		}
		return strtr( TS_Texts::get( 'store_no_stock_notice' ), array(
			'{tienda}'  => $name,
			'{tiendas}' => self::names( $ok ),
		) );
	}

	/**
	 * "Tienda Chacao, Tienda Delicias y Tienda Valencia".
	 */
	private static function names( $ids ) {
		$names = array_values( array_filter( array_map( array( 'TS_Locations', 'name' ), $ids ) ) );
		if ( count( $names ) < 2 ) {
			return implode( '', $names );
		}
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' ' . __( 'y', 'total-sucursales' ) . ' ' . $last;
	}

	public static function enqueue() {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		wp_enqueue_script( 'ts-store-stock', TS_PLUGIN_URL . 'assets/js/ts-store-stock.js', array( 'jquery' ), TS_VERSION, true );
	}

	/**
	 * "Añadir al carrito" de Multi Locations en una tienda sin stock: aviso y vuelta a la ficha (su JS
	 * sigue "product_url" cuando llega "error"), en lugar de una respuesta vacía.
	 */
	public static function guard_add_to_cart() {
		// phpcs:disable WordPress.Security.NonceVerification -- Multi Locations valida la petición.
		$tid = isset( $_POST['product_location_termid'] ) ? sanitize_text_field( wp_unslash( $_POST['product_location_termid'] ) ) : '';
		$pid = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$vid = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
		// phpcs:enable
		if ( ! ctype_digit( $tid ) || ! (int) $tid || ! TS_Locations::get( (int) $tid ) ) {
			return;
		}
		$tid     = (int) $tid;
		$product = wc_get_product( $vid ? $vid : $pid );
		if ( ! $product || ! TS_Availability::uses_store_stock( $product ) ) {
			return;
		}
		if ( TS_Availability::available_at( $product, $tid ) ) {
			self::align_cookie( $tid );
			return;
		}
		$text = self::notice_text( $tid, self::stores_with_stock( $product, $tid ) );
		if ( ! in_array( $text, array_map( array( __CLASS__, 'notice_body' ), wc_get_notices( 'error' ) ), true ) ) {
			wc_add_notice( $text, 'error' );
		}
		// Un visitante con el carrito vacío aún no tiene sesión: sin ella el aviso no llegaría a la ficha.
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		$parent = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		wp_send_json( array(
			'error'       => true,
			'product_url' => apply_filters( 'woocommerce_cart_redirect_after_error', get_permalink( $parent ), $parent ),
		) );
	}

	/**
	 * Las validaciones de Multi Locations miran la tienda de la cookie (la de la cabecera), no la
	 * elegida en el formulario: con la de la cabecera sin stock rechazaban el alta en otra tienda que
	 * sí lo tiene. Sólo para esta petición, la cookie pasa a ser la tienda elegida.
	 */
	private static function align_cookie( $tid ) {
		if ( (int) TS_Customer::selected_mli_location_id() === $tid ) {
			return;
		}
		$index = TS_Locations::mli_index_of( $tid );
		if ( null === $index ) {
			return;
		}
		$_COOKIE['wcmlim_selected_location']        = (string) $index;
		$_COOKIE['wcmlim_selected_location_termid'] = (string) $tid;
	}

	private static function notice_body( $n ) {
		return is_array( $n ) && isset( $n['notice'] ) ? $n['notice'] : (string) $n;
	}
}
