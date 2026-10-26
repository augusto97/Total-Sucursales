<?php
/**
 * Front: scripts, selector de estado (shortcode + modal) y endpoints AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Frontend {

	const NONCE = 'ts_front_nonce';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ) );
		add_shortcode( 'ts_selector_estado', array( __CLASS__, 'shortcode_selector' ) );

		add_action( 'wp_ajax_ts_set_state', array( __CLASS__, 'ajax_set_state' ) );
		add_action( 'wp_ajax_nopriv_ts_set_state', array( __CLASS__, 'ajax_set_state' ) );
		add_action( 'wp_ajax_ts_set_position', array( __CLASS__, 'ajax_set_position' ) );
		add_action( 'wp_ajax_nopriv_ts_set_position', array( __CLASS__, 'ajax_set_position' ) );
		add_action( 'wp_ajax_ts_use_default', array( __CLASS__, 'ajax_use_default' ) );
		add_action( 'wp_ajax_ts_mli_nonce', array( __CLASS__, 'ajax_mli_nonce' ) );
		add_action( 'wp_ajax_nopriv_ts_mli_nonce', array( __CLASS__, 'ajax_mli_nonce' ) );
		add_action( 'wp_ajax_nopriv_ts_use_default', array( __CLASS__, 'ajax_use_default' ) );
	}

	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_style( 'ts-frontend', TS_PLUGIN_URL . 'assets/css/ts-frontend.css', array(), TS_VERSION );
		wp_enqueue_script( 'ts-frontend', TS_PLUGIN_URL . 'assets/js/ts-frontend.js', array( 'jquery' ), TS_VERSION, true );

		$states = TS_Locations::states_with_locations();
		wp_localize_script( 'ts-frontend', 'ts_params', array(
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( self::NONCE ),
			'store_label'     => TS_Texts::get( 'sp_label' ),
			'detect_state'    => TS_Settings::get( 'detect_state', 'gps' ),
			'ask_if_gps_fails'=> TS_Settings::is_yes( 'ask_if_gps_fails' ),
			'state'           => TS_Customer::get_state(),
			'has_choice'      => TS_Customer::has_choice(),
			'state_source'    => TS_Customer::get_state_source(),
			'has_coords'      => (bool) TS_Customer::get_coords(),
			'visibility_mode' => TS_Settings::visibility_mode(),
			'has_gps'         => self::has_gps(),
			'ip_fallback'     => TS_IP_Location::enabled(),
			'has_ip'          => (bool) TS_IP_Location::get_coords() || TS_IP_Location::tried_without_result(),
			'states'          => $states,
			'selected_location_id' => TS_Customer::selected_mli_location_id(),
			'single_location_view' => TS_Settings::is_yes( 'single_location_view' ) && is_product(),
			'location_address'     => ( is_product() && TS_Settings::is_yes( 'show_location_address' ) ) ? TS_Locations::address_lines() : new stdClass(),
			'is_checkout'     => is_checkout(),
			'pickup_by_municipio' => 'radius' !== TS_Settings::pickup_criterion(),
			'i18n'            => array(
				'all_states'   => TS_Texts::get( 'all_states' ),
				'locating'     => TS_Texts::get( 'locating' ),
				'geo_error'    => TS_Texts::get( 'geo_error' ),
				'geo_denied'   => TS_Texts::get( 'geo_denied' ),
				'located'      => TS_Texts::get( 'located' ),
				'checkout_error' => TS_Texts::get( 'checkout_error' ),
			),
		) );
	}

	private static function has_gps() {
		$c = TS_Customer::get_coords();
		return $c && 'gps' === $c['source'];
	}

	/**
	 * [ts_selector_estado] – select de estados con sucursales para el header.
	 * En el modo "por ciudad" no hay estados que elegir: sólo el botón de ubicación.
	 */
	public static function shortcode_selector( $atts = array() ) {
		$atts   = shortcode_atts( array( 'label' => TS_Texts::get( 'selector_label' ), 'class' => '' ), $atts, 'ts_selector_estado' );
		if ( in_array( TS_Settings::visibility_mode(), array( 'city', 'all' ), true ) ) {
			return '<div class="ts-state-selector ts-state-selector--city ' . esc_attr( $atts['class'] ) . '"><button type="button" class="ts-use-gps button">' . esc_html( TS_Texts::get( 'modal_gps' ) ) . '</button></div>';
		}
		$states = TS_Locations::states_with_locations();
		$cur    = TS_Customer::get_state();

		ob_start();
		?>
		<div class="ts-state-selector <?php echo esc_attr( $atts['class'] ); ?>">
			<?php if ( $atts['label'] ) : ?>
				<label for="ts-state-select"><?php echo esc_html( $atts['label'] ); ?></label>
			<?php endif; ?>
			<select id="ts-state-select" class="ts-state-select">
				<option value=""><?php echo esc_html( TS_Texts::get( 'all_states' ) ); ?></option>
				<?php foreach ( $states as $code => $name ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $cur, $code ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="ts-use-gps" title="<?php esc_attr_e( 'Usar mi ubicación', 'total-sucursales' ); ?>">&#9673;</button>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Modal de primera visita (sin estado). Se muestra por JS según el modo configurado.
	 */
	public static function render_modal() {
		// Sin ventana: "no detectar", los modos sin estados ("por ciudad", "todas las tiendas") y
		// "GPS y, si falla, la tienda por defecto", que nunca pregunta.
		if ( is_admin() || in_array( TS_Settings::get( 'detect_state' ), array( 'off', 'gps_default' ), true ) || 'state' !== TS_Settings::visibility_mode() ) {
			return;
		}
		if ( TS_Customer::has_choice() ) {
			return;
		}
		$states = TS_Locations::states_with_locations();
		if ( empty( $states ) ) {
			return;
		}
		?>
		<div id="ts-state-modal" class="ts-modal" hidden>
			<div class="ts-modal__box" role="dialog" aria-modal="true" aria-labelledby="ts-modal-title">
				<h3 id="ts-modal-title"><?php echo esc_html( TS_Texts::get( 'modal_title' ) ); ?></h3>
				<p><?php echo esc_html( TS_Texts::get( 'modal_text' ) ); ?></p>
				<p class="ts-modal__status" aria-live="polite"></p>
				<select class="ts-modal__select">
					<option value=""><?php echo esc_html( TS_Texts::get( 'modal_placeholder' ) ); ?></option>
					<?php foreach ( $states as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
					<option value="__all__"><?php echo esc_html( TS_Texts::get( 'modal_all' ) ); ?></option>
				</select>
				<div class="ts-modal__actions">
					<button type="button" class="button ts-modal__gps"><?php echo esc_html( TS_Texts::get( 'modal_gps' ) ); ?></button>
					<button type="button" class="button button-primary ts-modal__ok"><?php echo esc_html( TS_Texts::get( 'modal_ok' ) ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ AJAX */

	/**
	 * "GPS y, si falla, la tienda por defecto": el cliente no dio su ubicación (la negó, falló o no
	 * contestó). Se le asigna la sucursal por defecto, sin ventana de estado: en el modo por estado,
	 * con el estado de esa sucursal (así ya tiene estado y no se le vuelve a pedir la ubicación).
	 */
	public static function ajax_use_default() {
		self::check_nonce();
		TS_Customer::mark_detected();
		if ( TS_Customer::store_is_manual() ) {
			// La tienda la eligió el cliente: la detección automática no la cambia.
			wp_send_json_success( array( 'reload' => false, 'reason' => 'manual' ) );
		}
		$default = TS_Settings::default_location_id();
		$row     = $default ? TS_Locations::get( $default ) : null;
		if ( ! $row ) {
			wp_send_json_success( array( 'reload' => false, 'reason' => 'no-default' ) );
		}
		$before = TS_Customer::selected_mli_location_id();
		$mode   = TS_Settings::visibility_mode();
		if ( 'state' === $mode && ! TS_Customer::has_choice() ) {
			TS_Customer::set_state( '' !== (string) $row['state'] ? $row['state'] : '__ALL__', 'default' );
		}
		TS_Location_Filter::flush_request_cache();
		if ( 'city' !== $mode || in_array( $default, array_map( 'intval', TS_Location_Filter::visible_ids() ), true ) ) {
			TS_Customer::select_mli_location( $default, 'auto' );
		}
		wp_send_json_success( array(
			'reload'   => (int) $before !== (int) TS_Customer::selected_mli_location_id() || 'state' === $mode,
			'location' => TS_Locations::name( $default ),
		) );
	}

	/**
	 * Nonce nuevo de Multi Locations. Su "Añadir al carrito" y su cambio de tienda lo llevan en la
	 * página; si la caché la sirve con más de 12-24 h, el nonce ha caducado y contestan "-1" (no
	 * añade, no cambia). ts-frontend.js pide uno nuevo aquí y repite la petición.
	 */
	public static function ajax_mli_nonce() {
		self::verify_request();
		wp_send_json_success( array( 'security' => wp_create_nonce( 'wcmlim_locations_nonce' ) ) );
	}

	private static function check_nonce() {
		self::verify_request();
	}

	/**
	 * Las peticiones de la tienda (guardar tienda o estado, posición) vienen de páginas que la caché
	 * (LiteSpeed y similares) puede servir durante días: su nonce caduca a las 12-24 h y entonces
	 * fallaban ("a veces no guarda la tienda"). Vale el nonce o que la petición venga del propio
	 * sitio (cabecera Origin o Referer), que es la protección que importa aquí: sólo cambian
	 * preferencias del propio visitante.
	 */
	public static function verify_request() {
		if ( check_ajax_referer( self::NONCE, 'nonce', false ) || self::same_origin() ) {
			return;
		}
		wp_send_json_error( array( 'message' => 'nonce' ), 403 );
	}

	private static function same_origin() {
		$hosts = array_filter( array(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			wp_parse_url( site_url(), PHP_URL_HOST ),
		) );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sólo se compara el host.
		$from = ! empty( $_SERVER['HTTP_ORIGIN'] ) ? wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : ( ! empty( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' );
		// phpcs:enable
		$host = $from ? wp_parse_url( $from, PHP_URL_HOST ) : '';
		return $host && in_array( strtolower( $host ), array_map( 'strtolower', $hosts ), true );
	}

	/**
	 * ¿Petición de la detección automática (no de un botón que pulsó el cliente)?
	 */
	private static function is_auto() {
		return ! empty( $_POST['auto'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Fija el estado manualmente. state='' o '__all__' => todas las sucursales.
	 */
	public static function ajax_set_state() {
		self::check_nonce();
		$state = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		if ( '__all__' === $state ) {
			$state = '';
		}
		$state = ts_normalize_state( $state );
		if ( '' !== $state && ! array_key_exists( $state, ts_get_ve_states() ) ) {
			wp_send_json_error( array( 'message' => __( 'Estado inválido', 'total-sucursales' ) ) );
		}
		$changed = TS_Customer::set_state( $state, 'manual' );
		if ( '' === $state ) {
			// "Todos": guardamos una marca para no volver a preguntar.
			ts_set_cookie( TS_Customer::COOKIE_STATE, '__ALL__', null, true );
		}
		wp_send_json_success( array(
			'state'   => $state,
			'changed' => $changed,
			'reload'  => true,
		) );
	}

	/**
	 * Recibe lat/lng del navegador, fija coordenadas y deriva el estado por la sucursal más cercana.
	 */
	public static function ajax_set_position() {
		self::check_nonce();
		$lat = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : null;
		$lng = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : null;
		if ( ! ts_valid_coords( $lat, $lng ) ) {
			wp_send_json_error( array( 'message' => __( 'Coordenadas inválidas', 'total-sucursales' ) ) );
		}
		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'browse';
		$auto    = self::is_auto();
		if ( 'checkout' !== $context ) {
			TS_Customer::mark_detected();
		}
		if ( $auto && 'checkout' !== $context && TS_Customer::store_is_manual() ) {
			// Detección automática con una tienda que eligió el cliente: no se toca nada.
			wp_send_json_success( array( 'reload' => false, 'changed' => false, 'reason' => 'manual' ) );
		}
		TS_Customer::set_gps_coords( $lat, $lng );
		$source = $auto ? 'auto' : 'manual';

		// Modo "por ciudad": con la posición cambian las tiendas visibles; se elige la más cercana.
		// "Todas las tiendas": se elige la más cercana de todas.
		if ( in_array( TS_Settings::visibility_mode(), array( 'city', 'all' ), true ) ) {
			TS_Location_Filter::flush_request_cache();
			$visible = TS_Location_Filter::visible_ids();
			$nearest = TS_Locations::nearest( $lat, $lng, $visible );
			if ( 'checkout' !== $context ) {
				$target = $nearest ? $nearest['id'] : ( $visible ? $visible[0] : 0 );
				if ( $target ) {
					TS_Customer::select_mli_location( $target, $source );
				}
			}
			wp_send_json_success( array(
				'mode'    => TS_Settings::visibility_mode(),
				'visible' => array_map( array( 'TS_Locations', 'name' ), $visible ),
				'nearest' => $nearest ? array( 'id' => $nearest['id'], 'name' => TS_Locations::name( $nearest['id'] ), 'distance' => round( $nearest['distance'], 2 ) ) : null,
				'reload'  => 'checkout' !== $context,
			) );
		}

		$resolved = TS_Customer::resolve_state_from_coords( $lat, $lng );
		$changed  = false;
		$state    = TS_Customer::get_state();

		if ( $resolved && 'checkout' !== $context && '' !== $resolved['state'] ) {
			$changed = TS_Customer::set_state( $resolved['state'], $auto ? 'gps' : 'manual' );
			$state   = $resolved['state'];
			if ( ! $changed ) {
				// Mismo estado: al menos alinear la sucursal seleccionada con la más cercana si no había ninguna.
				if ( ! TS_Customer::selected_mli_location_id() ) {
					TS_Customer::resync_mli_selection( $source );
				}
			}
		}

		wp_send_json_success( array(
			'state'         => $state,
			'state_name'    => $state ? ts_state_name( $state ) : '',
			'changed'       => $changed,
			'nearest'       => $resolved ? array(
				'id'       => $resolved['location_id'],
				'name'     => TS_Locations::name( $resolved['location_id'] ),
				'distance' => round( $resolved['distance'], 2 ),
			) : null,
			'too_far'       => $resolved ? ! empty( $resolved['too_far'] ) : false,
			'reload'        => 'checkout' !== $context && '' !== $state && ( $changed || 'browse' === $context ),
		) );
	}
}
