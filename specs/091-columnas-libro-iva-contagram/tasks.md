# Tasks: Columnas del Libro IVA calcadas de Contagram (spec 091)

**Fecha**: 2026-08-28 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md)

---

## Fase 1 — Red de seguridad

- [X] **T001** Test de **no-regresión de importes**: para un comprobante con valores conocidos, los
      netos y el IVA del archivo con 13 columnas son los mismos que emite el CRM hoy con 19 (SC-004).
      *Contraparte del T001 de la spec 089: allá se protegió el contenido al cambiar el formato; acá,
      al cambiar las columnas.*

- [X] **T002** Test **crítico** (FR-008): un comprobante con IVA al 10,5% aparece con su importe en la
      columna de IVA —no en cero— y la suma de la columna del período coincide con el IVA total.
      *Es el test que hace segura toda la reducción de columnas: sin él, una venta a otra alícuota
      desaparecería del libro en silencio.*

## Fase 2 — Datos comerciales (los tres que faltan)

- [X] **T003** `DatosComercialesComprobante` (`app/Services/Informes/Contador/`): a partir de las filas
      ya materializadas, resuelve **provincia** (`COALESCE(provincia_fiscal, provincia)` — la misma
      expresión que `LibroIvaQuery::filtrarProvincia()`, para que filtro y columna no diverjan) y
      **medio de cobro/pago** (primer cobro/pago → `cuentas_tesoreria`). Dos consultas **por lote**, no
      por fila. Los históricos (spec 088) devuelven `'-'` y vacío sin consultar.

- [X] **T004** `[P]` Test de `DatosComercialesComprobante`: provincia fiscal, respaldo en la comercial,
      `'-'` sin ninguna; medio del cobro, vacío sin cobro, el primero cuando hay varios.

## Fase 3 — Las 13 columnas

- [X] **T005** `LibroIvaExport`: `ENCABEZADOS` pasa a las 13 de Contagram, en su orden. El armado de
      cada fila mapea desde las 19 de `detalle()`:
      IVA = **suma de las cinco alícuotas** (FR-008); Total Facturado = netos + IVA + percepciones +
      impuestos internos/municipales (Decisión 2, para que esos importes no desaparezcan al perder su
      columna); Provincia y Medio de T003.

- [X] **T006** Ajustar `columnWidths()`, `columnFormats()` y `styles()` a 13 columnas (la última pasa
      de `S` a `M`), conservando el formato de la spec 089.

- [X] **T007** El pie de totales (spec 089) se recalcula sobre las columnas nuevas e incluye el total
      de Total Facturado (FR-010).

- [X] **T008** En Compras, la última columna se rotula **"Medio de Pago"** (FR-006), derivado del
      título que ya recibe el constructor.

## Fase 4 — Verificación

- [X] **T009** Test: la fila de títulos coincide con la del fixture
      `tests/Fixtures/LibroIvaExport/IVA Ventas Contagram 13 columnas.xlsx`, nombre por nombre y
      posición por posición (SC-001) — comparando contra el archivo real, no contra una lista a mano.

- [X] **T010** `[P]` Tests de Total Facturado (con y sin percepciones) y de que los tres renglones del
      pie cierran sobre las columnas nuevas (FR-009, FR-010).

- [X] **T011** `[P]` Adaptar `LibroIvaExportFormatoTest` (spec 089) a 13 columnas y verificar que
      **todo el formato sobrevive**: encabezado del negocio, fondo azul de títulos, fechas como fecha,
      importes numéricos, apaisado, sin grilla, tres renglones al pie.

- [X] **T012** Verificación manual comparando el archivo generado contra el fixture de Contagram, y
      correr la suite completa de Informes para descartar regresiones.

- [X] **T013** Actualizar `docs/documentacion_principal_crm.md` §6.7: el export pasa de 19 a 13
      columnas (la **pantalla** sigue con 19), con el motivo y la salvaguarda de FR-008.

---

## Orden de ejecución

```
T001/T002  (red de seguridad, primero)
  → T003 → T004
  → T005 → T006 → T007 → T008
  → T009/T010/T011 → T012 → T013
```

## Trazabilidad

| Requisito | Tareas |
|---|---|
| FR-001, FR-002 | T005, T009 |
| FR-003 | T005, T010 |
| FR-004, FR-005 | T003, T004 |
| FR-006 | T008 |
| FR-007 / SC-004 | T001 |
| FR-008 / SC-003 | T002, T005 |
| FR-009, FR-010 | T007, T010 |
| SC-001 | T009 |
| SC-002 | T005, T010 |
