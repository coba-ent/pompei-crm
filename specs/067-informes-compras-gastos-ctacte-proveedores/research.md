# Research — Módulo Informes, Tanda 1

**Feature**: `067-informes-compras-gastos-ctacte-proveedores`
**Fecha**: 2026-08-14

Todo lo que sigue se resolvió inspeccionando el código vigente del repositorio, no eligiendo
tecnología nueva. La conclusión general es que **esta tanda no necesita ninguna dependencia nueva ni
ninguna migración**: los tres informes se construyen con piezas que ya existen y funcionan.

---

## R1 — Selector de rango "Emisión" de 9 opciones

**Decisión**: reutilizar `bootstrap-daterangepicker` tal como ya está configurado en
`resources/js/compras.js:127-149` (`opcionesRango()`), sin escribir un widget nuevo.

**Rationale**: ese helper ya define exactamente los 7 rangos rápidos del relevamiento (Hoy, Ayer,
Última Semana, Mes actual, Mes anterior, Últimos 30 días, Año actual), más "Desde - Hasta" como
`customRangeLabel` y "Borrar filtro" como `cancelLabel`. El widget de daterangepicker en modo rango
personalizado ya renderiza los dos calendarios mensuales contiguos con la lista de accesos rápidos
visible en paralelo — es literalmente la captura `04_ventas_emision_desde_hasta_calendario.gif`. Y
`Mes actual` ya está definido como `startOf('month')` … `endOf('month')`, que es el mes calendario
completo que pide FR-005, incluyendo fechas futuras.

**Consecuencia**: `opcionesRango()` está duplicado hoy entre `compras.js`, `auditoria.js` e
`informe-cuenta-corriente.js`. Se extrae a un helper compartido (`resources/js/rango-emision.js`) y
los tres informes nuevos lo consumen; los consumidores existentes se migran para no dejar cuatro
copias. Los `pagelevel` de `config/dz.php` ya cargan moment + daterangepicker para
`informe-compras`, `informe-gastos` e `informe-cuenta-corriente`.

**Alternativas descartadas**: escribir un dropdown propio de 9 opciones (reinventa lo que el
template ya trae y no da el doble calendario); flatpickr (no está vendorizado).

---

## R2 — Selector de columnas con persistencia

**Decisión**: botón `colvis` de DataTables Buttons + `stateSave: true`, exactamente el patrón de
`resources/js/clientes.js:77-131`.

**Rationale**: `buttons.colVis.min.js` y `dataTables.buttons.min.js` ya están vendorizados en
`public/vendor/datatables/js/`, y el pagelevel `informe-stock` ya los carga. `stateSave` persiste la
visibilidad de columnas en localStorage — cubre FR-017 sin tocar la base de datos ni el modelo de
usuario. La convención `no-colvis` de `clientes.js` sirve para excluir columnas que no deben poder
ocultarse.

**Consecuencia**: los pagelevel `informe-compras` y `informe-gastos` de `config/dz.php` hoy **no**
cargan `dataTables.buttons`, `buttons.colVis` ni `select2`. Hay que completarlos (y agregar el
pagelevel nuevo de Cta Cte Proveedores).

**Alternativas descartadas**: guardar las preferencias de columnas en la base por usuario (más
trabajo, ningún requisito lo pide, y rompe con lo que ya hacen las otras pantallas).

---

## R3 — Agrupación jerárquica del Informe de Gastos manteniendo paginación server-side

**Decisión**: una única tabla DataTables server-side ordenada por Categoría → Subcategoría → Fecha,
con la extensión **RowGroup** de DataTables para dibujar los encabezados de grupo y las filas de
subtotal. Los subtotales por grupo y el Gasto Total llegan desde el servidor en un endpoint de
`stats` aparte, no se calculan sumando la página visible.

**Rationale**: la regla obligatoria #1 del proyecto exige tablas server-side; renderizar una tabla
independiente por subcategoría obligaría a traer todos los gastos del período de una (exactamente el
problema de memoria que ya explotó en producción con el tab Saldos de Cta Cte, documentado en
`documentacion_principal_crm.md §6.4`). RowGroup resuelve la lectura jerárquica del relevamiento sin
romper el `LIMIT/OFFSET`. Calcular los subtotales en el servidor evita que un subtotal quede cortado
por la paginación — que sería un error de negocio visible.

**Consecuencia**: `dataTables.rowGroup.min.js` + su CSS **no están vendorizados**; hay que agregarlos
a `public/vendor/datatables/` y al pagelevel `informe-gastos`. Es una extensión oficial de
DataTables, misma familia que Buttons y Responsive que ya conviven ahí.

**Alternativas descartadas**: acordeón Bootstrap con una tabla por subcategoría (rompe paginación
real); `drawCallback` artesanal insertando `<tr>` de grupo a mano (es reimplementar RowGroup peor);
paginar por categoría en vez de por gasto (el usuario quiere ver el detalle, no sólo los grupos).

---

## R4 — Exportación Excel de doble hoja

**Decisión**: usar `maatwebsite/excel` (ya en `composer.json`, `^3.1`) con una clase de export por
informe que implemente `WithMultipleSheets`, devolviendo dos hojas: una "formateada" (jerárquica,
con subtotales) y una "plana" (una fila por registro).

**Rationale**: el exportador genérico actual, `app/Exports/InformeCsvExport.php`, produce **CSV**, no
`.xlsx` — un CSV no puede tener dos hojas, así que no sirve para FR-040. `maatwebsite/excel` ya es
dependencia del proyecto (se usa en `ProductosExport`) y trae `WithMultipleSheets`,
`FromQuery`/`FromCollection`, `WithHeadings` y `ShouldQueue` si algún día hiciera falta.

**Consecuencia**: `InformeCsvExport` se conserva para los informes viejos; los tres nuevos usan
clases `.xlsx` propias. Las hojas planas deben construirse con `FromQuery` en modo *chunked* para no
cargar todo el período en memoria (misma lección del §6.4).

**Alternativas descartadas**: extender `InformeCsvExport` a dos archivos CSV en un ZIP (rarísimo de
consumir); generar el XLSX en el navegador con SheetJS como hace Contagram en sus rankings (acá el
dataset está en el servidor, no en memoria del cliente — traerlo entero sería el antipatrón que R3
evita).

---

## R5 — PDF de los informes

**Decisión**: `barryvdh/laravel-dompdf` (`Pdf::loadView(...)->stream()`) con
`Content-Disposition: inline`, servido en el modal compartido vía `window.AppPdf.abrir(url, titulo)`.

**Rationale**: es el patrón ya establecido en todo el proyecto (`VentaController:637`,
`CompraController:455`, `TesoreriaController:151` que ya imprime un listado de movimientos, no un
comprobante — el precedente más parecido a un informe). El modal compartido
`resources/views/elements/modal-pdf.blade.php` está incluido globalmente en el layout.

**Consecuencia**: el PDF de un informe con miles de filas es pesado. Se limita el PDF a un resumen
agrupado + un tope de filas de detalle, con leyenda explícita de que el detalle completo va por
Excel. Esto se refleja en `quickstart.md` como escenario de validación.

**Alternativas descartadas**: `window.open` directo (violación explícita de la regla #4 de
CLAUDE.md); generación asíncrona por cola con aviso (sobredimensionado para el volumen real).

---

## R6 — Desglose impositivo de Compras sin tocar el esquema

**Decisión**: derivar las columnas impositivas de lo que ya existe, sin migraciones.

| Columna del informe | De dónde sale |
|---------------------|---------------|
| IVA 2,5 / 5 / 10,5 / 21 / 27 % | `compra_items.iva_pct` — se agrupa por alícuota y se suma `subtotal_con_iva − subtotal` |
| Importe Neto Gravado | suma de `compra_items.subtotal` de ítems con `iva_pct > 0` |
| Importe Neto No Gravado | suma de `compra_items.subtotal` de ítems con `iva_pct` nulo |
| Importe Neto Exento | suma de `compra_items.subtotal` de ítems con `iva_pct = 0` |
| Perc. IVA / Perc. IIBB / Otras Percepciones | `compra_conceptos` con `tipo = 'percepcion'`, clasificados por el texto de `concepto` |
| Imp. Internos | `compra_conceptos` con `tipo = 'impuesto_interno'` |
| Subtotal sin/con Descuento, Descuento en $ | `compras.subtotal_sin_descuento`, `subtotal_con_descuento`, `descuento` |
| Vencimiento, CUIT/DNI, Punto de Venta, N° Factura, Tipo de Comprobante | `compras.fecha_vto_pago`, `proveedores`, `compras.nro_comprobante`, `compras.tipo_comprobante` |
| Código, Tipo de producto, Afecta Stock | `productos` / `tipos_producto` vía `compra_items.producto_id` |
| Etiquetas | relación polimórfica de etiquetas ya existente en `Compra` |

**Rationale**: verificado leyendo `database/migrations/2026_07_31_070001_create_compras_tables.php`
(`compra_items.iva_pct`, `compra_conceptos.tipo` enum `percepcion|impuesto_interno|interes`) y
`app/Models/Compra.php`. Todo el desglose es derivable; el modelo de datos ya es tan rico como el que
el relevamiento descubrió en Contagram.

**Punto flojo conocido**: `compra_conceptos.tipo` no separa percepción de IVA de percepción de IIBB —
por eso FR-015b crea la tercera columna "Otras Percepciones" como destino de lo no clasificable, en
vez de forzar una imputación posiblemente incorrecta. La clasificación se hace por coincidencia de
texto sobre `concepto` (`iibb`, `ingresos brutos` → IIBB; `iva` → IVA; resto → Otras), en un único
punto del código para que sea fácil de corregir.

**Alternativas descartadas**: agregar `subtipo` a `compra_conceptos` con migración + backfill (cambia
el formulario de Compra y excede el alcance de un informe; queda anotado como mejora futura).

---

## R7 — Cuenta Corriente Proveedores: reutilización del servicio existente

**Decisión**: `CuentaCorrienteProveedorController` nuevo, que llama a
`App\Services\Tesoreria\CuentaCorriente::porCliente('proveedor')` sin modificar el servicio.

**Rationale**: `app/Services/Tesoreria/CuentaCorriente.php:292` ya recibe `$tipo` y resuelve
`$campoEntidad = $tipo === 'cliente' ? 'cliente_id' : 'proveedor_id'`, con los 5 buckets, la
tolerancia de cero, el criterio de conservar saldos negativos y el saldo inicial. El camino de
proveedores ya está escrito y cubierto por los tests de aging; lo único que falta es la pantalla.

**Consecuencia crítica**: cualquier cambio que se haga al servicio afecta también al informe de
clientes y al Dashboard. La regla para esta spec es **no tocar `CuentaCorriente`**. Si algún
requisito pareciera exigirlo, se resuelve en el controlador.

**Herencia consciente**: el informe de proveedores hereda la brecha ya documentada de que la
agregación por entidad ocurre en PHP y no en SQL (`documentacion_principal_crm.md §6.4`). Esta spec
no la resuelve ni la empeora; queda para su spec propia.

**Nombre del método**: `porCliente('proveedor')` es un nombre desafortunado ahora que se usa para
proveedores, pero renombrarlo tocaría el Dashboard y los tests. Se deja como está y se documenta.

---

## R8 — Query de Movimientos de proveedores

**Decisión**: UNION SQL de Compras + Pagos + Notas de Crédito/Débito de compra + fila sintética de
Saldo Inicial, servido con `DataTables::of()` sobre Query Builder — espejo de
`CuentaCorrienteController::queryMovimientos()` (clientes).

**Rationale**: la query de clientes ya resuelve la parte difícil (proyección de columnas con `NULL`
donde no aplica, subconsultas de cobrado / a cobrar, `deleted_at` respetado, orden por fecha
descendente). La versión de proveedores es la misma con `compras`/`pagos`/`proveedor_id` en lugar de
`ventas`/`cobros`/`cliente_id`. Al usar Query Builder mantiene `LIMIT/OFFSET` real, a diferencia del
tab Saldos.

**Alternativas descartadas**: generalizar la query de clientes con parámetros para servir a ambos
(el riesgo de romper una pantalla en producción supera el beneficio de no duplicar ~60 líneas de
SQL; se deja el TODO de unificación anotado).

---

## R9 — Modal de ficha de proveedor (sólo lectura)

**Decisión**: modal Bootstrap nuevo, poblado por AJAX desde un endpoint `show` en JSON, en modo
lectura pura.

**Rationale**: `resources/js/cliente-modal.js` existe pero es el modal de **alta/edición** completo
de Cliente, reutilizado desde Ventas y Presupuestos. FR-033 pide explícitamente sólo lectura, sin
botones de edición. Reutilizar el modal de edición y esconderle los botones sería frágil y expondría
un formulario de escritura desde una pantalla declarada de sólo lectura.

**Alternativas descartadas**: navegar a la ficha del proveedor en otra pantalla (FR-008: nada
recarga la página); reusar `cliente-modal.js` en modo `readonly` (ver arriba).

---

## R10 — Deep-link desde el menú de fila de Compras

**Decisión**: la opción "Cta Cte" del menú de fila de Compras, hoy `disabled` con leyenda
"Próximamente", pasa a navegar a `/informes/cuenta-corriente-proveedores?proveedor_id={id}`, que
precarga el filtro y abre el tab "Movimientos".

**Rationale**: es exactamente el patrón que ya implementa
`Informes\CuentaCorrienteController::index()` con `?cliente_id=` para el deep-link desde Clientes.
Cerrar este "Próximamente" es parte del valor de la spec (`documentacion_principal_crm.md §4.3` lo
lista como brecha abierta).

---

## Resumen de impacto

| Área | Cambio |
|------|--------|
| Migraciones | **Ninguna** |
| Dependencias PHP | **Ninguna nueva** (`maatwebsite/excel` y `laravel-dompdf` ya están) |
| Assets vendorizados | agregar `dataTables.rowGroup` (JS + CSS) |
| `config/dz.php` | completar pagelevel `informe-compras` y `informe-gastos`; agregar `informe-cuenta-corriente-proveedores` |
| Servicio de Cta Cte | **no se toca** |
| Deuda que se cierra | "Cta Cte" deshabilitado en el menú de fila de Compras |
| Deuda que se hereda | agregación en PHP del tab Saldos; `porCliente()` mal nombrado; `compra_conceptos` sin subtipo de percepción |
