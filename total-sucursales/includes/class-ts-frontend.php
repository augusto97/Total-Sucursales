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
			'detect_state'    => TS_Settings::get( 'detect_state', 'gps' ),
			'ask_if_gps_fails'=> TS_Settings::is_yes( 'ask_if_gps_fails' ),
			'state'           => TS_Customer::get_state(),
			'has_choice'      => TS_Customer::has_choice(),
			'state_source'    => TS_Customer::get_state_source(),
			'has_coords'      => (bool) TS_Customer::get_coords(),
			'states'          => $states,
			'selected_location_id' => TS_Customer::selected_mli_location_id(),
			'single_location_view' => TS_Settings::is_yes( 'single_location_view' ) && is_product(),
			'location_address'     => ( is_product() && TS_Settings::is_yes( 'show_location_address' ) ) ? TS_Locations::address_lines() : new stdClass(),
			'is_checkout'     => is_checkout(),
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

	/**
	 * [ts_selector_estado] – select de estados con sucursales para el header.
	 */
	public static function shortcode_selector( $atts = array() ) {
		$atts   = shortcode_atts( array( 'label' => TS_Texts::get( 'selector_label' ), 'class' => '' ), $atts, 'ts_selector_estado' );
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
		if ( is_admin() || 'off' === TS_Settings::get( 'detect_state' ) ) {
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

	private static function check_nonce() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'nonce' ), 403 );
		}
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
			ts_set_cookie( TS_Customer::COOKIE_STATE, '__ALL__' );
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
		TS_Customer::set_gps_coords( $lat, $lng );

		$resolved = TS_Customer::resolve_state_from_coords( $lat, $lng );
		$changed  = false;
		$state    = TS_Customer::get_state();

		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'browse';

		if ( $resolved && 'checkout' !== $context && '' !== $resolved['state'] ) {
			$changed = TS_Customer::set_state( $resolved['state'], 'gps' );
			$state   = $resolved['state'];
			if ( ! $changed ) {
				// Mismo estado: al menos alinear la sucursal seleccionada con la más cercana si no había ninguna.
				if ( ! TS_Customer::selected_mli_location_id() ) {
					TS_Customer::resync_mli_selection();
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
