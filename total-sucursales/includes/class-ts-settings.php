<?php
/**
 * Ajustes del plugin (WooCommerce > Ajustes > Total Sucursales).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Settings {

	const OPTION = 'ts_settings';

	/** @var array|null */
	private static $cache = null;

	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_tabs_total_sucursales', array( __CLASS__, 'render_tab' ) );
		add_action( 'woocommerce_update_options_total_sucursales', array( __CLASS__, 'save' ) );
	}

	public static function defaults() {
		return array(
			'radius_km'            => 10,
			'detect_state'         => 'gps',      // gps | ask | off
			'state_detect_max_km'  => 100,
			'ask_if_gps_fails'     => 'yes',
			'geocoder'             => 'nominatim', // none | nominatim | google
			'google_api_key'       => '',
			'nominatim_email'      => get_option( 'admin_email' ),
			'checkout_geo_button'  => 'yes',
			'show_distance_info'   => 'yes',
			'single_location_view' => 'no',
			'catalog_filter'       => 'yes',
			'blocks_municipio'     => 'yes',
			// Sin __() aquí: defaults() puede ejecutarse antes de init (WP 6.7+ avisa si se cargan traducciones antes).
			'pickup_label'         => 'Retiro en tienda',
			'modal_title'          => '¿Desde qué estado nos visitas?',
			'modal_text'           => 'Elige tu estado para mostrarte las sucursales y el catálogo disponible en tu zona.',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( isset( $all[ $key ] ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	public static function radius_km() {
		$r = (float) self::get( 'radius_km', 10 );
		return $r > 0 ? $r : 10.0;
	}

	public static function google_api_key() {
		$key = trim( (string) self::get( 'google_api_key', '' ) );
		if ( '' === $key ) {
			$key = trim( (string) get_option( 'wcmlim_google_api_key', '' ) ); // Reutiliza la clave de MLI si existe.
		}
		return $key;
	}

	public static function is_yes( $key ) {
		return 'yes' === self::get( $key );
	}

	public static function add_tab( $tabs ) {
		$tabs['total_sucursales'] = __( 'Total Sucursales', 'total-sucursales' );
		return $tabs;
	}

	public static function fields() {
		$p = self::OPTION;
		return array(
			array(
				'title' => __( 'Sucursales y estado del cliente', 'total-sucursales' ),
				'type'  => 'title',
				'desc'  => __( 'Determina el estado del cliente y restringe las sucursales de Multi Locations a ese estado. Si el estado no tiene sucursales, se muestran todas.', 'total-sucursales' ),
				'id'    => 'ts_section_state',
			),
			array(
				'title'   => __( 'Detección del estado', 'total-sucursales' ),
				'id'      => "{$p}[detect_state]",
				'type'    => 'select',
				'options' => array(
					'gps' => __( 'Ubicación del navegador (GPS) y, si falla, preguntar', 'total-sucursales' ),
					'ask' => __( 'Preguntar siempre con un selector de estado', 'total-sucursales' ),
					'off' => __( 'No detectar (sólo usuarios con dirección guardada o shortcode)', 'total-sucursales' ),
				),
				'default' => 'gps',
			),
			array(
				'title'             => __( 'Distancia máxima para inferir el estado (km)', 'total-sucursales' ),
				'id'                => "{$p}[state_detect_max_km]",
				'type'              => 'number',
				'desc'              => __( 'El estado se toma de la sucursal más cercana a la posición GPS. Si esa sucursal está más lejos que este valor, se pregunta al cliente en lugar de asumir.', 'total-sucursales' ),
				'default'           => 100,
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
			),
			array(
				'title'   => __( 'Título del selector de estado', 'total-sucursales' ),
				'id'      => "{$p}[modal_title]",
				'type'    => 'text',
				'default' => self::defaults()['modal_title'],
			),
			array(
				'title'   => __( 'Texto del selector de estado', 'total-sucursales' ),
				'id'      => "{$p}[modal_text]",
				'type'    => 'textarea',
				'default' => self::defaults()['modal_text'],
			),
			array(
				'title'   => __( 'Filtrar el catálogo por stock de la sucursal seleccionada', 'total-sucursales' ),
				'id'      => "{$p}[catalog_filter]",
				'type'    => 'checkbox',
				'desc'    => __( 'Oculta productos sin stock en la sucursal activa en el loop clásico, shortcodes, bloques Product Collection y Store API. Sustituye a "Display Only In-Stock Items" de Multi Locations (que sólo cubre el loop clásico y es lento).', 'total-sucursales' ),
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Checkout por bloques: municipio como select', 'total-sucursales' ),
				'id'      => "{$p}[blocks_municipio]",
				'type'    => 'checkbox',
				'desc'    => __( 'Registra un select de municipios por estado (datos de States and Municipalities of Venezuela) en el checkout por bloques y oculta el campo de ciudad libre. Sin efecto en el checkout clásico.', 'total-sucursales' ),
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Producto: mostrar sólo la sucursal seleccionada', 'total-sucursales' ),
				'id'      => "{$p}[single_location_view]",
				'type'    => 'checkbox',
				'desc'    => __( 'Oculta en la página de producto las demás sucursales cuando ya hay una seleccionada.', 'total-sucursales' ),
				'default' => 'no',
			),
			array( 'type' => 'sectionend', 'id' => 'ts_section_state' ),

			array(
				'title' => __( 'Retiro en tienda por radio', 'total-sucursales' ),
				'type'  => 'title',
				'desc'  => __( 'La sucursal más cercana al cliente dentro del radio es la única elegible para PICKUP. Las demás sucursales del pedido se tratan como envío nacional. Usa las condiciones "Total Sucursales" en las reglas de Advanced Shipping.', 'total-sucursales' ),
				'id'    => 'ts_section_radius',
			),
			array(
				'title'             => __( 'Radio de retiro (km)', 'total-sucursales' ),
				'id'                => "{$p}[radius_km]",
				'type'              => 'number',
				'default'           => 10,
				'custom_attributes' => array( 'min' => 0.1, 'step' => 0.1 ),
			),
			array(
				'title'   => __( 'Botón "Usar mi ubicación" en el checkout', 'total-sucursales' ),
				'id'      => "{$p}[checkout_geo_button]",
				'type'    => 'checkbox',
				'desc'    => __( 'Permite al cliente enviar su posición GPS para calcular la distancia con precisión.', 'total-sucursales' ),
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Mostrar distancia a cada sucursal en el checkout', 'total-sucursales' ),
				'id'      => "{$p}[show_distance_info]",
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Geocodificar la dirección de envío', 'total-sucursales' ),
				'id'      => "{$p}[geocoder]",
				'type'    => 'select',
				'desc'    => __( 'Se usa cuando el cliente no comparte su GPS. Nominatim (OpenStreetMap) es gratuito con límite de 1 petición por segundo; los resultados se guardan en caché.', 'total-sucursales' ),
				'options' => array(
					'none'      => __( 'No geocodificar (sin GPS no hay pickup)', 'total-sucursales' ),
					'nominatim' => __( 'Nominatim (OpenStreetMap, gratuito)', 'total-sucursales' ),
					'google'    => __( 'Google Geocoding (requiere clave)', 'total-sucursales' ),
				),
				'default' => 'nominatim',
			),
			array(
				'title'   => __( 'Correo de contacto para Nominatim', 'total-sucursales' ),
				'id'      => "{$p}[nominatim_email]",
				'type'    => 'text',
				'desc'    => __( 'Requerido por la política de uso de Nominatim.', 'total-sucursales' ),
				'default' => get_option( 'admin_email' ),
			),
			array(
				'title'   => __( 'Clave de Google (opcional)', 'total-sucursales' ),
				'id'      => "{$p}[google_api_key]",
				'type'    => 'text',
				'desc'    => __( 'Si se deja vacía se reutiliza la clave configurada en Multi Locations.', 'total-sucursales' ),
				'default' => '',
			),
			array( 'type' => 'sectionend', 'id' => 'ts_section_radius' ),
		);
	}

	public static function render_tab() {
		woocommerce_admin_fields( self::fields() );
		self::render_status();
	}

	public static function save() {
		woocommerce_update_options( self::fields() );
		self::$cache = null;
		TS_Locations::flush_cache();
		if ( class_exists( 'TS_Catalog' ) ) {
			TS_Catalog::flush();
		}
	}

	private static function render_status() {
		$deps  = total_sucursales()->deps;
		$rows  = array(
			'WooCommerce'                                  => $deps['woocommerce'],
			'Multi Locations Inventory Management (MLI)'   => taxonomy_exists( 'locations' ),
			'Advanced Shipping (WAS)'                      => class_exists( 'WPC_Condition' ),
			'States and Municipalities of Venezuela (SMV)' => $deps['smv'],
		);
		$split = get_option( 'wcmlim_enable_split_packages' ) === 'on';
		echo '<h2>' . esc_html__( 'Estado de la integración', 'total-sucursales' ) . '</h2><table class="widefat striped" style="max-width:700px">';
		foreach ( $rows as $label => $ok ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . ( $ok ? '<span style="color:green">&#10004;</span>' : '<span style="color:#c00">&#10008;</span>' ) . '</td></tr>';
		}
		echo '<tr><td>' . esc_html__( 'MLI: dividir paquetes por sucursal (wcmlim_enable_split_packages)', 'total-sucursales' ) . '</td><td>' . ( $split ? esc_html__( 'Activo: el pickup se evalúa por paquete/sucursal.', 'total-sucursales' ) : esc_html__( 'Inactivo: el carrito debe contener una sola sucursal para ofrecer pickup.', 'total-sucursales' ) ) . '</td></tr>';
		$locs = TS_Locations::all();
		$sin  = 0;
		foreach ( $locs as $l ) {
			if ( ! $l['has_coords'] || '' === $l['state'] ) {
				$sin++;
			}
		}
		echo '<tr><td>' . esc_html__( 'Sucursales cargadas', 'total-sucursales' ) . '</td><td>' . count( $locs ) . ( $sin ? ' <span style="color:#c00">(' . sprintf( esc_html__( '%d sin estado o sin coordenadas', 'total-sucursales' ), $sin ) . ')</span>' : '' ) . '</td></tr>';
		echo '</table>';
	}
}
