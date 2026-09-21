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
	 * Multi Locations llama a $product->is_type() sin comprobar que el producto exista, así que
	 * cualquier petición con un ID inválido termina en error 500. Se responde antes con un error
	 * limpio, que es lo que su propio JavaScript espera de una petición sin resultados.
	 */
	public static function guard_quantity_attributes() {
		$id = isset( $_POST['currentProductId'] ) ? absint( wp_unslash( $_POST['currentProductId'] ) ) : 0;
		if ( $id && wc_get_product( $id ) ) {
			return; // El producto existe: que siga Multi Locations.
		}
		wp_send_json( array(
			'status'  => 'Failed!',
			'message' => 'invalid product id',
		) );
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
			'wcmlim_get_quantity_attributes' => __( 'Activo: se descartan las peticiones de stock con un producto inexistente antes de que Multi Locations falle.', 'total-sucursales' ),
		);
	}
}
