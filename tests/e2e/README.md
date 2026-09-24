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

`textos-tienda.js` recorre lo que ve el cliente con la traducción de Multi Locations activa:

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

Notas del entorno:

- `woocommerce_hold_stock_minutes` se vacía porque la reserva de stock de WooCommerce usa `FOR UPDATE`/`FROM DUAL`, no soportado por el driver SQLite. En MySQL no hace falta.
- Con temas de bloques el filtro de catálogo de Multi-Locations (`woocommerce_product_query`) no se ejecuta; por eso `total-sucursales` trae su propio filtro (`TS_Catalog`) que cubre loop clásico, shortcodes, Product Collection y Store API. En la prueba de bloques se desactiva `wcmlim_hide_outofstock_products`.
- Multi-Locations emite errores de consola en temas/checkout de bloques (`google is not defined`, `registerCheckoutFilters of undefined`). Son de su propio código y no afectan a este plugin.
- Multi-Locations crea la sede "Online Store" sin estado ni coordenadas. Con un estado elegido queda oculta; sin estado es la sede por defecto y no tiene stock. Conviene borrarla o asignarle stock en producción.
- El switcher de Multi-Locations envía un formulario POST; el script que lo dispara al cambiar sólo se carga con alguno de sus modos de detección activos. La prueba envía el formulario directamente.
