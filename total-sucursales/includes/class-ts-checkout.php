<?php
/**
 * Checkout clásico: captura de posición, geocodificación de respaldo e información de distancia.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Checkout {

	public static function init() {
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'on_update_order_review' ), 5 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'on_checkout_process' ), 5 );
		add_action( 'woocommerce_review_order_before_shipping', array( __CLASS__, 'render_geo_button' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render_distance_info' ) );
		add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'render_cart_note' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		wp_enqueue_script( 'ts-checkout', TS_PLUGIN_URL . 'assets/js/ts-checkout.js', array( 'jquery', 'wc-checkout', 'ts-frontend' ), TS_VERSION, true );
	}

	/**
	 * Cada refresco del checkout: si no hay GPS, geocodificar la dirección de envío efectiva.
	 */
	public static function on_update_order_review( $post_data ) {
		parse_str( (string) $post_data, $data );
		self::sync_coords_from_address( $data );
	}

	public static function on_checkout_process() {
		self::sync_coords_from_address( wp_unslash( $_POST ) );
	}

	/**
	 * Dirección efectiva de envío del formulario (shipping_* si "enviar a otra dirección", si no billing_*).
	 */
	public static function effective_address( array $data ) {
		$prefix = ! empty( $data['ship_to_different_address'] ) ? 'shipping_' : 'billing_';
		return array(
			'address_1' => sanitize_text_field( $data[ $prefix . 'address_1' ] ?? '' ),
			'address_2' => sanitize_text_field( $data[ $prefix . 'address_2' ] ?? '' ),
			'city'      => sanitize_text_field( $data[ $prefix . 'city' ] ?? '' ),
			'state'     => sanitize_text_field( $data[ $prefix . 'state' ] ?? '' ),
			'postcode'  => sanitize_text_field( $data[ $prefix . 'postcode' ] ?? '' ),
			'country'   => sanitize_text_field( $data[ $prefix . 'country' ] ?? 'VE' ),
		);
	}

	private static function sync_coords_from_address( array $data ) {
		$coords = TS_Customer::get_coords();
		if ( $coords && 'gps' === $coords['source'] ) {
			return; // El GPS manda.
		}
		$address = self::effective_address( $data );
		if ( 'VE' !== $address['country'] || '' === $address['city'] ) {
			TS_Customer::set_session_coords( null, null, 'none' );
			return;
		}
		$result = TS_Geocoder::geocode( $address );
		if ( $result ) {
			TS_Customer::set_session_coords( $result['lat'], $result['lng'], 'geocode' );
		} else {
			TS_Customer::set_session_coords( null, null, 'none' );
		}
	}

	/**
	 * Botón "Usar mi ubicación" encima de los métodos de envío.
	 */
	public static function render_geo_button() {
		if ( ! TS_Packages::geo_button_enabled() ) {
			return;
		}
		$coords = TS_Customer::get_coords();
		$has    = $coords && 'gps' === $coords['source'];
		?>
		<tr class="ts-checkout-geo">
			<td colspan="2">
				<button type="button" class="button ts-checkout-geo__btn" <?php disabled( $has ); ?>>
					<?php echo esc_html( TS_Texts::get( $has ? 'checkout_registered' : 'checkout_button' ) ); ?>
				</button>
				<small class="ts-checkout-geo__hint"><?php echo esc_html( TS_Texts::get( 'checkout_hint' ) ); ?></small>
				<span class="ts-checkout-geo__status" aria-live="polite"></span>
			</td>
		</tr>
		<?php
	}

	/**
	 * Distancia a cada sucursal del pedido y cuál es elegible para pickup.
	 */
	public static function render_distance_info() {
		if ( ! TS_Settings::is_yes( 'show_distance_info' ) ) {
			return;
		}
		$summary = TS_Packages::summary();
		if ( empty( $summary['packages'] ) ) {
			return;
		}
		$rows = array();
		foreach ( $summary['packages'] as $p ) {
			if ( ! $p['location_id'] ) {
				continue;
			}
			$rows[] = $p;
		}
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<tr class="ts-distance-info">
			<td colspan="2">
				<ul class="ts-distance-list">
					<?php foreach ( $rows as $p ) : ?>
						<li>
							<strong><?php echo esc_html( $p['location_name'] ); ?></strong>
							<?php $lbl = TS_Packages::row_label( $p ); ?>
							<?php if ( '' !== $lbl['label'] ) : ?>
								<span class="ts-distance<?php echo $lbl['unknown'] ? ' ts-distance--unknown' : ''; ?>"><?php echo esc_html( $lbl['label'] ); ?></span>
							<?php endif; ?>
							<?php if ( $p['pickup_eligible'] ) : ?>
								<span class="ts-badge ts-badge--pickup"><?php echo esc_html( TS_Texts::get( 'pickup_label' ) ); ?></span>
							<?php else : ?>
								<span class="ts-badge ts-badge--national"><?php echo esc_html( TS_Texts::get( 'national' ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php self::print_note( TS_Packages::pickup_note( $summary, 'checkout' ) ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Carrito clásico: el cálculo de envío del carrito no pide el municipio, así que un cliente de
	 * Maracaibo ve "Envío nacional" aunque podría retirar. Se le avisa de que puede elegirlo al
	 * finalizar la compra y de qué municipios retiran en cada tienda.
	 */
	public static function render_cart_note() {
		if ( ! TS_Settings::is_yes( 'show_distance_info' ) ) {
			return;
		}
		$note = TS_Packages::pickup_note( TS_Packages::summary(), 'cart' );
		if ( '' === $note['text'] ) {
			return;
		}
		echo '<tr class="ts-cart-pickup-note"><td colspan="2">';
		self::print_note( $note );
		echo '</td></tr>';
	}

	/**
	 * @param array{text:string,lines:string[]} $note TS_Packages::pickup_note().
	 */
	private static function print_note( array $note ) {
		if ( '' === $note['text'] ) {
			return;
		}
		echo '<small class="ts-distance-note">' . esc_html( $note['text'] );
		foreach ( $note['lines'] as $line ) {
			echo '<span class="ts-distance-note__line">' . esc_html( $line ) . '</span>';
		}
		echo '</small>';
	}
}
