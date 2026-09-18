# Arquitectura y flujo: plugin `total-sucursales`

Plugin "pegamento" que integra **States and Municipalities of Venezuela (SMV)**, **Multi Locations
Inventory Management (MLI)** y **Advanced Shipping (WAS)** para cubrir la FASE 1 del cliente.
No modifica ninguno de los tres; se engancha a sus opciones, cookies, paquetes y filtros.

## Estructura

```
total-sucursales/
├── total-sucursales.php                      # cabecera, constantes, HPOS, arranque en plugins_loaded:20
├── readme.txt                                # instalación, configuración, shortcodes, hooks
├── includes/
│   ├── class-ts-plugin.php                   # detección de dependencias + carga de módulos
│   ├── ts-functions.php                      # Haversine, cookies, estados VE, formato km
│   ├── class-ts-settings.php                 # pestaña WooCommerce > Ajustes > Total Sucursales
│   ├── class-ts-locations.php                # repositorio de sucursales de MLI (estado, lat/lng) con transient
│   ├── class-ts-geocoder.php                 # Nominatim / Google con caché (30 días, negativo 1 día)
│   ├── class-ts-customer.php                 # estado y coordenadas del cliente; sincroniza cookies de MLI
│   ├── class-ts-location-filter.php          # pre_option_wcmlim_exclude_locations_from_frontend
│   ├── class-ts-packages.php                 # enriquece paquetes: sucursal, distancia, pickup elegible
│   ├── class-ts-frontend.php                 # scripts, modal de estado, [ts_selector_estado], AJAX
│   ├── class-ts-checkout.php                 # botón GPS, geocodificación de respaldo, info de distancias
│   ├── class-ts-order.php                    # meta del pedido + panel en el admin del pedido
│   ├── class-ts-product-view.php             # opción "sólo la sucursal seleccionada" en producto
│   └── integrations/
│       ├── class-ts-was-conditions.php       # registro en WAS (was_conditions, wp-conditions\registered_conditions)
│       └── conditions/
│           ├── class-wpc-ts-sede-condition.php            # "Sucursal del paquete" (term_id)
│           ├── class-wpc-ts-distancia-sede-condition.php  # "Distancia a la sucursal (km)" <= / >=
│           └── class-wpc-ts-sede-pickup-condition.php     # "Sucursal elegible para pickup" Sí/No
└── assets/
    ├── js/ts-frontend.js                     # GPS, modal, selector, vista de producto
    ├── js/ts-checkout.js                     # botón "Usar mi ubicación" → update_checkout
    └── css/ts-frontend.css
```

## Datos que usa de cada plugin

| Origen | Dato | Uso |
|---|---|---|
| MLI term meta | `wcmlim_administrative_area_level_1` (código WC, ej. `ZU`) | estado de la sucursal |
| MLI term meta | `wcmlim_lat`, `wcmlim_lng` | distancia Haversine |
| MLI opción | `wcmlim_exclude_locations_from_frontend` | interceptada con `pre_option_*` para ocultar sucursales de otros estados |
| MLI cookies | `wcmlim_selected_location` (índice), `wcmlim_selected_location_termid`, `wcmlim_user_lat/lng` | se reescriben al cambiar de estado / al recibir GPS |
| MLI paquete | `shipping_term_id` (con split) o `select_location.location_termId` de cada item | sucursal del paquete |
| SMV | `woocommerce_states['VE']` | lista de estados, mismos códigos que MLI guarda |
| WAS | `was_conditions`, `wp-conditions\registered_conditions`, clases `WPC_*_Condition`, `$args['package']` | condiciones propias evaluadas por paquete |

## Flujo 1: navegación (catálogo por estado)

1. Primera visita sin cookie `ts_estado`:
   - modo **gps** (por defecto): `ts-frontend.js` pide `navigator.geolocation` → AJAX `ts_set_position`
     → `TS_Customer::set_gps_coords()` + `resolve_state_from_coords()` (estado de la sucursal más cercana)
     → `set_state()` → recarga.
   - si el usuario niega el permiso o el modo es **ask**: modal con los estados que tienen sucursales
     (+ "Otro estado / ver todas") → AJAX `ts_set_state` → recarga.
   - usuario logueado sin cookie: `TS_Customer::maybe_state_from_account()` toma `shipping_state`.
2. En cada request, `TS_Location_Filter::filter_option()` devuelve
   `exclusiones manuales ∪ (todas − sucursales del estado)`; si el estado no tiene sucursales devuelve
   sólo las manuales → **se muestran todas** (requisito 2).
3. Al cambiar de estado, `TS_Customer::resync_mli_selection()` recalcula la sucursal seleccionada de
   MLI con los mismos argumentos de `get_terms` que usa MLI (índice + term_id), eligiendo la más
   cercana si hay coordenadas. Así no se rompe el índice posicional de la cookie de MLI.
4. Switcher de sedes, catálogo por stock de la sede, carrito de una sede: **nativo de MLI**
   (`[wcmlim_locations_switch]`, `wcmlim_hide_outofstock_products`, `wcmlim_clear_cart`).
5. Producto: con la opción "sólo la sucursal seleccionada", CSS oculta los items
   `.wcmlim_radio_option[data-location-id]` y JS elimina las `<option data-lc-termid>` ajenas.

## Flujo 2: checkout y envío

1. El cliente completa dirección (estado/municipio de SMV).
2. Posición del cliente (`TS_Customer::get_coords()`), en orden:
   - **GPS**: botón "Usar mi ubicación" en el checkout → AJAX `ts_set_position` (contexto `checkout`,
     no cambia el estado de navegación) → `update_checkout`.
   - **Geocodificación**: en `woocommerce_checkout_update_order_review` y `woocommerce_checkout_process`,
     `TS_Checkout::sync_coords_from_address()` geocodifica la dirección efectiva (shipping si "enviar a
     otra dirección", si no billing) con Nominatim/Google y la guarda en `WC()->session['ts_coords']`.
     El municipio "Municipio Maracaibo (Maracaibo)" se reduce a "Maracaibo" para la consulta.
3. `TS_Packages::enrich_packages()` (filtro `woocommerce_cart_shipping_packages`, prioridad 50, después
   del split de MLI) añade a cada paquete `ts_location_id`, `ts_distance_km`, `ts_pickup_eligible` y
   `destination.ts_lat/ts_lng`. Elige **un solo** paquete elegible: el de menor distancia ≤ radio.
   Como los datos viven en el paquete, entran en el hash de caché de tarifas de WooCommerce.
4. WAS evalúa cada regla por paquete. Las condiciones `ts_sede_pickup`, `ts_distancia_sede`, `ts_sede`
   leen esas claves. El administrador configura:
   - **Retiro en tienda** = `ts_sede_pickup == Sí` (costo 0).
   - **Envío nacional** = `ts_sede_pickup == No` (+ tarifa por estado/peso/etc.).
5. Bajo los métodos de envío se muestra la lista de sucursales del pedido con distancia y etiqueta
   PICKUP / Envío nacional.
6. Al crear el pedido, `TS_Order::save_meta()` guarda estado, coordenadas (con fuente), radio, sucursal
   de pickup y el detalle por paquete; se ve en el admin del pedido con enlace a OpenStreetMap.

### Variante A (una sucursal por carrito, MLI "Restrict to One Location")
Un solo paquete; `ts_location_id` = sucursal única de los items. Pickup si está a ≤ radio.

### Variante B (varias sucursales, MLI "Split order Packages by location")
Un paquete por sucursal (`shipping_term_id`). Sólo el más cercano dentro del radio es elegible;
los demás caen en la regla de envío nacional. El pedido tiene N líneas de envío.

Sin split y con items de varias sucursales: `ts_mixed_locations = true`, sin sucursal ni pickup
(se documenta como configuración no soportada).

## Ajustes (WooCommerce > Ajustes > Total Sucursales)

| Ajuste | Default | Efecto |
|---|---|---|
| Detección del estado | gps | gps / ask / off |
| Radio de retiro (km) | 10 | umbral de pickup |
| Botón "Usar mi ubicación" en checkout | sí | |
| Mostrar distancia en checkout | sí | |
| Geocodificador | nominatim | none / nominatim / google |
| Clave de Google | (usa la de MLI) | |
| Producto: sólo la sucursal seleccionada | no | |

## Estado de pruebas

Probado end-to-end en un WordPress local (ver `tests/e2e/README.md`): 30 comprobaciones automatizadas
con Chromium pasan, incluidos ambos modos de carrito (una sede y varias sedes con split). Capturas en
`docs/capturas/`.

Hallazgos de la integración con MLI que el plugin ya contempla:

- MLI mezcla `get_terms()` con y sin exclusiones y guarda índices posicionales en cookies; por eso el
  filtro por estado se aplica también en `get_terms_args` (no sólo en la opción), para que todas sus
  rutas vean la misma lista.
- MLI guarda `location_termId = -1` en el carrito cuando no se eligió sede; se ignora.
- La sede por defecto "Online Store" de MLI (sin estado) queda oculta en cuanto el cliente tiene estado.

## Pendiente / siguientes iteraciones

- Caché de carrito por sucursal (requisito 7, segunda opción). No incluido; MLI hoy pregunta y migra.
- Consulta optimizada del catálogo por sede para catálogos grandes (MLI carga todos los IDs).
- Soporte de checkout por bloques (el botón GPS y la info de distancia usan hooks del checkout clásico;
  las condiciones y el filtro por estado funcionarían igual).
- FASE 2: shortcode de mapa Leaflet/OSM con las coordenadas de las sucursales.
- Traducciones (.pot) y pruebas en staging con la versión de WooCommerce del cliente.
