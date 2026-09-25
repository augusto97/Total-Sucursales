<?php
/**
 * Persistencia en el pedido y visualización en el admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Order {

	public static function init() {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_meta' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_display' ) );

		// Dónde retirar: página de gracias (también la de bloques), el pedido en "Mi cuenta" y los correos.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_pickup_where' ), 5 );
		add_action( 'woocommerce_view_order', array( __CLASS__, 'render_pickup_where' ), 5 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_pickup_where' ), 10, 4 );
		// Si todo se retira en tienda, la dirección de envío no pinta nada (como con "Recogida local").
		add_filter( 'woocommerce_order_hide_shipping_address', array( __CLASS__, 'hide_shipping_address' ), 10, 2 );

		// Título del método de pago en la página de gracias, el pedido y los correos (texto editable).
		add_filter( 'gettext_woocommerce', array( __CLASS__, 'payment_label_gettext' ), 10, 3 );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'payment_label_totals' ), 10, 3 );
	}

	/**
	 * Texto de "Textos → Página de gracias, pedido y correos: título del método de pago", con dos puntos.
	 * Vacío: se deja el de WooCommerce.
	 */
	private static function payment_label() {
		$t = trim( (string) TS_Texts::get( 'payment_label' ) );
		if ( '' === $t ) {
			return '';
		}
		return ':' === substr( $t, -1 ) ? $t : $t . ':';
	}

	/**
	 * "Pago:" del resumen de la página de gracias por bloques y "Método de pago:" de la clásica. Sólo en
	 * el front: el editor de pedidos del admin conserva el de WooCommerce.
	 */
	public static function payment_label_gettext( $translation, $text, $domain = 'woocommerce' ) {
		if ( 'Payment:' !== $text && 'Payment method:' !== $text ) {
			return $translation;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $translation;
		}
		$label = self::payment_label();
		return '' !== $label ? $label : $translation;
	}

	/**
	 * Fila "Método de pago" de la tabla de totales del pedido (página de gracias, "Mi cuenta" y correos,
	 * también los que se envían desde el admin).
	 */
	public static function payment_label_totals( $rows, $order = null, $tax_display = '' ) {
		$label = self::payment_label();
		if ( '' !== $label && isset( $rows['payment_method']['label'] ) ) {
			$rows['payment_method']['label'] = $label;
		}
		return $rows;
	}

	/**
	 * Tiendas de retiro que el cliente eligió, según las líneas de envío del pedido.
	 *
	 * Las tarifas del método propio y las de reglas de Advanced Shipping con la condición de retiro
	 * llevan _ts_mode. Si ninguna línea lo lleva (otro método, o pedidos anteriores a 0.6.1), se usa
	 * lo que se guardó como elegible.
	 *
	 * @param WC_Order $order
	 * @return int[]|null term_id de las tiendas, o null si las líneas no dicen nada.
	 */
	public static function chosen_pickups( $order ) {
		$tagged  = false;
		$pickups = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$mode = (string) $item->get_meta( '_ts_mode' );
			if ( '' === $mode ) {
				continue;
			}
			$tagged = true;
			if ( 'pickup' === $mode && (int) $item->get_meta( '_ts_location_id' ) ) {
				$pickups[] = (int) $item->get_meta( '_ts_location_id' );
			}
		}
		return $tagged ? array_values( array_unique( $pickups ) ) : null;
	}

	/**
	 * @param WC_Order $order
	 * @return int[]
	 */
	public static function pickup_location_ids( $order ) {
		$ids = $order->get_meta( '_ts_pickup_location_ids' );
		if ( is_array( $ids ) ) {
			return array_map( 'intval', $ids );
		}
		$one = (int) $order->get_meta( '_ts_pickup_location_id' );
		return $one ? array( $one ) : array();
	}

	public static function save_meta( $order, $data ) {
		$coords  = TS_Customer::get_coords();
		$summary = TS_Packages::summary();

		$order->update_meta_data( '_ts_customer_state', TS_Customer::get_state() );
		if ( $coords ) {
			$order->update_meta_data( '_ts_customer_lat', $coords['lat'] );
			$order->update_meta_data( '_ts_customer_lng', $coords['lng'] );
			$order->update_meta_data( '_ts_coords_source', $coords['source'] );
		}
		$order->update_meta_data( '_ts_radius_km', TS_Settings::radius_km() );
		$order->update_meta_data( '_ts_pickup_criterion', TS_Settings::pickup_criterion() );
		$order->update_meta_data( '_ts_pickup_scope', TS_Settings::pickup_scope() );
		$order->update_meta_data( '_ts_customer_municipio', (string) ( $summary['customer_municipio'] ?? '' ) );
		// Si el checkout no mostró las opciones de envío, tampoco se muestran después (gracias, correos).
		$order->update_meta_data( '_ts_shipping_hidden', TS_Shipping_UI::hidden() ? 'yes' : 'no' );

		// Lo que eligió el cliente manda: con "Envío también donde se puede retirar" puede haber
		// preferido el envío en una tienda donde podía retirar.
		$chosen  = self::chosen_pickups( $order );
		$pickups = array();
		$rows    = array();
		foreach ( (array) ( $summary['packages'] ?? array() ) as $p ) {
			if ( ! $p['location_id'] ) {
				continue;
			}
			$pickup = null === $chosen ? (bool) $p['pickup_eligible'] : in_array( (int) $p['location_id'], $chosen, true );
			$rows[] = array(
				'location_id'     => $p['location_id'],
				'location_name'   => $p['location_name'],
				'distance_km'     => $p['distance_km'],
				'pickup_eligible' => $p['pickup_eligible'],
				'pickup_reason'   => $p['pickup_reason'] ?? '',
				'pickup'          => $pickup,
			);
			if ( $pickup ) {
				$pickups[] = (int) $p['location_id'];
			}
		}
		$pickups = array_values( array_unique( $pickups ) );
		$first = $pickups ? $pickups[0] : null;
		$order->update_meta_data( '_ts_packages', $rows );
		// Con alcance "todas" puede haber varias tiendas con retiro; la primera se conserva por compatibilidad.
		$order->update_meta_data( '_ts_pickup_location_ids', $pickups );
		$order->update_meta_data( '_ts_pickup_location_id', $first );
		$order->update_meta_data( '_ts_pickup_location_name', implode( ', ', array_map( array( 'TS_Locations', 'name' ), $pickups ) ) );
	}

	public static function admin_display( $order ) {
		$rows = $order->get_meta( '_ts_packages' );
		if ( empty( $rows ) && ! $order->get_meta( '_ts_customer_state' ) ) {
			return;
		}
		echo '<div class="ts-order-meta"><h3>' . esc_html__( 'Total Sucursales', 'total-sucursales' ) . '</h3>';
		$state = $order->get_meta( '_ts_customer_state' );
		if ( $state && '__ALL__' !== $state ) {
			echo '<p><strong>' . esc_html__( 'Estado del cliente:', 'total-sucursales' ) . '</strong> ' . esc_html( ts_state_name( $state ) ) . '</p>';
		}
		$muni = (string) $order->get_meta( '_ts_customer_municipio' );
		if ( '' !== $muni ) {
			echo '<p><strong>' . esc_html__( 'Municipio del cliente:', 'total-sucursales' ) . '</strong> ' . esc_html( TS_Municipios::label( $muni ) ) . '</p>';
		}
		$lat = $order->get_meta( '_ts_customer_lat' );
		$lng = $order->get_meta( '_ts_customer_lng' );
		if ( $lat && $lng ) {
			printf(
				'<p><strong>%s</strong> <a href="%s" target="_blank" rel="noopener">%s, %s</a> <em>(%s)</em></p>',
				esc_html__( 'Posición:', 'total-sucursales' ),
				esc_url( 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=15/' . $lat . '/' . $lng ),
				esc_html( $lat ),
				esc_html( $lng ),
				esc_html( $order->get_meta( '_ts_coords_source' ) )
			);
		}
		if ( ! empty( $rows ) ) {
			echo '<ul>';
			foreach ( (array) $rows as $p ) {
				$reason = isset( $p['pickup_reason'] ) ? $p['pickup_reason'] : '';
				$pickup = isset( $p['pickup'] ) ? $p['pickup'] : $p['pickup_eligible'];
				if ( $pickup ) {
					$what = '<strong>' . esc_html__( 'PICKUP', 'total-sucursales' ) . '</strong>' . ( $reason ? ' ' . esc_html( 'municipio' === $reason ? __( '(por municipio)', 'total-sucursales' ) : __( '(por radio)', 'total-sucursales' ) ) : '' );
				} elseif ( $p['pickup_eligible'] ) {
					$what = esc_html__( 'Envío nacional (podía retirar, eligió envío)', 'total-sucursales' );
				} else {
					$what = esc_html__( 'Envío nacional', 'total-sucursales' );
				}
				printf(
					'<li>%s — %s — %s</li>',
					esc_html( $p['location_name'] ),
					null !== $p['distance_km'] ? esc_html( ts_format_km( $p['distance_km'] ) ) : esc_html__( 'sin distancia', 'total-sucursales' ),
					$what // Escapado arriba.
				);
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Tiendas de retiro del pedido con su dirección: [ [name, address], ... ].
	 *
	 * @param WC_Order $order
	 */
	private static function pickup_places( $order ) {
		$out = array();
		foreach ( self::pickup_location_ids( $order ) as $id ) {
			$name = TS_Locations::name( $id );
			if ( '' === (string) $name ) {
				continue;
			}
			$out[] = array( 'name' => $name, 'address' => TS_Locations::address_line( $id ) );
		}
		return $out;
	}

	/**
	 * @param int|WC_Order $order_id
	 */
	public static function render_pickup_where( $order_id ) {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order || TS_Shipping_UI::order_hidden( $order ) ) {
			return;
		}
		$places = self::pickup_places( $order );
		if ( ! $places ) {
			return;
		}
		echo '<div class="ts-pickup-where"><h2>' . esc_html( TS_Texts::get( 'pickup_where_title' ) ) . '</h2><ul>';
		foreach ( $places as $pl ) {
			echo '<li><strong>' . esc_html( $pl['name'] ) . '</strong>';
			if ( '' !== $pl['address'] ) {
				echo '<span class="ts-pickup-where__address">' . esc_html( $pl['address'] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Correos: mismo bloque, con estilos en línea (los clientes de correo ignoran las hojas de estilo).
	 */
	public static function email_pickup_where( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order instanceof WC_Order || TS_Shipping_UI::order_hidden( $order ) ) {
			return;
		}
		$places = self::pickup_places( $order );
		if ( ! $places ) {
			return;
		}
		$title = TS_Texts::get( 'pickup_where_title' );
		if ( $plain_text ) {
			// Texto plano: no es HTML, así que no se escapa (como hace WooCommerce en sus plantillas).
			echo "\n" . wp_strip_all_tags( wc_strtoupper( $title ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $places as $pl ) {
				echo wp_strip_all_tags( $pl['name'] . ( '' !== $pl['address'] ? ' — ' . $pl['address'] : '' ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo "\n";
			return;
		}
		echo '<div class="ts-pickup-where" style="margin:0 0 24px;padding:12px 16px;border:1px solid #cfe3d6;border-left:4px solid #2e7d4f;background:#f3faf5;">';
		echo '<h2 style="margin:0 0 8px;">' . esc_html( $title ) . '</h2>';
		foreach ( $places as $pl ) {
			echo '<p style="margin:0 0 6px;"><strong>' . esc_html( $pl['name'] ) . '</strong>';
			if ( '' !== $pl['address'] ) {
				echo '<br>' . esc_html( $pl['address'] );
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Oculta la dirección de envío (correos, página de gracias) cuando todas las líneas de envío del
	 * pedido son retiro en tienda. Con un pedido mixto (retiro + envío) se mantiene.
	 *
	 * @param string[] $methods Métodos para los que WooCommerce oculta la dirección.
	 * @param WC_Order $order
	 */
	public static function hide_shipping_address( $methods, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			return $methods;
		}
		$items = $order->get_items( 'shipping' );
		if ( ! $items ) {
			return $methods;
		}
		$ids = array();
		foreach ( $items as $item ) {
			if ( 'pickup' !== $item->get_meta( '_ts_mode' ) ) {
				return $methods;
			}
			$ids[] = $item->get_method_id();
		}
		return array_values( array_unique( array_merge( (array) $methods, $ids ) ) );
	}
}
