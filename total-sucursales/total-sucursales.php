<?php
/**
 * Plugin Name:       Total Sucursales
 * Plugin URI:        https://github.com/augusto97/Total-Sucursales
 * Description:       Reglas de sucursales por estado, radio de retiro en tienda (pickup) y condiciones para Advanced Shipping, sobre WooCommerce Multi Locations Inventory Management y States and Municipalities of Venezuela.
 * Version:           0.8.1
 * Author:            Augusto
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       total-sucursales
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   10.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_VERSION', '0.8.1' );
define( 'TS_PLUGIN_FILE', __FILE__ );
define( 'TS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TS_PLUGIN_DIR . 'includes/class-ts-plugin.php';

/**
 * Acceso global al plugin.
 *
 * @return TS_Plugin
 */
function total_sucursales() {
	return TS_Plugin::instance();
}

add_action( 'plugins_loaded', array( 'TS_Plugin', 'instance' ), 20 );

// Compatibilidad con HPOS (tablas de pedidos personalizadas).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
