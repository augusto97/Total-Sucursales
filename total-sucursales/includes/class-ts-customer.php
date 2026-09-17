<?php
/**
 * Estado y posición del cliente (cookies + sesión de WooCommerce).
 *
 * Cookies propias:  ts_estado (código), ts_estado_src (gps|manual|account)
 * Cookies de MLI reutilizadas: wcmlim_user_lat, wcmlim_user_lng, wcmlim_selected_location, wcmlim_selected_location_termid
 * Sesión WC: ts_coords => array(lat, lng, source)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Customer {

	const COOKIE_STATE  = 'ts_estado';
	const COOKIE_SOURCE = 'ts_estado_src';
	const SESSION_COORDS = 'ts_coords';

	public static function init() {
		// Usuarios con dirección guardada: fijar estado si no hay cookie.
		add_action( 'wp', array( __CLASS__, 'maybe_state_from_account' ), 5 );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Estado
	 * ------------------------------------------------------------------ */

	/**
	 * Código de estado del cliente o '' si se desconoce.
	 */
	public static function get_state() {
		$cookie = ts_get_cookie( self::COOKIE_STATE );
		if ( null !== $cookie && '__ALL__' !== strtoupper( $cookie ) ) {
			return ts_normalize_state( $cookie );
		}
		return '';
	}

	/**
	 * ¿El cliente ya eligió (o se detectó) algo, incluido "todos los estados"?
	 */
	public static function has_choice() {
		return null !== ts_get_cookie( self::COOKIE_STATE );
	}

	public static function get_state_source() {
		return (string) ts_get_cookie( self::COOKIE_SOURCE );
	}

	/**
	 * Fija el estado del cliente y resincroniza las cookies de MLI.
	 *
	 * @param string $state  Código ('ZU') o '' para "todos".
	 * @param string $source gps|manual|account
	 * @return bool true si cambió.
	 */
	public static function set_state( $state, $source = 'manual' ) {
		$state   = ts_normalize_state( $state );
		$current = self::get_state();

		ts_set_cookie( self::COOKIE_STATE, $state );
		ts_set_cookie( self::COOKIE_SOURCE, $source );

		$changed = ( $current !== $state );
		if ( $changed ) {
			TS_Location_Filter::flush_request_cache();
			self::resync_mli_selection();
			do_action( 'ts_customer_state_changed', $state, $current, $source );
		}
		return $changed;
	}

	public static function clear_state() {
		ts_delete_cookie( self::COOKIE_STATE );
		ts_delete_cookie( self::COOKIE_SOURCE );
		TS_Location_Filter::flush_request_cache();
	}

	/**
	 * Para usuarios logueados sin cookie: usar el estado de envío de su cuenta.
	 */
	public static function maybe_state_from_account() {
		if ( is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		if ( '' !== self::get_state() ) {
			return;
		}
		$state = self::state_from_account( get_current_user_id() );
		if ( '' !== $state ) {
			self::set_state( $state, 'account' );
		}
	}

	public static function on_login( $user_login, $user ) {
		if ( '' !== self::get_state() ) {
			return;
		}
		$state = self::state_from_account( $user->ID );
		if ( '' !== $state ) {
			self::set_state( $state, 'account' );
		}
	}

	private static function state_from_account( $user_id ) {
		$country = get_user_meta( $user_id, 'shipping_country', true );
		$state   = get_user_meta( $user_id, 'shipping_state', true );
		if ( ! $state ) {
			$country = get_user_meta( $user_id, 'billing_country', true );
			$state   = get_user_meta( $user_id, 'billing_state', true );
		}
		if ( 'VE' !== $country || ! $state ) {
			return '';
		}
		return ts_normalize_state( $state );
	}

	/* ---------------------------------------------------------------------
	 * Coordenadas
	 * ------------------------------------------------------------------ */

	/**
	 * Coordenadas del cliente. Prioridad: GPS (cookies de MLI) > geocodificación en sesión.
	 *
	 * @return array{lat:float,lng:float,source:string}|null
	 */
	public static function get_coords() {
		$lat = ts_get_cookie( 'wcmlim_user_lat' );
		$lng = ts_get_cookie( 'wcmlim_user_lng' );
		if ( ts_valid_coords( $lat, $lng ) ) {
			return array( 'lat' => (float) $lat, 'lng' => (float) $lng, 'source' => 'gps' );
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			$s = WC()->session->get( self::SESSION_COORDS );
			if ( is_array( $s ) && ts_valid_coords( $s['lat'] ?? null, $s['lng'] ?? null ) ) {
				return array( 'lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'source' => $s['source'] ?? 'geocode' );
			}
		}
		return null;
	}

	/**
	 * Guarda coordenadas GPS (cookies compatibles con MLI).
	 */
	public static function set_gps_coords( $lat, $lng ) {
		if ( ! ts_valid_coords( $lat, $lng ) ) {
			return false;
		}
		ts_set_cookie( 'wcmlim_user_lat', (float) $lat );
		ts_set_cookie( 'wcmlim_user_lng', (float) $lng );
		self::set_session_coords( $lat, $lng, 'gps' );
		return true;
	}

	public static function set_session_coords( $lat, $lng, $source ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		if ( ! ts_valid_coords( $lat, $lng ) ) {
			WC()->session->set( self::SESSION_COORDS, null );
			return;
		}
		WC()->session->set( self::SESSION_COORDS, array(
			'lat'    => (float) $lat,
			'lng'    => (float) $lng,
			'source' => $source,
		) );
	}

	/**
	 * Resuelve el estado a partir de coordenadas: el de la sucursal más cercana.
	 * Devuelve array{state, location_id, distance} o null si no hay sucursales con coordenadas.
	 */
	public static function resolve_state_from_coords( $lat, $lng ) {
		$nearest = TS_Locations::nearest( $lat, $lng );
		if ( ! $nearest ) {
			return null;
		}
		$loc = TS_Locations::get( $nearest['id'] );
		return array(
			'state'       => $loc['state'],
			'location_id' => $nearest['id'],
			'distance'    => $nearest['distance'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Sincronización con MLI
	 * ------------------------------------------------------------------ */

	/**
	 * Tras cambiar el estado, la lista visible de sucursales cambia y con ella los índices
	 * posicionales que MLI guarda en `wcmlim_selected_location`. Recalculamos la selección:
	 * - si la sucursal seleccionada sigue visible, actualizamos su índice;
	 * - si no, seleccionamos la más cercana (si hay coordenadas) o la primera visible;
	 * - si no queda ninguna, borramos la selección.
	 */
	public static function resync_mli_selection() {
		if ( ! taxonomy_exists( 'locations' ) ) {
			return;
		}
		$visible = TS_Locations::mli_term_list();
		if ( empty( $visible ) ) {
			self::clear_mli_selection();
			return;
		}
		$visible_ids = array_map( function ( $t ) { return (int) $t->term_id; }, $visible );

		$current = (int) ts_get_cookie( 'wcmlim_selected_location_termid' );
		$target  = null;

		if ( $current && in_array( $current, $visible_ids, true ) ) {
			$target = $current;
		} else {
			$coords = self::get_coords();
			if ( $coords ) {
				$nearest = TS_Locations::nearest( $coords['lat'], $coords['lng'], $visible_ids );
				if ( $nearest ) {
					$target = $nearest['id'];
				}
			}
			if ( ! $target ) {
				$target = $visible_ids[0];
			}
		}

		self::select_mli_location( $target );
	}

	/**
	 * Selecciona una sucursal en MLI (índice + term_id), imitando wcmlim-change-cookie-change-location.php.
	 */
	public static function select_mli_location( $term_id ) {
		$index = TS_Locations::mli_index_of( $term_id );
		if ( null === $index ) {
			self::clear_mli_selection();
			return false;
		}
		$days = (int) get_option( 'wcmlim_set_location_cookie_time' );
		$ttl  = ( $days > 0 ? $days : 1 ) * DAY_IN_SECONDS;

		ts_set_cookie( 'wcmlim_selected_location', $index, $ttl );
		ts_set_cookie( 'wcmlim_selected_location_termid', (int) $term_id, $ttl );
		if ( 'on' !== get_option( 'wcmlim_enable_autodetect_location_by_maxmind' ) ) {
			ts_set_cookie( 'wcmlim_nearby_location', $index, $ttl );
		}
		return true;
	}

	public static function clear_mli_selection() {
		ts_delete_cookie( 'wcmlim_selected_location' );
		ts_delete_cookie( 'wcmlim_selected_location_termid' );
	}

	public static function selected_mli_location_id() {
		return (int) ts_get_cookie( 'wcmlim_selected_location_termid' );
	}
}
