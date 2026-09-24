<?php
/**
 * Textos que ve el cliente, editables desde WooCommerce > Ajustes > Total Sucursales.
 *
 * Cada texto tiene un valor por defecto en español. Si el administrador deja un campo vacío se
 * vuelve a usar el de por defecto, así que nunca queda un botón o un aviso sin texto.
 *
 * En los textos que ve el cliente se habla de "tienda": "sucursal" y "location" quedan para el
 * panel de administración.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Texts {

	/**
	 * Claves que ya existían en los ajustes sin el prefijo txt_ (se conservan para no perder lo que
	 * el administrador hubiera guardado).
	 */
	const LEGACY_KEYS = array( 'modal_title', 'modal_text', 'pickup_label' );

	/** @var array|null */
	private static $catalog = null;

	/**
	 * Catálogo de textos: clave => [ grupo, etiqueta del ajuste, valor por defecto, tipo ].
	 *
	 * Se construye al pedirlo (no al cargar el plugin) para no llamar a __() antes de tiempo.
	 *
	 * @return array<string,array{group:string,label:string,default:string,type:string}>
	 */
	public static function catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}
		$g_modal    = __( 'Selector de estado (ventana de la primera visita)', 'total-sucursales' );
		$g_header   = __( 'Selector de estado de la cabecera', 'total-sucursales' );
		$g_checkout = __( 'Checkout', 'total-sucursales' );
		$g_mli      = __( 'Multi Locations (ficha de producto, carrito y avisos)', 'total-sucursales' );

		$t = array();
		$add = function ( $key, $group, $label, $default, $type = 'text' ) use ( &$t ) {
			$t[ $key ] = array( 'group' => $group, 'label' => $label, 'default' => $default, 'type' => $type );
		};

		$add( 'modal_title', $g_modal, __( 'Título', 'total-sucursales' ), __( '¿Desde qué estado nos visitas?', 'total-sucursales' ) );
		$add( 'modal_text', $g_modal, __( 'Texto', 'total-sucursales' ), __( 'Elige tu estado para mostrarte las tiendas y el catálogo disponible en tu zona.', 'total-sucursales' ), 'textarea' );
		$add( 'modal_placeholder', $g_modal, __( 'Primera opción del desplegable', 'total-sucursales' ), __( 'Selecciona tu estado…', 'total-sucursales' ) );
		$add( 'modal_all', $g_modal, __( 'Opción "ver todas"', 'total-sucursales' ), __( 'Otro estado / ver todas las tiendas', 'total-sucursales' ) );
		$add( 'modal_gps', $g_modal, __( 'Botón de ubicación', 'total-sucursales' ), __( 'Usar mi ubicación', 'total-sucursales' ) );
		$add( 'modal_ok', $g_modal, __( 'Botón de confirmar', 'total-sucursales' ), __( 'Continuar', 'total-sucursales' ) );
		$add( 'locating', $g_modal, __( 'Mientras se obtiene la ubicación', 'total-sucursales' ), __( 'Obteniendo tu ubicación…', 'total-sucursales' ) );
		$add( 'geo_error', $g_modal, __( 'Si falla la ubicación', 'total-sucursales' ), __( 'No pudimos obtener tu ubicación. Elige tu estado manualmente.', 'total-sucursales' ) );
		$add( 'geo_denied', $g_modal, __( 'Si el cliente no da permiso', 'total-sucursales' ), __( 'Sin acceso a tu ubicación. Elige tu estado manualmente.', 'total-sucursales' ) );

		$add( 'selector_label', $g_header, __( 'Etiqueta', 'total-sucursales' ), __( 'Estado', 'total-sucursales' ) );
		$add( 'all_states', $g_header, __( 'Opción "todos"', 'total-sucursales' ), __( 'Todos los estados', 'total-sucursales' ) );

		$add( 'pickup_label', $g_checkout, __( 'Distintivo de retiro', 'total-sucursales' ), __( 'Retiro en tienda', 'total-sucursales' ) );
		$add( 'national', $g_checkout, __( 'Distintivo de envío nacional', 'total-sucursales' ), __( 'Envío nacional', 'total-sucursales' ) );
		$add( 'checkout_button', $g_checkout, __( 'Botón de ubicación', 'total-sucursales' ), __( 'Usar mi ubicación para retiro en tienda', 'total-sucursales' ) );
		$add( 'checkout_registered', $g_checkout, __( 'Botón tras compartir la ubicación', 'total-sucursales' ), __( 'Ubicación registrada', 'total-sucursales' ) );
		$add( 'checkout_hint', $g_checkout, __( 'Ayuda bajo el botón', 'total-sucursales' ), __( 'Comparte tu posición para saber si puedes retirar en la tienda más cercana.', 'total-sucursales' ) );
		$add( 'located', $g_checkout, __( 'Tras compartir la ubicación', 'total-sucursales' ), __( 'Ubicación registrada. Recalculando envíos…', 'total-sucursales' ) );
		$add( 'checkout_error', $g_checkout, __( 'Si falla la ubicación', 'total-sucursales' ), __( 'No pudimos obtener tu ubicación.', 'total-sucursales' ) );
		$add( 'in_your_municipio', $g_checkout, __( 'Retiro por municipio (en lugar de la distancia)', 'total-sucursales' ), __( 'en tu municipio', 'total-sucursales' ) );
		$add( 'unknown_distance', $g_checkout, __( 'Distancia desconocida', 'total-sucursales' ), __( 'distancia no disponible', 'total-sucursales' ) );
		$add( 'no_position', $g_checkout, __( 'Aviso sin posición', 'total-sucursales' ), __( 'No conocemos tu posición: comparte tu ubicación o completa la dirección para evaluar el retiro en tienda.', 'total-sucursales' ), 'textarea' );
		$add( 'choose_municipio', $g_checkout, __( 'Aviso sin municipio (checkout)', 'total-sucursales' ), __( 'Elige tu municipio para saber si puedes retirar en tienda.', 'total-sucursales' ), 'textarea' );
		$add( 'choose_municipio_gps', $g_checkout, __( 'Aviso sin municipio ni posición (checkout, criterio "municipio o radio")', 'total-sucursales' ), __( 'Elige tu municipio, o comparte tu ubicación, para saber si puedes retirar en tienda.', 'total-sucursales' ), 'textarea' );
		$add( 'cart_choose_municipio', $g_checkout, __( 'Aviso sin municipio (carrito)', 'total-sucursales' ), __( 'Al finalizar la compra, elige tu municipio para saber si puedes retirar en tienda.', 'total-sucursales' ), 'textarea' );
		$add( 'pickup_for', $g_checkout, __( 'Municipios con retiro en cada tienda ({tienda}, {municipios})', 'total-sucursales' ), __( 'Retiro en {tienda} para clientes de {municipios}.', 'total-sucursales' ) );
		$add( 'pickup_where_title', $g_checkout, __( 'Página de gracias y correos: título del lugar de retiro', 'total-sucursales' ), __( 'Dónde retirar tu pedido', 'total-sucursales' ) );

		$add( 'mli_location', $g_mli, __( 'Cómo se llama una "location"', 'total-sucursales' ), __( 'Tienda', 'total-sucursales' ) );
		$add( 'mli_pickup_location', $g_mli, __( 'Tienda de retiro (en el pedido)', 'total-sucursales' ), __( 'Tienda de retiro', 'total-sucursales' ) );
		$add( 'mli_select', $g_mli, __( 'Desplegable sin tienda elegida', 'total-sucursales' ), __( 'Selecciona una tienda', 'total-sucursales' ) );
		$add( 'mli_select_msg', $g_mli, __( 'Aviso si no se eligió tienda', 'total-sucursales' ), __( 'Selecciona una tienda antes de añadir al carrito.', 'total-sucursales' ) );
		$add( 'mli_change_text', $g_mli, __( 'Diálogo de cambio de tienda: texto ({actual}, {nueva})', 'total-sucursales' ), __( 'Tu carrito tiene productos de {actual}. ¿Quieres pasarlos todos a {nueva}?', 'total-sucursales' ), 'textarea' );
		$add( 'mli_change_confirm', $g_mli, __( 'Diálogo de cambio de tienda: botón de aceptar', 'total-sucursales' ), __( 'Sí, cambiar de tienda', 'total-sucursales' ) );
		$add( 'mli_cancel', $g_mli, __( 'Botón de cancelar de los diálogos', 'total-sucursales' ), __( 'Cancelar', 'total-sucursales' ) );
		$add( 'mli_updated_title', $g_mli, __( 'Tienda cambiada: título', 'total-sucursales' ), __( 'Tienda actualizada', 'total-sucursales' ) );
		$add( 'mli_updated_text', $g_mli, __( 'Tienda cambiada: texto ({nueva})', 'total-sucursales' ), __( 'Tu carrito ahora es de la tienda {nueva}. Producto añadido.', 'total-sucursales' ) );
		$add( 'mli_not_available_title', $g_mli, __( 'Producto no disponible: título', 'total-sucursales' ), __( 'No disponible', 'total-sucursales' ) );
		$add( 'mli_not_available_text', $g_mli, __( 'Producto no disponible: texto', 'total-sucursales' ), __( 'Este producto no está disponible en esta tienda.', 'total-sucursales' ) );
		$add( 'mli_unavailable_move', $g_mli, __( 'Productos que no hay en la nueva tienda ({nueva}, {lista})', 'total-sucursales' ), __( "Estos productos no están disponibles en {nueva}:\n\n{lista}\n\n¿Quieres quitarlos y pasar el resto a {nueva}?", 'total-sucursales' ), 'textarea' );

		/**
		 * Permite añadir o cambiar textos del catálogo.
		 *
		 * @param array $t Catálogo.
		 */
		self::$catalog = apply_filters( 'ts_texts_catalog', $t );
		return self::$catalog;
	}

	/**
	 * Hasta la 0.3.0 el título, el texto del selector y el distintivo de retiro vivían en los ajustes
	 * generales, y WooCommerce guarda todos los campos al pulsar "Guardar". Quien guardó alguna vez
	 * tiene el texto por defecto de entonces fijado en la base de datos ("...las sucursales..."), así
	 * que nunca vería el nuevo. Se borran los que siguen siendo exactamente aquel texto por defecto;
	 * los personalizados se respetan.
	 */
	public static function migrate() {
		if ( get_option( 'ts_texts_migrated' ) ) {
			return;
		}
		$old_defaults = array(
			'modal_title'  => '¿Desde qué estado nos visitas?',
			'modal_text'   => 'Elige tu estado para mostrarte las sucursales y el catálogo disponible en tu zona.',
			'pickup_label' => 'Retiro en tienda',
		);
		$saved = get_option( TS_Settings::OPTION, array() );
		if ( is_array( $saved ) ) {
			$changed = false;
			foreach ( $old_defaults as $key => $old ) {
				if ( isset( $saved[ $key ] ) && trim( (string) $saved[ $key ] ) === $old ) {
					unset( $saved[ $key ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( TS_Settings::OPTION, $saved );
			}
		}
		update_option( 'ts_texts_migrated', 1 ); // autoload: se consulta en cada carga
	}

	/**
	 * Clave de ts_settings donde se guarda un texto.
	 */
	public static function option_key( $key ) {
		return in_array( $key, self::LEGACY_KEYS, true ) ? $key : 'txt_' . $key;
	}

	/**
	 * Texto final: el guardado por el administrador o, si está vacío, el de por defecto.
	 */
	public static function get( $key ) {
		$cat     = self::catalog();
		$default = isset( $cat[ $key ] ) ? $cat[ $key ]['default'] : '';
		$saved   = TS_Settings::get( self::option_key( $key ), '' );
		$saved   = is_string( $saved ) ? trim( $saved ) : '';
		return '' !== $saved ? $saved : $default;
	}

	private static function group_desc( $i, $title ) {
		if ( 1 === $i ) {
			return __( 'Textos que ve el cliente. Un campo vacío vuelve al texto por defecto.', 'total-sucursales' );
		}
		if ( 0 === strpos( $title, 'Multi Locations' ) ) {
			return __( 'Textos que Multi Locations tiene fijos en su código y no permite cambiar. Los que sí tienen ajuste propio en MULTILOCA (Disponible, Agotado, Disponibilidad por tienda, "¿Cambiar de tienda?"...) se cambian allí: aquí sólo se ponen en español mientras sigan con su texto de fábrica en inglés.', 'total-sucursales' );
		}
		return '';
	}

	/**
	 * Campos de ajustes de WooCommerce, un bloque por grupo.
	 *
	 * @param string $p Nombre de la opción (ts_settings).
	 * @return array
	 */
	public static function fields( $p ) {
		$out    = array();
		$groups = array();
		foreach ( self::catalog() as $key => $row ) {
			$groups[ $row['group'] ][ $key ] = $row;
		}
		$i = 0;
		foreach ( $groups as $title => $rows ) {
			$id    = 'ts_section_texts_' . ( ++$i );
			$out[] = array(
				'title' => $title,
				'type'  => 'title',
				'desc'  => self::group_desc( $i, $title ),
				'id'    => $id,
			);
			foreach ( $rows as $key => $row ) {
				$out[] = array(
					'title'       => $row['label'],
					'id'          => $p . '[' . self::option_key( $key ) . ']',
					'type'        => $row['type'],
					'placeholder' => $row['default'],
					'default'     => '',
					'css'         => 'textarea' === $row['type'] ? 'min-width:400px;height:60px' : 'min-width:400px',
				);
			}
			$out[] = array( 'type' => 'sectionend', 'id' => $id );
		}
		return $out;
	}
}
