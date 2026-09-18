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

Notas del entorno:

- `woocommerce_hold_stock_minutes` se vacía porque la reserva de stock de WooCommerce usa `FOR UPDATE`/`FROM DUAL`, no soportado por el driver SQLite. En MySQL no hace falta.
- Con temas de bloques el filtro de catálogo de Multi-Locations (`woocommerce_product_query`) no se ejecuta; por eso `total-sucursales` trae su propio filtro (`TS_Catalog`) que cubre loop clásico, shortcodes, Product Collection y Store API. En la prueba de bloques se desactiva `wcmlim_hide_outofstock_products`.
- Multi-Locations emite errores de consola en temas/checkout de bloques (`google is not defined`, `registerCheckoutFilters of undefined`). Son de su propio código y no afectan a este plugin.
- Multi-Locations crea la sede "Online Store" sin estado ni coordenadas. Con un estado elegido queda oculta; sin estado es la sede por defecto y no tiene stock. Conviene borrarla o asignarle stock en producción.
- El switcher de Multi-Locations envía un formulario POST; el script que lo dispara al cambiar sólo se carga con alguno de sus modos de detección activos. La prueba envía el formulario directamente.
