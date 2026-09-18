<?php
/**
 * Catálogo por sede: oculta los productos sin stock en la sede seleccionada en TODAS las consultas
 * de productos del front (loop clásico, shortcodes, bloque Product Collection / Query Loop y Store API).
 *
 * MLI sólo filtra el loop clásico (`woocommerce_product_query`) recorriendo todo el catálogo; esta
 * implementación usa dos consultas SQL y caché.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Catalog {

	const VERSION_OPTION = 'ts_catalog_version';

	/** @var array<int,int[]> caché por request: location_id => ids excluidos */
	private static $cache = array();

	public static function init() {
		if ( ! TS_Settings::is_yes( 'catalog_filter' ) ) {
			return;
		}
		// Loop clásico (después de MLI, prioridad 10) y shortcodes.
		add_action( 'woocommerce_product_query', array( __CLASS__, 'filter_wp_query' ), 20 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'filter_query_args' ), 20 );
		// Bloques Product Collection / Query Loop.
		add_filter( 'query_loop_block_query_vars', array( __CLASS__, 'filter_query_args' ), 20 );
		// Store API (/wc/store/v1/products) y cualquier otra consulta de productos en el front.
		add_action( 'pre_get_posts', array( __CLASS__, 'maybe_filter_rest_query' ), 20 );

		// Invalidación de caché.
		foreach ( array( 'save_post_product', 'save_post_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_object_updated_props', 'created_locations', 'edited_locations', 'delete_locations' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
	}

	public static function on_meta_change( $meta_id, $object_id, $meta_key ) {
		if ( 0 === strpos( $meta_key, 'wcmlim_stock_at_' ) || 0 === strpos( $meta_key, 'wcmlim_allow_backorder_at_' ) || '_manage_stock' === $meta_key || '_backorders' === $meta_key ) {
			self::flush();
		}
	}

	public static function flush() {
		self::$cache = array();
		update_option( self::VERSION_OPTION, time(), false );
	}

	private static function version() {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	/**
	 * IDs de productos (padres) a ocultar para una sede.
	 *
	 * @return int[]
	 */
	public static function excluded_product_ids( $location_id ) {
		global $wpdb;
		$location_id = (int) $location_id;
		if ( $location_id <= 0 ) {
			return array();
		}
		if ( isset( self::$cache[ $location_id ] ) ) {
			return self::$cache[ $location_id ];
		}
		$key    = 'ts_catalog_excl_' . $location_id . '_' . self::version();
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			self::$cache[ $location_id ] = $cached;
			return $cached;
		}

		$parent_expr = "CASE WHEN p.post_parent > 0 THEN p.post_parent ELSE p.ID END";

		// Productos (o variaciones) con gestión de stock.
		$managed = $wpdb->get_col(
			"SELECT DISTINCT {$parent_expr} FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private')
			   AND m.meta_key = '_manage_stock' AND m.meta_value = 'yes'"
		);
		if ( empty( $managed ) ) {
			self::$cache[ $location_id ] = array();
			set_transient( $key, array(), HOUR_IN_SECONDS );
			return array();
		}

		// Con stock (> 0) en la sede.
		$stock_key = 'wcmlim_stock_at_' . $location_id;
		$allowed   = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT {$parent_expr} FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type IN ('product','product_variation')
			   AND m.meta_key = %s AND ( m.meta_value + 0 ) > 0",
			$stock_key
		) );

		// Reservas (backorder) permitidas a nivel de producto y no negadas en la sede.
		$bo_key     = 'wcmlim_allow_backorder_at_' . $location_id;
		$backorders = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT {$parent_expr} FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 LEFT JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
			 WHERE p.post_type IN ('product','product_variation')
			   AND m.meta_key = '_backorders' AND m.meta_value IN ('yes','notify')
			   AND ( b.meta_value IS NULL OR LOWER( b.meta_value ) <> 'no' )",
			$bo_key
		) );

		$excluded = array_values( array_diff( array_map( 'intval', $managed ), array_map( 'intval', $allowed ), array_map( 'intval', $backorders ) ) );
		$excluded = apply_filters( 'ts_catalog_excluded_product_ids', $excluded, $location_id );

		self::$cache[ $location_id ] = $excluded;
		set_transient( $key, $excluded, HOUR_IN_SECONDS );
		return $excluded;
	}

	/**
	 * Sede activa para filtrar (0 = no filtrar).
	 */
	public static function active_location_id() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return 0;
		}
		$id = TS_Customer::selected_mli_location_id();
		return (int) apply_filters( 'ts_catalog_location_id', $id );
	}

	private static function is_product_query( $post_type ) {
		return 'product' === $post_type || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) );
	}

	/** Loop principal de WooCommerce. */
	public static function filter_wp_query( $q ) {
		$loc = self::active_location_id();
		if ( ! $loc ) {
			return;
		}
		$excluded = self::excluded_product_ids( $loc );
		if ( empty( $excluded ) ) {
			return;
		}
		$current = (array) $q->get( 'post__not_in' );
		$q->set( 'post__not_in', array_values( array_unique( array_merge( $current, $excluded ) ) ) );
	}

	/** Arrays de argumentos (shortcodes, Product Collection). */
	public static function filter_query_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$post_type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
		if ( ! self::is_product_query( $post_type ) ) {
			return $args;
		}
		$loc = self::active_location_id();
		if ( ! $loc ) {
			return $args;
		}
		$excluded = self::excluded_product_ids( $loc );
		if ( empty( $excluded ) ) {
			return $args;
		}
		$current              = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
		$args['post__not_in'] = array_values( array_unique( array_merge( $current, $excluded ) ) );
		if ( ! empty( $args['post__in'] ) ) {
			$args['post__in'] = array_values( array_diff( (array) $args['post__in'], $excluded ) );
			if ( empty( $args['post__in'] ) ) {
				$args['post__in'] = array( 0 );
			}
		}
		return $args;
	}

	/** Store API de productos (bloques All Products, filtros, búsqueda). */
	public static function maybe_filter_rest_query( $q ) {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( false === strpos( $route, '/wc/store/' ) || false === strpos( $route, '/products' ) ) {
			return;
		}
		if ( ! self::is_product_query( $q->get( 'post_type' ) ) ) {
			return;
		}
		self::filter_wp_query( $q );
	}
}
