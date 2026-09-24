<?php
/**
 * Municipio en la ficha de la tienda (Productos → Locations de Multi Locations).
 *
 * Multi Locations sólo tiene un campo de texto "City". En Venezuela se cambia por un desplegable
 * con los municipios del estado elegido (datos de States and Municipalities of Venezuela):
 *   - el municipio queda guardado en la tienda (meta ts_municipio), que es lo que usa el retiro por
 *     municipio, sin depender de cómo se escribió la ciudad;
 *   - el campo City de Multi Locations se rellena con la ciudad del municipio (su capital:
 *     Lagunillas → Ciudad Ojeda), que es lo que se muestra en la ficha de producto.
 * Queda la opción "Otra ciudad" para escribirla a mano, y fuera de Venezuela el campo no cambia.
 * No se modifica Multi Locations: el script trabaja sobre su formulario y el guardado es aparte.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Location_Admin {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Antes de que TS_Locations vacíe su caché (prioridad 10).
		add_action( 'created_locations', array( __CLASS__, 'save' ), 5 );
		add_action( 'edited_locations', array( __CLASS__, 'save' ), 5 );
	}

	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'locations' !== $screen->taxonomy || ! TS_Municipios::has_data() ) {
			return;
		}

		$states = array();
		foreach ( TS_Municipios::all() as $state => $list ) {
			foreach ( $list as $key => $m ) {
				$states[ $state ][] = array( 'key' => $key, 'name' => $m['name'], 'capital' => $m['capital'] );
			}
		}
		$current = '';
		if ( 'term.php' === $hook && ! empty( $_GET['tag_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current = TS_Municipios::of_location( absint( $_GET['tag_ID'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		wp_enqueue_script( 'ts-admin-locations', TS_PLUGIN_URL . 'assets/js/ts-admin-locations.js', array( 'jquery' ), TS_VERSION, true );
		wp_localize_script( 'ts-admin-locations', 'tsLocAdmin', array(
			'states'  => $states,
			'current' => $current,
			'i18n'    => array(
				'label'       => __( 'Municipio', 'total-sucursales' ),
				'choose'      => __( '— Elige el municipio —', 'total-sucursales' ),
				'state_first' => __( '— Elige primero el estado —', 'total-sucursales' ),
				'other'       => __( 'Otra ciudad (escribir a mano)', 'total-sucursales' ),
				'hint'        => __( 'Municipio de la tienda (Total Sucursales). La ciudad se rellena sola y el retiro por municipio lo reconoce sin más ajustes.', 'total-sucursales' ),
			),
		) );
	}

	/**
	 * WordPress ya comprobó el nonce del formulario de la etiqueta y el permiso de edición antes de
	 * disparar created_/edited_locations.
	 */
	public static function save( $term_id ) {
		if ( ! isset( $_POST['ts_municipio'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$key = sanitize_text_field( wp_unslash( $_POST['ts_municipio'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( TS_Municipios::exists( $key ) ) {
			update_term_meta( (int) $term_id, TS_Municipios::LOCATION_META, $key );
		} else {
			delete_term_meta( (int) $term_id, TS_Municipios::LOCATION_META );
		}
	}
}
