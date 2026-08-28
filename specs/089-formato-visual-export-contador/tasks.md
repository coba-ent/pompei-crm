# Tasks: Formato visual del Excel del Libro IVA (spec 089)

**Fecha**: 2026-08-28 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md)

`[P]` = paralelizable con las tareas marcadas igual dentro de la misma fase.

---

## Fase 1 — Red de seguridad (bloqueante, va PRIMERO)

- [X] **T001** Test de **no-regresión de contenido** en `tests/Feature/Informes/LibroIvaExportFormatoTest.php`:
      para un período con ventas + NC/ND, capturar los valores de las filas de datos del Excel que se
      genera **hoy** (antes de tocar nada) y afirmar que siguen siendo idénticos —mismos valores, mismas
      columnas, mismo orden— después de la feature.
      *Va primero a propósito: es la única defensa contra correr las filas y desalinear números ya
      verificados peso por peso contra Contagram (plan §Riesgos fila 1). Si esto no está en verde antes
      de empezar, no hay con qué comparar después.*

## Fase 2 — Encabezado del negocio (US1)

**Objetivo**: el archivo se identifica solo — razón social, CUIT, título y período arriba de la tabla.

- [X] **T002** `[US1]` En `LibroIvaExport::array()`, anteponer las 4 filas de encabezado (razón social,
      CUIT, título centrado, período centrado, fila vacía) tomando los datos de
      `DatosEmpresa::instancia()` y el nombre del mes de `Periodo` (spec 087) — sin duplicar el array de
      meses. Guardar en propiedades los índices de fila de cada bloque (patrón `MovimientosExport::$filasTotal`).

- [X] **T003** `[US1]` En `styles()`, formatear ese bloque: Arial, razón social y CUIT en negrita 11pt
      alineados a izquierda; título negrita **18pt** centrado; período negrita 11pt centrado
      (plan §Referencia de formato).

- [X] **T004** `[US1]` Test: el encabezado trae razón social, CUIT, el título correcto según Ventas o
      Compras, y el período en castellano ("Periodo: Agosto de 2026"). **Incluye** el caso
      `DatosEmpresa` inexistente: el archivo se genera igual con esos renglones vacíos (FR-004).

## Fase 3 — Formato de la tabla (US2)

**Objetivo**: la tabla se lee y se opera como planilla contable — ordenable, sumable, sin retocar anchos.

- [X] **T005** `[US2]` `styles()`: fila de títulos de columna con fondo `0E5DA1`, fuente blanca 10pt,
      centrada, borde inferior fino negro y alto de fila 27; cuerpo de datos Arial 10pt.
      *El azul ya se usa en `MovimientosExport` — se reusa la convención, no se elige una paleta nueva.*

- [X] **T006** `[US2]` Implementar `WithColumnFormatting::columnFormats()`: `DD/MM/YYYY` para Emisión y
      `0.00;(0.00)` para las 12 columnas de importe. Emitir la fecha con `Date::PHPToExcel()` — sin eso
      no se graba como fecha (gotcha ya documentado en la memoria del proyecto).

- [X] **T007** `[US2]` Implementar `WithColumnWidths::columnWidths()` con el ancho de cada una de las 19
      columnas según su contenido esperado (Cliente/Proveedor ancha, columnas de alícuota angostas).

- [X] **T008** `[US2]` Agregar `WithStrictNullComparison`. **No es opcional**: sin él PhpSpreadsheet
      compara cada celda con `null` usando `==`, y como en PHP `0 == null`, las columnas de alícuota en
      cero —que en este libro son la mayoría— no se escribirían. Dejar el motivo en un comentario.

- [X] **T009** `[US2]` `registerEvents()` / `AfterSheet`: ocultar la grilla (`showGridLines = false`) y
      fijar orientación apaisada (FR-016).

- [X] **T010** `[US2]` Test de **tipos de celda**: las fechas se leen como fecha y los importes como
      número al releer el `.xlsx` generado (no como string) — es lo que habilita ordenar y sumar
      (SC-001, SC-002). Más assert del `numFmt` y del fondo/fuente de la fila de títulos.

- [X] **T011** `[US2]` ~~Corregir acentos (FR-014)~~ — **FALSO POSITIVO, nada que corregir.**
      Diagnosticado el 28/08/2026 leyendo los bytes crudos de `xl/sharedStrings.xml` de ambos archivos:
      el del CRM trae `45 6d 69 73 69 c3 b3 6e` = `Emisión` en UTF-8 **correcto**, y el fixture de
      Contagram trae `52 61 7a c3 b3 6e` = `Razón`, también correcto. El `�` observado era la consola de
      Windows al imprimir, no el contenido. FR-014 queda sin efecto (ver spec).
      *Se deja igual un assert de acentos dentro de T010, para que una regresión futura de encoding no
      pase desapercibida.*

## Fase 4 — Totales al pie (US3)

**Objetivo**: el pie muestra facturación, notas de crédito y total del período.

- [X] **T012** `[US3]` Calcular el desglose facturación / notas de crédito **en el export**, sobre las
      filas ya materializadas, separando por el prefijo de `tipo` (`NC`/`ND`) — mismo criterio que
      `ComprobantesVentasWriter::esNota()`. **No** tocar `LibroIvaQuery::totales()` (plan §Decisión 3).
      **Confirmar antes** que el prefijo de `tipo` sigue siendo el discriminador correcto: el 28/08 la
      partición del Libro IVA se afinó (`sqlFirme()` parametrizado por comprobante, columna
      `sin_comprobante_fiscal`), así que hay que verificarlo contra el `LibroIvaVentasQuery` actual y no
      contra el que existía cuando se escribió este plan.

- [X] **T013** `[US3]` Emitir al pie las 3 filas ("Por Facturación:", "Por Nota de Crédito:",
      "Totales:") con sus importes por columna, y dejar de emitir la barra de KPIs de arriba (FR-015).

- [X] **T014** `[US3]` `styles()`: rótulos de las 3 filas en negrita; importes de "Totales:" también en
      negrita, los de las otras dos normales (plan §Referencia de formato).

- [X] **T015** `[US3]` Test: las 3 filas existen y **facturación + notas = totales** en cada columna de
      importe (FR-013). Con un período que tenga NC/ND reales, y otro sin notas (renglón en cero).

## Fase 5 — Cobertura cruzada y cierre

- [X] **T016** Correr **todos** los tests de formato también para el Libro IVA **Compras**, no sólo
      Ventas — es la misma clase con dos configuraciones y una regresión podría afectar sólo a una
      (plan §Estrategia de test 8).

- [X] **T017** `[P]` Test de período vacío: el archivo se genera con encabezado, títulos y totales en
      cero, sin excepción (spec §Edge Cases).

- [X] **T018** `[P]` Verificar que el adjunto que viaja por correo (spec 087) sale con el mismo formato
      — es la misma clase, así que alcanza con un test que genere el adjunto vía `PaqueteContador` y
      asserte un par de marcas de formato (SC-005).

- [X] **T019** Verificación manual: exportar Ventas y Compras de un período real, abrir los `.xlsx` y
      compararlos visualmente contra
      `tests/Fixtures/LibroIvaExport/IVA Ventas Agosto 2026 Contagram.xlsx`. Confirmar que se puede
      ordenar por fecha y sumar una columna de importes sin retocar nada.
      *Los tests verifican propiedades; que "se vea bien" se confirma mirándolo.*

- [X] **T020** Actualizar `docs/documentacion_principal_crm.md` §6.7 con una subsección de formato del
      export (encabezado, estilos, totales al pie, orientación) y la referencia al fixture — hoy el §6.7
      documenta la pantalla en detalle pero no dice nada del archivo.

---

## Orden de ejecución

```
T001  (red de seguridad, bloqueante)
  → T002 → T003 → T004
  → T005 → T006 → T007 → T008 → T009 → T010/T011
  → T012 → T013 → T014 → T015
  → T016 → T017/T018 → T019 → T020
```

**MVP**: T001–T011 ya entrega el valor central (archivo identificable y operable como planilla). Los
totales del pie (US3, P2) se pueden sumar después sin bloquear el resto.

## Trazabilidad

| Requisito | Tareas |
|---|---|
| FR-001, FR-002, FR-003 | T002, T003, T004 |
| FR-004 | T004 |
| FR-005 | T005, T010 |
| FR-006 | T006, T010 |
| FR-007 | T006, T010 |
| FR-008 | T007, T019 |
| FR-009 | T003, T005 |
| FR-010 / SC-004 | T001 |
| FR-011, FR-012 | T013, T014 |
| FR-013 | T012, T015 |
| FR-014 | T011 |
| FR-015 | T013 |
| FR-016 | T009 |
| SC-001, SC-002 | T010, T019 |
| SC-003 | T004 |
| SC-005 | T016, T018 |
