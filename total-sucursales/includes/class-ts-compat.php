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
			'wcmlim_ajax_cart_count' => __( 'Activo: se descartan las consultas del selector de sucursal que no suponen ningún cambio, que son las que dejaban la página recargándose en bucle y sacaban el diálogo "¿Cambiar de tienda?" con la misma sucursal a los dos lados.', 'total-sucursales' ),
		);
	}
}
