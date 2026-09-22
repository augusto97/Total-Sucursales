<?php
/**
 * Multi Locations en español, hablando de "tienda" en lugar de "location".
 *
 * No se modifica Multi Locations (se sobrescribe en cada actualización). Sus textos llegan al
 * cliente por cuatro caminos distintos y cada uno se trata en su sitio:
 *
 * 1. Opciones de MLI con texto (In Stock, Sold Out, Stock Information...). Se editan en los
 *    ajustes de MLI; aquí sólo se sustituyen mientras sigan con su valor de fábrica en inglés, así
 *    que lo que el administrador haya escrito allí siempre manda.
 * 2. Textos de su PHP que pasan por __() con el dominio 'wcmlim'. Filtro gettext, sólo en el front.
 * 3. La clave "Location" con la que MLI guarda la tienda en cada línea del pedido, que se ve en la
 *    página de gracias, en los correos y en "Mi cuenta".
 * 4. Textos fijos en su JavaScript (los diálogos de SweetAlert) y en alguna plantilla que usa
 *    esc_html() en lugar de __(). Se traducen en el navegador con assets/js/ts-mli-i18n.js.
 *
 * Los más visibles se pueden editar en los ajustes de Total Sucursales (grupo Multi Locations de
 * TS_Texts); el resto usa la traducción fija de este archivo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_MLI_I18n {

	public static function init() {
		if ( ! TS_Settings::is_yes( 'mli_spanish' ) ) {
			return;
		}

		foreach ( array_keys( self::option_defaults() ) as $option ) {
			add_filter( 'option_' . $option, array( __CLASS__, 'filter_option' ), 20, 2 );
			add_filter( 'default_option_' . $option, array( __CLASS__, 'filter_default_option' ), 20, 3 );
		}

		// En el admin MLI sigue en su idioma: esto es para el cliente.
		if ( ! is_admin() || wp_doing_ajax() ) {
			add_filter( 'gettext_wcmlim', array( __CLASS__, 'filter_gettext' ), 20, 2 );
		}

		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'filter_order_meta_key' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 100 );
	}

	/* ---------------------------------------------------------------------
	 * 1. Opciones de texto de MLI
	 * ------------------------------------------------------------------ */

	/**
	 * Opción de MLI => [ valores de fábrica en inglés, texto en español ].
	 *
	 * @return array<string,array{0:string[],1:string}>
	 */
	public static function option_defaults() {
		return array(
			'wcmlim_instock_button_text'                   => array( array( 'In Stock' ), 'Disponible' ),
			'wcmlim_soldout_button_text'                   => array( array( 'Sold Out', 'Out of Stock' ), 'Agotado' ),
			'wcmlim_onbackorder_button_text'               => array( array( 'Available on backorder', 'On backorder' ), 'Disponible por encargo' ),
			'wcmlim_txt_stock_info'                        => array( array( 'Stock Information' ), 'Disponibilidad por tienda' ),
			'wcmlim_location_display_heading_setting'      => array( array( 'Stock Information' ), 'Disponibilidad por tienda' ),
			'wcmlim_txt_inline_location'                   => array( array( 'Location:', 'Location' ), '{tienda}:' ),
			'wcmlim_purchase_change_location_text'         => array( array( 'Change Cart Location?' ), '¿Cambiar de tienda?' ),
			'wcmlim_purchase_change_location_notavailtext' => array( array( 'Products Not Available!' ), 'Productos no disponibles' ),
			'wcmlim_valid_cart_message'                    => array( array( 'You can order from only one location!' ), 'Solo puedes comprar en una tienda a la vez.' ),
			'wcmlim_cart_popup_heading'                    => array( array( 'Updated Cart!' ), 'Carrito actualizado' ),
			'wcmlim_cart_popup_message'                    => array( array( 'Your cart has been cleared, re-add from new location !' ), 'Vaciamos tu carrito: vuelve a añadir los productos desde la nueva tienda.' ),
			'wcmlim_prod_instock_valid'                    => array( array( 'You can’t add more items than are available in stock .', "You can't add more items than are available in stock ." ), 'No puedes añadir más unidades de las que hay en stock.' ),
		);
	}

	private static function spanish_for_option( $option ) {
		$map = self::option_defaults();
		$es  = isset( $map[ $option ] ) ? $map[ $option ][1] : '';
		if ( false === strpos( $es, '{tienda}' ) ) {
			return $es;
		}
		// Antes de init no se puede pedir el catálogo (usa __()); se usa el texto por defecto.
		$tienda = did_action( 'init' ) ? TS_Texts::get( 'mli_location' ) : 'Tienda';
		return str_replace( '{tienda}', $tienda, $es );
	}

	private static function is_factory_english( $option, $value ) {
		$map = self::option_defaults();
		if ( ! isset( $map[ $option ] ) ) {
			return false;
		}
		$v = strtolower( trim( (string) $value ) );
		if ( '' === $v ) {
			return true;
		}
		foreach ( $map[ $option ][0] as $english ) {
			if ( strtolower( trim( $english ) ) === $v ) {
				return true;
			}
		}
		return false;
	}

	public static function filter_option( $value, $option ) {
		// Conserva el espacio final que MLI pone en "Location: " para separar el nombre.
		$trail = ( is_string( $value ) && ' ' === substr( $value, -1 ) ) ? ' ' : '';
		return self::is_factory_english( $option, $value ) ? self::spanish_for_option( $option ) . $trail : $value;
	}

	public static function filter_default_option( $default, $option, $passed_default ) {
		return self::is_factory_english( $option, $default ) ? self::spanish_for_option( $option ) : $default;
	}

	/* ---------------------------------------------------------------------
	 * 2. Textos de MLI que pasan por gettext
	 * ------------------------------------------------------------------ */

	/**
	 * Original en inglés => español. Sólo los que ve el cliente.
	 *
	 * "undefined" no está a propósito: MLI lo usa como valor de un atributo que luego compara en JS.
	 *
	 * @return array<string,string>
	 */
	public static function gettext_map() {
		$tienda = TS_Texts::get( 'mli_location' );
		$select = TS_Texts::get( 'mli_select' );
		$map    = array(
			'Location'                    => $tienda,
			'Location:'                   => $tienda . ':',
			'Location: '                  => $tienda . ': ',
			'Location : '                 => $tienda . ': ',
			'Store: '                     => $tienda . ': ',
			'Locations'                   => self::plural( $tienda ),
			'Select Location'             => $select,
			'Select'                      => 'Seleccionar',
			' - Select - '                => ' - Seleccionar - ',
			'Please Select'               => 'Selecciona',
			'Select Location Group:'      => 'Selecciona una zona:',
			'-- Select a Location Group --' => '-- Selecciona una zona --',
			'Please select a location before adding to cart' => TS_Texts::get( 'mli_select_msg' ),
			'Please select a valid location.' => 'Selecciona una tienda válida.',
			'Please select a Location Group to view stock details.' => 'Selecciona una zona para ver la disponibilidad.',
			'Please select a variation to view stock details.' => 'Selecciona una variación para ver la disponibilidad.',
			'The selected variation is not available.' => 'La variación seleccionada no está disponible.',
			'Next Closest in Stock'       => 'Tienda más cercana con stock',
			'away'                        => 'de distancia',
			'Check your nearest stock location :' => 'Consulta la tienda más cercana con stock:',
			'Check'                       => 'Consultar',
			'Enter Location'              => 'Ingresa tu ubicación',
			'Enter Address'               => 'Ingresa tu dirección',
			'Enter Pincode/Zipcode'       => 'Código postal',
			'Search Location'             => 'Buscar tienda',
			'Search Address'              => 'Buscar dirección',
			'Search'                      => 'Buscar',
			'Use Current Location'        => 'Usar mi ubicación',
			'Loading...'                  => 'Cargando…',
			'Insufficient stock available for the selected location. Please reduce the quantity to proceed.' => 'No hay stock suficiente en la tienda seleccionada. Reduce la cantidad para continuar.',
			'Invalid location.'           => 'Tienda no válida.',
			'Invalid location ID'         => 'Tienda no válida.',
			'Not found any location.'     => 'No se encontró ninguna tienda.',
			'Error loading locations.'    => 'No se pudieron cargar las tiendas.',
			'An error occurred. Please try again.' => 'Ocurrió un error. Inténtalo de nuevo.',
			'All locations'               => 'Todas las tiendas',
			'Any location'                => 'Cualquier tienda',
			'Direction'                   => 'Cómo llegar',
			'Details'                     => 'Detalles',
			'Shop Now'                    => 'Comprar',
			'View Product'                => 'Ver producto',
			'Street address'              => 'Dirección',
			'City'                        => 'Ciudad',
			'State'                       => 'Estado',
			'Country'                     => 'País',
			'Phone'                       => 'Teléfono',
			'Email'                       => 'Correo',
			'Zip code'                    => 'Código postal',
			'Radius'                      => 'Radio',
			'Distance Range'              => 'Distancia',
			'Price Range'                 => 'Precio',
			'Apply'                       => 'Aplicar',
			'Fee : '                      => 'Cargo: ',
			'&laquo; Previous'            => '&laquo; Anterior',
			'Next &raquo;'                => 'Siguiente &raquo;',
			'No common payment methods are available for the selected items. Please check your cart and try again.' => 'No hay un método de pago común a todos los productos del carrito. Revísalo e inténtalo de nuevo.',
			'This coupon requires products from %s in your cart.' => 'Este cupón requiere productos de %s en tu carrito.',
			'View Reviews'                => 'Ver reseñas',
			'Write a Review'              => 'Escribir una reseña',
			'No reviews available yet.'   => 'Aún no hay reseñas.',
			'No reviews found for this location.' => 'Esta tienda aún no tiene reseñas.',
			'Please select a location to view reviews.' => 'Selecciona una tienda para ver sus reseñas.',
			'Reviews for %s'              => 'Reseñas de %s',
			'reviews'                     => 'reseñas',
			'Address saved successfully!' => 'Dirección guardada.',
			'Address deleted successfully!' => 'Dirección eliminada.',
			'Address selected successfully!' => 'Dirección seleccionada.',
			'Are you sure you want to delete this address?' => '¿Seguro que quieres eliminar esta dirección?',
			'Edit'                        => 'Editar',
			'Title (optional):'           => 'Nombre (opcional):',
		);

		/**
		 * Permite corregir o ampliar la traducción de Multi Locations.
		 *
		 * @param array<string,string> $map Original en inglés => traducción.
		 */
		return apply_filters( 'ts_mli_gettext', $map );
	}

	public static function filter_gettext( $translation, $text ) {
		static $map = null;
		if ( null === $map ) {
			$map = self::gettext_map();
		}
		return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
	}

	/* ---------------------------------------------------------------------
	 * 3. Clave de la tienda en las líneas del pedido
	 * ------------------------------------------------------------------ */

	public static function filter_order_meta_key( $key ) {
		if ( 'Location' === $key ) {
			return TS_Texts::get( 'mli_location' );
		}
		if ( 'Pickup Location' === $key ) {
			return TS_Texts::get( 'mli_pickup_location' );
		}
		return $key;
	}

	/* ---------------------------------------------------------------------
	 * 4. Textos fijos del JavaScript de MLI
	 * ------------------------------------------------------------------ */

	/**
	 * Textos exactos de sus diálogos y de alguna plantilla.
	 *
	 * @return array<string,string>
	 */
	public static function js_exact() {
		$select = TS_Texts::get( 'mli_select' );
		$map    = array(
			'- Select Location -'  => '- ' . $select . ' -',
			'Select Location'      => $select,
			'Select'               => 'Seleccionar',
			'Error!'               => 'Error',
			'Cancel'               => TS_Texts::get( 'mli_cancel' ),
			'OK'                   => 'Aceptar',
			'Not Available!'       => TS_Texts::get( 'mli_not_available_title' ),
			'Item is not available at this location!' => TS_Texts::get( 'mli_not_available_text' ),
			'Please select any location!' => TS_Texts::get( 'mli_select_msg' ),
			'Yes, Change Location!' => TS_Texts::get( 'mli_change_confirm' ),
			'Location Updated!'    => TS_Texts::get( 'mli_updated_title' ),
			'We are not serving this area...' => 'Aún no llegamos a tu zona.',
			'Oops...!'             => '¡Vaya!',
			'Invalid response from server.' => 'El servidor respondió algo inesperado. Inténtalo de nuevo.',
			"You've decided not to share your position, but it's OK. We won't ask you again." => 'Decidiste no compartir tu ubicación. No te la volveremos a pedir.',
			'There was an error updating the cart.' => 'No se pudo actualizar el carrito.',
			'AJAX URL not available. Please refresh the page and try again.' => 'Recarga la página e inténtalo de nuevo.',
			'Yes, Update Cart!'    => 'Sí, actualizar el carrito',
			'Yes, Remove & Update!' => 'Sí, quitar y actualizar',
			'Yes, Clear Cart!'     => 'Sí, vaciar el carrito',
			'Updated Cart!'        => 'Carrito actualizado',
			'Cart Updated!'        => 'Carrito actualizado',
			'Cart Cleared!'        => 'Carrito vaciado',
			'Cart Validation'      => 'Tu carrito',
			'The request to get user location timed out.' => 'Se agotó el tiempo para obtener tu ubicación.',
			'The attempt timed out before it could get the location data.' => 'Se agotó el tiempo para obtener tu ubicación.',
			"The network is down or the positioning service can't be reached.You've decided not to share your position, but it's OK. We won't ask you again." => 'No se pudo contactar con el servicio de ubicación.',
			'Location information is unavailable.' => 'Tu ubicación no está disponible.',
			'Geolocation failed due to unknown error.' => 'No pudimos obtener tu ubicación.',
			'An unknown error occurred.' => 'No pudimos obtener tu ubicación.',
			"Product doesn't have a stock!" => 'Este producto no tiene stock.',
			'Please Enter Location!' => 'Ingresa tu ubicación.',
			'Cart was cleared but there was an error adding the new product.' => 'Vaciamos el carrito, pero no pudimos añadir el producto nuevo.',
			'Your cart items has been updated, Please add the item again!' => 'Actualizamos tu carrito. Vuelve a añadir el producto.',
			'Your cart items has been updated and new product added successfully!' => 'Actualizamos tu carrito y añadimos el producto.',
			'Your cart contains items from another location, do you want to update the cart' => 'Tu carrito tiene productos de otra tienda. ¿Quieres actualizarlo?',
			'You can order from only one location!' => 'Solo puedes comprar en una tienda a la vez.',
			'There was an error clearing the cart.' => 'No se pudo vaciar el carrito.',
			'Cart cleared and new product added successfully!' => 'Vaciamos el carrito y añadimos el producto.',
			'Out of Stock'         => 'Agotado',
		);
		return apply_filters( 'ts_mli_js_exact', $map );
	}

	/**
	 * Frases con partes variables: expresión regular del original => plantilla en español.
	 *
	 * Las plantillas usan {actual}, {nueva}, {lista} y {cantidad}, que el JS rellena con lo que
	 * capturó cada grupo según el orden de 'groups'.
	 *
	 * @return array<int,array{re:string,groups:string[],es:string}>
	 */
	public static function js_patterns() {
		$patterns = array(
			array(
				're'     => '^Your cart contains items from ([\\s\\S]+?)\\. Do you want to change all items to ([\\s\\S]+?)\\?$',
				'groups' => array( 'actual', 'nueva' ),
				'es'     => TS_Texts::get( 'mli_change_text' ),
			),
			array(
				're'     => '^Cart location changed to ([\\s\\S]+?)\\. Product added successfully!$',
				'groups' => array( 'nueva' ),
				'es'     => TS_Texts::get( 'mli_updated_text' ),
			),
			array(
				're'     => '^The following products are not available at ([\\s\\S]+?):\\n\\n([\\s\\S]*?)\\n\\nDo you want to remove these products and move the remaining items to [\\s\\S]+?\\?$',
				'groups' => array( 'nueva', 'lista' ),
				'es'     => TS_Texts::get( 'mli_unavailable_move' ),
			),
			array(
				're'     => '^The following products are not available at ([\\s\\S]+?):\\n\\n([\\s\\S]*?)\\n\\nDo you want to clear your cart and add the new product\\?$',
				'groups' => array( 'nueva', 'lista' ),
				'es'     => "Estos productos no están disponibles en {nueva}:\n\n{lista}\n\n¿Quieres vaciar el carrito y añadir el producto nuevo?",
			),
			array(
				're'     => '^The maximum quantity available for this product at the selected location is ([\\s\\S]+?)\\.$',
				'groups' => array( 'cantidad' ),
				'es'     => 'La cantidad máxima disponible en la tienda seleccionada es {cantidad}.',
			),
			array(
				're'     => '^Available quantity is ([\\s\\S]+?) at location ([\\s\\S]+)$',
				'groups' => array( 'cantidad', 'nueva' ),
				'es'     => 'Hay {cantidad} disponibles en {nueva}.',
			),
			array(
				're'     => '^Failed to update cart location: ([\\s\\S]*)$',
				'groups' => array( 'lista' ),
				'es'     => 'No se pudo cambiar la tienda del carrito: {lista}',
			),
			array(
				're'     => '^Please Select [\\s\\S]* any location[\\s\\S]*$',
				'groups' => array(),
				'es'     => TS_Texts::get( 'mli_select_msg' ),
			),
			array(
				// El carrito por bloques añade " | Location : <tienda>" junto al precio de cada línea.
				're'     => '^([\\s\\S]*?)\\|\\s*Location\\s*:\\s*([\\s\\S]*)$',
				'groups' => array( 'actual', 'nueva' ),
				'es'     => '{actual}| ' . TS_Texts::get( 'mli_location' ) . ': {nueva}',
			),
		);
		return apply_filters( 'ts_mli_js_patterns', $patterns );
	}

	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script( 'ts-mli-i18n', TS_PLUGIN_URL . 'assets/js/ts-mli-i18n.js', array(), TS_VERSION, true );
		wp_localize_script( 'ts-mli-i18n', 'ts_mli_i18n', array(
			'exact'    => self::js_exact(),
			'patterns' => self::js_patterns(),
			'qty'      => array( '(Qty: ', '(Cant.: ' ),
			'stock'    => array(
				'instock'   => self::spanish_for_option( 'wcmlim_instock_button_text' ),
				'soldout'   => self::spanish_for_option( 'wcmlim_soldout_button_text' ),
				'backorder' => self::spanish_for_option( 'wcmlim_onbackorder_button_text' ),
				// "5 In Stock" => "5 disponibles", "1 In Stock" => "1 disponible".
				'unit_one'  => 'disponible',
				'unit_many' => 'disponibles',
			),
		) );
	}

	/**
	 * "Tienda" => "Tiendas" para el plural de "Locations".
	 */
	private static function plural( $word ) {
		$word = trim( $word );
		if ( '' === $word ) {
			return $word;
		}
		return preg_match( '/[aeiouáéíóú]$/iu', $word ) ? $word . 's' : $word . 'es';
	}
}
