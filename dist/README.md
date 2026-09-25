# Total Sucursales – descargas

| Archivo | Descripción |
|---|---|
| `total-sucursales.zip` | Última versión del plugin, lista para subir en *Plugins → Añadir nuevo → Subir plugin*. |
| `total-sucursales-0.8.3.zip` | Misma build, con número de versión en el nombre. |
| `guia-total-sucursales.zip` | Guía de implementación en HTML con capturas. Descomprimir y abrir `index.html` en cualquier navegador. |
| `SHA256SUMS` | Sumas de verificación. |

Enlace directo (rama `release`):
https://github.com/augusto97/Total-Sucursales/raw/release/dist/total-sucursales.zip

Guía para el cliente:
https://github.com/augusto97/Total-Sucursales/raw/release/dist/guia-total-sucursales.zip

## Instalación

1. WordPress → Plugins → Añadir nuevo → Subir plugin → `total-sucursales.zip` → Activar.
2. Requiere WooCommerce y **MULTILOCA – Multi Locations Inventory Management** activos, y **States and Municipalities of Venezuela** para
   estados y municipios. **Advanced Shipping ya no es necesario**: el plugin trae su propio método de envío.
3. En cada sucursal de Multi Locations (Productos → Locations) completa *State* y *Location Lat / Lng*.
4. WooCommerce → Ajustes → Total Sucursales → *Retiro en tienda*: criterio (municipio o radio), los municipios que pueden
   retirar en cada tienda (por defecto, el de su ciudad; añade los del área metropolitana) y el radio (10 km). Luego detección
   del estado y geocodificador.
5. Envío: WooCommerce → Ajustes → Envío → tu zona → Añadir método → **Total Sucursales: retiro o envío nacional**, con el
   nombre y costo del retiro y del envío. (Si prefieres Advanced Shipping, crea sus dos reglas en su lugar; no uses ambos en la
   misma zona.)
6. Opcional: shortcode `[ts_selector_estado]` en el header junto a `[wcmlim_locations_switch]`.

Documentación completa: `ARQUITECTURA.md` y `total-sucursales/readme.txt` en la raíz del repositorio.
