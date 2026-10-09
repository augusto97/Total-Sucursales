<?php
/**
 * Selector de tienda de la cabecera: un botón ("Tu tienda: SANTA RITA") que abre una ventana para
 * elegir la tienda, al estilo de los grandes comercios.
 *
 *   - Shortcode [ts_tienda]. Con "Selector de tienda con el diseño de Total Sucursales" activado,
 *     el [wcmlim_locations_switch] de Multi Locations también lo muestra, así que no hace falta
 *     tocar la cabecera.
 *   - La ventana lista las tiendas que el cliente puede elegir (en el modo por estado, todas las no
 *     ocultas, agrupadas por estado; en el modo por ciudad, las de su ciudad o la de por defecto),
 *     con ciudad y dirección, y ofrece "Usar mi ubicación".
 *   - Al guardar: si el carrito tiene productos de otra tienda y Multi Locations sólo permite comprar
 *     en una ("Restrict to One Location"), avisa en la propia ventana; al confirmar, pasa el
 *     carrito a la tienda nueva y quita lo que no hay en ella. Luego fija la tienda (y su estado) y
 *     recarga la página con el aviso "Tienda guardada".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Store_Picker {

	/** @var bool Se pintó algún botón en esta página: hay que imprimir la ventana. */
	private static $used = false;

	public static function init() {
		add_shortcode( 'ts_tienda', array( __CLASS__, 'shortcode' ) );
		if ( self::replaces_mli() ) {
			// Después de que TS_Compat envuelva los shortcodes de Multi Locations (init 999).
			add_action( 'init', array( __CLASS__, 'take_over_mli_shortcode' ), 1000 );
			add_action( 'wp', array( __CLASS__, 'take_over_mli_shortcode' ), 1 );
		}
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ), 5 );
		add_filter( 'render_block_core/shortcode', array( __CLASS__, 'shortcode_block_no_autop' ), 21, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'wp_ajax_ts_pick_store', array( __CLASS__, 'ajax_pick' ) );
		add_action( 'wp_ajax_nopriv_ts_pick_store', array( __CLASS__, 'ajax_pick' ) );
	}

	public static function replaces_mli() {
		return TS_Settings::is_yes( 'store_picker' );
	}

	public static function take_over_mli_shortcode() {
		global $shortcode_tags;
		if ( isset( $shortcode_tags['wcmlim_locations_switch'] ) ) {
			$shortcode_tags['wcmlim_locations_switch'] = array( __CLASS__, 'shortcode' );
		}
	}

	/** Marca delante del botón, para reconocerlo dentro de un bloque Shortcode ya expandido. */
	const MARK = '<!-- ts:tienda -->';

	/**
	 * El bloque "Shortcode" pasa su contenido por wpautop(), que metería <p> en el botón. Si el bloque
	 * sólo contiene el selector, se devuelve tal cual (como en TS_Compat para Multi Locations).
	 */
	public static function shortcode_block_no_autop( $content, $block = array() ) {
		$inner = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		if ( false !== strpos( $inner, self::MARK ) || preg_match( '/^\s*(\[(ts_tienda|wcmlim_locations_switch)[^\]]*\]\s*)+$/i', $inner ) ) {
			return trim( $inner );
		}
		return $content;
	}

	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_style( 'ts-store-picker', TS_PLUGIN_URL . 'assets/css/ts-store-picker.css', array(), TS_VERSION );
		wp_enqueue_script( 'ts-store-picker', TS_PLUGIN_URL . 'assets/js/ts-store-picker.js', array( 'jquery', 'ts-frontend' ), TS_VERSION, true );
	}

	/**
	 * Tiendas que el cliente puede elegir en la ventana.
	 *
	 * @return array<int,array{id:int,name:string,state:string,state_name:string,city:string,address:string}>
	 */
	public static function stores() {
		$all = TS_Locations::all();
		if ( 'city' === TS_Settings::visibility_mode() ) {
			$ids = TS_Location_Filter::visible_ids();
		} else {
			$ids = array_diff( array_keys( $all ), TS_Location_Filter::manual_exclusions() );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! isset( $all[ $id ] ) ) {
				continue;
			}
			$l     = $all[ $id ];
			$out[] = array(
				'id'         => $id,
				'name'       => $l['name'],
				'state'      => (string) $l['state'],
				'state_name' => '' !== (string) $l['state'] ? ts_state_name( $l['state'] ) : '',
				'city'       => (string) $l['city'],
				'address'    => (string) $l['address'],
			);
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $a['state_name'] . ' ' . $a['name'], $b['state_name'] . ' ' . $b['name'] );
		} );
		return $out;
	}

	/**
	 * La tienda elegida, si el cliente puede verla.
	 */
	public static function current() {
		$id = TS_Customer::selected_mli_location_id();
		return $id && TS_Locations::get( $id ) ? $id : 0;
	}

	public static function shortcode( $atts = array() ) {
		self::$used = true;
		$atts    = shortcode_atts( array( 'class' => '' ), $atts, 'ts_tienda' );
		$current = self::current();
		$name    = $current ? TS_Locations::name( $current ) : '';
		$pin     = self::icon_pin();
		$html    = self::MARK . '<div class="ts-sp ' . esc_attr( $atts['class'] ) . '">'
			. '<button type="button" class="ts-sp__trigger" aria-haspopup="dialog" aria-controls="ts-sp-modal">'
			. $pin
			. '<span class="ts-sp__text">'
			. ( $current
				? '<span class="ts-sp__label">' . esc_html( TS_Texts::get( 'sp_label' ) ) . '</span><strong class="ts-sp__name">' . esc_html( $name ) . '</strong>'
				: '<strong class="ts-sp__name ts-sp__name--empty">' . esc_html( TS_Texts::get( 'sp_none' ) ) . '</strong>' )
			. '</span>'
			. '<svg class="ts-sp__chevron" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
			. '</button>'
			. '<div class="ts-sp__toast" role="status" hidden>'
			. '<svg class="ts-sp__toast-icon" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m7.5 12.5 3 3 6-6.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
			. '<span><strong>' . esc_html( TS_Texts::get( 'sp_saved_title' ) ) . '</strong><span>' . esc_html( TS_Texts::get( 'sp_saved_text' ) ) . '</span></span>'
			. '<button type="button" class="ts-sp__toast-close" aria-label="' . esc_attr__( 'Cerrar', 'total-sucursales' ) . '">&times;</button>'
			. '</div>'
			. '</div>';
		return $html;
	}

	private static function icon_pin( $class = 'ts-sp__pin' ) {
		return '<svg class="' . esc_attr( $class ) . '" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21.5s-6.5-6.2-6.5-11.2a6.5 6.5 0 0 1 13 0c0 5-6.5 11.2-6.5 11.2Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="10.3" r="2.4" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
	}

	/**
	 * La ventana, una vez por página, al final del <body> (dentro de la cabecera un ancestro con
	 * transform o overflow podría recortarla).
	 */
	public static function render_modal() {
		if ( ! self::$used || is_admin() ) {
			return;
		}
		$stores  = self::stores();
		$current = self::current();
		$states  = array();
		foreach ( $stores as $s ) {
			if ( '' !== $s['state'] ) {
				$states[ $s['state'] ] = $s['state_name'];
			}
		}
		asort( $states );
		$cur_state = $current && TS_Locations::get( $current ) ? (string) TS_Locations::get( $current )['state'] : '';
		$show_states = 'city' !== TS_Settings::visibility_mode() && count( $states ) > 1;
		?>
		<div id="ts-sp-modal" class="ts-sp-modal" role="dialog" aria-modal="true" aria-labelledby="ts-sp-title" hidden>
			<div class="ts-sp-modal__box">
				<div class="ts-sp-modal__head">
					<?php echo self::icon_pin( 'ts-sp-modal__pin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<h2 id="ts-sp-title" class="ts-sp-modal__title">
						<?php echo esc_html( TS_Texts::get( 'sp_title' ) ); ?>
						<strong class="ts-sp-modal__current"><?php echo $current ? esc_html( TS_Locations::name( $current ) ) : ''; ?></strong>
					</h2>
					<button type="button" class="ts-sp-modal__close" aria-label="<?php esc_attr_e( 'Cerrar', 'total-sucursales' ); ?>">&times;</button>
				</div>
				<p class="ts-sp-modal__intro"><?php echo esc_html( TS_Texts::get( 'sp_intro' ) ); ?></p>
				<div class="ts-sp-modal__form">
					<?php if ( $show_states ) : ?>
						<label class="ts-sp-field">
							<span class="ts-sp-field__label"><?php echo esc_html( TS_Texts::get( 'sp_state' ) ); ?></span>
							<select class="ts-sp-field__control ts-sp-state">
								<?php foreach ( $states as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $cur_state ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<fieldset class="ts-sp-field ts-sp-field--stores">
						<legend class="ts-sp-field__label"><?php echo esc_html( TS_Texts::get( 'sp_store' ) ); ?></legend>
						<div class="ts-sp-stores">
							<?php foreach ( $stores as $s ) : ?>
								<label class="ts-sp-store" data-state="<?php echo esc_attr( $s['state'] ); ?>">
									<input type="radio" name="ts_sp_store" value="<?php echo esc_attr( $s['id'] ); ?>" <?php checked( $s['id'], $current ); ?>>
									<span class="ts-sp-store__body">
										<strong class="ts-sp-store__name"><?php echo esc_html( $s['name'] ); ?></strong>
										<?php
										$line = implode( ' · ', array_filter( array( $s['city'], $s['address'] ), 'strlen' ) );
										if ( '' !== $line ) :
											?>
											<span class="ts-sp-store__address"><?php echo esc_html( $line ); ?></span>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<button type="button" class="ts-sp-modal__gps">
						<?php echo self::icon_pin( 'ts-sp-modal__gps-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo esc_html( TS_Texts::get( 'modal_gps' ) ); ?>
					</button>
					<div class="ts-sp-modal__conflict" hidden>
						<p class="ts-sp-modal__conflict-text"></p>
						<ul class="ts-sp-modal__conflict-list"></ul>
					</div>
					<p class="ts-sp-modal__status" aria-live="polite"></p>
					<div class="ts-sp-modal__actions">
						<button type="button" class="ts-sp-modal__cancel" hidden><?php echo esc_html( TS_Texts::get( 'mli_cancel' ) ); ?></button>
						<button type="button" class="ts-sp-modal__save"><?php echo esc_html( TS_Texts::get( 'sp_save' ) ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<script type="application/json" id="ts-sp-data"><?php
		echo wp_json_encode( array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( TS_Frontend::NONCE ),
			'i18n'     => array(
				'conflict'    => TS_Texts::get( 'sp_conflict' ),
				'unavailable' => TS_Texts::get( 'sp_unavailable' ),
				'confirm'     => TS_Texts::get( 'sp_confirm' ),
				'save'        => TS_Texts::get( 'sp_save' ),
				'pick'        => TS_Texts::get( 'sp_pick' ),
				'error'       => TS_Texts::get( 'sp_error' ),
				'locating'    => TS_Texts::get( 'locating' ),
				'geo_error'   => TS_Texts::get( 'geo_error' ),
			),
		) );
		?></script>
		<?php
	}

	/**
	 * Guardar la tienda elegida.
	 *
	 * POST: term_id, move (1 = ya confirmó pasar el carrito a la tienda nueva).
	 * Respuestas: {saved:true} o {conflict:true, current, target, unavailable:[{name,quantity}]}.
	 */
	public static function ajax_pick() {
		if ( ! check_ajax_referer( TS_Frontend::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'nonce' ), 403 );
		}
		$target  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$move    = ! empty( $_POST['move'] );
		$allowed = wp_list_pluck( self::stores(), 'id' );
		if ( ! $target || ! in_array( $target, array_map( 'intval', $allowed ), true ) ) {
			wp_send_json_error( array( 'message' => 'store' ), 400 );
		}

		$conflict = self::cart_conflict( $target );
		if ( $conflict && ! $move ) {
			wp_send_json_success( array(
				'conflict'    => true,
				'current'     => $conflict['current'],
				'target'      => TS_Locations::name( $target ),
				'unavailable' => $conflict['unavailable'],
			) );
		}
		if ( $conflict ) {
			self::move_cart( $target );
		}

		// En el modo por estado, la tienda nueva puede ser de un estado que el cliente no está viendo:
		// primero ese estado (cambia las tiendas visibles), luego la tienda.
		if ( 'city' !== TS_Settings::visibility_mode() && ! in_array( $target, array_map( 'intval', TS_Location_Filter::visible_ids() ), true ) ) {
			$state = (string) TS_Locations::get( $target )['state'];
			if ( '' !== $state ) {
				TS_Customer::set_state( $state, 'manual' );
			}
		}
		TS_Location_Filter::flush_request_cache();
		TS_Customer::select_mli_location( $target );
		wp_send_json_success( array( 'saved' => true, 'name' => TS_Locations::name( $target ) ) );
	}

	/**
	 * Con "Restrict to One Location" de Multi Locations, ¿hay en el carrito productos de otra tienda?
	 *
	 * @return array{current:string,unavailable:array}|null
	 */
	public static function cart_conflict( $target ) {
		if ( 'on' !== get_option( 'wcmlim_clear_cart' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$current     = '';
		$unavailable = array();
		$conflict    = false;
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
			if ( 'yes' !== get_post_meta( $pid, '_manage_stock', true ) ) {
				continue;
			}
			$loc = isset( $item['select_location']['location_termId'] ) ? (int) $item['select_location']['location_termId'] : 0;
			if ( $loc === (int) $target ) {
				continue;
			}
			$conflict = true;
			if ( '' === $current && $loc ) {
				$current = TS_Locations::name( $loc );
			}
			if ( ! self::available_at( $pid, $target, (int) $item['quantity'] ) ) {
				$unavailable[] = array( 'name' => get_the_title( $pid ), 'quantity' => (int) $item['quantity'] );
			}
		}
		return $conflict ? array( 'current' => $current, 'unavailable' => $unavailable ) : null;
	}

	private static function available_at( $pid, $loc, $qty ) {
		$stock    = (float) get_post_meta( $pid, 'wcmlim_stock_at_' . $loc, true );
		$product  = wc_get_product( $pid );
		$backhere = 'no' !== strtolower( (string) get_post_meta( $pid, 'wcmlim_allow_backorder_at_' . $loc, true ) );
		return $stock >= $qty || ( $product && $product->backorders_allowed() && $backhere );
	}

	/**
	 * Pasa el carrito a la tienda nueva, como hace Multi Locations al confirmar su "¿Cambiar de
	 * tienda?" (wcmlim_update_cart_location): cambia la tienda de cada línea y quita las que no
	 * tienen stock en ella.
	 */
	private static function move_cart( $target ) {
		$cart  = WC()->cart;
		$name  = TS_Locations::name( $target );
		$price = 'on' === get_option( 'wcmlim_enable_price' );
		foreach ( $cart->get_cart() as $key => $item ) {
			$pid = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
			if ( 'yes' !== get_post_meta( $pid, '_manage_stock', true ) ) {
				continue;
			}
			if ( ! self::available_at( $pid, $target, (int) $item['quantity'] ) ) {
				$cart->remove_cart_item( $key );
				continue;
			}
			$item['select_location']['location_termId'] = $target;
			$item['select_location']['location_name']   = $name;
			$item['select_location']['location_qty']    = (int) get_post_meta( $pid, 'wcmlim_stock_at_' . $target, true );
			if ( $price ) {
				$sale    = get_post_meta( $pid, 'wcmlim_sale_price_at_' . $target, true );
				$regular = get_post_meta( $pid, 'wcmlim_regular_price_at_' . $target, true );
				$val     = '' !== (string) $sale ? $sale : $regular;
				if ( '' !== (string) $val ) {
					$item['select_location']['location_cart_price'] = $val;
				}
			}
			$cart->cart_contents[ $key ] = $item;
		}
		$cart->set_session();
	}
}
