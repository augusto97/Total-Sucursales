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

	const FATALS_OPTION = 'ts_last_fatals';
	const FATALS_VERSION_OPTION = 'ts_last_fatals_version';

	/** @var array|null memoria por request */
	private static $conflicts = null;

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 999 );
		self::forget_fatals_from_previous_version();
		if ( TS_Settings::is_yes( 'debug_front' ) ) {
			register_shutdown_function( array( __CLASS__, 'capture_fatal' ) );
		}
	}

	/**
	 * Al actualizar el plugin se vacía el registro de errores fatales.
	 *
	 * Los que hubiera son de un código que ya no se está ejecutando, así que sólo confunden: dan la
	 * impresión de estar ocurriendo ahora cuando puede que la actualización los haya corregido. No se
	 * pierde nada, porque si el error sigue ahí se vuelve a registrar en cuanto ocurra.
	 */
	private static function forget_fatals_from_previous_version() {
		if ( get_option( self::FATALS_VERSION_OPTION ) === TS_VERSION ) {
			return;
		}
		delete_option( self::FATALS_OPTION );
		update_option( self::FATALS_VERSION_OPTION, TS_VERSION, false );
	}

	/**
	 * Guarda los errores fatales de PHP mientras el diagnóstico está activo.
	 *
	 * Sirve sobre todo para ver por qué fallan las llamadas AJAX (error 500), que de otro modo
	 * sólo aparecen en el log del servidor.
	 */
	public static function capture_fatal() {
		$e = error_get_last();
		if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}
		$action = '';
		if ( isset( $_REQUEST['action'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
		}
		$entry = array(
			'time'    => time(),
			'version' => defined( 'TS_VERSION' ) ? TS_VERSION : '',
			'action'  => $action,
			'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'message' => $e['message'],
			'file'    => str_replace( ABSPATH, '', $e['file'] ) . ':' . $e['line'],
		);
		$list = get_option( self::FATALS_OPTION, array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		// Evitar duplicados del mismo error.
		foreach ( $list as $old_entry ) {
			if ( isset( $old_entry['message'], $old_entry['file'] ) && $old_entry['message'] === $entry['message'] && $old_entry['file'] === $entry['file'] ) {
				return;
			}
		}
		array_unshift( $list, $entry );
		update_option( self::FATALS_OPTION, array_slice( $list, 0, 5 ), false );
	}

	/**
	 * Sucursal por defecto configurada, avisando si el filtro por estado la deja fuera.
	 */
	private static function default_location_label() {
		$id = TS_Settings::default_location_id();
		if ( ! $id ) {
			return __( '(ninguna: se usa la primera sucursal disponible)', 'total-sucursales' );
		}
		$row   = TS_Locations::get( $id );
		$label = ( $row ? $row['name'] : __( 'sucursal borrada', 'total-sucursales' ) ) . ' #' . $id;
		$visible_ids = array_map(
			function ( $t ) { return (int) $t->term_id; },
			TS_Locations::mli_term_list()
		);
		if ( ! in_array( (int) $id, $visible_ids, true ) ) {
			$label .= ' · ' . __( 'no visible para este cliente: se usa la primera disponible', 'total-sucursales' );
		}
		return $label;
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
		if ( null !== self::$conflicts ) {
			return self::$conflicts;
		}
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

		$autodetect = array(
			'wcmlim_distance_calculator_by_coordinates'      => __( 'navegador', 'total-sucursales' ),
			'wcmlim_enable_autodetect_location'              => __( 'Google', 'total-sucursales' ),
			'wcmlim_enable_autodetect_location_by_maxmind'   => __( 'MaxMind', 'total-sucursales' ),
			'wcmlim_enable_autodetect_location_with_ipinfo'  => __( 'IPinfo', 'total-sucursales' ),
			'wcmlim_distance_calculator_by_cloudfare'        => __( 'Cloudflare', 'total-sucursales' ),
		);
		$on = array();
		foreach ( $autodetect as $opt => $label ) {
			if ( 'on' === get_option( $opt ) ) {
				$on[] = $label;
			}
		}
		if ( ! empty( $on ) ) {
			$out['wcmlim_autodetect'] = sprintf(
				/* translators: %s lista de métodos */
				__( 'La detección de ubicación de Multi Locations está activa (%s). Duplica la de este plugin: la detección ya la hace Total Sucursales, así que conviene apagarla en MULTILOCA → Settings → Location. Además dispara su función wcmlim_closest_location, que tiene un fallo propio y devuelve error 500 (este plugin lo corrige si los parches de compatibilidad están activos).', 'total-sucursales' ),
				implode( ', ', $on )
			);
		}

		if ( 'on' === get_option( 'wcmlim_enable_location_group' ) ) {
			$out['wcmlim_enable_location_group'] = __( 'Los grupos de ubicaciones están activos: el selector muestra primero el grupo y luego sus sucursales. Si el grupo no contiene sucursales del estado del cliente, la lista sale vacía. Este plugin no filtra grupos.', 'total-sucursales' );
		}

		self::$conflicts = $out;
		return $out;
	}

	/**
	 * Sucursales que el desplegable de Multi Locations omite por no pertenecer a un grupo.
	 *
	 * El controlador AJAX de MLI que rellena el selector (wcmlim_getdropdown_location) sólo
	 * incluye las sucursales que tienen la meta wcmlim_locator (grupo de ubicaciones), esté o no
	 * activada la función de grupos. Una sucursal sin grupo no aparece en ese desplegable, aunque
	 * este plugin la deje visible.
	 *
	 * @return array<int,string> id => nombre
	 */
	public static function branches_without_group() {
		$out = array();
		foreach ( TS_Locations::all() as $id => $l ) {
			$locator = get_term_meta( $id, 'wcmlim_locator', true );
			if ( '' === $locator || null === $locator ) {
				$out[ $id ] = $l['name'];
			}
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
		$raw_opt   = $manual; // valor real del admin, sin el filtro de este plugin
		$no_group  = self::branches_without_group();
		// Permite vaciar el registro para saber si un error vuelve a ocurrir o es antiguo.
		if ( isset( $_GET['ts_clear_fatals'] ) ) {
			delete_option( self::FATALS_OPTION );
		}
		$fatals = array_values( array_filter(
			(array) get_option( self::FATALS_OPTION, array() ),
			function ( $f ) {
				// Sólo las últimas 24 horas: un error de ayer ya corregido sólo confunde.
				return is_array( $f ) && isset( $f['time'] ) && ( time() - (int) $f['time'] ) < DAY_IN_SECONDS;
			}
		) );
		// Un fatal registrado con una versión anterior puede estar ya corregido en la instalada.
		$stale_fatals = 0;
		foreach ( $fatals as $f ) {
			if ( empty( $f['version'] ) || $f['version'] !== TS_VERSION ) {
				$stale_fatals++;
			}
		}
		$all       = TS_Locations::all();

		$rows = array(
			__( 'Estado del cliente', 'total-sucursales' ) => ( '' === $state ? __( '(ninguno: se muestran todas)', 'total-sucursales' ) : $state . ' · ' . ts_state_name( $state ) ) . ' [' . ( TS_Customer::get_state_source() ? TS_Customer::get_state_source() : 'sin definir' ) . ']',
			__( 'Cookie ts_estado', 'total-sucursales' ) => var_export( ts_get_cookie( TS_Customer::COOKIE_STATE ), true ),
			__( 'Sucursal seleccionada (MLI)', 'total-sucursales' ) => 'termid=' . var_export( ts_get_cookie( 'wcmlim_selected_location_termid' ), true ) . ' · índice=' . var_export( ts_get_cookie( 'wcmlim_selected_location' ), true ),
			__( 'Sucursal por defecto (ajustes)', 'total-sucursales' ) => self::default_location_label(),
			__( 'Posición del cliente', 'total-sucursales' ) => $coords ? $coords['lat'] . ', ' . $coords['lng'] . ' (' . $coords['source'] . ')' : __( '(desconocida)', 'total-sucursales' ),
			__( 'Filtro por estado', 'total-sucursales' ) => $applies ? __( 'activo', 'total-sucursales' ) : __( 'NO se aplica en esta página', 'total-sucursales' ),
			__( 'Ocultas en MLI (ajuste del admin)', 'total-sucursales' ) => empty( $manual ) ? __( '(ninguna)', 'total-sucursales' ) : implode( ', ', $manual ),
			__( 'Opción de MLI (valor real guardado)', 'total-sucursales' ) => is_scalar( $raw_opt ) ? (string) $raw_opt : wp_json_encode( $raw_opt ),
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
				#ts-debug li{margin-bottom:4px}
				#ts-debug summary{cursor:pointer}
				#ts-debug pre{white-space:pre-wrap;word-break:break-all;margin:4px 0 0;padding:0}
				#ts-debug.ts-min{max-height:none;overflow:visible}
				#ts-debug.ts-min>*:not(.ts-bar){display:none!important}
			</style>
			<div class="ts-bar" style="display:flex;justify-content:space-between;gap:12px;margin-bottom:8px">
				<span class="title">Total Sucursales · diagnóstico <?php echo esc_html( TS_VERSION ); ?></span>
				<span>
					<a href="#" class="muted" id="ts-debug-min"><?php esc_html_e( 'plegar', 'total-sucursales' ); ?></a>
					<a href="#" class="muted" style="margin-left:12px" onclick="document.getElementById('ts-debug').remove();return false"><?php esc_html_e( 'cerrar', 'total-sucursales' ); ?> ✕</a>
				</span>
			</div>
			<script>
			(function () {
				// Plegado recordado: el panel tapa media pantalla y estorba mientras se prueba la tienda.
				var panel = document.getElementById('ts-debug');
				var link  = document.getElementById('ts-debug-min');
				var KEY   = 'ts_debug_min';
				function paint() {
					var min = false;
					try { min = localStorage.getItem(KEY) === '1'; } catch (e) {}
					panel.classList.toggle('ts-min', min);
					link.textContent = min ? <?php echo wp_json_encode( __( 'desplegar', 'total-sucursales' ) ); ?> : <?php echo wp_json_encode( __( 'plegar', 'total-sucursales' ) ); ?>;
				}
				link.addEventListener('click', function (e) {
					e.preventDefault();
					try { localStorage.setItem(KEY, panel.classList.contains('ts-min') ? '0' : '1'); } catch (e2) {}
					paint();
				});
				paint();
			})();
			</script>
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
				<tr class="muted"><td>ID</td><td>Nombre</td><td><?php esc_html_e( 'Estado guardado', 'total-sucursales' ); ?></td><td><?php esc_html_e( 'Se lee', 'total-sucursales' ); ?></td><td><?php esc_html_e( 'Coordenadas', 'total-sucursales' ); ?></td><td><?php esc_html_e( 'Grupo', 'total-sucursales' ); ?></td><td><?php esc_html_e( 'Situación', 'total-sucursales' ); ?></td></tr>
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
						<td class="<?php echo ( isset( $no_group[ $id ] ) ? 'bad' : '' ); ?>"><?php echo isset( $no_group[ $id ] ) ? '—' : esc_html( (string) get_term_meta( $id, 'wcmlim_locator', true ) ); ?></td>
						<td class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $note ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php if ( ! empty( $no_group ) ) : ?>
				<div class="warn" style="margin-top:10px"><?php esc_html_e( 'Sucursales sin grupo de ubicaciones (meta wcmlim_locator vacía). Sólo importa si el selector de tu cabecera es el que Multi Locations rellena por AJAX: ese desplegable descarta las sucursales sin grupo. Si el selector se ve correctamente, puedes ignorar este aviso; si aparece vacío, asígnales un grupo en la ficha de la sucursal.', 'total-sucursales' ); ?></div>
				<div class="warn"><?php echo esc_html( implode( ' · ', array_map( function ( $id, $n ) { return $n . ' #' . $id; }, array_keys( $no_group ), $no_group ) ) ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $fatals ) && is_array( $fatals ) ) : ?>
				<div class="bad" style="margin-top:10px">
					<?php esc_html_e( 'Errores fatales de PHP de las últimas 24 horas (causan los errores 500 en admin-ajax.php):', 'total-sucursales' ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'ts_clear_fatals', '1' ) ); ?>" class="muted">[<?php esc_html_e( 'vaciar registro', 'total-sucursales' ); ?>]</a>
				</div>
				<ul class="bad">
					<?php foreach ( $fatals as $f ) : ?>
						<li>
							<?php
							/* translators: %s tiempo transcurrido */
							echo esc_html( sprintf( __( 'hace %s', 'total-sucursales' ), human_time_diff( (int) $f['time'] ) ) . ' · ' );
							// La traza de PHP ocupa varias pantallas: se deja plegada y a la vista sólo el error.
							$parts = preg_split( '/\s*Stack trace:\s*/', (string) $f['message'], 2 );
							// El mensaje termina repitiendo la ruta absoluta del fichero, que ya va aparte.
							$head  = preg_replace( '#\s+in\s+/\S+:\d+$#', '', trim( $parts[0] ) );
							$trace = isset( $parts[1] ) ? trim( $parts[1] ) : '';
							$where = preg_replace( '#^wp-content/plugins/#', '', (string) $f['file'] );
							echo esc_html( ( $f['action'] ? 'action=' . $f['action'] . ' · ' : '' ) . $head . ' · ' . $where );
							if ( empty( $f['version'] ) || $f['version'] !== TS_VERSION ) {
								echo esc_html( ' · ' . (
									empty( $f['version'] )
										? __( 'registrado con una versión anterior', 'total-sucursales' )
										/* translators: %s número de versión */
										: sprintf( __( 'registrado con la versión %s', 'total-sucursales' ), $f['version'] )
								) );
							}
							?>
							<?php if ( '' !== $trace ) : ?>
								<details>
									<summary class="muted"><?php esc_html_e( 'ver traza', 'total-sucursales' ); ?></summary>
									<pre><?php echo esc_html( $trace ); ?></pre>
								</details>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $stale_fatals === count( $fatals ) ) : ?>
					<div class="muted">
						<?php
						/* translators: %s versión instalada */
						echo esc_html( sprintf(
							_n(
								'El error listado se registró antes de instalar la versión %s, así que puede estar ya corregido. Vacía el registro y recarga: si no vuelve a salir, lo está.',
								'Ninguno de los errores listados se registró con la versión %s instalada, así que pueden estar ya corregidos. Vacía el registro y recarga: los que no vuelvan a salir, lo están.',
								count( $fatals ),
								'total-sucursales'
							),
							TS_VERSION
						) );
						?>
					</div>
				<?php else : ?>
					<div class="muted"><?php esc_html_e( 'Si acabas de cambiar un ajuste, vacía el registro y recarga: los que vuelvan a salir son los que siguen ocurriendo.', 'total-sucursales' ); ?></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php $compat = class_exists( 'TS_Compat' ) ? TS_Compat::status() : array(); ?>
			<?php if ( ! empty( $compat ) ) : ?>
				<div class="muted" style="margin-top:10px"><?php esc_html_e( 'Parches de compatibilidad con Multi Locations:', 'total-sucursales' ); ?></div>
				<ul class="muted">
					<?php foreach ( $compat as $k => $v ) : ?>
						<li><?php echo esc_html( $k . ' — ' . $v ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

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
