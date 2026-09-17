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
		if ( ! TS_Settings::is_yes( 'checkout_geo_button' ) ) {
			return;
		}
		$coords = TS_Customer::get_coords();
		$has    = $coords && 'gps' === $coords['source'];
		?>
		<tr class="ts-checkout-geo">
			<td colspan="2">
				<button type="button" class="button ts-checkout-geo__btn" <?php disabled( $has ); ?>>
					<?php echo $has ? esc_html__( 'Ubicación registrada', 'total-sucursales' ) : esc_html__( 'Usar mi ubicación para retiro en tienda', 'total-sucursales' ); ?>
				</button>
				<small class="ts-checkout-geo__hint"><?php esc_html_e( 'Comparte tu posición para saber si puedes retirar en la sucursal más cercana.', 'total-sucursales' ); ?></small>
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
							<?php if ( null !== $p['distance_km'] ) : ?>
								<span class="ts-distance"><?php echo esc_html( ts_format_km( $p['distance_km'] ) ); ?></span>
							<?php else : ?>
								<span class="ts-distance ts-distance--unknown"><?php esc_html_e( 'distancia no disponible', 'total-sucursales' ); ?></span>
							<?php endif; ?>
							<?php if ( $p['pickup_eligible'] ) : ?>
								<span class="ts-badge ts-badge--pickup"><?php echo esc_html( TS_Settings::get( 'pickup_label' ) ); ?></span>
							<?php else : ?>
								<span class="ts-badge ts-badge--national"><?php esc_html_e( 'Envío nacional', 'total-sucursales' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( ! $summary['coords'] ) : ?>
					<small class="ts-distance-note"><?php esc_html_e( 'No conocemos tu posición: comparte tu ubicación o completa la dirección para evaluar el retiro en tienda.', 'total-sucursales' ); ?></small>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
