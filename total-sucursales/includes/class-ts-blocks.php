<?php
/**
 * Soporte para el carrito y checkout por bloques (Store API), sin build de React.
 *
 * - Datos de sucursal/distancia/pickup en la respuesta del carrito (extensions['total-sucursales']).
 * - Callback de actualización (extensionCartUpdate) para recibir la posición GPS.
 * - Geocodificación de respaldo al actualizar la dirección desde el bloque.
 * - Selects de municipio por estado como campos adicionales (SMV no funciona en bloques).
 * - Meta del pedido al finalizar desde el checkout por bloques.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Blocks {

	const NS = 'total-sucursales';

	public static function init() {
		// woocommerce_blocks_loaded puede haberse disparado antes de que este plugin cargue (plugins_loaded:20).
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
		}
		add_action( 'woocommerce_init', array( __CLASS__, 'register_municipio_fields' ), 20 );
		// Tarde, para ganar a otros plugins que vuelvan a mostrar la ciudad de Venezuela; si aun así sale,
		// ts-blocks-checkout.js la rellena con el municipio y la oculta.
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'hide_city_in_blocks' ), 999 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'on_update_customer' ), 10, 2 );
		// Al pagar, la Store API vuelve a copiar la dirección del formulario en el cliente, con la ciudad
		// vacía (oculta), y recalcula el envío: sin volver a poner el municipio, el pedido perdía el
		// retiro por municipio y pasaba a envío nacional.
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( __CLASS__, 'on_checkout_update_customer' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'on_update_order' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/* ------------------------------------------------------------------ helpers */

	public static function is_block_checkout() {
		static $is = null;
		if ( null === $is ) {
			$page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
			$is      = $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
		}
		return (bool) apply_filters( 'ts_is_block_checkout', $is );
	}

	public static function municipio_fields_enabled() {
		return TS_Settings::is_yes( 'blocks_municipio' ) && self::is_block_checkout() && self::municipalities();
	}

	/**
	 * Municipios por estado desde el plugin SMV (código estado => lista).
	 */
	public static function municipalities() {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$list = array();
		if ( isset( $GLOBALS['wc_municipality_select'] ) && method_exists( $GLOBALS['wc_municipality_select'], 'get_cities' ) ) {
			$cities = $GLOBALS['wc_municipality_select']->get_cities( 'VE' );
			if ( is_array( $cities ) ) {
				$list = $cities;
			}
		}
		return apply_filters( 'ts_municipalities', $list );
	}

	public static function field_id( $state ) {
		return self::NS . '/municipio-' . strtolower( $state );
	}

	/* ------------------------------------------------------------------ Store API */

	public static function register_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data( array(
			'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
			'namespace'       => self::NS,
			'data_callback'   => array( __CLASS__, 'cart_data' ),
			'schema_callback' => array( __CLASS__, 'cart_schema' ),
			'schema_type'     => ARRAY_A,
		) );
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback( array(
				'namespace' => self::NS,
				'callback'  => array( __CLASS__, 'update_callback' ),
			) );
		}
	}

	public static function cart_schema() {
		return array(
			'has_coords'    => array( 'type' => 'boolean', 'readonly' => true ),
			'coords_source' => array( 'type' => 'string', 'readonly' => true ),
			'radius_km'     => array( 'type' => 'number', 'readonly' => true ),
			'geo_button'    => array( 'type' => 'boolean', 'readonly' => true ),
			'show_note'     => array( 'type' => 'boolean', 'readonly' => true ),
			'hide_shipping' => array( 'type' => 'boolean', 'readonly' => true ),
			'note'          => array( 'type' => 'object', 'readonly' => true ),
			'cart_note'     => array( 'type' => 'object', 'readonly' => true ),
			'show_info'     => array( 'type' => 'boolean', 'readonly' => true ),
			'packages'      => array( 'type' => 'array', 'readonly' => true ),
			'i18n'          => array( 'type' => 'object', 'readonly' => true ),
		);
	}

	public static function cart_data() {
		$summary = TS_Packages::summary();
		$coords  = TS_Customer::get_coords();
		$rows    = array();
		foreach ( (array) ( $summary['packages'] ?? array() ) as $p ) {
			if ( ! $p['location_id'] ) {
				continue;
			}
			$lbl    = TS_Packages::row_label( $p );
			$rows[] = array(
				'location_id'      => (int) $p['location_id'],
				'location_name'    => $p['location_name'],
				'distance_km'      => null === $p['distance_km'] ? null : (float) $p['distance_km'],
				'distance_label'   => $lbl['label'],
				'distance_unknown' => $lbl['unknown'],
				'pickup_eligible'  => (bool) $p['pickup_eligible'],
				'pickup_reason'    => (string) ( $p['pickup_reason'] ?? '' ),
			);
		}
		$note = TS_Packages::pickup_note( $summary, 'checkout' );
		return array(
			'has_coords'    => (bool) $coords,
			'coords_source' => $coords ? $coords['source'] : 'none',
			'radius_km'     => TS_Settings::radius_km(),
			'geo_button'    => TS_Packages::geo_button_enabled(),
			'show_note'     => '' !== $note['text'],
			'hide_shipping' => TS_Shipping_UI::hidden(),
			'note'          => $note,
			// La Store API no distingue carrito de checkout: el script elige según la página.
			'cart_note'     => TS_Packages::pickup_note( $summary, 'cart' ),
			'show_info'     => TS_Settings::is_yes( 'show_distance_info' ),
			'packages'      => $rows,
			'i18n'          => array(
				'button'      => TS_Texts::get( 'checkout_button' ),
				'registered'  => TS_Texts::get( 'checkout_registered' ),
				'hint'        => TS_Texts::get( 'checkout_hint' ),
				'locating'    => TS_Texts::get( 'locating' ),
				'error'       => TS_Texts::get( 'checkout_error' ),
				'pickup'      => TS_Texts::get( 'pickup_label' ),
				'national'    => TS_Texts::get( 'national' ),
				'unknown'     => TS_Texts::get( 'unknown_distance' ),
				'no_position' => TS_Texts::get( 'no_position' ),
			),
		);
	}

	/**
	 * extensionCartUpdate({ namespace: 'total-sucursales', data: { lat, lng } })
	 */
	public static function update_callback( $data ) {
		$lat = isset( $data['lat'] ) ? (float) $data['lat'] : null;
		$lng = isset( $data['lng'] ) ? (float) $data['lng'] : null;
		if ( ts_valid_coords( $lat, $lng ) ) {
			TS_Customer::set_gps_coords( $lat, $lng );
		}
		if ( WC()->cart ) {
			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Al actualizar la dirección desde el bloque: municipio → ciudad y geocodificación de respaldo.
	 */
	public static function on_checkout_update_customer( $customer, $request ) {
		self::apply_municipio_to_customer( $customer, $request );
	}

	public static function on_update_customer( $customer, $request ) {
		self::apply_municipio_to_customer( $customer, $request );

		$coords = TS_Customer::get_coords();
		if ( $coords && 'gps' === $coords['source'] ) {
			return;
		}
		$use_shipping = WC()->cart && WC()->cart->needs_shipping();
		$address      = array(
			'address_1' => $use_shipping ? $customer->get_shipping_address_1() : $customer->get_billing_address_1(),
			'address_2' => $use_shipping ? $customer->get_shipping_address_2() : $customer->get_billing_address_2(),
			'city'      => $use_shipping ? $customer->get_shipping_city() : $customer->get_billing_city(),
			'state'     => $use_shipping ? $customer->get_shipping_state() : $customer->get_billing_state(),
			'postcode'  => $use_shipping ? $customer->get_shipping_postcode() : $customer->get_billing_postcode(),
			'country'   => $use_shipping ? $customer->get_shipping_country() : $customer->get_billing_country(),
		);
		if ( 'VE' !== $address['country'] || '' === $address['city'] ) {
			TS_Customer::set_session_coords( null, null, 'none' );
			return;
		}
		$result = TS_Geocoder::geocode( $address );
		TS_Customer::set_session_coords( $result['lat'] ?? null, $result['lng'] ?? null, 'geocode' );
	}

	/**
	 * Al crear el pedido desde el checkout por bloques.
	 */
	public static function on_update_order( $order, $request ) {
		self::apply_municipio_to_order( $order, $request );
		TS_Order::save_meta( $order, array() );
	}

	/* ------------------------------------------------------------------ municipios en bloques */

	public static function register_municipio_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! self::municipio_fields_enabled() ) {
			return;
		}
		$states = ts_get_ve_states();
		foreach ( self::municipalities() as $code => $munis ) {
			if ( empty( $munis ) || ! is_array( $munis ) ) {
				continue;
			}
			$options = array();
			foreach ( $munis as $m ) {
				$options[] = array( 'value' => $m, 'label' => $m );
			}
			$match = array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'properties' => array(
								'country' => array( 'const' => 'VE' ),
								'state'   => array( 'const' => $code ),
							),
							'required'   => array( 'country', 'state' ),
						),
					),
				),
			);
			$no_match = array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'not' => $match['customer']['properties']['address'],
						),
					),
				),
			);
			try {
				woocommerce_register_additional_checkout_field( array(
					'id'       => self::field_id( $code ),
					/* translators: %s nombre del estado */
					'label'    => sprintf( __( 'Municipio (%s)', 'total-sucursales' ), $states[ $code ] ?? $code ),
					'location' => 'address',
					'type'     => 'select',
					'options'  => $options,
					'required' => $match,
					'hidden'   => $no_match,
					'index'    => 85, // WC: city 70, state 80, postcode 90 → el municipio va justo después del estado.
				) );
			} catch ( \Throwable $e ) {
				// Versión de WooCommerce sin soporte de reglas: no registrar.
				return;
			}
		}
	}

	/**
	 * Oculta la ciudad libre de Venezuela en el checkout por bloques (la reemplaza el select de municipio).
	 */
	public static function hide_city_in_blocks( $locale ) {
		if ( ! self::municipio_fields_enabled() ) {
			return $locale;
		}
		// En el checkout clásico SMV necesita el campo city; sólo ocultarlo cuando la petición viene de bloques/Store API.
		$is_store_api = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$is_checkout  = function_exists( 'is_checkout' ) && is_checkout();
		if ( ! $is_store_api && ! $is_checkout && ! function_exists( 'is_cart' ) ) {
			return $locale;
		}
		if ( ! isset( $locale['VE'] ) ) {
			$locale['VE'] = array();
		}
		$locale['VE']['city'] = array( 'hidden' => true, 'required' => false );
		return $locale;
	}

	private static function municipio_from_request( $request, $type ) {
		$key  = 'shipping' === $type ? 'shipping_address' : 'billing_address';
		$addr = isset( $request[ $key ] ) && is_array( $request[ $key ] ) ? $request[ $key ] : array();
		$state = ts_normalize_state( $addr['state'] ?? '' );
		if ( '' === $state ) {
			return '';
		}
		$fid = self::field_id( $state );
		return isset( $addr[ $fid ] ) ? sanitize_text_field( (string) $addr[ $fid ] ) : '';
	}

	private static function apply_municipio_to_customer( $customer, $request ) {
		if ( ! self::municipio_fields_enabled() ) {
			return;
		}
		$s = self::municipio_from_request( $request, 'shipping' );
		if ( '' !== $s ) {
			$customer->set_shipping_city( $s );
		}
		$b = self::municipio_from_request( $request, 'billing' );
		if ( '' !== $b ) {
			$customer->set_billing_city( $b );
		}
	}

	private static function apply_municipio_to_order( $order, $request ) {
		if ( ! self::municipio_fields_enabled() ) {
			return;
		}
		$s = self::municipio_from_request( $request, 'shipping' );
		if ( '' !== $s ) {
			$order->set_shipping_city( $s );
		}
		$b = self::municipio_from_request( $request, 'billing' );
		if ( '' !== $b ) {
			$order->set_billing_city( $b );
		}
	}

	/* ------------------------------------------------------------------ assets */

	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! ( has_block( 'woocommerce/checkout', $post ) || has_block( 'woocommerce/cart', $post ) ) ) {
			return;
		}
		wp_enqueue_script( 'ts-blocks-checkout', TS_PLUGIN_URL . 'assets/js/ts-blocks-checkout.js', array( 'wc-blocks-checkout', 'wp-plugins', 'wp-element', 'wp-data', 'wp-i18n' ), TS_VERSION, true );
		wp_enqueue_style( 'ts-frontend' );
	}
}
