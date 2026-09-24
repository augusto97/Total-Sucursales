<?php
/**
 * Opciones de envío visibles u ocultas en el carrito y el checkout.
 *
 * Con "Ocultar las opciones de envío" activado, el cliente no ve las opciones de envío ni la línea de
 * envío del resumen, salvo en los pedidos de las "tiendas autorizadas para envíos". Es sólo visual:
 * las tarifas se siguen calculando y el pedido guarda la que corresponde.
 *   - Si el cliente puede retirar, queda elegido el retiro y se descarta el envío nacional (como no ve
 *     las opciones, no podría cambiarlo).
 *   - Si no, queda el envío nacional.
 *
 * Aparte, en todos los casos: cuando aparece la opción de retiro, es la que se elige por defecto.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Shipping_UI {

	/** @var bool */
	private static $buffering = false;

	public static function init() {
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_rates' ), 100, 2 );
		add_filter( 'woocommerce_shipping_chosen_method', array( __CLASS__, 'prefer_pickup' ), 10, 3 );

		// Checkout y carrito clásicos: las filas de envío (y el panel de tiendas) se ocultan.
		add_action( 'woocommerce_review_order_before_shipping', array( __CLASS__, 'start' ), 1 );
		add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'end' ), 999 );
		add_action( 'woocommerce_cart_totals_before_shipping', array( __CLASS__, 'start' ), 1 );
		add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'end' ), 999 );

		// Carrito y checkout por bloques: una clase en <body> que ts-blocks-checkout.js mantiene al día.
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	public static function enabled() {
		return TS_Settings::is_yes( 'hide_shipping_ui' );
	}

	/**
	 * ¿Ve el cliente las opciones de envío de los pedidos de esta tienda?
	 */
	public static function location_visible( $location_id ) {
		if ( ! self::enabled() ) {
			return true;
		}
		return $location_id && in_array( (int) $location_id, TS_Settings::shipping_visible_locations(), true );
	}

	/**
	 * ¿Se ocultan las opciones de envío del carrito actual? Con varias tiendas en el pedido se muestran
	 * si alguna está autorizada.
	 */
	public static function hidden() {
		if ( ! self::enabled() ) {
			return false;
		}
		$summary = TS_Packages::summary();
		foreach ( (array) ( $summary['packages'] ?? array() ) as $p ) {
			if ( self::location_visible( $p['location_id'] ?? 0 ) ) {
				return false;
			}
		}
		return true;
	}

	private static function mode( $rate ) {
		if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_meta_data' ) ) {
			return '';
		}
		$meta = $rate->get_meta_data();
		return isset( $meta['_ts_mode'] ) ? (string) $meta['_ts_mode'] : '';
	}

	/**
	 * Paquete de una tienda sin opciones visibles que puede retirar: sólo queda el retiro.
	 */
	public static function filter_rates( $rates, $package ) {
		if ( ! is_array( $rates ) || ! isset( $package['ts_shipping_visible'] ) || $package['ts_shipping_visible'] ) {
			return $rates;
		}
		$has_pickup = false;
		foreach ( $rates as $rate ) {
			if ( 'pickup' === self::mode( $rate ) ) {
				$has_pickup = true;
				break;
			}
		}
		if ( ! $has_pickup ) {
			return $rates;
		}
		foreach ( $rates as $id => $rate ) {
			if ( 'national' === self::mode( $rate ) ) {
				unset( $rates[ $id ] );
			}
		}
		return $rates;
	}

	/**
	 * WooCommerce elige tarifa por defecto cuando cambian las disponibles (por ejemplo, al elegir un
	 * municipio con retiro): si hay retiro, ése.
	 */
	public static function prefer_pickup( $default, $rates, $chosen = '' ) {
		foreach ( (array) $rates as $id => $rate ) {
			if ( 'pickup' === self::mode( $rate ) ) {
				return $id;
			}
		}
		return $default;
	}

	public static function start() {
		if ( self::$buffering || ! self::hidden() ) {
			return;
		}
		self::$buffering = true;
		ob_start();
	}

	/**
	 * Las filas siguen en la página (los radios se envían con el formulario, como siempre), sólo que
	 * no se ven.
	 */
	public static function end() {
		if ( ! self::$buffering ) {
			return;
		}
		self::$buffering = false;
		$html = (string) ob_get_clean();
		echo preg_replace( '/<tr\b/i', '<tr style="display:none"', $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML de WooCommerce ya escapado.
	}

	public static function body_class( $classes ) {
		if ( ! self::enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $classes;
		}
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			if ( self::hidden() ) {
				$classes[] = 'ts-hide-shipping';
			}
		}
		return $classes;
	}
}
