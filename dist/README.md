# Total Sucursales – descargas

| Archivo | Descripción |
|---|---|
| `total-sucursales.zip` | Última versión del plugin, lista para subir en *Plugins → Añadir nuevo → Subir plugin*. |
| `total-sucursales-0.1.0.zip` | Misma build, con número de versión en el nombre. |
| `guia-total-sucursales.zip` | Guía de implementación en HTML con capturas. Descomprimir y abrir `index.html` en cualquier navegador. |
| `SHA256SUMS` | Sumas de verificación. |

Enlace directo (rama `release`):
https://github.com/augusto97/Total-Sucursales/raw/release/dist/total-sucursales.zip

Guía para el cliente:
https://github.com/augusto97/Total-Sucursales/raw/release/dist/guia-total-sucursales.zip

## Instalación

1. WordPress → Plugins → Añadir nuevo → Subir plugin → `total-sucursales.zip` → Activar.
2. Requiere WooCommerce y **MULTILOCA – Multi Locations Inventory Management** activos; **Advanced Shipping** para
   las condiciones de envío y **States and Municipalities of Venezuela** para estados y municipios.
3. En cada sucursal de Multi Locations (Productos → Locations) completa *State* y *Location Lat / Lng*.
4. WooCommerce → Ajustes → Total Sucursales: radio de retiro (10 km), detección del estado, geocodificador.
5. En Advanced Shipping crea las reglas "Retiro en tienda" (*Sucursal elegible para pickup* = Sí, costo 0) y
   "Envío nacional" (*Sucursal elegible para pickup* = No).
6. Opcional: shortcode `[ts_selector_estado]` en el header junto a `[wcmlim_locations_switch]`.

Documentación completa: `ARQUITECTURA.md` y `total-sucursales/readme.txt` en la raíz del repositorio.
