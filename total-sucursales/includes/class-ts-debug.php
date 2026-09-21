<?php
/**
 * Modo diagnóstico en el front.
 *
 * Se activa en los ajustes del plugin y se abre añadiendo ?ts_debug=1 a cualquier URL de la tienda.
 * Muestra el estado interno que decide qué sucursales ve el visitante, para poder diagnosticar
 * sin acceso al servidor. Conviene apagarlo cuando se termina de revisar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Debug {

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 999 );
	}

	public static function is_active() {
		if ( ! isset( $_GET['ts_debug'] ) || '1' !== (string) $_GET['ts_debug'] ) {
			return false;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return TS_Settings::is_yes( 'debug_front' );
	}

	/**
	 * Ajustes de Multi Locations que pueden dejar el selector con una sola sucursal o vacío.
	 *
	 * @return array<string,string> etiqueta => explicación
	 */
	public static function mli_conflicts() {
		$out = array();

		if ( 'on' === get_option( 'wcmlim_enable_restrict_guestuser_location' ) && ! is_user_logged_in() ) {
			$forced = (int) get_option( 'wcmlim_restrict_guest_user_location' );
			$out['wcmlim_enable_restrict_guestuser_location'] = sprintf(
				/* translators: %s nombre de la sucursal */
				__( 'Multi Locations está forzando una única sucursal para visitantes no registrados (%s). El filtro por estado se desactiva para no dejar el selector vacío.', 'total-sucursales' ),
				$forced ? TS_Locations::name( $forced ) . ' #' . $forced : __( 'sin definir', 'total-sucursales' )
			);
		}

		if ( 'on' === get_option( 'wcmlim_enable_userspecific_location' ) && is_user_logged_in() ) {
			$user_loc = get_user_meta( get_current_user_id(), 'wcmlim_user_specific_location', true );
			if ( '' !== $user_loc && '0' !== (string) $user_loc ) {
				$out['wcmlim_enable_userspecific_location'] = __( 'Multi Locations asigna una sucursal fija a este usuario. El filtro por estado se desactiva para no dejar el selector vacío.', 'total-sucursales' );
			}
		}

		if ( 'on' === get_option( 'wcmlim_enable_location_group' ) ) {
			$out['wcmlim_enable_location_group'] = __( 'Los grupos de ubicaciones están activos: el selector muestra primero el grupo y luego sus sucursales. Si el grupo no contiene sucursales del estado del cliente, la lista sale vacía. Este plugin no filtra grupos.', 'total-sucursales' );
		}

		return $out;
	}

	public static function render() {
		if ( is_admin() || ! self::is_active() ) {
			return;
		}

		$applies   = TS_Location_Filter::applies();
		$manual    = TS_Location_Filter::manual_exclusions();
		$excluded  = $applies ? TS_Location_Filter::excluded_ids() : array();
		$visible   = TS_Locations::mli_term_list();
		$vis_names = array();
		foreach ( $visible as $t ) {
			$vis_names[] = $t->name . ' #' . $t->term_id;
		}
		$coords    = TS_Customer::get_coords();
		$conflicts = self::mli_conflicts();
		$state     = TS_Customer::get_state();
		$raw_opt   = get_option( 'wcmlim_exclude_locations_from_frontend' );
		$all       = TS_Locations::all();

		$rows = array(
			__( 'Estado del cliente', 'total-sucursales' ) => ( '' === $state ? __( '(ninguno: se muestran todas)', 'total-sucursales' ) : $state . ' · ' . ts_state_name( $state ) ) . ' [' . ( TS_Customer::get_state_source() ? TS_Customer::get_state_source() : 'sin definir' ) . ']',
			__( 'Cookie ts_estado', 'total-sucursales' ) => var_export( ts_get_cookie( TS_Customer::COOKIE_STATE ), true ),
			__( 'Sucursal seleccionada (MLI)', 'total-sucursales' ) => 'termid=' . var_export( ts_get_cookie( 'wcmlim_selected_location_termid' ), true ) . ' · índice=' . var_export( ts_get_cookie( 'wcmlim_selected_location' ), true ),
			__( 'Posición del cliente', 'total-sucursales' ) => $coords ? $coords['lat'] . ', ' . $coords['lng'] . ' (' . $coords['source'] . ')' : __( '(desconocida)', 'total-sucursales' ),
			__( 'Filtro por estado', 'total-sucursales' ) => $applies ? __( 'activo', 'total-sucursales' ) : __( 'NO se aplica en esta página', 'total-sucursales' ),
			__( 'Ocultas en MLI (ajuste del admin)', 'total-sucursales' ) => empty( $manual ) ? __( '(ninguna)', 'total-sucursales' ) : implode( ', ', $manual ),
			__( 'Valor crudo de la opción de MLI', 'total-sucursales' ) => is_scalar( $raw_opt ) ? (string) $raw_opt : wp_json_encode( $raw_opt ),
			__( 'Excluidas ahora mismo', 'total-sucursales' ) => $applies ? ( empty( $excluded ) ? __( '(ninguna)', 'total-sucursales' ) : implode( ', ', $excluded ) ) : __( '(ninguna: el filtro no se aplica)', 'total-sucursales' ),
			__( 'Sucursales visibles', 'total-sucursales' ) => empty( $vis_names ) ? __( '¡NINGUNA!', 'total-sucursales' ) : implode( ' · ', $vis_names ),
			__( 'Usuario', 'total-sucursales' ) => is_user_logged_in() ? 'registrado #' . get_current_user_id() : __( 'invitado', 'total-sucursales' ),
		);
		?>
		<div id="ts-debug">
			<style>
				#ts-debug{position:fixed!important;left:0!important;right:0!important;bottom:0!important;z-index:2147483647!important;background:#111!important;max-height:60vh;overflow:auto;padding:12px 16px;box-shadow:0 -4px 20px rgba(0,0,0,.45);margin:0!important}
				#ts-debug *{color:#e8e8e8!important;font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace!important;background:transparent!important;text-transform:none!important;letter-spacing:normal!important;text-shadow:none!important}
				#ts-debug table{border-collapse:collapse;width:100%;max-width:1100px;margin:0}
				#ts-debug td{padding:2px 12px 2px 0;vertical-align:top;border:0}
				#ts-debug .k{white-space:nowrap}
				#ts-debug .k,#ts-debug .muted,#ts-debug .muted *{color:#98a2ad!important}
				#ts-debug .ok,#ts-debug .ok *{color:#4ade80!important}
				#ts-debug .warn,#ts-debug .warn *{color:#fbbf24!important}
				#ts-debug .bad,#ts-debug .bad *{color:#f87171!important}
				#ts-debug .title,#ts-debug .title *{color:#2dd4bf!important;font-weight:700!important}
				#ts-debug ul{margin:4px 0 0;padding-left:18px}
			</style>
			<div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:8px">
				<span class="title">Total Sucursales · diagnóstico <?php echo esc_html( TS_VERSION ); ?></span>
				<a href="#" class="muted" onclick="document.getElementById('ts-debug').remove();return false">cerrar ✕</a>
			</div>
			<table>
				<?php foreach ( $rows as $k => $v ) : ?>
					<tr>
						<td class="k"><?php echo esc_html( $k ); ?></td>
						<td class="<?php echo ( false !== strpos( $v, 'NINGUNA' ) ? 'bad' : '' ); ?>"><?php echo esc_html( $v ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<div class="muted" style="margin-top:10px"><?php printf( esc_html__( 'Sucursales cargadas (%d):', 'total-sucursales' ), count( $all ) ); ?></div>
			<table style="margin-top:4px">
				<tr class="muted"><td>ID</td><td>Nombre</td><td><?php esc_html_e( 'Estado guardado', 'total-sucursales' ); ?></td><td><?php esc_html_e( 'Se lee', 'total-sucursales' ); ?></td><td><?php esc_html_e( 'Coordenadas', 'total-sucursales' ); ?></td><td><?php esc_html_e( 'Situación', 'total-sucursales' ); ?></td></tr>
				<?php
				foreach ( $all as $id => $l ) :
					$hidden = in_array( (int) $id, $manual, true );
					$is_out = in_array( (int) $id, $excluded, true );
					if ( $hidden ) {
						$note = __( 'oculta en MLI', 'total-sucursales' );
						$cls  = 'bad';
					} elseif ( $is_out ) {
						$note = __( 'fuera del estado', 'total-sucursales' );
						$cls  = 'warn';
					} else {
						$note = __( 'visible', 'total-sucursales' );
						$cls  = 'ok';
					}
					?>
					<tr>
						<td><?php echo (int) $id; ?></td>
						<td><?php echo esc_html( $l['name'] ); ?></td>
						<td><?php echo esc_html( '' === $l['state_raw'] ? '—' : $l['state_raw'] ); ?></td>
						<td class="<?php echo ( '' === $l['state'] ? 'bad' : '' ); ?>"><?php echo esc_html( '' === $l['state'] ? '—' : $l['state'] ); ?></td>
						<td class="<?php echo ( $l['has_coords'] ? '' : 'bad' ); ?>"><?php echo $l['has_coords'] ? esc_html( $l['lat'] . ', ' . $l['lng'] ) : '—'; ?></td>
						<td class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $note ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php if ( ! empty( $conflicts ) ) : ?>
				<div class="warn" style="margin-top:10px"><?php esc_html_e( 'Ajustes de Multi Locations que afectan al selector:', 'total-sucursales' ); ?></div>
				<ul class="warn">
					<?php foreach ( $conflicts as $opt => $msg ) : ?>
						<li><?php echo esc_html( $opt ); ?> — <?php echo esc_html( $msg ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}
