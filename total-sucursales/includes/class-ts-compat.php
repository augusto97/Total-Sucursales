<?php
/**
 * Parches de compatibilidad para fallos de Multi Locations que provocan errores 500.
 *
 * Son mínimos y reversibles: no se toca el código del plugin de pago (que además se
 * sobrescribe en cada actualización). Se pueden desactivar desde los ajustes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Compat {

	public static function init() {
		if ( ! TS_Settings::is_yes( 'mli_shims' ) ) {
			return;
		}

		self::define_distance_function();

		// Evita el fatal cuando Multi Locations pide el stock de un producto que no existe.
		foreach ( array( 'wp_ajax_wcmlim_get_quantity_attributes', 'wp_ajax_nopriv_wcmlim_get_quantity_attributes' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'guard_quantity_attributes' ), 1 );
		}

		// Evita el bucle de recargas y el "¿Cambiar de tienda?" cuando no hay cambio de sucursal.
		foreach ( array( 'wp_ajax_wcmlim_ajax_cart_count', 'wp_ajax_nopriv_wcmlim_ajax_cart_count' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'guard_cart_count' ), 1 );
		}

		// "Añadir al carrito" sin stock suficiente en la tienda: avisar en el momento, una vez.
		foreach ( array( 'wp_ajax_wcmlim_ajax_add_to_cart', 'wp_ajax_nopriv_wcmlim_ajax_add_to_cart' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'guard_add_to_cart' ), 1 );
		}
		add_action( 'template_redirect', array( __CLASS__, 'dedupe_notices' ), 1 );

		// Precio por tienda en el carrito sin formato (decimales de más, sin moneda).
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'format_cart_item_price' ), 11, 3 );

		// Selector de tiendas de la ficha dibujado fuera de la ficha (vistas rápidas, bloques).
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'display_location_start' ), 1 );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'display_location_end' ), 11 );
	}

	/**
	 * Con el precio por tienda activado, Multi Locations (wcmlim_cart_item_price) devuelve el precio
	 * de cada línea del carrito tal cual está guardado, sin pasar por wc_price(): un precio de
	 * 156.3912 sale con sus 4 decimales y sin símbolo de moneda, aunque WooCommerce esté configurado
	 * con 2. Aquí se le vuelve a dar el formato de WooCommerce (decimales, moneda, separadores y
	 * con o sin impuestos según los ajustes del carrito). Si ya viene formateado, no se toca.
	 */
	public static function format_cart_item_price( $price, $cart_item = array(), $cart_item_key = '' ) {
		if ( ! isset( $cart_item['select_location'], $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
			return $price;
		}
		if ( is_string( $price ) && false !== strpos( $price, '<' ) ) {
			return $price; // Ya es HTML (wc_price): nada que corregir.
		}
		$product = $cart_item['data'];
		$raw     = trim( wp_strip_all_tags( (string) $price ) );
		if ( is_numeric( $raw ) ) {
			$args = array( 'price' => (float) $raw );
			$show = WC()->cart && WC()->cart->display_prices_including_tax()
				? wc_get_price_including_tax( $product, $args )
				: wc_get_price_excluding_tax( $product, $args );
			return wc_price( $show );
		}
		// Texto sin formato ("$156.39" sin su HTML): el precio de la línea con el formato normal.
		return WC()->cart ? WC()->cart->get_product_price( $product ) : $price;
	}

	/** @var array|null Estado guardado por display_location_start(). */
	private static $display_location = null;

	/**
	 * Multi Locations dibuja su selector de tiendas (wcmlim_display_location, prioridad 10) con
	 * wc_get_product( $post->ID ), es decir, el producto de la entrada actual y no el que se está
	 * mostrando. En una vista rápida de producto dentro de una página (por ejemplo el bloque
	 * "Product Quick View" de GreenShift, también al abrir la página en el editor), $post es la
	 * página, wc_get_product() devuelve false y salta "Call to a member function get_price_html()
	 * on false": error fatal.
	 *
	 * Mientras se dibuja, $post pasa a ser el producto que se muestra (el global $product), así que
	 * el selector sale con las tiendas del producto correcto; justo después se restaura. Si no hay
	 * producto que mostrar, ese selector se omite en lugar de romper la página.
	 */
	public static function display_location_start() {
		$post    = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
		if ( $product instanceof WC_Product ) {
			if ( $post instanceof WP_Post && (int) $post->ID === (int) $product->get_id() ) {
				return;
			}
			$target = get_post( $product->get_id() );
			if ( $target ) {
				self::$display_location = array( 'post' => $post );
				$GLOBALS['post']        = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
			return;
		}
		if ( $post instanceof WP_Post && wc_get_product( $post->ID ) ) {
			return;
		}
		$cb = self::mli_display_callback();
		if ( $cb ) {
			remove_action( 'woocommerce_before_add_to_cart_button', $cb, 10 );
			self::$display_location = array( 'removed' => $cb );
		}
	}

	public static function display_location_end() {
		if ( null === self::$display_location ) {
			return;
		}
		if ( array_key_exists( 'post', self::$display_location ) ) {
			$GLOBALS['post'] = self::$display_location['post']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
		if ( isset( self::$display_location['removed'] ) ) {
			add_action( 'woocommerce_before_add_to_cart_button', self::$display_location['removed'], 10 );
		}
		self::$display_location = null;
	}

	/**
	 * El callback de Multi Locations (Wcmlim_Public::wcmlim_display_location) tal como está enganchado.
	 */
	private static function mli_display_callback() {
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

	/**
	 * El "Añadir al carrito" por AJAX de Multi Locations no avisa cuando la tienda no tiene stock
	 * suficiente:
	 * - Si su validación falla (por ejemplo "We don't have enough stock to fulfill your request"),
	 *   deja el aviso en la sesión y contesta vacío: el botón parece no hacer nada y, con cada clic,
	 *   se acumula otro aviso que sale junto a los demás en la siguiente página que se cargue (al
	 *   cambiar de tienda, por ejemplo).
	 * - Si la cantidad supera el stock de la tienda contesta "4", que su JavaScript no trata, y no se
	 *   ve nada.
	 *
	 * En los dos casos se contesta como lo hace WooCommerce cuando no puede añadir un producto
	 * ({error, product_url}), que su JavaScript ya entiende: lleva a la ficha del producto y ahí sale
	 * el aviso, una sola vez. El resto de respuestas pasan tal cual.
	 */
	public static function guard_add_to_cart() {
		ob_start();
		self::$add_to_cart_level = ob_get_level();
		add_filter( 'wp_die_ajax_handler', array( __CLASS__, 'add_to_cart_die_handler_name' ), PHP_INT_MAX );
	}

	/** @var int Nivel del búfer abierto en guard_add_to_cart(). */
	private static $add_to_cart_level = 0;

	public static function add_to_cart_die_handler_name() {
		return array( __CLASS__, 'add_to_cart_die_handler' );
	}

	public static function add_to_cart_die_handler( $message, $title = '', $args = array() ) {
		remove_filter( 'wp_die_ajax_handler', array( __CLASS__, 'add_to_cart_die_handler_name' ), PHP_INT_MAX );
		$out = '';
		if ( self::$add_to_cart_level && ob_get_level() >= self::$add_to_cart_level ) {
			while ( ob_get_level() > self::$add_to_cart_level ) {
				ob_end_flush();
			}
			$out = (string) ob_get_clean();
		}
		$code = trim( $out );
		if ( '4' === $code ) {
			self::add_notice_once( TS_Texts::get( 'mli_not_enough_stock' ) );
		}
		if ( '4' === $code || ( '' === $code && function_exists( 'wc_notice_count' ) && wc_notice_count( 'error' ) > 0 ) ) {
			// Un visitante con el carrito vacío aún no tiene sesión: sin ella el aviso no llegaría a la ficha.
			if ( WC()->session && ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			$pid = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			wp_send_json( array(
				'error'       => true,
				'product_url' => apply_filters( 'woocommerce_cart_redirect_after_error', $pid ? get_permalink( $pid ) : wc_get_cart_url(), $pid ),
			) );
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- respuesta original de Multi Locations.
		_ajax_wp_die_handler( $message, $title, $args );
	}

	private static function add_notice_once( $text ) {
		foreach ( wc_get_notices( 'error' ) as $n ) {
			if ( ( is_array( $n ) ? $n['notice'] : $n ) === $text ) {
				return;
			}
		}
		wc_add_notice( $text, 'error' );
	}

	/**
	 * Quita los avisos repetidos (mismo tipo y texto) antes de mostrarlos: algunos flujos de Multi
	 * Locations validan el mismo producto varias veces y apilan el mismo aviso.
	 */
	public static function dedupe_notices() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$all = WC()->session->get( 'wc_notices', array() );
		if ( ! is_array( $all ) || ! $all ) {
			return;
		}
		$changed = false;
		foreach ( $all as $type => $list ) {
			$seen = array();
			$keep = array();
			foreach ( (array) $list as $n ) {
				$key = is_array( $n ) && isset( $n['notice'] ) ? (string) $n['notice'] : (string) $n;
				if ( isset( $seen[ $key ] ) ) {
					$changed = true;
					continue;
				}
				$seen[ $key ] = true;
				$keep[]       = $n;
			}
			$all[ $type ] = $keep;
		}
		if ( $changed ) {
			WC()->session->set( 'wc_notices', $all );
		}
	}

	/**
	 * Multi Locations declara distance_between_coordinates() dentro de un trait y dentro de una
	 * clase, pero su controlador wcmlim-closest-location.php la llama como función global (sin
	 * $this->). Por eso lanza "Call to undefined function" y devuelve 500 en cada llamada.
	 *
	 * Aquí se declara la función global con la misma fórmula y el mismo redondeo que usa Multi
	 * Locations, para que sus resultados no cambien. Nunca hay conflicto porque el plugin no la
	 * declara en el ámbito global en ningún momento.
	 */
	private static function define_distance_function() {
		if ( function_exists( 'distance_between_coordinates' ) ) {
			return;
		}

		/**
		 * Distancia entre dos coordenadas, igual que la de Multi Locations (millas por defecto).
		 */
		function distance_between_coordinates( $latitude1, $longitude1, $latitude2, $longitude2, $unit = 'miles' ) {
			if ( ! is_numeric( $latitude1 ) || ! is_numeric( $longitude1 ) || ! is_numeric( $latitude2 ) || ! is_numeric( $longitude2 ) ) {
				return 0;
			}

			$theta    = $longitude1 - $longitude2;
			$distance = ( sin( deg2rad( $latitude1 ) ) * sin( deg2rad( $latitude2 ) ) )
				+ ( cos( deg2rad( $latitude1 ) ) * cos( deg2rad( $latitude2 ) ) * cos( deg2rad( $theta ) ) );
			$distance = acos( min( 1.0, max( -1.0, $distance ) ) );
			$distance = rad2deg( $distance );
			$distance = $distance * 60 * 1.1515;
			$distance = round( $distance, 2 );

			if ( 'kilometers' === $unit ) {
				$distance *= 1.609344;
			}

			return $distance;
		}
	}

	/**
	 * Multi Locations manda como "currentProductId" el ID de la entrada actual (wcmlim_product_data
	 * se rellena con $post->ID), así que fuera de la ficha de producto viaja el ID de una página.
	 * Su handler llama a $product->is_type() sin comprobar que wc_get_product() haya devuelto algo,
	 * y la petición acaba en error 500.
	 *
	 * Se responde antes, con el mismo formato que usa el propio plugin cuando no tiene nada que
	 * calcular (wp_send_json_success, es decir response.data en su JavaScript). Es importante
	 * respetar ese formato: si se contesta sin la clave "data", wcmlim-public.js revienta con
	 * "Cannot read properties of undefined (reading 'backorder')".
	 *
	 * Las claves elegidas reproducen la salida de su propia salida temprana (la de "selectedLocation
	 * = -1"): sin "backorder" ni "stock" no se oculta el botón de compra, con threshold_text a null
	 * no se reescribe el mensaje y con is_prodict_variable a true no se inyecta ningún texto nuevo.
	 */
	public static function guard_quantity_attributes() {
		$id = isset( $_POST['currentProductId'] ) ? absint( wp_unslash( $_POST['currentProductId'] ) ) : 0;
		if ( $id && wc_get_product( $id ) ) {
			return; // El producto existe: que siga Multi Locations.
		}

		wp_send_json_success( array(
			'status'              => 'Failed!',
			'message'             => 'invalid product id',
			'instock'             => (string) get_option( 'wcmlim_instock_button_text' ),
			'soldout'             => (string) get_option( 'wcmlim_soldout_button_text' ),
			'is_prodict_variable' => true,
			'threshold_text'      => null,
			'ts_shim'             => true,
		) );
	}

	/**
	 * El selector de sucursal de Multi Locations consulta wcmlim_ajax_cart_count en cada evento
	 * "change", incluidos los que dispara su propio JavaScript al cargar la página. Ese handler no
	 * comprueba si la sucursal pedida es la que ya estaba activa:
	 *
	 * - Si no hay cambio real, termina en die() con una respuesta vacía, y su JavaScript interpreta
	 *   cualquier respuesta que no empiece por "{" como "recarga la página". Al recargar se repite el
	 *   mismo evento, y de ahí el bucle de recargas.
	 * - Si el selector está en "Select" (-1), resuelve la sucursal de destino a null y todos los
	 *   artículos del carrito le parecen de otra sucursal, así que saca el diálogo "¿Cambiar de
	 *   tienda?" con la misma sucursal a los dos lados.
	 *
	 * Aquí se corta antes en esos dos casos, que por definición no tienen nada que migrar. Un cambio
	 * de sucursal de verdad sigue pasando entero a Multi Locations, con su diálogo y su recarga.
	 */
	public static function guard_cart_count() {
		if ( ! isset( $_POST['e_value'] ) ) {
			return;
		}

		// El nonce lo comprueba Multi Locations; si no es válido, que conteste su propio error.
		$nonce = isset( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wcmlim_locations_nonce' ) ) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST['e_value'] ) );

		// "Select": no se ha elegido sucursal, así que no hay cambio que aplicar.
		if ( '' === $value || '-1' === $value ) {
			self::end_cart_count( 'sin sucursal elegida' );
		}

		$target  = TS_Locations::mli_term_at( $value );
		$current = isset( $_COOKIE['wcmlim_selected_location_termid'] )
			? absint( wp_unslash( $_COOKIE['wcmlim_selected_location_termid'] ) )
			: 0;

		if ( $target && $target === $current ) {
			self::end_cart_count( 'la sucursal pedida ya era la activa' );
		}
	}

	/**
	 * Respuesta inerte para wcmlim_ajax_cart_count.
	 *
	 * Tiene que salir como texto y empezar por "{": su JavaScript sólo deja la página en paz cuando
	 * recibe una cadena con un JSON cuyo "status" no reconoce. Con wp_send_json() la respuesta llega
	 * como application/json, jQuery la convierte en objeto y el "else" acabaría recargando la página,
	 * que es justo lo que se quiere evitar.
	 */
	private static function end_cart_count( $reason ) {
		echo wp_json_encode( array(
			'status'  => 'ts_sin_cambio',
			'message' => $reason,
		) );
		wp_die();
	}

	/**
	 * Estado de los parches, para el diagnóstico.
	 *
	 * @return array<string,string>
	 */
	public static function status() {
		if ( ! TS_Settings::is_yes( 'mli_shims' ) ) {
			return array( 'mli_shims' => __( 'Parches de compatibilidad desactivados en los ajustes.', 'total-sucursales' ) );
		}
		return array(
			'distance_between_coordinates' => function_exists( 'distance_between_coordinates' )
				? __( 'Activo: se suple la función que falta en Multi Locations, así que su "closest location" deja de dar error 500.', 'total-sucursales' )
				: __( 'No aplicado.', 'total-sucursales' ),
			'wcmlim_get_quantity_attributes' => __( 'Activo: se responde a las peticiones de stock con un producto inexistente antes de que Multi Locations falle, y con el formato que espera su JavaScript.', 'total-sucursales' ),
			'wcmlim_cart_item_price' => __( 'Activo: con el precio por tienda, el precio de cada línea del carrito sale con el formato de WooCommerce (sus decimales y moneda) en lugar del número tal cual.', 'total-sucursales' ),
			'wcmlim_display_location' => __( 'Activo: el selector de tiendas de la ficha también funciona en vistas rápidas de producto dentro de otras páginas (antes daba error fatal, también en el editor).', 'total-sucursales' ),
			'wcmlim_ajax_add_to_cart' => __( 'Activo: si "Añadir al carrito" no puede añadir el producto por falta de stock en la tienda, se lleva al cliente a la ficha con el aviso, una sola vez (Multi Locations no lo mostraba y lo iba acumulando).', 'total-sucursales' ),
			'wcmlim_ajax_cart_count' => __( 'Activo: se descartan las consultas del selector de sucursal que no suponen ningún cambio, que son las que dejaban la página recargándose en bucle y sacaban el diálogo "¿Cambiar de tienda?" con la misma sucursal a los dos lados.', 'total-sucursales' ),
		);
	}
}
