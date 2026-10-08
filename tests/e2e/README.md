# Pruebas end-to-end

Entorno local reproducible: WordPress 6.8 sobre SQLite (sin MySQL), WooCommerce 10.1, los tres plugins
del repositorio y `total-sucursales` enlazado por symlink.

```bash
bash tests/e2e/setup.sh /tmp/ts-wp          # descarga, instala y siembra datos (sedes, productos, reglas WAS)
cd /tmp/ts-wp && php -S 127.0.0.1:8080 -t wordpress router.php &
node e2e.js                                  # 30 comprobaciones con tema clásico + checkout shortcode
php wp-cli.phar --allow-root --path=wordpress theme activate twentytwentyfive
php wp-cli.phar --allow-root --path=wordpress eval-file pages.php blocks   # carrito y checkout por bloques
node e2e-blocks.js                           # 11 comprobaciones con tema de bloques + checkout por bloques
```

Toda la batería, en el orden correcto (tema clásico primero, bloques al final, stock repuesto antes de
cada prueba) y dejando el sitio en modo clásico:

```bash
bash run-all.sh /tmp/ts-wp     # logs en /tmp/ts-wp/logs/; sale con 0 si todo pasa
```

`pages.php blocks|classic` cambia el contenido de las páginas de carrito y checkout entre bloques y shortcodes.

Datos sembrados por `seed.php`: 4 sedes con coordenadas (Delicias y San Francisco en Zulia, Chacao en
Distrito Capital, Valencia en Carabobo), 4 productos con stock por sede, zona de envío Venezuela con dos
reglas de Advanced Shipping ("Retiro en tienda" = `ts_sede_pickup == Sí`, costo 0; "Envío nacional" =
`ts_sede_pickup == No`, costo 5), pago contra entrega, checkout clásico.

Escenarios de `e2e.js`:

| # | Escenario | Comprueba |
|---|---|---|
| E1 | GPS en Maracaibo | estado ZU, sede Delicias, switcher y catálogo sólo de Zulia, producto sin sedes ajenas, checkout con "Retiro en tienda" a 3,1 km, pedido creado |
| E2 | GPS en Caracas | estado DC, catálogo de Chacao |
| E3 | GPS a >100 km de toda sede | no se asume estado, aparece el modal; "todas" muestra las 4 sedes |
| E4 | Permiso denegado | modal, elección manual (Carabobo), cambio a Zulia por selector, checkout sin posición = envío nacional, botón GPS en checkout habilita retiro |
| E5 | Dos sedes con split de paquetes | Delicias → retiro en tienda, Chacao (520 km) → envío nacional, pedido con dos envíos, panel en el admin, condiciones en el editor de reglas |

Escenarios de `e2e-blocks.js` (tema de bloques Twenty Twenty-Five, carrito y checkout por bloques):

| # | Escenario | Comprueba |
|---|---|---|
| B1 | GPS en Maracaibo | Product Collection filtrado por sede, select de sede de MLI en producto, carrito por bloques, select de municipio por estado (ciudad libre oculta), retiro en tienda, panel de distancias, pedido creado con ciudad = municipio y pickup en Delicias |
| B2 | Sin GPS, estado manual | sólo envío nacional; el botón "Usar mi ubicación" dentro del checkout por bloques (extensionCartUpdate) habilita retiro y muestra 3,1 km |

## Regresión del filtro por estado

`repro-filtro-estado.php` reproduce el caso reportado en producción (una sucursal oculta en
"Hide Locations From Frontend" dejaba el switcher vacío al elegir su estado) y comprueba los cuatro
escenarios de datos:

```bash
php wp-cli.phar --allow-root --path=wordpress eval-file repro-filtro-estado.php
```

Resultado esperado tras la corrección de 0.2.0: ningún escenario devuelve una lista vacía, y el
estado guardado como nombre ("Carabobo") se reconoce igual que el código ("CA"). Ojo: el script borra
y recrea las sucursales, así que después hay que volver a ejecutar `seed.php`.

## Regresión del "backorder" del selector de sede

`repro-backorder.js` recorta el manejador `success()` real de `wcmlim-public.js` y lo ejecuta contra
las respuestas reales de `admin-ajax.php`, para el producto real, para una página (que es lo que Multi
Locations manda como `currentProductId` fuera de la ficha de producto) y para un ID inexistente:

```bash
node repro-backorder.js
```

La cuarta comprobación es el control: la respuesta que devolvía la 0.2.4 (sin envolver en `data`) tiene
que seguir rompiendo con `Cannot read properties of undefined (reading 'backorder')`. Si dejara de
fallar, la prueba no estaría comprobando nada.

## Bucle de recargas del selector de sucursal

`repro-switcher-loop.js` deja un artículo de Delicias en el carrito y dispara el `change` real del
selector de Multi Locations en las tres transiciones posibles:

```bash
node repro-switcher-loop.js
```

Cambiar a la sucursal que ya estaba activa y dejar el selector en "Select" no deben recargar la página
ni abrir ningún diálogo. La tercera comprobación es el control: un cambio de sucursal de verdad tiene
que seguir mostrando el aviso de Multi Locations, para que la prueba no pase con el aviso desactivado.

## Sucursal por defecto

`sucursal-por-defecto.js` comprueba el orden de precedencia del ajuste `default_location`:

```bash
node sucursal-por-defecto.js
```

Elección del cliente &gt; sucursal más cercana por GPS &gt; sucursal por defecto &gt; primera visible, con el
filtro por estado por encima de todo. Incluye el caso de apagado: sin sucursal por defecto configurada,
una carga de página no debe asignar ninguna por su cuenta.

## Textos en español y dirección de cada tienda

`textos-tienda.js` recorre lo que ve el cliente con la traducción de Multi Locations activa. Al final
comprueba que, sin stock suficiente en la tienda (cantidad mayor que su stock, o tres clics con la
validación de Multi Locations fallando), sale un solo aviso en español en la ficha:

```bash
node textos-tienda.js
```

Ventana de estado y selector de la cabecera, ficha de producto en vista de lista (título, cantidades y
ciudad · dirección bajo cada tienda) y de desplegable, carrito clásico y por bloques, la línea del
pedido y los dos diálogos de SweetAlert de Multi Locations (cambio de tienda y productos no
disponibles). Comprueba también que un texto editado en los ajustes se usa, que un texto personalizado
en Multi Locations no se pisa y que su texto de fábrica en inglés sale en español.

## Envío sin Advanced Shipping

`envio-sin-was.js` desactiva Advanced Shipping, pone el método propio "Total Sucursales" en la zona
(retiro 0, envío nacional 5) con `envio-modo.php`, y al terminar lo deja todo como estaba:

```bash
node envio-sin-was.js
```

Comprueba el admin (sin errores, sin pedir Advanced Shipping, y con aviso si no hay método en ninguna
zona), retiro dentro del radio, envío nacional sin posición, un pedido con dos tiendas (una línea de
cada tipo), la opción de ofrecer también el envío donde se puede retirar y el checkout por bloques.

## Retiro por municipio

`regla-retiro.php` prueba la regla de retiro sin navegador: construye paquetes a mano y comprueba qué
tiendas ofrecen retiro y por qué en cada combinación de criterio (radio / municipio / ambos) y alcance
(una tienda / todas), con y sin GPS, texto libre en la ciudad y una tienda sin municipios. Deja los
ajustes como estaban:

```bash
php wp-cli.phar --allow-root --path=wordpress eval-file regla-retiro.php
```

`retiro-municipio.js` hace lo mismo en el navegador, de punta a punta, con Delicias aceptando
Maracaibo y San Francisco:

```bash
node retiro-municipio.js
```

Comprueba el checkout clásico sin GPS (Maracaibo → retiro "en tu municipio", Cabimas → envío
nacional, San Francisco → Delicias), que el cambio de municipio recalcula aunque falten campos de la
dirección, el pedido (municipio, criterio y tienda de retiro guardados), los modos "sólo radio" y
"sólo municipio", el botón "Usar mi ubicación", el alcance una tienda / todas con dos tiendas en el
carrito, el checkout por bloques, los avisos de elegir el municipio (carrito y checkout), el recuadro
"Dónde retirar tu pedido" de la página de gracias y del correo, y que un cliente que podía retirar pero
eligió el envío no quede como retiro. Restaura ajustes, municipios, motor de envío y la página de
checkout aunque falle.

Las pruebas de retiro con GPS lejos de la tienda (`e2e.js` E4, `e2e-blocks.js` B2, `envio-sin-was.js`)
usan Cabimas, un municipio sin tienda: con el criterio por defecto, Maracaibo daría retiro por
municipio aunque no haya posición.

## Municipio en la ficha de la tienda

`ficha-municipio.js` entra al admin (admin / admin) y comprueba el desplegable de municipio que
sustituye al campo "City" de Multi Locations: abre con el municipio deducido de la ciudad, elegir otro
rellena la ciudad con su capital, cambiar de estado cambia la lista, "Otra ciudad" deja escribir, al
guardar la tienda queda con ese municipio (y el retiro lo usa), una ciudad escrita que no es municipio
se respeta y en el alta pide primero el estado. Restaura las tiendas al terminar.

```bash
node ficha-municipio.js
```

## Opciones de envío ocultas

`envio-oculto.js` activa "Ocultar las opciones de envío" y comprueba: carrito y checkout clásicos sin
filas de envío a la vista; por debajo "Retiro en tienda" con Maracaibo y "Envío nacional" con Cabimas;
el pedido con retiro y "Dónde retirar"; una tienda autorizada con todo a la vista; con el método propio
ofreciendo también el envío, que en la autorizada el retiro queda elegido al aparecer y que en la no
autorizada sólo queda el retiro; y el checkout por bloques sin el bloque de opciones ni la línea de
envío. Restaura ajustes, motor de envío y páginas.

```bash
node envio-oculto.js
```

## Tiendas por ciudad

`tiendas-ciudad.js` pone "Tiendas que ve el cliente: por ciudad" (radio 20 km, tienda por defecto
Valencia) y comprueba el selector de tiendas, el catálogo y la ficha: sin ubicación sólo Valencia (sin
ventana de estado y sin volver a preguntar); en Maracaibo Delicias y San Francisco con Delicias
elegida; en Cabimas (a 28 km de la más cercana) sólo Valencia; en Caracas sólo Chacao; y "Usar mi
ubicación" después de no haberla dado.

```bash
node tiendas-ciudad.js
```

## Ubicación por IP (IPinfo)

`ip-ubicacion.js` instala un mu-plugin que simula IPinfo (`pre_http_request`) y toma la IP del
visitante de la cookie `ts_test_ip` (filtro `ts_client_ip`); lo borra al terminar. Comprueba, en el
modo por ciudad, el GPS negado (IP de Maracaibo: Delicias y San Francisco; Cabimas: la tienda por
defecto), el aviso sin contestar (a los 8 s se usa la IP), la cookie de "GPS negado" anterior, que el
GPS manda sobre la IP y la sustituye después, y que la IP no se guarda en las cookies del GPS. Además
comprueba la caché por IP, el resultado vacío, el tope por hora, el nonce, la opción desactivada, que
el script de IPinfo de Multi Locations no se carga ni aparece su token, y el modo por estado (asigna
Zulia sin ventana; en Mérida, Machiques y Bogotá, sin tiendas a menos de 100 km, usa el estado de
IPinfo si tiene tiendas o el de la tienda por defecto; si no hay resultado, pregunta).

```bash
node ip-ubicacion.js
```

## Vista rápida de producto dentro de una página

`vista-rapida.js` añade (con un mu-plugin que borra al terminar) un shortcode que pinta el formulario
de compra de un producto dentro de una página, como el bloque "Product Quick View" de GreenShift.
Comprueba que ni la página ni la API REST que precarga el editor dan el error fatal de Multi
Locations (`get_price_html() on false`), que el selector sale con las tiendas del producto mostrado
y el stock en español, y que sin producto la página no se rompe. Con "Selector de tiendas sólo en la ficha"
(por defecto) comprueba además que un listado de dos productos no muestra el selector y que "Añadir" en
el segundo, con cantidad 2, añade ese producto, 2 unidades, con la tienda de la cabecera.

```bash
node vista-rapida.js
```

## Vista de lista: elegir tienda en la ficha

`vista-lista.js` pone la vista de lista de Multi Locations (botones de radio) y comprueba que al pulsar
una tienda queda esa (y no la primera de la lista), que con el carrito de otra tienda el cambio deja
la nueva elegida, en la cabecera y en el carrito, y que una posición guardada vieja se corrige al
cargar la página (la cabecera muestra la tienda, no "Seleccionar").

```bash
node vista-lista.js
```

## Botón de compra de los listados

`boton-listado.js` pinta (con un mu-plugin que borra al terminar) un botón de listado como el de
GreenShift, con icono SVG, pasado por `woocommerce_loop_add_to_cart_link`. Comprueba que conserva el
icono y sus clases, que lleva la clase y la tienda de Multi Locations (sin `ajax_add_to_cart`, para
no añadirlo dos veces) con un aria-label bien formado, y que al pulsarlo se añade una unidad con la
tienda de la cabecera.

```bash
node boton-listado.js
```

## Precio por tienda con los decimales de WooCommerce

`precio-decimales.js` activa el precio por tienda de Multi Locations, da a Producto C un precio de
39.6712 en Tienda Chacao, lo añade al carrito y comprueba que la columna de precio dice $39.67 (con 2
decimales configurados) y no 39.6712. Deja las opciones y el precio como estaban.

```bash
node precio-decimales.js
```

## Producto sin stock en las tiendas del cliente

`disponibilidad.js`: un cliente de Zulia abre Producto D (sólo hay en Valencia) y debe verlo como no
disponible ("No disponible en Tienda Delicias ni Tienda San Francisco", sin botón), sin poder añadirlo
ni forzando la URL; Producto A sigue comprable y un cliente de Carabobo sí puede comprar Producto D.

```bash
node disponibilidad.js
```

Notas del entorno:

- `woocommerce_hold_stock_minutes` se vacía porque la reserva de stock de WooCommerce usa `FOR UPDATE`/`FROM DUAL`, no soportado por el driver SQLite. En MySQL no hace falta.
- Con temas de bloques el filtro de catálogo de Multi-Locations (`woocommerce_product_query`) no se ejecuta; por eso `total-sucursales` trae su propio filtro (`TS_Catalog`) que cubre loop clásico, shortcodes, Product Collection y Store API. En la prueba de bloques se desactiva `wcmlim_hide_outofstock_products`.
- Multi-Locations emite errores de consola en temas/checkout de bloques (`google is not defined`, `registerCheckoutFilters of undefined`). Son de su propio código y no afectan a este plugin.
- Multi-Locations crea la sede "Online Store" sin estado ni coordenadas. Con un estado elegido queda oculta; sin estado es la sede por defecto y no tiene stock. Conviene borrarla o asignarle stock en producción.
- El switcher de Multi-Locations envía un formulario POST; el script que lo dispara al cambiar sólo se carga con alguno de sus modos de detección activos. La prueba envía el formulario directamente.
