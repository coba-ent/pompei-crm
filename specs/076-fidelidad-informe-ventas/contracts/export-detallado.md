# Contrato: Exportación detallada del Informe de Ventas (spec 076)

**Fecha**: 2026-08-24

El contrato es el **archivo generado**: tiene que ser comparable, celda a celda, con el que produce
Contagram. Referencia real: `Informe_de_Ventas_Detallado_24-08-2026_1429_Hs.xlsx` y los seis años de
`migracion-nueva/excel-origen/Ventas/`.

---

## 1. Endpoint

`GET /informes/ventas/exportar-detallado`

Acepta **exactamente los mismos parámetros** de rango y filtros que `informes.ventas.data` e
`informes.ventas.exportar`, y devuelve un `.xlsx` como descarga. Ningún parámetro nuevo.

Nombre del archivo, con el mismo patrón que el resumen que ya existe:
`Informe de Ventas Detallado <fecha> <hora> Hs.xlsx`.

---

## 2. Estructura del archivo

**Una sola hoja** (el resumen tiene dos; el detallado no, ver `research.md §R6`).

| Filas | Contenido |
|---|---|
| 1-2 | `Total Ventas Creadas` · `Total Nota de Débito` · `Total Nota de Crédito` · `Total Ventas`, y sus valores |
| 3 | vacía |
| 4-5 | `Cantidad de Productos/Servicios` · `Cantidad Ventas Creadas` · `Venta Promedio` · `Costo Actual`, y sus valores |
| 6 | vacía |
| 7-8 | `Precio Neto` · `Costo Mercadería Vendida` · `Resultado`, y sus valores |
| 9 | vacía |
| 10 | encabezado de las 44 columnas |
| 11+ | una fila por línea de venta o de nota |

Los tres bloques de KPIs salen del **mismo servicio** que alimenta las cards de la pantalla, no de
sumar columnas del detalle (SC-004).

---

## 3. Las 44 columnas, en orden

| # | Rótulo | Notas |
|---|---|---|
| 1 | `Id` | del CRM, **nunca** el de Contagram |
| 2 | `Emisión` | como fecha de Excel, no como texto |
| 3 | `Vencimiento` | |
| 4 | `Categoría` | |
| 5 | `Cliente` | |
| 6 | `CUIT / DNI` | vacío si no tiene |
| 7 | `ARCA` | `Aprobado` / `Sin Enviar` / `---` |
| 8 | `Tipo` | letra del comprobante (`A`, `B`, …) — vacío si no aplica |
| 9 | `Tipo de Comprobante` | **sigla completa**: `FCA`, `FCB`, `FC`, `NCA`, `NCB`, `NC` |
| 10 | `Punto de Venta` | `-` si no fue emitido |
| 11 | `N° Factura` | `-` si no fue emitido |
| 12 | `Vendedor` | |
| 13 | `Producto/Servicio` | **sólo el nombre** (el código va en la columna 14) |
| 14 | `Código` | vacío en líneas de concepto libre |
| 15 | `Tipo` | tipo de **producto** — el rótulo se repite a propósito (ver §5) |
| 16 | `Proveedor` | |
| 17 | `Cantidad` | negativa en notas de crédito |
| 18 | `Precio Unitario` | |
| 19 | `Costo Total Actual` | |
| 20 | `CMV Total` | costo congelado con fallback (spec 075) |
| 21 | `Lista de Precios` | vacío si la venta no tiene |
| 22 | `Precio de Venta` | el neto de la línea |
| 23 | `Resultado` | `Precio de Venta − CMV Total` |
| 24 | `Subtotal sin Descuento` | |
| 25 | `Descuento en $` | |
| 26 | `Subtotal con Descuento` | |
| 27 | `Importe Neto No Gravado` | una sola de las tres lleva valor |
| 28 | `Importe Neto Exento` | |
| 29 | `Importe Neto Gravado` | |
| 30-34 | `IVA - 2,5%` · `IVA - 5%` · `IVA - 10,5%` · `IVA - 21%` · `IVA - 27%` | como mucho una con valor |
| 35 | `Exento` | |
| 36 | `No Gravado` | |
| 37 | `Perc. IVA` | prorrateado por línea |
| 38 | `Perc. IIBB` | prorrateado por línea |
| 39 | `Imp. Internos` | prorrateado por línea |
| 40 | `Total Venta` | **el importe de la línea**; la columna suma el total del período |
| 41 | `Etiquetas` | |
| 42 | `Nota para el Cliente` | |
| 43 | `Nota Interna` | |
| 44 | `Afecta Stock` | `Si` / `No` |

---

## 4. Invariantes (cada uno con test)

| # | Invariante | Por qué importa |
|---|---|---|
| I1 | La suma de `Total Venta` de las líneas de un comprobante iguala su total, al centavo | Es el corazón de la feature (FR-002) |
| I2 | La suma de `Total Venta` de todo el detalle iguala el KPI `Total Ventas` | SC-001 |
| I3 | Cada línea imputa a **una sola** columna de neto y a **como mucho una** de alícuota | FR-011 |
| I4 | Los negativos salen como números negativos, no como texto entre paréntesis | Si no, las celdas dejan de sumarse en Excel |
| I5 | Los totales del archivo coinciden con los de la pantalla para los mismos filtros | SC-004 |
| I6 | Una venta con dos comprobantes fiscales (rechazo + reintento) aporta **una** fila por línea | El join polimórfico es el riesgo #1 del plan |
| I7 | Las columnas del desglose impositivo sin valor van en **cero**, no vacías | Una columna con celdas vacías intercaladas rompe el autosuma y las tablas dinámicas del contador |
| I8 | Las fechas van como **fecha de Excel**, no como texto | Si no, el contador no puede ordenar ni filtrar por fecha |
| I9 | El export **resumen** conserva sus dos hojas después del cambio | Nada verifica hoy que corregir el importe no lo colapse a una sola |

---

## 5. Fidelidad por encima de la prolijidad

Dos cosas del archivo de Contagram son objetivamente malas prácticas y **se replican igual**:

- El rótulo `Tipo` aparece **dos veces** (columnas 8 y 15), con significados distintos.
- Los rótulos difieren de los de la pantalla: `Emisión`/Fecha, `Precio de Venta`/Precio Total Neto,
  `Total Venta`/Total Comprobante.

Se replican porque el objetivo del archivo es ser **comparable** con el de Contagram mientras el
cliente use las dos herramientas en paralelo. Es la misma decisión que ya se tomó para el export
resumen.
