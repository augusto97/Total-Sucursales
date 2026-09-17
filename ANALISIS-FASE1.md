# Análisis: ¿alcanza con los 3 plugins + el fork para cubrir la FASE 1 del cliente?

Plugins evaluados (código leído, no documentación):

| Plugin | Versión | Rol en la solución |
|---|---|---|
| States and Municipalities of Venezuela for WooCommerce (SMV) | 1.1 | Selects de estado y municipio en checkout. Códigos de estado (`ZU`, `DC`…) en WooCommerce. |
| Advanced Shipping for WooCommerce (WAS) | 1.1.5 | Motor de reglas de envío por condiciones. |
| MULTILOCA – Multi Locations Inventory Management (MLI) | 4.2.15 | Sedes como taxonomía `locations`, stock por sede, selector de sede, carrito por sede, paquetes por sede, pickup, mapa. |

**Respuesta corta: no alcanza, pero falta menos de lo que parece y el fork del primer plugin ya no es la pieza correcta.**
MLI ya trae el concepto de "sucursal" (sus *locations*), lo lleva en el carrito y lo expone como
condición en WAS. Lo que falta es un **plugin "pegamento" pequeño** con la lógica específica del
cliente (filtro por estado, radio de 10 km, carrito por sede) y **decidir el checkout clásico**.

---

## 1. Cambio de enfoque: el fork de SMV con CPT "Sucursal" queda descartado

El plan de `PLAN.md` proponía un CPT propio de sucursales y un tercer select en el checkout. Con MLI
en la ecuación eso **duplica el modelo de datos** y choca con el flujo del plugin:

- MLI ya guarda la sede en cada línea del carrito (`select_location.location_termId`), en cada
  item del pedido (`_selectedLocTermId`) y en el pedido (`_multilocation`).
- MLI ya registra en WAS la condición **"Locations"** (grupo *MultiInventory*) usando la API
  legada `was_conditions` / `was_values` / `was_match_condition_locations`. Verifiqué que WAS 1.1.5
  mantiene el puente de compatibilidad (`was_add_bc_filter_condition_match` en
  `includes/core-functions.php:138-151`), así que **esa condición sí funciona con esta versión**.
- La sede se elige al navegar (cookie), no en el checkout. Un select de sucursal en el checkout
  contradiría la sede con la que el cliente ya llenó el carrito.

**Qué se conserva del plan anterior:** la parte de "condición personalizada para WAS" (sección 6 del
PLAN), pero leyendo la sede desde el paquete de MLI en lugar de un campo de formulario.
**SMV se instala tal cual, sin fork** (salvo que se decida checkout por bloques, ver §5).

---

## 2. Requisito por requisito (FASE 1 – catálogo)

| # | Requisito | ¿Nativo en MLI? | Qué falta |
|---|---|---|---|
| 1 | Determinar la ubicación del cliente | **Sí, parcial.** 5 métodos excluyentes: GPS del navegador (`wcmlim_distance_calculator_by_coordinates`), MaxMind, Google, IPinfo, Cloudflare (este último llama a un handler AJAX que no existe en el plugin). Guarda `wcmlim_user_lat`/`wcmlim_user_lng` en cookies. **No usa la geolocalización nativa de WooCommerce ni la dirección guardada del cliente.** | MLI obtiene coordenadas, **no el estado**. Hay que derivar el estado: (a) usuario logueado → `shipping_state` de su cuenta (código SMV); (b) invitado → sede más cercana por Haversine y tomar su `wcmlim_administrative_area_level_1`, o geocodificación inversa (Google, requiere clave); (c) fallback: popup "¿En qué estado estás?" con la lista de SMV. **Desarrollo pequeño.** |
| 2 | Mostrar sólo las sedes del estado del cliente; si no hay, todas | **No.** MLI no tiene ningún filtro/hook para restringir la lista de sedes (cero `apply_filters` en shortcodes y controladores). Sus reglas de visibilidad sólo aceptan producto/categoría/etiqueta/marca/atributo. El filtro por radio (`wcmlim_enable_filter_by_proximity`) sólo aplica en la página de producto. | **Solución barata y global:** MLI lee la opción `wcmlim_exclude_locations_from_frontend` con `get_option()` en 35+ puntos (switcher, popup, producto, carrito, closest-location…). Un filtro `pre_option_wcmlim_exclude_locations_from_frontend` que devuelva los IDs de las sedes **fuera** del estado del cliente las oculta en todo el front sin tocar MLI. Si el estado no tiene sedes, devolver vacío → se muestran todas. **Desarrollo pequeño.** Riesgo a mitigar: MLI usa índices posicionales en la cookie `wcmlim_selected_location`; al cambiar el estado hay que resetear esa cookie y `wcmlim_selected_location_termid`. |
| 3 | Filtro superior para cambiar entre sedes | **Sí.** `[wcmlim_locations_switch]`, `[wcmlim_locations_popup]` o `[wcmlim_locations_storeview]` (vista drawer/popup). Con el punto 2 resuelto, el switcher ya sale filtrado. | Nada. Elegir el shortcode y ubicarlo en el header del tema. |
| 4 | Catálogo de la sede seleccionada | **Sí.** Opción `wcmlim_hide_outofstock_products` ("Display Only In-Stock Items for Selected Location"). | **Advertencia de rendimiento:** la implementación carga *todos* los IDs de productos y variaciones en cada consulta (`wcmlim_change_product_query`, `public/class-wcmlim-public.php:2186-2308`) sin caché. Con catálogos > ~2.000 productos conviene reemplazarla por un `meta_query` sobre `wcmlim_stock_at_{id} > 0` desde el plugin pegamento. Además **todos los productos deben tener "gestionar stock" activado y stock cargado por sede**; sin eso MLI no les asigna sede en el carrito. |
| 5 | En el producto, información sólo de la sede actual | **Parcial.** MLI muestra todas las sedes (filtradas por punto 2) con la actual preseleccionada. Vistas: `select_view`, `simple_text_view`, `drawer_view`, etc. (`wcmlim_backend_display_stock_view`). | Si el cliente quiere ver **una sola** sede: filtro `wcmlim_override_view_{vista}` (único hook del plugin, `includes/helper/wcmlim-view-loader.php:17`) para renderizar sólo el término seleccionado, o CSS que oculte las demás. **Desarrollo pequeño.** |
| 6 | Carrito sólo con artículos de la sede actual | **Sí.** Opción `wcmlim_clear_cart` ("Restrict to One Location"). | Ojo: sólo valida contra el **primer** item del carrito y sólo aplica a productos con gestión de stock. Suficiente si se cumple la condición del punto 4. |
| 7 | Al cambiar de sede: vaciar carrito **o** guardarlo por sede y restaurarlo | **Sólo la primera mitad.** Con "Restrict to One Location" activo, al cambiar de sede MLI pregunta (SweetAlert) y **migra** el carrito a la nueva sede quitando lo que no tenga stock; el flujo antiguo lo vacía. **No existe guardar/restaurar carrito por sede** (no usa sesión de WC en ningún punto). | Si el cliente quiere el "caché por sede": al detectar el cambio (AJAX `wcmlim_update_cart_location` / cookie), serializar el carrito actual en `WC()->session` o user meta bajo `carrito_sede_{id}` y restaurarlo al volver. **Desarrollo mediano** (hay que interceptar los dos flujos de MLI y cuidar stock al restaurar). Recomendación: arrancar con el comportamiento nativo (preguntar + migrar) y dejar el caché para una iteración posterior. |

**Contradicción a resolver con el cliente antes de programar:** los puntos 6-7 imponen **una sola
sede por carrito**, pero la sección "Determinar envío" parte de "múltiples artículos de distintas
locaciones". No pueden ser ambas. Las dos variantes son viables, pero cambian el diseño del envío:

- **Variante A (una sede por carrito):** no hace falta split de paquetes. Regla: si la sede del
  carrito está a ≤ 10 km del cliente → sólo PICKUP; si no → sólo ENVÍO NACIONAL.
- **Variante B (varias sedes por carrito):** activar `wcmlim_enable_split_packages` (un paquete de
  envío por sede, MLI expone `shipping_term_id` en cada paquete) y evaluar la regla por paquete:
  el paquete de la sede más cercana dentro de 10 km recibe PICKUP; los demás, ENVÍO NACIONAL.
  Nota: MLI **no** divide el pedido, sólo los paquetes; un pedido tendrá N envíos. Dividir pedidos
  es un add-on de pago de Techspawn ("Split Order For Multi Location").

---

## 3. Requisito por requisito (FASE 1 – envío)

| Requisito | ¿Nativo? | Qué falta |
|---|---|---|
| Datos de facturación/envío con estado y municipio | **Sí** (SMV, checkout clásico). MLI además puede autocompletar la dirección desde la sede detectada (`wcmlim_auto_billing_address`). | Nada. |
| Método PICKUP sólo para la sede más cercana dentro de 10 km | **No.** El "Service Radius" de MLI es **sólo informativo**: muestra un aviso "We are not serving this area" y devuelve las tarifas sin tocarlas (`wcmlim-checking-order-is-in-location-radius.php:83-88`); además lee orígenes de una opción legacy que suele estar vacía. El método `wcmlim_pickup_location` de MLI es una única tarifa gratuita por paquete, no elige sede. | **Núcleo del desarrollo a medida.** Ver §4. |
| ENVÍO NACIONAL cuando la sede está fuera del radio | **Parcial.** WAS puede definir las tarifas nacionales con condiciones (estado, subtotal, peso, "Locations" de MLI). | Falta la condición de distancia. Ver §4. |
| Sólo **una** sede del pedido con PICKUP | **No.** | Ver §4 (se resuelve en la misma condición). |

### Cómo obtener la posición del cliente en el checkout

Es la decisión técnica más importante de la fase de envío, porque de ella depende el "10 km":

| Fuente | Pros | Contras |
|---|---|---|
| **GPS del navegador** (cookies `wcmlim_user_lat/lng` que MLI ya guarda) | Sin clave de API, preciso, ya existe en MLI. | Requiere permiso del usuario; en escritorio puede ser impreciso; si lo niega no hay posición. |
| **Geocodificar la dirección de envío** (Google Geocoding; MLI ya tiene `get_coordinates_from_address()` y la clave `wcmlim_google_api_key`) | Funciona sin permiso del navegador. | Cuesta dinero, y las direcciones venezolanas geocodifican mal (urbanizaciones, "casa sin número"). Alternativa gratuita: Nominatim de OSM, con límite de 1 req/s y obligación de caché. |
| **Centroide del municipio** (tabla propia municipio → lat/lng) | Gratis, determinista, no depende del usuario. | Precisión de municipio, no de calle: en Maracaibo "10 km" desde el centroide puede dejar fuera a clientes reales. |

Recomendación: **GPS del navegador como fuente principal** (botón "Usar mi ubicación" en el
checkout, reutilizando las cookies de MLI) **con fallback a geocodificación de la dirección**
cacheada por dirección normalizada. Documentar al cliente que sin ninguna de las dos no se puede
ofrecer PICKUP y se cae a ENVÍO NACIONAL.

---

## 4. Diseño del plugin "pegamento" (nombre provisional: `total-sucursales-reglas`)

Un solo plugin propio, sin tocar el código de los tres comerciales. Módulos:

### 4.1 Estado del cliente (`class-ts-customer-state.php`)
- Resuelve el estado en este orden: `WC()->customer->get_shipping_state()` si existe → estado de la
  sede más cercana a `wcmlim_user_lat/lng` (Haversine, función `wcmlim_calculate_distance()` de MLI) →
  popup propio con la lista de estados de SMV. Guarda el resultado en cookie/sesión `ts_estado`.
- Al cambiar `ts_estado`, borra `wcmlim_selected_location` y `wcmlim_selected_location_termid`
  para evitar el desfase de índices de MLI.

### 4.2 Filtro de sedes por estado (`class-ts-location-filter.php`)
```php
add_filter( 'pre_option_wcmlim_exclude_locations_from_frontend', function ( $pre ) {
    if ( is_admin() && ! wp_doing_ajax() ) return $pre;
    $estado = ts_get_customer_state();               // 'ZU'
    $en_estado = ts_get_location_ids_by_state( $estado ); // term meta wcmlim_administrative_area_level_1
    if ( empty( $en_estado ) ) return $pre;          // sin sedes en el estado → mostrar todas
    return array_diff( ts_get_all_location_ids(), $en_estado );
} );
```
Cubre switcher, popup, página de producto, closest-location, add-to-cart y carrito de una vez.
`wcmlim_administrative_area_level_1` se llena desde el AJAX `wcmlim_get_states` de MLI, que usa la
lista de estados de WooCommerce, así que **con SMV instalado las sedes se guardan con los mismos
códigos (`ZU`) que el checkout**. Verificar en staging con una sede de prueba.

### 4.3 Condiciones para Advanced Shipping (`class-ts-was-conditions.php`)
Reutiliza el diseño de la sección 6 del `PLAN.md`, pero con dos condiciones nuevas basadas en el
paquete de MLI (`$args['package']['shipping_term_id']` con split, o la sede del carrito sin split):

| Condición (slug) | Operadores | Valor | Qué compara |
|---|---|---|---|
| `distancia_sede` | `<=`, `>=` | km (ej. `10`) | Distancia Haversine entre la posición del cliente y `wcmlim_lat/lng` de la sede del paquete. |
| `sede_pickup_elegible` | `==`, `!=` | sí/no | `sí` únicamente para **la sede más cercana** entre las del pedido **y** dentro del radio configurado. Garantiza que sólo una sede reciba PICKUP. |
| `sede` (opcional) | `==`, `!=` | select con **term_id** | Reemplazo de la condición "Locations" de MLI, que compara contra todo el carrito y usa índices posicionales (se rompe al añadir/ocultar sedes). Ésta compara la sede **del paquete**. |

Con eso el administrador arma en WAS, sin código:
- Regla **"Retiro en tienda"**: `sede_pickup_elegible == sí` → costo 0.
- Regla **"Envío nacional"**: `sede_pickup_elegible == no` (más las condiciones de tarifa que quiera:
  estado, peso, subtotal).

Implementación: clases `WPC_Distancia_Sede_Condition`, `WPC_Sede_Pickup_Elegible_Condition`,
`WPC_Sede_Condition` (WAS las resuelve por nombre), registro en `was_conditions` y
`wp-conditions\registered_conditions`. El radio (10 km) como ajuste del plugin pegamento.
Se recomienda usar las reglas de WAS para pickup en lugar del método `wcmlim_pickup_location` de
MLI, porque el de MLI no valida sede y su selector sólo aparece en "modo backend".

### 4.4 Posición del cliente en checkout (`class-ts-checkout-geo.php`)
- Botón "Usar mi ubicación" (JS `navigator.geolocation`) que escribe `wcmlim_user_lat/lng` y dispara
  `update_checkout`.
- Fallback: `woocommerce_checkout_update_order_review` → geocodificar la dirección (Google o
  Nominatim) con caché en transient por hash de dirección → guardar en `WC()->session`.
- Inyectar `lat/lng` en `$package['destination']` vía `woocommerce_cart_shipping_packages`
  (prioridad > 10 para correr después del split de MLI). Esto además invalida el caché de tarifas
  de WooCommerce cuando cambia la posición.

### 4.5 Opcionales según decisión del cliente
- **Caché de carrito por sede** (requisito 7, segunda opción): mediano.
- **Vista de producto de una sola sede** (requisito 5): pequeño, vía `wcmlim_override_view_*`.
- **Consulta de catálogo optimizada** (requisito 4): pequeño-mediano, sólo si el catálogo es grande.

---

## 5. Checkout por bloques: ¿se puede?

**Sí es posible, pero para esta FASE 1 conviene el checkout clásico.** Motivos:

| Pieza | Clásico | Bloques |
|---|---|---|
| Estados de Venezuela (SMV) | Sí | Sí (`woocommerce_states` aplica a ambos). |
| Municipios como select (SMV) | Sí | **No.** En bloques la ciudad es texto libre; SMV usa `woocommerce_form_field_city`, que no existe en bloques. Habría que reconstruirlo como campo adicional de bloques o como bloque React propio. |
| Selector de pickup y validación de MLI | Sí | **No** (`woocommerce_review_order_after_shipping` y `$_POST['wcmlim_pickup']` no existen en la Store API). MLI en bloques sólo muestra el nombre de la sede por línea (`CartItemSchema` extendido). |
| Reglas de WAS | Sí | Sí (es un método de envío normal). |
| Condiciones propias de §4.3 | Sí | Sí, siempre que la posición del cliente llegue al paquete (§4.4 funciona igual; el botón GPS se registra como bloque interior o script). |
| Campo "sucursal" en el checkout (idea original) | Sí | Posible con `woocommerce_register_additional_checkout_field()` (WC ≥ 8.9): tipo `select`, `location => 'address'`, validación con `woocommerce_validate_additional_field`. Las opciones son **estáticas**, pero `hidden`/`required` aceptan JSON Schema que referencia otros campos, así que se puede registrar un select por ciudad con sedes y ocultarlo salvo que `address.city` coincida. Ya no es necesario con MLI. |

Si el cliente exige bloques, el costo extra es: reconstruir el select de municipios como campo
adicional (o aceptar ciudad libre y comparar normalizada), y no usar el pickup de MLI (lo cubren
las reglas de WAS de todos modos). Estimación adicional: 2-3 días.

---

## 6. FASE 2 (mapa / OpenStreetMap): estado real

- El campo **"OpenStreetMap ID"** de la sede (`wcmlim_openstreetmap_id`) **se guarda pero ningún
  otro archivo lo lee**. Es un campo muerto hoy.
- Todos los mapas de MLI (`[wcmlim_location_finder]`, `[wcmlim_location_finder_list_view]`,
  `[wcmlim_location_info]`) usan **Google Maps JavaScript API** con la clave `wcmlim_google_api_key`.
  No hay Leaflet ni tiles de OSM en el plugin.
- Opciones para FASE 2: (a) pagar clave de Google y usar los shortcodes nativos; (b) shortcode
  propio con Leaflet + tiles de OSM leyendo `wcmlim_lat/lng`, `wcmlim_street_number`, `wcmlim_route`,
  `wcmlim_locality`, `wcmlim_phone`, `wcmlim_start_time/end_time` de cada sede. Sin clave, sin
  costo, ~1-2 días. El "OSM ID" serviría sólo como enlace a la ficha en openstreetmap.org.
- Bug a tener en cuenta si se usa la página de archivo de sede de MLI (`/locations/{slug}/`): la
  plantilla lee metas que no existen (`wcmlim_location_address1`, `wcmlim_location_city`…), así que
  los datos de contacto salen vacíos. Sobrescribible desde el tema con `wcmlim-location-archive.php`.

---

## 7. Lista de lo que hace falta (resumen para el cliente)

**Lo que ya cubren los plugins tal cual:** sedes con dirección/lat/lng, stock por sede, selector de
sede en header, catálogo por sede, carrito de una sola sede con diálogo al cambiar, sede en cada
item del pedido, reglas de envío por condiciones, estados y municipios de Venezuela en checkout.

**Lo que hay que desarrollar (plugin pegamento):**

| Módulo | Esfuerzo |
|---|---|
| Resolver estado del cliente + reset de cookies de MLI | 1 día |
| Filtro de sedes por estado (`pre_option`) | 0.5 día |
| Posición del cliente en checkout (GPS + geocodificación con caché + inyección al paquete) | 1.5 días |
| Condiciones WAS: `distancia_sede`, `sede_pickup_elegible`, `sede` | 1.5 días |
| Vista de producto de una sola sede | 0.5 día |
| Pruebas end-to-end en staging (variante A o B), ajustes de MLI, documentación de configuración | 1.5 días |
| **Subtotal FASE 1** | **6.5 días** |
| Caché de carrito por sede (si se confirma) | +2 días |
| Checkout por bloques (si se exige) | +2-3 días |
| FASE 2 mapa OSM con Leaflet | +1-2 días |

**Decisiones que debe tomar el cliente antes de empezar:**
1. ¿Una sede por carrito (variante A) o varias (variante B, con N envíos por pedido)?
2. ¿Checkout clásico (recomendado) o por bloques?
3. ¿Fuente de la posición del cliente: GPS del navegador con fallback a geocodificación? ¿Clave de
   Google o Nominatim?
4. ¿Vaciar/migrar carrito al cambiar de sede (nativo) o caché por sede (a medida)?
5. Confirmar que todos los productos tendrán gestión de stock y stock cargado por sede (requisito
   duro de MLI).

**Riesgos técnicos de MLI detectados en el código** (para presupuestar margen de pruebas):
uso de índices posicionales en cookies y en su condición de WAS; llamadas cURL a Google sin caché
en varias rutas; `error_log()` de depuración en producción; cabecera "WC tested up to 5.8" pese a
changelog de 2025; varios bloques de código muerto (`getSecondNearestLocation.php`,
`wcmlim-clear-cart-and-add.php`, campo pickup por sede envuelto en `if (false)`). Probar en staging
con la versión exacta de WooCommerce del cliente antes de comprometer fechas.
