# Matching e Integración Checklist: Vinculación automática por SKU

**Purpose**: Validar la calidad de los requisitos que gobiernan el matching automático (ambos canales) y
la dependencia nueva del catálogo en vivo de Tiendanube — las áreas de mayor riesgo de esta spec, porque
una vinculación mal resuelta afecta qué producto se descuenta de stock aguas abajo (conversión de
órdenes, specs 012/017/018), y la consulta al catálogo en vivo es una dependencia externa nueva que el
diseño original no tenía.
**Created**: 2026-07-30
**Feature**: [spec.md](../spec.md)

**Note**: Generado con foco (Depth: Standard, Audience: Revisor de plan/tasks) en matching e integridad
de datos — mismo criterio que el checklist análogo de la spec 021 descartada, ajustado a los dos
mecanismos nuevos (matching por `id` en ML, resolución por catálogo en vivo en TN).

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa cuando el SKU de Mercado Libre no es un valor numérico
  interpretable como `id`? [Completeness, Spec §Edge Cases] — cubierto: FR-004 lo trata como "sin
  coincidencia", mismo motivo que cualquier SKU sin match.
- [x] CHK002 - ¿Están definidos los motivos de fallo de la vinculación automática de ML de forma
  exhaustiva? [Completeness, Spec §FR-004]
- [x] CHK003 - ¿Están definidos los motivos de fallo de la importación de Tiendanube de forma
  exhaustiva, distinguiendo "producto no encontrado" de "Tiendanube no encontrado"? [Completeness, Spec
  §FR-013, data-model.md §Resultado de las corridas]
- [ ] CHK004 - ¿Especifica la spec qué pasa si la consulta al catálogo en vivo de Tiendanube falla o da
  timeout a mitad de una importación (no un rechazo por fila, sino un fallo de la corrida completa)?
  [Gap]

## Requirement Clarity

- [x] CHK005 - ¿Está cuantificado el criterio de "más reciente" para el SKU de Mercado Libre visto en
  órdenes, con un desempate objetivo? [Clarity, Spec §FR-002, data-model.md]
- [x] CHK006 - ¿Es "coincide con el número inicial del código" (Tiendanube) un criterio suficientemente
  preciso para implementarse sin ambigüedad (ej. separador esperado)? [Clarity, Spec §FR-011,
  research.md R4] — precisado en plan.md (`LIKE '$sku %'`, separador espacio, confirmado con 6 casos
  reales).
- [ ] CHK007 - ¿Especifica la spec si la comparación de `codigo`/SKU (Tiendanube) es sensible a
  mayúsculas/minúsculas o a espacios al inicio/fin? [Ambiguity]

## Requirement Consistency

- [x] CHK008 - ¿Es el criterio de resolución del SKU de Mercado Libre (contra órdenes ya sincronizadas,
  sin API en vivo) consistente entre lo que ya usaba `publicacionesPendientes()` y lo que usa la
  vinculación automática nueva? [Consistency, Spec §FR-002] — mismo query, confirmado en plan.md §1.
- [x] CHK009 - ¿Es consistente el uso del catálogo en vivo de Tiendanube (sólo para ids, nunca para SKU)
  con el hallazgo de research.md R3 (el SKU nunca viaja por esa integración)? [Consistency, Spec
  §Assumptions]

## Acceptance Criteria Quality

- [x] CHK010 - ¿Es SC-001 ("vincular automáticamente todas las publicaciones pendientes... en una sola
  operación") medible sin ambigüedad? [Measurability, Spec §SC-001]
- [x] CHK011 - ¿Es SC-004 ("reintentar... deja el 100% de lo ya vinculado sin cambios") verificable de
  forma objetiva contra el estado de la base después de una segunda corrida? [Measurability, Spec
  §SC-004]
- [ ] CHK012 - ¿Existe un criterio de éxito medible específico para la resolución por catálogo en vivo de
  Tiendanube (ej. tiempo máximo aceptable de la paginación completa), más allá de SC-002 ("menos de un
  minuto" para toda la importación)? [Gap]

## Scenario Coverage

- [x] CHK013 - ¿Cubre la spec el escenario de un producto de Tiendanube que coincide por `codigo` pero
  cuyo "Identificador de URL" ya no existe en el catálogo en vivo (despublicado)? [Coverage, Spec
  §Edge Cases]
- [x] CHK014 - ¿Cubre la spec el escenario de reintentar la vinculación automática de ML o la
  importación de Tiendanube después de una corrida parcial? [Coverage, Spec §SC-004]
- [ ] CHK015 - ¿Cubre la spec el escenario de un catálogo de Tiendanube con más de una página cuyo
  "Identificador de URL" buscado está en la última página (validar que la paginación se agota completa,
  no se corta antes)? [Gap]

## Edge Case Coverage

- [x] CHK016 - ¿Está definido el comportamiento cuando dos publicaciones de Mercado Libre tienen el
  mismo SKU (mismo `id` de producto)? [Edge Case, Spec §Acceptance Scenario 4]
- [x] CHK017 - ¿Está definido el comportamiento cuando el producto resuelto (por `id` en ML, por
  `codigo` en TN) está inactivo? [Edge Case, Spec §FR-002, §FR-011] — ambos canales: se vincula igual,
  sin excluir inactivos (resuelto explícitamente en FR-011 tras `/speckit-analyze`).
- [x] CHK018 - ¿Es el criterio de "producto inactivo se vincula igual" (clarificado para Mercado Libre)
  el mismo para la importación de Tiendanube? [Spec §FR-011] — sí, resuelto explícitamente.

## Non-Functional Requirements

- [x] CHK019 - ¿Especifica la spec un volumen esperado para el que el procesamiento síncrono (ML y TN)
  es aceptable? [Clarity, Spec §Technical Context]
- [x] CHK020 - ¿Está documentado el rate limit real de Tiendanube y por qué el volumen de esta spec no
  lo compromete? [Non-Functional, research.md R5, plan.md §Technical Context]

## Dependencies & Assumptions

- [x] CHK021 - ¿Está documentada la dependencia de que el SKU de Mercado Libre sólo se puede resolver
  contra órdenes ya sincronizadas, y su consecuencia (publicaciones que nunca vendieron no se vinculan)?
  [Dependency, Spec §Assumptions]
- [x] CHK022 - ¿Está documentado el riesgo de que Tiendanube cambie el formato de su export nativo (y
  deje de reconocerse la columna SKU o "Identificador de URL")? [Dependency, Spec §Assumptions]
- [x] CHK023 - ¿Está validada contra datos reales (no sólo declarada como supuesto) la premisa de que el
  slug de Tiendanube identifica un producto de forma única? [Assumption, research.md R5 — 102/102 casos
  reales, sin colisiones observadas]

## Ambiguities & Conflicts

- [x] CHK024 - CHK018 (productos inactivos en la importación de Tiendanube) — resuelto en `/speckit-analyze`:
  se elevó a FR-011 explícito, mismo criterio que Mercado Libre (no excluir inactivos).

## Notes

- Ítems marcados `[x]` ya están resueltos por el texto de spec.md/research.md/plan.md/data-model.md tal
  como quedaron redactados en esta ronda — se dejan documentados para trazabilidad.
- Ítems sin marcar (`[ ]`) son huecos genuinos de bajo-medio impacto (no bloquean planificación ni
  justifican una nueva ronda de `/speckit-clarify`): CHK004, CHK007, CHK012, CHK015, CHK018/CHK024 quedan
  como notas para `/speckit-tasks`/implementación, con defaults razonables: CHK004 (fallo de catálogo en
  vivo se trata como fallo de conexión ya manejado por `ClienteTiendanube`, con reintentos ya
  implementados — no hace falta lógica nueva); CHK007 (comparación case-insensitive por collation de
  MySQL, ya vigente en el resto del proyecto); CHK012 (sin SLA propio más allá de SC-002); CHK015
  (paginar hasta agotar `pagination.total_pages`, sin cortar antes — ya especificado en plan.md); CHK018
  (mismo criterio que ML: no excluir inactivos, por consistencia entre canales, salvo que el usuario
  indique lo contrario antes de implementar).
