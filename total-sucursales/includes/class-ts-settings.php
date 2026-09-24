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
		TS_Texts::migrate();
		add_action( 'woocommerce_admin_field_ts_pickup_municipios', array( __CLASS__, 'render_municipios_field' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . TS_Municipios::OPTION, array( __CLASS__, 'sanitize_municipios_field' ), 10, 3 );
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_tabs_total_sucursales', array( __CLASS__, 'render_tab' ) );
		add_action( 'woocommerce_update_options_total_sucursales', array( __CLASS__, 'save' ) );
	}

	public static function defaults() {
		return array(
			'radius_km'            => 10,
			'pickup_criterion'     => 'both',
			'pickup_scope'         => 'one',
			'detect_state'         => 'gps',      // gps | ask | off
			'state_detect_max_km'  => 100,
			'ask_if_gps_fails'     => 'yes',
			'geocoder'             => 'nominatim', // none | nominatim | google
			'google_api_key'       => '',
			'nominatim_email'      => get_option( 'admin_email' ),
			'checkout_geo_button'  => 'yes',
			'show_distance_info'   => 'yes',
			'single_location_view' => 'no',
			'show_location_address' => 'yes',
			'catalog_filter'       => 'yes',
			'default_location'     => 0,
			'blocks_municipio'     => 'yes',
			'debug_front'          => 'no',
			'mli_shims'            => 'yes',
			'mli_spanish'          => 'yes',
			// Los textos que ve el cliente (y sus valores por defecto) están en TS_Texts.
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

	/**
	 * Qué hace que una tienda ofrezca retiro: radius | municipio | both.
	 */
	public static function pickup_criterion() {
		$v = (string) self::get( 'pickup_criterion', 'both' );
		return in_array( $v, array( 'radius', 'municipio', 'both' ), true ) ? $v : 'both';
	}

	/**
	 * Cuántas tiendas de un pedido pueden ofrecer retiro: one | all.
	 */
	public static function pickup_scope() {
		$v = (string) self::get( 'pickup_scope', 'one' );
		return in_array( $v, array( 'one', 'all' ), true ) ? $v : 'one';
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
		$main = array(
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
				'title'   => __( 'Producto: ciudad y dirección bajo cada tienda', 'total-sucursales' ),
				'id'      => "{$p}[show_location_address]",
				'type'    => 'checkbox',
				'desc'    => __( 'En la lista de stock por tienda de la página de producto, añade debajo del nombre de cada tienda su ciudad y su dirección, tomadas de la ficha de la tienda en Multi Locations (campos Locality/City y Street Number + Route). Si activas también los campos de dirección en MULTILOCA → Display settings, la dirección saldría dos veces: usa sólo uno de los dos.', 'total-sucursales' ),
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
				'title' => __( 'Retiro en tienda', 'total-sucursales' ),
				'type'  => 'title',
				'desc'  => __( 'Decide en qué tiendas de cada pedido puede retirar el cliente; en las demás se le ofrece envío nacional. Para convertirlo en tarifas, añade el método de envío «Total Sucursales: retiro o envío nacional» a tu zona en WooCommerce → Ajustes → Envío (no necesita Advanced Shipping), o usa las condiciones "Total Sucursales" en las reglas de Advanced Shipping.', 'total-sucursales' ),
				'id'    => 'ts_section_radius',
			),
			array(
				'title'   => __( 'Criterio para ofrecer retiro', 'total-sucursales' ),
				'id'      => "{$p}[pickup_criterion]",
				'type'    => 'select',
				'desc'    => __( 'Por municipio: el municipio que el cliente elige en el checkout está entre los que acepta la tienda (tabla de abajo). No necesita GPS. Por radio: el cliente está a menos de la distancia indicada; hace falta conocer su posición (GPS o dirección). Con "municipio o radio" basta con cualquiera de los dos.', 'total-sucursales' ),
				'desc_tip' => false,
				'options' => array(
					'both'      => __( 'Municipio o radio (recomendado)', 'total-sucursales' ),
					'municipio' => __( 'Sólo por municipio', 'total-sucursales' ),
					'radius'    => __( 'Sólo por radio', 'total-sucursales' ),
				),
				'default' => 'both',
			),
			array(
				'title'   => __( 'Tiendas con retiro por pedido', 'total-sucursales' ),
				'id'      => "{$p}[pickup_scope]",
				'type'    => 'select',
				'desc'    => __( 'Cuando el pedido tiene productos de varias tiendas y más de una cumple el criterio. "Una sola": la más cercana si se conoce la posición del cliente; si no, la que tiene elegida en el selector de tiendas. Con "una tienda por carrito" en Multi Locations cada pedido es de una sola tienda y esto no cambia nada.', 'total-sucursales' ),
				'options' => array(
					'one' => __( 'Una sola tienda por pedido', 'total-sucursales' ),
					'all' => __( 'Todas las tiendas que cumplan el criterio', 'total-sucursales' ),
				),
				'default' => 'one',
			),
			array(
				'title' => __( 'Municipios que pueden retirar en cada tienda', 'total-sucursales' ),
				'id'    => TS_Municipios::OPTION,
				'type'  => 'ts_pickup_municipios',
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
				'title' => __( 'Idioma de Multi Locations', 'total-sucursales' ),
				'type'  => 'title',
				'id'    => 'ts_section_mli_lang',
			),
			array(
				'title'   => __( 'Multi Locations en español', 'total-sucursales' ),
				'id'      => "{$p}[mli_spanish]",
				'type'    => 'checkbox',
				'desc'    => __( 'Muestra en español, y hablando de "tienda" en lugar de "location", los textos que Multi Locations enseña al cliente: la lista de stock de la ficha de producto, sus diálogos, el carrito y la tienda de cada línea del pedido. No modifica Multi Locations.', 'total-sucursales' ),
				'default' => 'yes',
			),
			array( 'type' => 'sectionend', 'id' => 'ts_section_mli_lang' ),
			'__TEXTS__',
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

		// Los textos van justo antes del bloque de diagnóstico.
		$out = array();
		foreach ( $main as $field ) {
			if ( '__TEXTS__' === $field ) {
				$out = array_merge( $out, TS_Texts::fields( $p ) );
				continue;
			}
			$out[] = $field;
		}
		return $out;
	}

	/**
	 * Tabla "Municipios que pueden retirar en cada tienda": una fila por tienda con un selector
	 * múltiple (con buscador) de los 335 municipios, agrupados por estado y con el de la tienda primero.
	 */
	public static function render_municipios_field( $field ) {
		$locations = TS_Locations::all();
		$munis     = TS_Municipios::all();
		$states    = ts_get_ve_states();
		$name      = TS_Municipios::OPTION;
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $field['title'] ); ?></th>
			<td class="forminp">
				<?php if ( empty( $munis ) ) : ?>
					<p class="description"><?php esc_html_e( 'Hace falta el plugin States and Municipalities of Venezuela para elegir municipios. Mientras tanto el retiro sólo puede decidirse por radio.', 'total-sucursales' ); ?></p>
				<?php elseif ( empty( $locations ) ) : ?>
					<p class="description"><?php esc_html_e( 'Aún no hay tiendas en Multi Locations.', 'total-sucursales' ); ?></p>
				<?php else : ?>
					<p class="description" style="margin-bottom:8px"><?php esc_html_e( 'Un cliente de cualquiera de estos municipios puede retirar en la tienda. Incluye los municipios de su área metropolitana (por ejemplo Maracaibo y San Francisco). Si no configuras una tienda, se usa el municipio de la ciudad de su ficha en Multi Locations.', 'total-sucursales' ); ?></p>
					<table class="widefat striped ts-municipios" style="max-width:820px">
						<thead><tr><th style="width:30%"><?php esc_html_e( 'Tienda', 'total-sucursales' ); ?></th><th><?php esc_html_e( 'Municipios', 'total-sucursales' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $locations as $id => $loc ) : ?>
							<?php
							$selected   = TS_Municipios::for_location( $id );
							$configured = TS_Municipios::is_configured( $id );
							$order      = array_keys( $munis );
							if ( $loc['state'] && isset( $munis[ $loc['state'] ] ) ) {
								$order = array_merge( array( $loc['state'] ), array_diff( $order, array( $loc['state'] ) ) );
							}
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $loc['name'] ); ?></strong><br>
									<span class="description"><?php echo esc_html( trim( $loc['city'] . ( $loc['state'] ? ' · ' . $loc['state'] : '' ), ' ·' ) ); ?></span>
								</td>
								<td>
									<input type="hidden" name="<?php echo esc_attr( $name . '[' . (int) $id . '][]' ); ?>" value="">
									<select multiple="multiple" class="wc-enhanced-select ts-municipios-select" style="width:100%"
										name="<?php echo esc_attr( $name . '[' . (int) $id . '][]' ); ?>"
										data-location-id="<?php echo (int) $id; ?>"
										data-placeholder="<?php esc_attr_e( 'Sin municipios: sólo por radio', 'total-sucursales' ); ?>">
										<?php foreach ( $order as $code ) : ?>
											<optgroup label="<?php echo esc_attr( isset( $states[ $code ] ) ? $states[ $code ] : $code ); ?>">
												<?php foreach ( $munis[ $code ] as $key => $m ) : ?>
													<option value="<?php echo esc_attr( $key ); ?>" <?php selected( in_array( $key, $selected, true ) ); ?>><?php echo esc_html( $m['name'] . ( $m['capital'] && $m['capital'] !== $m['name'] ? ' (' . $m['capital'] . ')' : '' ) ); ?></option>
												<?php endforeach; ?>
											</optgroup>
										<?php endforeach; ?>
									</select>
									<?php if ( ! $configured ) : ?>
										<span class="description"><?php echo esc_html( empty( $selected ) ? __( 'Sin configurar y su ciudad no coincide con ningún municipio: elige los municipios a mano.', 'total-sucursales' ) : __( 'Propuesto a partir de la ciudad de la tienda. Se guarda al pulsar "Guardar cambios".', 'total-sucursales' ) ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function sanitize_municipios_field( $value, $option, $raw_value ) {
		return TS_Municipios::sanitize( $raw_value );
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
			'States and Municipalities of Venezuela (SMV)' => $deps['smv'],
		);
		if ( function_exists( 'WC' ) ) {
			WC()->shipping(); // Carga los métodos de envío (y con ellos TS_Shipping_Method).
		}
		$in_zone = class_exists( 'TS_Shipping_Method' ) && TS_Shipping_Method::is_in_any_zone();
		$was     = class_exists( 'WPC_Condition' );
		$split = 'on' === get_option( 'wcmlim_enable_split_packages' );

		echo '<h2>' . esc_html__( 'Estado de la integración', 'total-sucursales' ) . '</h2><table class="widefat striped" style="max-width:820px">';
		foreach ( $rows as $label => $ok ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . ( $ok ? '<span style="color:green">&#10004;</span>' : '<span style="color:#c00">&#10008;</span>' ) . '</td></tr>';
		}
		echo '<tr><td>' . esc_html__( 'Envío: método «Total Sucursales» en una zona', 'total-sucursales' ) . '</td><td>'
			. ( $in_zone
				? '<span style="color:green">&#10004;</span> ' . esc_html__( 'Decide entre retiro y envío nacional sin Advanced Shipping.', 'total-sucursales' )
				: ( $was
					? esc_html__( 'No se usa: el envío lo deciden las reglas de Advanced Shipping.', 'total-sucursales' )
					: '<span style="color:#c00">&#10008;</span> ' . esc_html__( 'Añádelo a tu zona en WooCommerce → Ajustes → Envío.', 'total-sucursales' ) ) )
			. '</td></tr>';
		echo '<tr><td>' . esc_html__( 'Advanced Shipping (opcional)', 'total-sucursales' ) . '</td><td>'
			. ( $was
				? esc_html__( 'Activo: sus reglas pueden usar las condiciones de Total Sucursales.', 'total-sucursales' ) . ( $in_zone ? ' ' . esc_html__( 'No pongas sus reglas y el método «Total Sucursales» en la misma zona: el cliente vería las tarifas repetidas.', 'total-sucursales' ) : '' )
				: esc_html__( 'No instalado. No hace falta si usas el método «Total Sucursales».', 'total-sucursales' ) )
			. '</td></tr>';
		echo '<tr><td>' . esc_html__( 'MLI: dividir paquetes por sucursal (wcmlim_enable_split_packages)', 'total-sucursales' ) . '</td><td>' . ( $split ? esc_html__( 'Activo: el pickup se evalúa por paquete/sucursal.', 'total-sucursales' ) : esc_html__( 'Inactivo: el carrito debe contener una sola sucursal para ofrecer pickup.', 'total-sucursales' ) ) . '</td></tr>';
		if ( 'radius' !== self::pickup_criterion() ) {
			echo '<tr><td>' . esc_html__( 'Retiro por municipio', 'total-sucursales' ) . '</td><td>' . self::municipio_status() . '</td></tr>';
		}
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
	 * Resumen del retiro por municipio para "Estado de la integración": una tienda visible sin
	 * municipios (su ciudad no coincide con ninguno y nadie los eligió) sólo ofrece retiro por radio,
	 * y con "sólo por municipio" no lo ofrece nunca. En la tabla se ve fila a fila; aquí, de un vistazo.
	 *
	 * @return string HTML ya escapado.
	 */
	private static function municipio_status() {
		if ( ! TS_Municipios::has_data() ) {
			return '<span style="color:#c00">&#10008;</span> ' . esc_html__( 'Sin lista de municipios: activa States and Municipalities of Venezuela.', 'total-sucursales' );
		}
		$hidden  = class_exists( 'TS_Location_Filter' ) ? TS_Location_Filter::manual_exclusions() : array();
		$without = array();
		foreach ( TS_Locations::all() as $id => $l ) {
			if ( '' === $l['state'] || in_array( (int) $id, $hidden, true ) ) {
				continue; // Sin estado u oculta: no la ve ningún cliente (lo dice el diagnóstico).
			}
			if ( ! TS_Municipios::for_location( $id ) ) {
				$without[] = $l['name'];
			}
		}
		if ( ! $without ) {
			return '<span style="color:green">&#10004;</span> ' . esc_html__( 'Todas las tiendas visibles tienen municipios de retiro.', 'total-sucursales' );
		}
		$effect = 'municipio' === self::pickup_criterion()
			? __( 'Con "sólo por municipio" no ofrecen retiro:', 'total-sucursales' )
			: __( 'Sólo ofrecen retiro por radio:', 'total-sucursales' );
		return '<span style="color:#c00">&#10008;</span> ' . esc_html( $effect . ' ' . implode( ', ', $without ) . '. ' . __( 'Elige sus municipios en la tabla «Municipios que pueden retirar en cada tienda».', 'total-sucursales' ) );
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
