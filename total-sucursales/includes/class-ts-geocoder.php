<?php
/**
 * Geocodificación de direcciones con caché (Nominatim u Google).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Geocoder {

	/**
	 * Geocodifica una dirección de WooCommerce.
	 *
	 * @param array $address Claves: address_1, address_2, city, state, postcode, country.
	 * @return array{lat:float,lng:float}|null
	 */
	public static function geocode( array $address ) {
		$provider = TS_Settings::get( 'geocoder', 'nominatim' );
		if ( 'none' === $provider ) {
			return null;
		}

		$address = array_map( 'trim', wp_parse_args( $address, array(
			'address_1' => '',
			'address_2' => '',
			'city'      => '',
			'state'     => '',
			'postcode'  => '',
			'country'   => 'VE',
		) ) );

		// Sin ciudad ni dirección no tiene sentido geocodificar.
		if ( '' === $address['city'] && '' === $address['address_1'] ) {
			return null;
		}

		$key    = 'ts_geo_' . md5( $provider . '|' . wp_json_encode( $address ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$result = ( 'google' === $provider ) ? self::google( $address ) : self::nominatim( $address );
		$result = apply_filters( 'ts_geocode_result', $result, $address, $provider );

		if ( $result ) {
			set_transient( $key, $result, DAY_IN_SECONDS * 30 );
		} else {
			set_transient( $key, 'none', DAY_IN_SECONDS );
		}
		return $result;
	}

	/**
	 * Texto de consulta legible a partir de una dirección venezolana.
	 * El municipio de SMV viene como "Municipio Maracaibo (Maracaibo)"; usamos la capital entre paréntesis.
	 */
	public static function address_to_query( array $address ) {
		$city = $address['city'];
		if ( preg_match( '/\(([^)]+)\)/', $city, $m ) ) {
			$city = $m[1];
		} else {
			$city = preg_replace( '/^(Municipio|Municipality)\s+/i', '', $city );
		}
		$state   = $address['state'] ? ts_state_name( ts_normalize_state( $address['state'] ) ) : '';
		$country = WC()->countries->countries[ $address['country'] ] ?? $address['country'];

		$parts = array_filter( array( $address['address_1'], $city, $state, $address['postcode'], $country ) );
		return implode( ', ', $parts );
	}

	private static function nominatim( array $address ) {
		$email = trim( (string) TS_Settings::get( 'nominatim_email', '' ) );
		$q     = self::address_to_query( $address );
		$url   = add_query_arg( array(
			'q'              => rawurlencode( $q ),
			'format'         => 'json',
			'limit'          => 1,
			'countrycodes'   => strtolower( $address['country'] ),
			'addressdetails' => 0,
			'email'          => $email ? rawurlencode( $email ) : null,
		), 'https://nominatim.openstreetmap.org/search' );

		$resp = wp_remote_get( $url, array(
			'timeout' => 8,
			'headers' => array(
				'User-Agent' => 'TotalSucursales/' . TS_VERSION . ' (' . home_url() . '; ' . $email . ')',
				'Accept-Language' => 'es',
			),
		) );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data[0]['lat'] ) || empty( $data[0]['lon'] ) ) {
			// Reintento sólo con ciudad + estado (las calles venezolanas rara vez están en OSM).
			if ( '' !== $address['address_1'] ) {
				$address['address_1'] = '';
				return self::nominatim( $address );
			}
			return null;
		}
		return array( 'lat' => (float) $data[0]['lat'], 'lng' => (float) $data[0]['lon'] );
	}

	private static function google( array $address ) {
		$key = TS_Settings::google_api_key();
		if ( '' === $key ) {
			return null;
		}
		$url  = add_query_arg( array(
			'address' => rawurlencode( self::address_to_query( $address ) ),
			'region'  => strtolower( $address['country'] ),
			'key'     => $key,
		), 'https://maps.googleapis.com/maps/api/geocode/json' );
		$resp = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['results'][0]['geometry']['location'] ) ) {
			return null;
		}
		$loc = $data['results'][0]['geometry']['location'];
		return array( 'lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng'] );
	}
}
