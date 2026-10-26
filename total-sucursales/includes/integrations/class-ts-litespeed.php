<?php
/**
 * Integración con LiteSpeed Cache.
 *
 * LiteSpeed guarda cada página una vez y se la sirve igual a todos los visitantes. Pero la página
 * depende de la tienda del cliente: la cabecera ("Tu tienda: …"), el stock y los precios de Multi
 * Locations, el catálogo filtrado y el aviso de "sin stock en tu tienda". Sin esto, todos veían la
 * página con la tienda de quien la generó, y al elegir otra tienda la página recargada seguía con
 * la anterior.
 *
 * Se le dice a LiteSpeed que guarde una copia por tienda (y por estado, y en el modo por ciudad por
 * posición), con las cookies que deciden esa página:
 *   - litespeed_vary_curr_cookies: la cabecera de respuesta de cada página (X-LiteSpeed-Vary).
 *   - litespeed_vary_cookies y su ajuste "Vary Cookies": la regla del .htaccess, para que el
 *     servidor busque la copia correcta antes de llegar a WordPress. El ajuste se completa solo una
 *     vez (al actualizar el plugin o cambiar el modo de tiendas) y LiteSpeed rehace su .htaccess y
 *     vacía la caché.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_LiteSpeed {

	const SYNC_OPTION = 'ts_litespeed_vary_synced';

	public static function init() {
		add_filter( 'litespeed_vary_curr_cookies', array( __CLASS__, 'add_cookies' ) );
		add_filter( 'litespeed_vary_cookies', array( __CLASS__, 'add_cookies' ) );
		add_action( 'admin_init', array( __CLASS__, 'sync_setting' ) );
	}

	public static function active() {
		return defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Core' );
	}

	/**
	 * Cookies que cambian lo que ve el cliente.
	 *
	 * @return string[]
	 */
	public static function cookies() {
		$list = array( 'wcmlim_selected_location_termid', TS_Customer::COOKIE_STATE );
		if ( 'city' === TS_Settings::visibility_mode() ) {
			// Las tiendas visibles dependen de la posición del cliente (GPS o IP).
			$list = array_merge( $list, array( 'wcmlim_user_lat', 'wcmlim_user_lng', TS_IP_Location::COOKIE_LAT, TS_IP_Location::COOKIE_LNG ) );
		}
		/**
		 * Cookies por las que LiteSpeed guarda una copia distinta de cada página.
		 *
		 * @param string[] $list Nombres de cookie.
		 */
		return array_values( array_unique( (array) apply_filters( 'ts_litespeed_vary_cookies', $list ) ) );
	}

	public static function add_cookies( $list ) {
		return array_values( array_unique( array_merge( (array) $list, self::cookies() ) ) );
	}

	/**
	 * Completa el ajuste "Vary Cookies" de LiteSpeed (Caché → Avanzado) con nuestras cookies, una
	 * vez por versión del plugin y lista de cookies. Guardarlo hace que LiteSpeed reescriba su
	 * .htaccess y vacíe la caché, como si se guardaran sus ajustes a mano.
	 */
	public static function sync_setting() {
		if ( ! self::active() || ! current_user_can( 'manage_options' ) || ! class_exists( '\LiteSpeed\Conf' ) ) {
			return;
		}
		$want = self::cookies();
		$key  = TS_VERSION . '|' . implode( ',', $want );
		if ( get_option( self::SYNC_OPTION ) === $key ) {
			return;
		}
		try {
			$conf    = \LiteSpeed\Conf::cls();
			$current = (array) $conf->conf( 'cache-vary_cookies' );
			$missing = array_diff( $want, $current );
			if ( $missing ) {
				$conf->update_confs( array( 'cache-vary_cookies' => array_values( array_unique( array_merge( $current, $want ) ) ) ) );
			}
			do_action( 'litespeed_purge_all', 'Total Sucursales: copia por tienda' );
			update_option( self::SYNC_OPTION, $key, false );
		} catch ( \Throwable $e ) {
			// Si cambia la API de LiteSpeed no se rompe el admin: queda el aviso en el estado.
			update_option( self::SYNC_OPTION, 'error: ' . $e->getMessage(), false );
		}
	}

	/**
	 * Para el panel de diagnóstico.
	 *
	 * @return array<string,string>
	 */
	public static function status() {
		if ( ! self::active() ) {
			return array();
		}
		$cookies = implode( ', ', self::cookies() );
		$current = array();
		if ( class_exists( '\LiteSpeed\Conf' ) ) {
			try {
				$current = (array) \LiteSpeed\Conf::cls()->conf( 'cache-vary_cookies' );
			} catch ( \Throwable $e ) {
				$current = array();
			}
		}
		$ok    = ! array_diff( self::cookies(), $current );
		$state = (string) get_option( self::SYNC_OPTION );
		return array(
			'LiteSpeed Cache' => $ok
				/* translators: %s: cookies */
				? sprintf( __( 'Una copia de cada página por tienda: "Vary Cookies" incluye %s.', 'total-sucursales' ), $cookies )
				/* translators: %s: cookies */
				: sprintf( __( 'Falta añadir a LiteSpeed Cache → Caché → Avanzado → "Vary Cookies": %s (se intenta solo al entrar al admin). Sin eso, todos ven la página con la misma tienda.', 'total-sucursales' ), $cookies ) . ( 0 === strpos( $state, 'error' ) ? ' (' . $state . ')' : '' ),
			__( 'Retrasar JavaScript', 'total-sucursales' ) => __( 'Compatible: si el cliente toca el selector de tienda antes de que cargue el JavaScript, se abre al cargar.', 'total-sucursales' ),
		);
	}
}
