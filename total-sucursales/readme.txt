=== Total Sucursales ===
Contributors: augusto97
Tags: woocommerce, sucursales, venezuela, pickup, multi locations, advanced shipping
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

Filtra las sucursales de Multi Locations por el estado del cliente y decide retiro en tienda por radio, exponiendo condiciones para Advanced Shipping.

== Dependencias ==

* WooCommerce
* MULTILOCA - WooCommerce Multi Locations Inventory Management (taxonomía `locations`)
* Advanced Shipping for WooCommerce (Jeroen Sormani) – opcional, para las condiciones de envío
* States and Municipalities of Venezuela for WooCommerce – recomendado, para estados y municipios en el checkout

== Configuración rápida ==

1. En cada sucursal de MLI (Productos > Locations) completa **State** y **Location Lat / Lng**.
2. WooCommerce > Ajustes > Total Sucursales: radio de retiro (10 km), detección del estado, geocodificador.
3. Coloca `[ts_selector_estado]` en el header (opcional) y el switcher de MLI `[wcmlim_locations_switch]`.
4. En MLI activa "Restrict to One Location" (una sucursal por carrito) o "Split order Packages by location" (varias sucursales, un paquete por sucursal).
5. En Advanced Shipping crea dos reglas:
   * "Retiro en tienda": condición *Sucursal elegible para pickup* = Sí, costo 0.
   * "Envío nacional": condición *Sucursal elegible para pickup* = No (+ las condiciones de tarifa que necesites).

== Temas y checkout por bloques ==

Compatible con temas de bloques y con el carrito/checkout por bloques: el catálogo por sede cubre el bloque
Product Collection y la Store API; en el checkout por bloques se añaden el botón "Usar mi ubicación", las
distancias por sucursal y un select de municipio por estado (la ciudad libre de Venezuela se oculta).

== Shortcodes ==

* `[ts_selector_estado label="Estado"]` – select de estados con sucursales + botón GPS.

== Changelog ==

= 0.2.0 =
* Corregido: si el filtro por estado dejaba la tienda sin sucursales visibles (por ejemplo cuando la única sede del estado estaba oculta en "Hide Locations From Frontend" de Multi Locations), el selector quedaba vacío. Ahora el filtro nunca oculta todas las sucursales.
* Corregido: las sucursales ocultas en Multi Locations ya no cuentan como "sucursales del estado", así que no arrastran a las demás.
* Añadido: el estado de una sucursal se reconoce por código ("ZU"), por nombre ("Zulia", "Anzoategui") y en formato "VE:ZU". El autocompletado de direcciones de Multi Locations guarda el nombre largo.
* Añadido: panel "Diagnóstico de sucursales" en los ajustes, con el estado guardado, cómo se interpreta, coordenadas y si la sede está oculta.
* Corregido: el filtro por estado ya no se aplica en WP-CLI ni en cron, para que importadores y tareas programadas vean todas las sucursales.

= 0.1.0 =
* Versión inicial.

== Hooks ==

* `ts_excluded_location_ids( $ids, $state, $manual )` – ajustar qué sucursales se ocultan.
* `ts_pickup_eligible_package_key( $key, $packages, $coords, $radius )` – cambiar qué paquete recibe pickup.
* `ts_geocode_result( $result, $address, $provider )` – sobrescribir geocodificación.
* `ts_location_filter_applies( $bool )` – desactivar el filtro por estado en contextos concretos.
* `ts_customer_state_changed( $new, $old, $source )` – acción al cambiar el estado del cliente.
