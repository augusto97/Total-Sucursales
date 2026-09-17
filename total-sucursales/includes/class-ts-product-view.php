<?php
/**
 * Página de producto: mostrar sólo la sucursal seleccionada (opcional).
 * Se resuelve en JS/CSS sobre el markup de MLI (data-lc-termid / data-location-id),
 * porque el único hook de MLI (wcmlim_override_view_*) obliga a re-renderizar la vista completa.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TS_Product_View {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'inline_css' ) );
	}

	public static function inline_css() {
		if ( ! is_product() || ! TS_Settings::is_yes( 'single_location_view' ) ) {
			return;
		}
		$id = TS_Customer::selected_mli_location_id();
		if ( ! $id ) {
			return;
		}
		// Ocultar items de lista de otras sucursales; las <option> se filtran en JS (CSS no oculta options en todos los navegadores).
		echo '<style id="ts-single-location">.wcmlim_radio_option[data-location-id]:not([data-location-id="' . (int) $id . '"]){display:none!important}</style>';
	}
}
