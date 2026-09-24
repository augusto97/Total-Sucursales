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
		require_once TS_PLUGIN_DIR . 'includes/class-ts-municipios.php';
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

		// Método de envío propio: retiro o envío nacional sin necesitar Advanced Shipping.
		add_action( 'woocommerce_shipping_init', function () {
			require_once TS_PLUGIN_DIR . 'includes/class-ts-shipping-method.php';
		} );
		add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
			$methods['total_sucursales'] = 'TS_Shipping_Method';
			return $methods;
		} );
	}

	public function late_dependency_check() {
		$this->deps['mli'] = taxonomy_exists( 'locations' ) && function_exists( 'setLocation' );
		$this->deps['was'] = class_exists( 'WPC_Condition' );
		if ( is_admin() ) {
			if ( ! $this->deps['mli'] ) {
				add_action( 'admin_notices', array( $this, 'notice_missing_optional' ) );
			}
			add_action( 'admin_notices', array( $this, 'notice_no_shipping_engine' ) );
		}
	}

	public function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Total Sucursales requiere WooCommerce activo.', 'total-sucursales' ) . '</p></div>';
	}

	/**
	 * Sin el método de envío de Total Sucursales en ninguna zona y sin Advanced Shipping, nada
	 * convierte "puede retirar / no puede" en tarifas: el cliente vería los métodos de la zona sin
	 * distinción. Sólo se avisa en las pantallas de WooCommerce y de plugins, para no molestar.
	 */
	public function notice_no_shipping_engine() {
		if ( $this->deps['was'] ) {
			return; // Con Advanced Shipping las reglas pueden estar hechas allí.
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-settings', 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		if ( ! class_exists( 'TS_Shipping_Method' ) && function_exists( 'WC' ) ) {
			WC()->shipping(); // Carga los métodos (dispara woocommerce_shipping_init).
		}
		if ( class_exists( 'TS_Shipping_Method' ) && TS_Shipping_Method::is_in_any_zone() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf(
			/* translators: %s ruta del menú */
			__( '<strong>Total Sucursales:</strong> para ofrecer retiro en tienda y envío nacional, añade el método de envío <strong>«Total Sucursales: retiro o envío nacional»</strong> a tu zona de envío en %s. No hace falta Advanced Shipping.', 'total-sucursales' ),
			'<em>WooCommerce → Ajustes → Envío</em>'
		) ) . '</p></div>';
	}

	public function notice_missing_optional() {
		$missing = array();
		if ( ! $this->deps['mli'] ) {
			$missing[] = 'WooCommerce Multi Locations Inventory Management';
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
