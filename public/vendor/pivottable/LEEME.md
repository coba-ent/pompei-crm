# PivotTable.js vendorizado — sólo el core

**Versión 2.23.0**, copiada de `node_modules/pivottable/dist/` (el paquete queda declarado en
`package.json` como devDependency para dejar registro de la versión; los archivos se sirven desde
acá, no por Vite, igual que el resto de `public/vendor/`).

## Qué se copió y qué NO

Sólo `pivot.min.js` y `pivot.min.css` — el core y el renderer `Table`.

**No se copian** `c3_renderers`, `d3_renderers`, `plotly_renderers`, `gchart_renderers` ni
`export_renderers`. La spec 069 recortó "Mostrar Como" a **Tabla** por decisión del cliente
(15/08/2026): se descartaron mapa de calor, gráficos e histograma porque no los usa y mostrar la
misma información de muchas formas sólo agranda la app. Traer esos paquetes arrastraría C3, D3 y
Plotly sin que nada los use.

`resources/js/informes-pivot.js` registra explícitamente `renderers: { Table: ... }` para que, si
algún día alguien copia un renderer de más acá, no aparezca solo en el desplegable.

## Dependencia

Necesita **jQuery UI** para el drag & drop de dimensiones entre ejes. Ya estaba vendorizado en
`public/vendor/jqueryui/` (viene con el template NexaDash), así que no se agregó nada.

## Actualizar

```bash
npm install pivottable@<version> --save-dev
cp node_modules/pivottable/dist/pivot.min.js  public/vendor/pivottable/
cp node_modules/pivottable/dist/pivot.min.css public/vendor/pivottable/
```
