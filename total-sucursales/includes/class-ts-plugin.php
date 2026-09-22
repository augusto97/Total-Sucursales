<?php
/**
 * Cargador principal: comprueba dependencias y carga los módulos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TS_Plugin {

	/** @var TS_Plugin */
	private static $instance = null;

	/** @var array Estado de dependencias detectadas. */
	public $deps = array(
		'woocommerce' => false,
		'mli'         => false, // Multi Locations Inventory Management.
		'was'         => false, // Advanced Shipping.
		'smv'         => false, // States and Municipalities of Venezuela.
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'total-sucursales', false, dirname( plugin_basename( TS_PLUGIN_FILE ) ) . '/languages' );

		$this->deps['woocommerce'] = class_exists( 'WooCommerce' );
		$this->deps['mli']         = function_exists( 'setLocation' ) || defined( 'WCMLIM_DIR_PATH' ) || taxonomy_exists( 'locations' );
		$this->deps['was']         = class_exists( 'WPC_Condition' ) || function_exists( 'was_get_available_conditions' );
		$this->deps['smv']         = function_exists( 'smvw_venezuelan_states' ) || function_exists( 'smvw_init' );

		if ( ! $this->deps['woocommerce'] ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		$this->includes();
		$this->init_modules();

		// La taxonomía de MLI se registra en init; re-evaluamos ahí para el aviso.
		add_action( 'init', array( $this, 'late_dependency_check' ), 99 );
	}

	private function includes() {
		require_once TS_PLUGIN_DIR . 'includes/ts-functions.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-settings.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-texts.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-mli-i18n.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-locations.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-geocoder.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-customer.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-location-filter.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-packages.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-catalog.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-blocks.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-debug.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-compat.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-frontend.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-checkout.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-order.php';
		require_once TS_PLUGIN_DIR . 'includes/class-ts-product-view.php';
		require_once TS_PLUGIN_DIR . 'includes/integrations/class-ts-was-conditions.php';
	}

	private function init_modules() {
		TS_Settings::init();
		TS_Locations::init();
		TS_Customer::init();
		TS_Location_Filter::init();
		TS_Packages::init();
		TS_Catalog::init();
		TS_Blocks::init();
		TS_Debug::init();
		TS_Compat::init();
		TS_MLI_I18n::init();
		TS_Frontend::init();
		TS_Checkout::init();
		TS_Order::init();
		TS_Product_View::init();
		TS_WAS_Conditions::init();
	}

	public function late_dependency_check() {
		$this->deps['mli'] = taxonomy_exists( 'locations' ) && function_exists( 'setLocation' );
		$this->deps['was'] = class_exists( 'WPC_Condition' );
		if ( is_admin() && ( ! $this->deps['mli'] || ! $this->deps['was'] ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_optional' ) );
		}
	}

	public function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Total Sucursales requiere WooCommerce activo.', 'total-sucursales' ) . '</p></div>';
	}

	public function notice_missing_optional() {
		$missing = array();
		if ( ! $this->deps['mli'] ) {
			$missing[] = 'WooCommerce Multi Locations Inventory Management';
		}
		if ( ! $this->deps['was'] ) {
			$missing[] = 'Advanced Shipping for WooCommerce';
		}
		if ( empty( $missing ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . sprintf(
			/* translators: %s lista de plugins */
			esc_html__( 'Total Sucursales: faltan plugins para el funcionamiento completo: %s', 'total-sucursales' ),
			'<strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>'
		) . '</p></div>';
	}
}
