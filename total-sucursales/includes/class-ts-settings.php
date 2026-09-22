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
			'default_location'     => 0,
			'blocks_municipio'     => 'yes',
			'debug_front'          => 'no',
			'mli_shims'            => 'yes',
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

	/**
	 * Sucursal por defecto para quien no elige ninguna, o 0 si no se ha configurado.
	 */
	public static function default_location_id() {
		return (int) self::get( 'default_location', 0 );
	}

	/**
	 * Opciones del selector de sucursal por defecto.
	 *
	 * @return array<int|string,string>
	 */
	private static function location_options() {
		$options = array( 0 => __( '— Ninguna: se usa la primera sucursal disponible —', 'total-sucursales' ) );
		foreach ( TS_Locations::all() as $id => $row ) {
			$state = '' !== $row['state'] ? ' (' . $row['state'] . ')' : '';
			$options[ (int) $id ] = $row['name'] . $state;
		}
		return $options;
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
				'title'   => __( 'Sucursal por defecto', 'total-sucursales' ),
				'id'      => "{$p}[default_location]",
				'type'    => 'select',
				'desc'    => __( 'Se aplica a quien no elige sucursal: los que responden "ver todas las sucursales" y los que navegan sin elegir nada. No pisa una elección del cliente, ni la sucursal más cercana cuando se conoce su posición, ni el filtro por estado: si la sucursal elegida aquí no está visible para ese cliente, se usa la primera que sí lo esté.', 'total-sucursales' ),
				'options' => self::location_options(),
				'default' => 0,
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

			array(
				'title' => __( 'Diagnóstico', 'total-sucursales' ),
				'type'  => 'title',
				'desc'  => __( 'Para revisar por qué un cliente ve unas sucursales u otras, activa el modo diagnóstico y abre cualquier página de la tienda añadiendo <code>?ts_debug=1</code> al final de la dirección. Aparece un panel al pie con el estado del visitante. Acuérdate de apagarlo al terminar.', 'total-sucursales' ),
				'id'    => 'ts_section_debug',
			),
			array(
				'title'   => __( 'Corregir fallos conocidos de Multi Locations', 'total-sucursales' ),
				'id'      => "{$p}[mli_shims]",
				'type'    => 'checkbox',
				'desc'    => __( 'Evita dos errores 500 de Multi Locations en admin-ajax.php: suple la función distance_between_coordinates() que su controlador llama pero nunca declara, y descarta las peticiones de stock con un producto inexistente. No modifica el plugin de Multi Locations.', 'total-sucursales' ),
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Modo diagnóstico en la tienda', 'total-sucursales' ),
				'id'      => "{$p}[debug_front]",
				'type'    => 'checkbox',
				'desc'    => __( 'Permite ver el panel a cualquier visitante que añada ?ts_debug=1 (útil para probar en ventana de incógnito). Los administradores lo ven siempre, esté activado o no.', 'total-sucursales' ),
				'default' => 'no',
			),
			array( 'type' => 'sectionend', 'id' => 'ts_section_debug' ),
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
		$deps = total_sucursales()->deps;
		$rows = array(
			'WooCommerce'                                  => $deps['woocommerce'],
			'Multi Locations Inventory Management (MLI)'   => taxonomy_exists( 'locations' ),
			'Advanced Shipping (WAS)'                      => class_exists( 'WPC_Condition' ),
			'States and Municipalities of Venezuela (SMV)' => $deps['smv'],
		);
		$split = 'on' === get_option( 'wcmlim_enable_split_packages' );

		echo '<h2>' . esc_html__( 'Estado de la integración', 'total-sucursales' ) . '</h2><table class="widefat striped" style="max-width:820px">';
		foreach ( $rows as $label => $ok ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . ( $ok ? '<span style="color:green">&#10004;</span>' : '<span style="color:#c00">&#10008;</span>' ) . '</td></tr>';
		}
		echo '<tr><td>' . esc_html__( 'MLI: dividir paquetes por sucursal (wcmlim_enable_split_packages)', 'total-sucursales' ) . '</td><td>' . ( $split ? esc_html__( 'Activo: el pickup se evalúa por paquete/sucursal.', 'total-sucursales' ) : esc_html__( 'Inactivo: el carrito debe contener una sola sucursal para ofrecer pickup.', 'total-sucursales' ) ) . '</td></tr>';
		echo '</table>';

		if ( class_exists( 'TS_Debug' ) ) {
			$no_group = TS_Debug::branches_without_group();
			if ( ! empty( $no_group ) ) {
				echo '<div class="notice notice-error inline" style="max-width:820px;margin:12px 0;padding:8px 12px"><p><strong>'
					. esc_html__( 'Sucursales sin grupo de ubicaciones', 'total-sucursales' ) . '</strong><br>'
					. esc_html__( 'Estas sucursales no pertenecen a ningún grupo de ubicaciones. El desplegable que Multi Locations rellena por AJAX sólo incluye sucursales con grupo asignado, esté o no activada la función de grupos, así que las omite aunque este plugin las deje visibles: si el cliente elige un estado cuya única sucursal está en esta lista, el selector aparece vacío. Asígnales un grupo en el campo "Location Group" de la ficha de la sucursal.', 'total-sucursales' )
					. '</p><p>' . esc_html( implode( ' · ', array_map( function ( $id, $n ) { return $n . ' #' . $id; }, array_keys( $no_group ), $no_group ) ) ) . '</p></div>';
			}
		}

		$conflicts = class_exists( 'TS_Debug' ) ? TS_Debug::mli_conflicts() : array();
		if ( ! empty( $conflicts ) ) {
			echo '<div class="notice notice-warning inline" style="max-width:820px;margin:12px 0;padding:8px 12px"><p><strong>' . esc_html__( 'Ajustes de Multi Locations que afectan al selector de sucursales:', 'total-sucursales' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $conflicts as $opt => $msg ) {
				echo '<li><code>' . esc_html( $opt ) . '</code> — ' . esc_html( $msg ) . '</li>';
			}
			echo '</ul></div>';
		}

		self::render_locations_diagnostic();
	}

	/**
	 * Diagnóstico por sucursal: por qué una sede no aparece en la tienda.
	 */
	private static function render_locations_diagnostic() {
		if ( ! taxonomy_exists( 'locations' ) ) {
			return;
		}
		TS_Locations::flush_cache();
		$locations = TS_Locations::all();
		$manual    = TS_Location_Filter::manual_exclusions();
		$states    = ts_get_ve_states();

		echo '<h2>' . esc_html__( 'Diagnóstico de sucursales', 'total-sucursales' ) . '</h2>';
		echo '<p class="description" style="max-width:820px">' . esc_html__( 'Una sucursal sólo se muestra a los clientes de su estado si tiene el estado cargado y no está oculta en Multi Locations. Sin coordenadas no se puede calcular distancia ni ofrecer retiro en tienda.', 'total-sucursales' ) . '</p>';

		if ( empty( $locations ) ) {
			echo '<p><strong>' . esc_html__( 'No hay sucursales cargadas en Multi Locations.', 'total-sucursales' ) . '</strong></p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr>'
			. '<th>' . esc_html__( 'ID', 'total-sucursales' ) . '</th>'
			. '<th>' . esc_html__( 'Sucursal', 'total-sucursales' ) . '</th>'
			. '<th>' . esc_html__( 'Estado guardado', 'total-sucursales' ) . '</th>'
			. '<th>' . esc_html__( 'Se interpreta como', 'total-sucursales' ) . '</th>'
			. '<th>' . esc_html__( 'Coordenadas', 'total-sucursales' ) . '</th>'
			. '<th>' . esc_html__( 'Diagnóstico', 'total-sucursales' ) . '</th>'
			. '</tr></thead><tbody>';

		$edit_base = admin_url( 'term.php?taxonomy=locations&post_type=product&tag_ID=' );

		foreach ( $locations as $id => $l ) {
			$problems = array();
			$hidden   = in_array( (int) $id, $manual, true );

			if ( $hidden ) {
				$problems[] = __( 'Oculta en MLI → Settings → Location → "Hide Locations From Frontend". No se muestra a ningún cliente.', 'total-sucursales' );
			}
			if ( '' === $l['state'] ) {
				$problems[] = __( 'Sin estado: se muestra a todos los clientes, pero nunca se filtra por zona. Carga el campo "State".', 'total-sucursales' );
			} elseif ( ! isset( $states[ $l['state'] ] ) ) {
				/* translators: %s valor guardado */
				$problems[] = sprintf( __( 'El estado "%s" no corresponde a ningún estado de Venezuela. Vuelve a elegirlo en el desplegable "State".', 'total-sucursales' ), $l['state_raw'] );
			}
			if ( ! $l['has_coords'] ) {
				$problems[] = __( 'Sin coordenadas: nunca podrá ofrecer retiro en tienda. Carga "Location Lat / Lng".', 'total-sucursales' );
			}

			$state_label = '' === $l['state']
				? '<span style="color:#c00">' . esc_html__( '(vacío)', 'total-sucursales' ) . '</span>'
				: esc_html( isset( $states[ $l['state'] ] ) ? $states[ $l['state'] ] . ' (' . $l['state'] . ')' : $l['state'] );

			echo '<tr>'
				. '<td>' . (int) $id . '</td>'
				. '<td><a href="' . esc_url( $edit_base . (int) $id ) . '">' . esc_html( $l['name'] ) . '</a></td>'
				. '<td><code>' . esc_html( '' === $l['state_raw'] ? '—' : $l['state_raw'] ) . '</code></td>'
				. '<td>' . $state_label . '</td>'
				. '<td>' . ( $l['has_coords'] ? esc_html( $l['lat'] . ', ' . $l['lng'] ) : '<span style="color:#c00">' . esc_html__( 'faltan', 'total-sucursales' ) . '</span>' ) . '</td>'
				. '<td>' . ( empty( $problems )
					? '<span style="color:green">' . esc_html__( 'Correcta', 'total-sucursales' ) . '</span>'
					: '<span style="color:#c00">' . implode( '<br>', array_map( 'esc_html', $problems ) ) . '</span>' )
				. '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';

		$by_state = array();
		foreach ( $locations as $id => $l ) {
			if ( '' !== $l['state'] && ! in_array( (int) $id, $manual, true ) ) {
				$by_state[ $l['state'] ][] = $l['name'];
			}
		}
		ksort( $by_state );
		echo '<p style="margin-top:12px"><strong>' . esc_html__( 'Lo que verá el cliente según su estado:', 'total-sucursales' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;max-width:820px">';
		if ( empty( $by_state ) ) {
			echo '<li>' . esc_html__( 'Ninguna sucursal se puede filtrar por estado; todos los clientes verán la lista completa.', 'total-sucursales' ) . '</li>';
		} else {
			foreach ( $by_state as $code => $names ) {
				$label = isset( $states[ $code ] ) ? $states[ $code ] : $code;
				echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( implode( ', ', $names ) ) . '</li>';
			}
			echo '<li>' . esc_html__( 'Clientes de cualquier otro estado: verán todas las sucursales no ocultas.', 'total-sucursales' ) . '</li>';
		}
		echo '</ul>';
	}
}
