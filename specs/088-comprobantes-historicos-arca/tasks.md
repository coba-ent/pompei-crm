# Tasks: Comprobantes Históricos con CAE Real de ARCA (spec 088)

**Fecha**: 2026-08-28 · **Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Data model**: [data-model.md](./data-model.md)

`[P]` = paralelizable con las tareas marcadas igual dentro de la misma fase.

---

## Fase 1 — Setup

- [X] **T001** Migración `create_comprobantes_historicos_arca_table` (`database/migrations/`): crea la
      tabla según data-model.md §1 (sin `deleted_at`, con columna `origen` literal — plan §Decisión 2)
      y, en la misma migración, `INSERT` de los 14 registros con los valores exactos de
      data-model.md §2. Verificar el invariante neto+IVA+percepciones=total de cada fila antes de
      escribir el `INSERT` (evita transcribir mal un número a mano).

- [X] **T002** `[P]` Modelo `ComprobanteHistoricoArca` (`app/Models/`): Eloquent simple, sólo lectura,
      sin relaciones a `Venta`/`Cliente` (data-model.md §4). Usado por la migración (`Model::insert()`)
      y por los tests — no se expone en ninguna pantalla ni ruta (spec FR-007).

## Fase 2 — Foundational (bloqueante para todas las historias)

- [X] **T003** Test de caracterización: cargar la migración T001 y verificar que
      `ComprobanteHistoricoArca::count()` da 14, y que la suma de `total` da $1.604.530,47 (tolerancia
      de centavos) — fija los datos correctos antes de escribir una línea de integración.
      *Sin esto, un error de tipeo en el `INSERT` de T001 se propaga en silencio a todo lo demás.*

- [X] **T004** `LibroIvaVentasQuery::queryHistoricos()` (método privado nuevo): selecciona de
      `comprobantes_historicos_arca` con las 19 columnas exactas del contrato de spec 077 (mismo
      alias/orden que `queryVentas()`), agrega el literal `'historico_migracion_agosto_2026' as origen`
      (plan §Decisión 2) y filtra con `filtrarPeriodo()` reusado tal cual sobre `fecha_emision`. **No
      modifica** `queryVentas()` ni `queryNotas()`. `detalle()` pasa a unir las tres ramas con
      `unionAll()`.

- [X] **T005** Test de regresión de `detalle()`: con datos de un período que ya tenía ventas y NC/ND
      antes de esta feature, verificar que el resultado (cantidad de filas, importes, orden) es
      idéntico al que daba antes de agregar `queryHistoricos()` — Fase Foundational no debe cambiar el
      comportamiento existente (plan §Riesgos, fila 2).

## Fase 3 — US1: Libro IVA Ventas incluye los 14 históricos (Priority: P1) 🎯 MVP

**Objetivo**: el Libro IVA Ventas de Agosto 2026 muestra los 14 comprobantes, con sus importes
correctos, sumando a los totales del período.

**Test independiente**: filtrar Agosto 2026 en el Libro IVA Ventas y verificar 14 filas nuevas con los
valores de data-model.md §2, más los totales del período correctamente incrementados.

- [X] **T006** `[US1]` Test: `LibroIvaVentasQuery::detalle()` filtrado a Agosto 2026 incluye las 14
      filas históricas, cada una con neto/IVA/total exactos (data-model.md §2), `tipo` = letra sola
      (`'A'`/`'B'`, no un prefijo nuevo — plan §Decisión 1), y `nro_comprobante` reconstruido como
      `"{punto_venta}-{numero}"` (mismo formato que `separarNroComprobante()` espera en los writers de
      IVA Digital — data-model.md §1). **Incluye también** un assert de que las columnas que devuelve
      `queryHistoricos()` coinciden en nombre y orden con las de `queryVentas()`/`queryNotas()` antes
      de unir — un desalineamiento ahí no siempre falla en runtime, puede mezclar valores en silencio.

- [X] **T007** `[US1]` Test: `LibroIvaVentasQuery::totales()` del período incluye el neto/IVA/total de
      los 14 históricos en la suma general (spec FR-008, SC-004: sube exactamente $278.472,23 de IVA).

- [X] **T008** `[US1]` Test: la venta histórica con dos comprobantes fiscales (filas 12 y 13 de
      data-model.md §2) aparece como dos filas separadas en `detalle()`, cada una con su propio
      `numero`/CAE — nunca fusionadas ni con el importe duplicado en una sola fila (spec, Edge Cases).

- [X] **T009** `[US1]` Test: con el filtro Electrónicas/Manuales (`arca=true, manuales=false`), los 14
      históricos siguen apareciendo — nunca se excluyen ni se clasifican como manuales (spec FR-010).
      *`filtrarArcaManuales()` sólo aplica a la rama `queryVentas()`; este test confirma que la rama
      histórica queda fuera de ese filtro tal como se diseñó (plan §Decisión 1).*

- [X] **T010** `[US1]` **Test crítico de aislamiento por id (plan §Decisión 2, checklist CHK009)**:
      crear una `Venta` real con `id=1` (coincide con el histórico id 1 de la tabla nueva) y verificar
      que `DatosFiscalesComprobante` (fase 4, ver T014) no cruza datos entre ambos — cada uno debe
      resolver su propio cliente/total, sin que uno le pise el dato al otro. *Reproduce exactamente el
      caso límite que motivó la Decisión 2 — sin este test, el bug puede pasar desapercibido hasta
      producción real.*

## Fase 4 — US2: IVA Digital incluye los 14 históricos (Priority: P1)

**Objetivo**: el ZIP de IVA Digital de Agosto 2026 incluye las 14 líneas en los archivos de
Comprobantes y Alícuotas Ventas, con formato posicional correcto.

**Test independiente**: generar el IVA Digital de Agosto 2026 y verificar que los archivos incluyen
las 14 líneas con los números de comprobante y CAE reales.

- [X] **T011** `[US2]` `DatosFiscalesComprobante::clave()`: agrega la rama `historico:{id}` cuando
      `$fila->origen === 'historico_migracion_agosto_2026'` — **antes** de la rama `comprobante`
      genérica, para que nunca caiga en `Venta::whereIn` (plan §Decisión 2).

- [X] **T012** `[US2]` `DatosFiscalesComprobante::resolverVentas()`: agrega una consulta a
      `comprobantes_historicos_arca` para los ids de la rama histórica, resolviendo
      `total`/`tipo_documento`/`documento` directo de sus columnas propias (data-model.md §1) —
      **nunca** pasa por `Venta::whereIn`. Depende de T011.

- [X] **T013** `[US2]` `ComprobantesVentasWriter::escribir()`: detecta el caso histórico (mismo
      criterio que T011) para no llamar `netoPorAlicuotaVenta((int) $fila->id)` con un id ajeno a
      `venta_items` — el neto por alícuota sale directo de las columnas `iva_2_5`..`iva_27` de la fila,
      igual que ya pasa hoy para NC/ND.

- [X] **T014** `[US2]` Ejecutar T010 contra el flujo completo de IVA Digital (no sólo
      `LibroIvaVentasQuery::detalle()`): mismo caso límite (histórico id 1 vs. venta real id 1), ahora
      verificando que el archivo generado no mezcla los datos de ambos.

- [X] **T015** `[US2]` Test posicional (mismo patrón que spec 086, plan §Estrategia de test 3): generar
      el IVA Digital de Agosto 2026 con los 14 históricos presentes y parsear campo por campo las 14
      líneas de "Comprobantes Ventas" y "Alicuotas Ventas" contra los valores de data-model.md §2 —
      no un `assertEquals` de archivo completo. **Incluye una aserción dedicada para las filas 12/13**
      (la venta con doble CAE, data-model.md §2): dos líneas distintas en "Comprobantes Ventas", cada
      una con su propio número/CAE, mismo neto/IVA/total en ambas — no una fila con el importe
      duplicado ni una que absorba a la otra.

- [X] **T016** `[US2]` Test: "Cantidad de alícuotas" de cada comprobante histórico se cumple por
      construcción (mismo criterio que spec 086 FR-016) — no hay valor fijo copiado a mano.

## Fase 5 — US3: Aislamiento total del resto del CRM (Priority: P1)

**Objetivo**: ningún otro módulo del CRM ve, lista o suma estos 14 comprobantes.

**Test independiente**: comparar Reporte Final, KPIs de ventas, Informe de Stock y Cuenta Corriente de
Clientes de Agosto 2026 antes y después de cargar los históricos — deben ser idénticos.

- [X] **T017** `[US3]` Test: Reporte Final da el mismo resultado con y sin los 14 históricos cargados —
      comparación antes/después real, no una afirmación de que "no debería cambiar" (spec SC-002,
      checklist CHK006). Correr **dos veces**: (a) para Agosto 2026, el único período con históricos, y
      (b) para un mes de control sin históricos — ambos deben dar exactamente el mismo total que antes
      de correr la migración T001.

- [X] **T018** `[US3]` `[P]` Test: Cuenta Corriente de un cliente involucrado (ej. Roberto, CUIT
      23247526749 — data-model.md §2 fila 4) no muestra ningún movimiento nuevo ni cambia de saldo
      tras cargar los históricos.

- [X] **T019** `[US3]` `[P]` Test: Tesorería no tiene ningún cobro ni movimiento de cuenta asociado a
      estos 14 comprobantes — cero registros nuevos en las tablas de movimientos/cobros.

- [X] **T020** `[US3]` `[P]` Test: Informe de Stock no descontó ni ajustó ningún producto por estos
      comprobantes — cero movimientos de stock nuevos.

- [X] **T021** `[US3]` Test: `ComprobanteHistoricoArca` no tiene ninguna ruta HTTP ni controlador
      asociado — confirma por ausencia que no hay flujo de alta/edición expuesto (spec FR-007).

## Fase 6 — Verificación final y documentación

- [ ] **T022** Verificación manual en local **con MySQL** (no sólo SQLite), siguiendo
      [quickstart.md](./quickstart.md) paso a paso: carga, Libro IVA Ventas, IVA Digital, y el test de
      aislamiento antes/después contra datos reales del período. *Memoria del proyecto: la suite verde
      en SQLite no garantiza el comportamiento en MySQL — ya pasó un caso real en spec 086.*

- [X] **T023** Actualizar `docs/documentacion_principal_crm.md`: dejar registrado el incidente de
      migración (13/14 comprobantes con CAE real que quedaron fuera de la base actual, cómo se
      recuperaron, y la regla de "estructura ajena a `ventas`, sólo Libro IVA/IVA Digital") para que no
      se reinterprete en el futuro como una funcionalidad de uso recurrente.

---

## Orden de ejecución

```
T001 → T002 → T003
     → T004 → T005
     → T006/T007/T008/T009 → T010
     → T011 → T012 → T013 → T014 → T015 → T016
     → T017/T018/T019/T020/T021
     → T022 → T023
```

**MVP**: hasta T010 ya existe la integración completa al Libro IVA Ventas en pantalla — visible al
contador antes incluso de generar el IVA Digital. T011-T016 (US2) y T017-T021 (US3) son igual de
prioritarios (ambos P1) porque son el requisito no negociable del usuario (aislamiento) y la
razón fiscal completa (IVA Digital también tiene que declararlos) — no se consideran "extra" del MVP.

## Trazabilidad

| Requisito | Tareas |
|---|---|
| FR-001, FR-002, FR-003 | T001, T003 |
| FR-004, FR-007 | T002, T021 |
| FR-005 | T019, T020 |
| FR-006 | T017, T018, T019, T020 |
| FR-008 | T004, T006, T007 |
| FR-009 | T011-T016 |
| FR-010 | T009 |
| FR-011 | T005 |
| SC-001 | T006, T015, T022 |
| SC-002 | T017, T018, T019, T020 |
| SC-003 | T019, T020 |
| SC-004 | T007 |
