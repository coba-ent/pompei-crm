# Checklist: Fidelidad estructural, reuso fiscal y cobertura de operaciones

**Purpose**: Validar la calidad de los requisitos de spec.md/plan.md antes de tasks — no valida
implementación (todavía no existe código de este feature).
**Created**: 2026-08-25
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md) · [research.md](../research.md) · [data-model.md](../data-model.md)

## Fidelidad estructural (calco de los archivos reales)

- [x] CHK001 - ¿Está especificado el orden exacto y el nombre exacto de las 34/33 columnas del Excel, no sólo "las columnas del archivo real"? [Completeness, Spec §FR-007/FR-008]
- [x] CHK002 - ¿Está aclarado qué pasa con la columna "No Gravado" que aparece duplicada conceptualmente en el header real junto a "Importe Neto No Gravado"? [Ambiguity, resuelto en data-model.md nota columna 27]
- [x] CHK003 - ¿Está especificado el título exacto y la orientación del PDF para Clientes y para Proveedores por separado? [Completeness, Spec §FR-003]
- [x] CHK004 - ¿Se documentó explícitamente qué elementos visuales de los archivos reales (resaltado de color) se decidió NO replicar, y por qué? [Clarity, Spec §FR-005]
- [x] CHK005 - ¿Está definido el nombre exacto del archivo descargado (patrón `Informe Cuentas Corrientes Movimientos de {Clientes|Proveedores} {fecha} {hora} Hs.xlsx`) en algún artefacto, no sólo el nombre de la hoja? [Completeness, Spec §FR-014]

## Reuso de infraestructura fiscal existente (no reinventar)

- [x] CHK006 - ¿La spec prohíbe explícitamente reimplementar el cálculo de IVA/netos en vez de reutilizar `DesgloseImpositivoVenta`/`DesgloseImpositivoCompra`? [Consistency, Spec §FR-009]
- [x] CHK007 - ¿Se identificó el conflicto real entre el filtro por mes/año del Libro IVA y el filtro por rango de fechas de esta pantalla, con una decisión explícita de cómo resolverlo sin tocar el cálculo fiscal? [Completeness, research.md D1]
- [x] CHK008 - ¿Se documentó que `LibroIvaVentasQuery`/`LibroIvaComprasQuery`/`DesgloseImpositivo*` no se modifican (sólo se extiende la clase base de forma aditiva)? [Consistency, plan.md Constitution Check + research.md D1]
- [x] CHK009 - ¿Está definido cómo se testea que los totales del nuevo export coinciden con los del Libro IVA para el mismo período (no sólo "debe reutilizar la lógica")? [Measurability, Spec §FR-015]

## Cobertura de las 4 filas de operación (Venta/Compra, Cobro/Pago, NC/ND, Saldo Inicial)

- [x] CHK010 - ¿Están definidas las columnas que aplican y las que quedan en blanco para la fila de Venta/Compra? [Completeness, data-model.md]
- [x] CHK011 - ¿Están definidas las columnas que aplican y las que quedan en blanco para la fila de Cobro/Pago, incluyendo que sea blanco y no 0? [Completeness, Spec §FR-010]
- [x] CHK012 - ¿Está definido explícitamente qué columnas aplican para la fila de NC/ND (Subtotal sin/con Descuento, Vendedor, Id Venta/Compra) más allá de "tiene su propio desglose fiscal"? [Completeness, Spec §FR-016]
- [x] CHK013 - ¿Está definido cómo se comporta la fila de Saldo Inicial en el export (columnas en blanco)? [Completeness, Edge Cases de spec.md]
- [x] CHK014 - ¿Está definido qué pasa si el filtro no devuelve movimientos (export vacío sin error)? [Edge Case, Spec Edge Cases]

## Consistencia entre Clientes y Proveedores

- [x] CHK015 - ¿Están explícitas todas las diferencias de columnas entre el export de Clientes y el de Proveedores (Vendedor, Sellos, renombres)? [Consistency, Spec §FR-008]
- [x] CHK016 - ¿Está definido de dónde sale el valor de la columna "Sellos" de Proveedores, más allá de "no se inventa un cálculo"? [Clarity, Spec §FR-017]

## Notes

- Los 4 gaps detectados en la primera pasada (CHK005, CHK009, CHK012, CHK016) se cerraron agregando
  FR-014 a FR-017 a `spec.md` en la misma sesión — checklist 16/16 en verde, listo para `/speckit-tasks`.
