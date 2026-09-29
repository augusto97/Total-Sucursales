<?php
/**
 * Ubicación aproximada por IP (IPinfo), como respaldo cuando el cliente no comparte su ubicación.
 *
 * Diferencias con la detección por IPinfo de Multi Locations, que se anula si está activa:
 *   - La consulta la hace el servidor: el token no se publica en la página (Multi Locations lo mete
 *     en el JavaScript, y cualquiera puede copiarlo y gastar la cuota).
 *   - Cada IP se consulta una vez y la respuesta se guarda 7 días (1 día si falla), y hay un tope de
 *     consultas nuevas por hora (filtro ts_ipinfo_hourly_limit, 200 por defecto): la IP sale de
 *     cabeceras como X-Forwarded-For, que cualquiera puede inventar, y sin tope bastaría con ir
 *     cambiándola para gastar la cuota de IPinfo.
 *   - La posición se guarda en cookies propias (ts_ip_lat / ts_ip_lng), no en las del GPS: nunca
 *     cuenta para el retiro por distancia (una IP da, como mucho, la ciudad) y la ubicación del
 *     navegador siempre tiene prioridad.
 *   - Sólo se usa si el cliente no da su ubicación: en el modo "por ciudad" decide qué tiendas ve; en
 *     el modo "por estado" elige su estado en lugar de preguntarle (ver state_for()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_IP_Location {

	const COOKIE_LAT  = 'ts_ip_lat';
	const COOKIE_LNG  = 'ts_ip_lng';
	const COOKIE_CITY = 'ts_ip_city';
	/** Marca de "ya se intentó y no hubo resultado", para no repetir la consulta en cada página. */
	const COOKIE_NONE = 'ts_ip';

	/** @var bool La última consulta no se hizo por el tope por hora. */
	private static $limited = false;

	public static function init() {
		add_action( 'wp_ajax_ts_ip_locate', array( __CLASS__, 'ajax_locate' ) );
		add_action( 'wp_ajax_nopriv_ts_ip_locate', array( __CLASS__, 'ajax_locate' ) );
		// Con la nuestra activa, la de Multi Locations sobra (y publica el token y escribe las cookies del GPS).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_mli' ), 100 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'dequeue_mli' ), 1 );
	}

	public static function enabled() {
		return TS_Settings::is_yes( 'ip_fallback' );
	}

	public static function dequeue_mli() {
		if ( self::enabled() ) {
			wp_dequeue_script( 'wcmlim_ipinfo' );
		}
	}

	/**
	 * Posición aproximada guardada para este visitante.
	 *
	 * @return array{lat:float,lng:float,source:string,city:string}|null
	 */
	public static function get_coords() {
		if ( ! self::enabled() ) {
			return null;
		}
		$lat = ts_get_cookie( self::COOKIE_LAT );
		$lng = ts_get_cookie( self::COOKIE_LNG );
		if ( ! ts_valid_coords( $lat, $lng ) ) {
			return null;
		}
		return array( 'lat' => (float) $lat, 'lng' => (float) $lng, 'source' => 'ip', 'city' => (string) ts_get_cookie( self::COOKIE_CITY ) );
	}

	public static function tried_without_result() {
		return 'none' === ts_get_cookie( self::COOKIE_NONE );
	}

	/**
	 * IP pública del visitante (la de WooCommerce, que tiene en cuenta proxies habituales).
	 */
	public static function client_ip() {
		$ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
		$ip = (string) apply_filters( 'ts_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ? $ip : '';
	}

	/**
	 * Consulta IPinfo (con caché por IP).
	 *
	 * @return array{lat:float,lng:float,city:string,region:string,country:string}|null
	 */
	public static function lookup( $ip ) {
		if ( '' === $ip ) {
			return null;
		}
		$key    = 'ts_ipinfo_' . md5( $ip );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return empty( $cached['lat'] ) && empty( $cached['lng'] ) ? null : $cached;
		}
		self::$limited = false;
		$bucket = 'ts_ipinfo_n_' . gmdate( 'YmdH' );
		$count  = (int) get_transient( $bucket );
		if ( $count >= (int) apply_filters( 'ts_ipinfo_hourly_limit', 200 ) ) {
			self::$limited = true; // Sin caché negativa: esta IP podrá consultarse en la próxima hora.
			return null;
		}
		set_transient( $bucket, $count + 1, 2 * HOUR_IN_SECONDS );

		$url   = 'https://ipinfo.io/' . rawurlencode( $ip ) . '/json';
		$token = TS_Settings::ipinfo_token();
		if ( '' !== $token ) {
			$url = add_query_arg( 'token', rawurlencode( $token ), $url );
		}
		$res  = wp_remote_get( $url, array( 'timeout' => 4, 'headers' => array( 'Accept' => 'application/json' ) ) );
		$data = is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		$loc  = is_array( $data ) && ! empty( $data['loc'] ) ? explode( ',', (string) $data['loc'] ) : array();
		if ( 2 !== count( $loc ) || ! ts_valid_coords( $loc[0], $loc[1] ) ) {
			set_transient( $key, array(), DAY_IN_SECONDS ); // Negativa: no insistir con la misma IP.
			return null;
		}
		$out = array(
			'lat'     => (float) $loc[0],
			'lng'     => (float) $loc[1],
			'city'    => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
			'region'  => sanitize_text_field( (string) ( $data['region'] ?? '' ) ),
			'country' => sanitize_text_field( (string) ( $data['country'] ?? '' ) ),
		);
		set_transient( $key, $out, 7 * DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * El navegador no dio la ubicación: se prueba con la IP.
	 */
	public static function ajax_locate() {
		if ( ! check_ajax_referer( TS_Frontend::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'nonce' ), 403 );
		}
		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'message' => 'disabled' ) );
		}
		$gps = TS_Customer::get_coords();
		if ( $gps && 'gps' === $gps['source'] ) {
			wp_send_json_success( array( 'reload' => false, 'reason' => 'gps' ) ); // La ubicación real manda.
		}
		$found = self::lookup( self::client_ip() );
		if ( ! $found ) {
			if ( self::$limited ) {
				wp_send_json_success( array( 'reload' => false, 'reason' => 'limit' ) ); // Se reintentará más tarde.
			}
			ts_set_cookie( self::COOKIE_NONE, 'none', DAY_IN_SECONDS );
			wp_send_json_success( array( 'reload' => false, 'reason' => 'no-result' ) );
		}
		ts_set_cookie( self::COOKIE_LAT, $found['lat'] );
		ts_set_cookie( self::COOKIE_LNG, $found['lng'] );
		ts_set_cookie( self::COOKIE_CITY, $found['city'] );
		TS_Location_Filter::flush_request_cache();

		$reload = false;
		$state  = '';
		if ( 'city' === TS_Settings::visibility_mode() ) {
			$visible = TS_Location_Filter::visible_ids();
			$nearest = TS_Locations::nearest( $found['lat'], $found['lng'], $visible );
			$target  = $nearest ? $nearest['id'] : ( $visible ? $visible[0] : 0 );
			if ( $target ) {
				TS_Customer::select_mli_location( $target );
			}
			$reload = true;
		} elseif ( ! TS_Customer::has_choice() || 'ip' === TS_Customer::get_state_source() ) {
			list( $state, $how ) = self::state_for( $found );
			if ( '' !== $state ) {
				TS_Customer::set_state( $state, 'ip' );
				$reload = true;
			}
		}
		wp_send_json_success( array(
			'reload' => $reload,
			'city'   => $found['city'],
			'region' => $found['region'],
			'state'  => $state,
			'how'    => isset( $how ) ? $how : '',
		) );
	}

	/**
	 * Modo por estado: qué estado asignar a una ubicación por IP.
	 *   1. El de la tienda más cercana, si está a menos de la "Distancia máxima para inferir el estado"
	 *      (como con el GPS: así un cliente de Los Teques ve las tiendas de Caracas).
	 *   2. Si no, el estado que da IPinfo (region), si en él hay tiendas.
	 *   3. Si tampoco (una ciudad sin tiendas cerca, o fuera de Venezuela): el de la tienda por
	 *      defecto, para enseñarle ésa en lugar de preguntarle. Sin tienda por defecto, se le pregunta.
	 *
	 * @return array{0:string,1:string} Código de estado ('' si no se decide) y cómo se decidió.
	 */
	public static function state_for( array $found ) {
		$resolved = TS_Customer::resolve_state_from_coords( $found['lat'], $found['lng'] );
		if ( $resolved && '' !== $resolved['state'] ) {
			return array( $resolved['state'], 'nearest' );
		}
		$with = TS_Locations::states_with_locations();
		if ( 'VE' === strtoupper( $found['country'] ) && '' !== $found['region'] ) {
			$region = self::region_to_state( $found['region'] );
			if ( '' !== $region && isset( $with[ $region ] ) ) {
				return array( $region, 'region' );
			}
		}
		$default = TS_Locations::get( TS_Settings::default_location_id() );
		if ( $default && '' !== $default['state'] ) {
			return array( $default['state'], 'default' );
		}
		return array( '', '' );
	}

	/**
	 * Nombre de estado de IPinfo ("Zulia", "Mérida", "Capital"...) a código de WooCommerce.
	 */
	public static function region_to_state( $region ) {
		$aliases = array(
			'capital'         => 'DC',
			'capitaldistrict' => 'DC',
			'distritofederal' => 'DC',
			'distritocapital' => 'DC',
			'vargas'          => 'LG',
		);
		$key = ts_key( $region );
		if ( isset( $aliases[ $key ] ) ) {
			return $aliases[ $key ];
		}
		$code   = ts_normalize_state( $region );
		$states = ts_get_ve_states();
		return isset( $states[ $code ] ) ? $code : '';
	}
}
