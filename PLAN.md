# Plan: plugin "Estados, Municipios y Sucursales de Venezuela para WooCommerce"

> **Actualización (ver `ANALISIS-FASE1.md`):** al incorporar el plugin *Multi Locations Inventory
> Management* (MLI), el CPT "Sucursal" y el select de sucursal en el checkout descritos abajo quedan
> **descartados**: MLI ya modela las sedes, las lleva en el carrito y las expone a Advanced Shipping.
> Se conserva de este plan la técnica de la sección 6 (condiciones personalizadas para WAS y
> transporte de datos al paquete de envío), que ahora lee la sede desde el paquete de MLI.

Fork del plugin *States and Municipalities of Venezuela for WooCommerce* (v1.1, Yordan Soares, GPL v2)
que añade un tercer select en cascada, **Sucursal**, y lo expone como condición dentro de
*Advanced Shipping for WooCommerce* (v1.1.5, Jeroen Sormani) para que el administrador cree
reglas de envío por sucursal.

---

## 1. Diagnóstico de los dos plugins

### 1.1 States and Municipalities of Venezuela (SMV)

| Pieza | Qué hace |
|---|---|
| `states/VE.php` | Filtro `woocommerce_states`: registra los 24 estados con código (`ZU` => Zulia). |
| `municipalities/VE.php` | Array global `$municipalities['VE'][ 'ZU' ] = [ 'Municipio Maracaibo (Maracaibo)', ... ]`. El **valor guardado en la ciudad es el texto completo**, no un código. |
| `includes/class-wc-venezuelan-municipalities-select.php` | Cambia `billing_city` / `shipping_city` a `type => 'city'`, renderiza el `<select>` vía `woocommerce_form_field_city`, y localiza el JSON de municipios en `wc_city_select_params`. |
| `assets/js/municipality-select.js` | Escucha `change` en `select.state_select`, dispara `state_changing` y repuebla el select de ciudad (`cityToSelect`). Emite el evento `city_to_select` cuando termina. |
| Plugin principal | Reordena prioridades: `state` = 70, `city` = 80. |

Puntos clave para el fork:

- El **valor de la ciudad se compone con `__('Municipality')`**, es decir, cambia según el idioma
  del sitio (`Municipio Maracaibo (Maracaibo)` en es_VE, `Municipality Maracaibo (Maracaibo)` en inglés).
  Cualquier mapeo sucursal → ciudad debe usar exactamente ese string o comparar normalizado.
- Sólo funciona con el **checkout clásico (shortcode)**. Los filtros `woocommerce_form_field_*`
  no aplican al checkout por bloques. El fork hereda esa limitación y hay que decírselo al cliente.
- El evento `city_to_select` que dispara el JS es el gancho natural para encadenar el select de sucursal.

### 1.2 Advanced Shipping (WAS)

La evaluación de reglas vive en la librería `wp-conditions`:

- `wpc_match_conditions( $groups, [ 'context' => 'was', 'package' => $package ] )` se llama desde
  `calculate_shipping()` del método de envío.
- Cada condición es una clase `WPC_{Slug}_Condition`. `wpc_get_condition('sucursal')` resuelve
  automáticamente `WPC_Sucursal_Condition` si la clase existe; no hace falta tocar el core.
- `match( $match, $operator, $value, $args )` recibe el paquete de envío en `$args['package']`.
- Las condiciones de usuario (`city`, `state`) leen de `WC()->customer` (`get_shipping_city()`).
  **WooCommerce no tiene ningún dato "sucursal" en el cliente ni en el paquete**, por eso hoy
  la regla sólo puede llegar hasta ciudad.

Puntos de extensión que ofrece WAS (todos son filtros/hook públicos, no hay que modificar su código):

| Hook | Para qué |
|---|---|
| `was_conditions` | Añadir "Sucursal" al dropdown de condiciones en el admin (grupo *User Details*). |
| `wp-conditions\registered_conditions` | Registrar la instancia para que el JS del admin sepa qué operadores (`==`, `!=`) y descripción mostrar. |
| Clase `WPC_Sucursal_Condition` | Lógica de match y el campo de valor (`get_value_field_args()` soporta `type => 'select'` con `optgroup`). |
| `woocommerce_cart_shipping_packages` (de WC) | Inyectar la sucursal en `$package['destination']`. Además esto **invalida el caché de tarifas** de WC, que se calcula con un hash del paquete. |

---

## 2. Decisión: fork con nuevo slug (no add-on separado)

Recomendación: **un solo plugin nuevo**, fork completo del SMV, en lugar de un add-on que dependa
del original.

Razones:

- El original está sin mantenimiento (probado hasta WC 6.2 / WP 5.9). El fork permite declarar
  compatibilidad HPOS y subir las versiones probadas.
- Hay que modificar `municipality-select.js` para encadenar el tercer select y la lógica de
  `form_field_city`; hacerlo desde fuera obliga a parchear con JS frágil.
- Para el cliente es una sola instalación: "Estados, Municipios y Sucursales de Venezuela".
- WAS sigue siendo dependencia externa (es de pago), pero la integración se activa sólo si está
  presente (`class_exists('WPC_Condition')`).

Nombre propuesto: **`sucursales-venezuela-woocommerce`**
Prefijo de funciones/opciones: `svw_` · Text domain: `sucursales-venezuela-woocommerce`
Créditos GPL al autor original en la cabecera y en `readme.txt`.

---

## 3. Estructura de archivos propuesta

```
sucursales-venezuela-woocommerce/
├── sucursales-venezuela-woocommerce.php     # bootstrap, constantes, carga condicional
├── readme.txt
├── states/VE.php                            # igual al original
├── municipalities/VE.php                    # igual al original
├── includes/
│   ├── class-svw-municipalities-select.php  # fork de la clase original (sin cambios funcionales)
│   ├── class-svw-branches.php               # CPT "Sucursal" + metabox estado/municipio + helpers
│   ├── class-svw-checkout-field.php         # campo billing_sucursal / shipping_sucursal
│   ├── class-svw-order.php                  # guardado, admin de pedido, emails, Mi cuenta
│   ├── class-svw-session.php                # lleva la sucursal a sesión y al paquete de envío
│   └── integrations/
│       └── class-svw-advanced-shipping.php  # condición WPC_Sucursal_Condition + filtros WAS
├── assets/
│   ├── js/municipality-select.js            # fork + encadenado del select de sucursal
│   ├── js/branch-select.js                  # (o dentro del anterior) lógica del 3er select
│   └── js/admin-branch-metabox.js           # estado → municipio dependiente en el admin
└── languages/
```

---

## 4. Modelo de datos: dónde el administrador carga sus sedes

**Custom Post Type `svw_sucursal`** ("Sucursales"), menú bajo *WooCommerce → Sucursales*.

| Dato | Almacenamiento |
|---|---|
| Nombre de la sede | `post_title` (ej. "Tienda Delicias") |
| Estado | meta `_svw_state` = código WC (`ZU`) |
| Municipio | meta `_svw_city` = string **exacto** del array de municipios (`Municipio Maracaibo (Maracaibo)`) |
| Dirección / teléfono / horario (opcional) | metas `_svw_address`, `_svw_phone`, `_svw_hours` |
| Activa / inactiva | `post_status` publish/draft |
| Orden | `menu_order` |

Por qué CPT y no una tabla de opciones con repeater:

- El admin obtiene gratis listado, búsqueda, borrador/publicado, papelera y edición individual.
- Cada sede tiene un **ID estable**; ese ID es el valor que guardan el checkout, el pedido y la
  regla de WAS. Renombrar la sede no rompe reglas ni pedidos antiguos.
- Escala a decenas de sedes sin una pantalla gigante.

Metabox de edición: select *Estado* (de `states/VE.php`) → select *Municipio* dependiente
(reutiliza el mismo JSON de municipios, con `admin-branch-metabox.js`).

Helper central:

```php
// Devuelve [ 'ZU' => [ 'Municipio Maracaibo (Maracaibo)' => [ ['id'=>12,'name'=>'Tienda Delicias'], ... ] ] ]
svw_get_branches_map();          // cacheado en transient, invalidado en save_post_svw_sucursal
svw_get_branches_for( $state, $city );
svw_get_branch_name( $id );
```

Comparación de ciudad: normalizar ambos lados (`strtolower`, `remove_accents`, trim) para
tolerar diferencias de idioma/acentos entre lo guardado en la sede y lo que llega del checkout.

---

## 5. Checkout: el tercer select

### 5.1 Registro del campo

- Filtros `woocommerce_billing_fields` y `woocommerce_shipping_fields`: añadir `billing_sucursal`
  y `shipping_sucursal` con `type => 'sucursal'`, `priority => 85` (entre ciudad = 80 y teléfono),
  `required => false` en PHP (la obligatoriedad se decide en validación, ver 5.3),
  `class => ['form-row-wide', 'address-field', 'update_totals_on_change']`.
  La clase `update_totals_on_change` hace que WooCommerce dispare `update_checkout` al cambiar,
  sin JS extra.
- Filtro `woocommerce_form_field_sucursal`: renderiza el `<select>` con las sedes de la
  ciudad actual (`WC()->checkout->get_value('billing_city')`) o vacío/deshabilitado si no hay.
  Marca el `<p class="form-row">` con `data-has-branches="0|1"` para poder ocultarlo.

### 5.2 JS (fork de `municipality-select.js`)

- Localizar `svw_branches_params = { branches: <mapa estado→ciudad→[{id,name}]>, i18n_select: 'Selecciona una sucursal…' }`.
- Escuchar `city_to_select` (ya lo emite el plugin) **y** `change` en `#billing_city, #shipping_city`:
  1. Leer estado + ciudad del contenedor.
  2. Buscar `branches[state][city]`.
  3. Si hay sedes: rellenar el select, mostrar la fila, marcar `validate-required`.
  4. Si no hay: vaciar, ocultar la fila (o dejar deshabilitado), quitar `validate-required`.
  5. Re-aplicar select2 (mismo patrón que `wc_city_select_select2`).
  6. Disparar `$(document.body).trigger('update_checkout')` si el valor cambió (para recalcular envío).
- Respetar el checkbox "Enviar a una dirección diferente": el select de shipping sólo se valida
  cuando está visible.

### 5.3 Validación (`woocommerce_checkout_process`)

- Determinar el bloque que decide el envío: `shipping_*` si `ship_to_different_address`,
  si no `billing_*` (mismo criterio que WC usa para el destino).
- Si para ese estado+ciudad **existen sedes** y `*_sucursal` está vacío → `wc_add_notice(..., 'error')`.
- Si viene un ID que no pertenece a esa ciudad (manipulación) → error.
- Ciudad sin sedes → el campo es opcional y se ignora.

### 5.4 Guardado y visualización

- WC guarda automáticamente cualquier campo `billing_*`/`shipping_*` posteado como meta
  `_billing_sucursal` / `_shipping_sucursal` en el pedido. Adicionalmente guardar el **nombre**
  en `_billing_sucursal_nombre` para que el pedido siga siendo legible aunque la sede se borre.
- Mostrar en: admin del pedido (`woocommerce_admin_order_data_after_shipping_address`),
  emails (`woocommerce_email_customer_details_fields`), página de gracias y Mi cuenta
  (`woocommerce_order_formatted_shipping_address` o `woocommerce_get_order_item_totals`).
- Guardar también en el cliente (`customer meta`) para precargar en próximas compras.
- Declarar compatibilidad HPOS (`FeaturesUtil::declare_compatibility('custom_order_tables', ...)`),
  usando siempre `$order->get_meta()` / `update_meta_data()`.

---

## 6. Integración con Advanced Shipping

Este es el corazón del requerimiento. Tres problemas a resolver:

### 6.1 Que WAS conozca la condición "Sucursal"

```php
// includes/integrations/class-svw-advanced-shipping.php
add_filter( 'was_conditions', function ( $conditions ) {
    $group = __( 'User Details', 'woocommerce-advanced-shipping' );
    $conditions[ $group ]['sucursal'] = __( 'Sucursal', 'sucursales-venezuela-woocommerce' );
    return $conditions;
} );

add_filter( 'wp-conditions\registered_conditions', function ( $conditions ) {
    $conditions[] = new WPC_Sucursal_Condition();
    return $conditions;
} );

class WPC_Sucursal_Condition extends WPC_Condition {
    public function __construct() {
        $this->name        = __( 'Sucursal', 'sucursales-venezuela-woocommerce' );
        $this->slug        = 'sucursal';
        $this->group       = __( 'User', 'wpc-conditions' );
        $this->description = __( 'Compara contra la sucursal elegida por el cliente en el checkout.', '...' );
        parent::__construct();
    }

    // Dropdown con optgroup "Zulia › Municipio Maracaibo (Maracaibo)" => [ id => nombre ]
    public function get_value_field_args() {
        return [
            'type'    => 'select',
            'class'   => [ 'wpc-value', 'wc-enhanced-select' ],
            'options' => svw_get_branches_for_condition_dropdown(),
        ];
    }

    public function get_available_operators() {
        $ops = parent::get_available_operators();
        unset( $ops['>='], $ops['<='] );
        return $ops;
    }

    public function match( $match, $operator, $value, $args = [] ) {
        $chosen = isset( $args['package']['destination']['sucursal'] )
            ? (int) $args['package']['destination']['sucursal']
            : (int) svw_get_session_branch();

        if ( '==' === $operator ) return $chosen === (int) $value;
        if ( '!=' === $operator ) return $chosen !== (int) $value;
        return $match;
    }
}
```

`wpc_get_condition('sucursal')` resolverá la clase por convención de nombre; no hay que tocar
`wpc_get_condition_class_name`.

### 6.2 Llevar la sucursal desde el formulario hasta el cálculo de envío

WooCommerce sólo copia al cliente (`WC()->customer`) los campos estándar cuando el checkout se
refresca por AJAX. La sucursal hay que transportarla a mano:

1. **Al refrescar el checkout** — hook `woocommerce_checkout_update_order_review( $post_data )`:
   `parse_str( $post_data, $data )`, elegir `shipping_sucursal` o `billing_sucursal` según
   `ship_to_different_address`, y guardar en `WC()->session->set( 'svw_sucursal', $id )`.
2. **Al construir los paquetes** — filtro `woocommerce_cart_shipping_packages`:
   `$package['destination']['sucursal'] = WC()->session->get('svw_sucursal')` en cada paquete.
   Esto tiene dos efectos: la condición lo lee desde `$args['package']`, y como WC hashea el
   paquete para cachear tarifas (`wc_ship_` + md5 del paquete), **cambiar de sede fuerza un
   recálculo** sin trucos.
3. **Al enviar el pedido** (`woocommerce_checkout_process`) se repite el paso 1 con `$_POST`,
   porque el cálculo final de envío vuelve a correr en ese request.
4. Limpiar la sesión al finalizar el pedido (`woocommerce_checkout_order_processed`).

### 6.3 Que el cliente vea la regla en cuanto elige la sede

- La clase `update_totals_on_change` en el campo (5.1) + `trigger('update_checkout')` en el JS
  (5.2) hacen que WC vuelva a pedir `update_order_review` → paso 6.2.1 → 6.2.2 → `calculate_shipping`
  de WAS → la regla con `Sucursal == Tienda Delicias` aparece.
- Cuando la ciudad cambia y la sucursal queda vacía, la regla desaparece y sólo quedan las que
  no dependen de sede (o una regla de respaldo por ciudad/estado que el admin cree).

### 6.4 Carrito y calculadora de envío

En la página del carrito no existe el campo sucursal. Las reglas con condición *Sucursal*
simplemente no coincidirán ahí. Recomendación al cliente: mantener una regla genérica por
estado/ciudad como fallback, o desactivar la calculadora del carrito. Se documenta, no se
programa alrededor.

---

## 7. Flujo completo (ejemplo)

1. Admin crea sede "Tienda Delicias" → Estado *Zulia*, Municipio *Municipio Maracaibo (Maracaibo)*.
2. Admin va a *WooCommerce → Ajustes → Envío → Zona Venezuela → Advanced Shipping → nueva regla*:
   condición **Sucursal** `igual a` **Zulia › Maracaibo › Tienda Delicias**, costo Bs. X, título "Retiro en Delicias".
3. Cliente en checkout: Región *Zulia* → Población *Municipio Maracaibo (Maracaibo)* → aparece
   el select **Sucursal** con "Tienda Delicias" (y las demás de Maracaibo).
4. Al elegir la sede, el checkout se refresca y aparece "Retiro en Delicias".
5. Pedido guardado con `_billing_sucursal = 12`, `_billing_sucursal_nombre = Tienda Delicias`;
   visible en el admin del pedido y en el email.

---

## 8. Riesgos y casos borde

| Riesgo | Mitigación |
|---|---|
| Checkout por bloques (WC Blocks) | Fuera de alcance, igual que el plugin original. Documentar que requiere el checkout clásico `[woocommerce_checkout]`. |
| El valor de ciudad depende del idioma (`Municipio` vs `Municipality`) | Comparar normalizado; en el metabox de sede guardar el string tal como lo genera el sitio. Alternativa a futuro: introducir claves estables por municipio (rompe compatibilidad con pedidos viejos, no recomendado ahora). |
| Caché de tarifas de WC | Resuelto al inyectar la sede en `$package['destination']`. |
| Cliente cambia "Enviar a otra dirección" | El JS re-evalúa qué select manda; PHP usa el mismo criterio que WC para el destino. |
| Sede borrada con pedidos antiguos | Nombre duplicado en meta `_*_sucursal_nombre`. Recomendar "borrador" en vez de borrar. |
| WAS desactivado | La integración se carga sólo si `class_exists('WPC_Condition')`; el campo sigue funcionando solo. |
| HPOS | Usar API de meta de `WC_Order`, declarar compatibilidad. |
| Edición de dirección en *Mi cuenta* | Ahí el campo aparece también (los filtros `*_fields` aplican); el JS ya se carga en `edit-address`. |
| Municipio con texto mal cerrado en el original (`'Miranda (Pariaguán'` sin paréntesis de cierre en Anzoátegui) | Corregir en el fork; afecta sólo al texto mostrado. |

---

## 9. Fases de trabajo

| Fase | Entregable | Estimación |
|---|---|---|
| 0 | Fork base: renombrar slug/prefijos/text domain, bootstrap, HPOS, subir "tested up to". | 0.5 día |
| 1 | CPT Sucursal + metabox estado/municipio dependiente + helpers y transient. | 1 día |
| 2 | Campo de checkout + JS encadenado + validación + guardado/visualización en pedido y emails. | 1.5 días |
| 3 | Integración WAS: condición, dropdown de valores, sesión + paquete, refresco. | 1 día |
| 4 | Pruebas manuales end-to-end (checkout clásico, envío a otra dirección, ciudad sin sedes, cambio de sede), traducciones es_VE, readme. | 1 día |

Total aproximado: 5 días de desarrollo.

### Pruebas mínimas de aceptación

- [ ] Sede creada en el admin aparece sólo cuando estado+ciudad coinciden.
- [ ] Ciudad sin sedes: el campo se oculta y el pedido se puede completar.
- [ ] Ciudad con sedes: no se puede completar sin elegir una.
- [ ] Al elegir sede, la regla de WAS aparece sin recargar; al cambiar de sede, cambia la regla.
- [ ] Con "enviar a otra dirección", manda la sede del bloque de envío.
- [ ] La sede queda en el pedido (admin + email) y sobrevive a borrar la sede.
- [ ] Con WAS desactivado, el plugin no genera errores.
