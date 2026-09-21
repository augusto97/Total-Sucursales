=== Total Sucursales ===
Contributors: augusto97
Tags: woocommerce, sucursales, venezuela, pickup, multi locations, advanced shipping
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.5
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

= 0.2.5 =
* Corregido: el parche de las peticiones de stock de Multi Locations devolvía la respuesta sin envolver en "data". Como ya no había error 500, su propio JavaScript llegaba a procesarla por primera vez y fallaba con "Cannot read properties of undefined (reading 'backorder')" en cada cambio de sucursal. Ahora la respuesta usa el mismo formato que el plugin y no cambia nada en la página.

= 0.2.4 =
* Añadido: parches de compatibilidad para dos fallos de Multi Locations que provocan errores 500 en admin-ajax.php. El primero es su función distance_between_coordinates(), que su controlador llama como función global aunque el plugin sólo la declara dentro de un trait y de una clase, así que falla siempre; ahora se suple con la misma fórmula. El segundo descarta las peticiones de stock con un producto inexistente antes de que falle. Se pueden desactivar en los ajustes.
* Corregido: el registro de errores fatales sólo muestra los de las últimas 24 horas, indica cuánto hace que ocurrieron y se puede vaciar desde el propio panel. Antes se acumulaban y parecían actuales después de corregirlos.
* Corregido: el aviso de sucursales sin grupo ya no se presenta como un error. Sólo afecta si el selector de la cabecera es el que Multi Locations rellena por AJAX.
* Corregido: el texto sobre distance_between_coordinates() decía que la función existe con el modo backend activado. No es así: está dentro de un trait, por lo que nunca existe como función global.

= 0.2.3 =
* Añadido: aviso cuando la detección de ubicación de Multi Locations está activa. Duplica la de este plugin y provoca errores 500 en admin-ajax.php, porque su función wcmlim_closest_location llama a distance_between_coordinates(), que sólo existe con el modo backend activado.
* Corregido: el aviso de sucursales sin grupo ahora se muestra siempre, no sólo con los grupos activos. El desplegable que Multi Locations rellena por AJAX omite las sucursales sin grupo en cualquier caso.
* Añadido: columna "Grupo" en el diagnóstico, para ver de un vistazo qué sucursales no tienen grupo asignado.
* Corregido: los avisos de grupos y de autodetección ya no desactivan el filtro por estado. Sólo lo desactivan los ajustes que fuerzan una sucursal única.

= 0.2.2 =
* Añadido: detección de sucursales sin grupo de ubicaciones. Con los grupos activos, el desplegable de Multi Locations omite cualquier sucursal que no pertenezca a un grupo, así que el selector puede quedar vacío aunque la sucursal esté visible. Ahora se avisa en los ajustes y en el panel de diagnóstico.
* Añadido: el modo diagnóstico captura los errores fatales de PHP y los muestra en el panel, para identificar los errores 500 de admin-ajax.php sin entrar al servidor.
* Corregido: el panel mostraba como "valor crudo" de la opción de Multi Locations el valor ya filtrado por este plugin, lo que daba a entender que había sucursales ocultas cuando no las había.
* Mejorado: las comprobaciones de ajustes de Multi Locations se calculan una sola vez por petición.

= 0.2.1 =
* Añadido: modo diagnóstico en la tienda. Con el ajuste activado, cualquier página abierta con ?ts_debug=1 muestra al pie el estado del visitante: estado detectado, cookies, exclusiones aplicadas y la situación de cada sucursal. Los administradores lo ven siempre.
* Corregido: si Multi Locations fuerza una única sucursal (ajuste para visitantes no registrados, o sucursal fija por usuario), el filtro por estado se desactiva en lugar de dejar el selector vacío.
* Añadido: aviso en los ajustes cuando esos modos de Multi Locations están activos, o cuando los grupos de ubicaciones pueden vaciar la lista.

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
