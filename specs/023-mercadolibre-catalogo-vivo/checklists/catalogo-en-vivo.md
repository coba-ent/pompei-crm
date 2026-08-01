# Catálogo en Vivo Checklist: Vinculación automática de Mercado Libre por catálogo en vivo

**Purpose**: Validar la calidad de los requisitos que gobiernan el recorrido del catálogo en vivo a escala
real (miles de publicaciones) y el matching por SKU — las áreas de mayor riesgo de esta corrección, porque
reemplazan por completo la fuente de datos de un mecanismo ya implementado (spec 021) y agregan una
dependencia nueva de una llamada en vivo que puede fallar a mitad de una corrida.
**Created**: 2026-07-31
**Feature**: [spec.md](../spec.md)

**Note**: Generado con foco (Depth: Standard, Audience: Revisor de plan/tasks) en el recorrido del catálogo
a escala y la integridad del matching — mismo criterio que el checklist análogo de la spec 021.

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué publicaciones se excluyen del recorrido del catálogo en vivo (además
  de las que tienen variantes)? [Completeness, Spec §FR-003] — cerradas/finalizadas excluidas, pausadas
  incluidas, ambos explícitos.
- [x] CHK002 - ¿Está definido qué pasa si la consulta al catálogo en vivo falla a mitad de una corrida (no
  un rechazo por publicación, sino un fallo de la corrida completa)? [Spec §Assumptions] — se aborta sin
  crear ningún vínculo, resuelto en Clarifications.
- [ ] CHK003 - ¿Especifica la spec un tope de tiempo razonable más allá de "puede tardar varios minutos",
  a partir del cual la corrida debería considerarse colgada en vez de simplemente lenta? [Gap]

## Requirement Clarity

- [x] CHK004 - ¿Está cuantificado el volumen real de publicaciones que tiene que soportar el recorrido,
  para dimensionar el diseño (paginado clásico vs. `scan`)? [Clarity, Spec §Assumptions] — "miles",
  confirmado por el usuario, motivó el cambio de mecanismo de paginado.
- [x] CHK005 - ¿Es "SKU vigente" (el que tiene la publicación en el momento de la corrida) suficientemente
  preciso para implementarse sin ambigüedad respecto a qué campo de la publicación se lee? [Clarity, Spec
  §Contexto] — precisado en research.md R3 (`attributes[SELLER_SKU]`, no `seller_custom_field`).

## Requirement Consistency

- [x] CHK006 - ¿Es el criterio de matching por `id` de producto (sin excluir inactivos) consistente entre
  esta corrección y el mecanismo que reemplaza (spec 021)? [Consistency, Spec §FR-004] — mismo criterio,
  confirmado.
- [x] CHK007 - ¿Es consistente la exclusión de publicaciones con variantes con el criterio ya vigente
  (spec 021 FR-007), incluida la forma de detectarlas? [Consistency, Spec §FR-007] — mismo criterio, ahora
  vía `variations[]` del propio multiget en vez de `ml_orden_items.ml_variation_id`.

## Acceptance Criteria Quality

- [x] CHK008 - ¿Es SC-001 ("vincular una publicación que nunca vendió") medible sin ambigüedad? [Spec
  §SC-001] — sí, verificable con o sin órdenes sincronizadas para esa publicación.
- [x] CHK009 - ¿Es SC-005 ("un catálogo de varios miles se recorre completo en una sola corrida") verificable
  de forma objetiva? [Measurability, Spec §SC-005] — verificable contra el total reportado por la propia
  API (`paging.total`) al agotar el `scan`.
- [ ] CHK010 - ¿Existe un criterio de éxito medible para el tiempo máximo aceptable de una corrida completa
  a escala real, más allá de "varios minutos sin problema"? [Gap]

## Scenario Coverage

- [x] CHK011 - ¿Cubre la spec el escenario de dos publicaciones activas/pausadas distintas con el mismo SKU
  (caso real confirmado, no hipotético)? [Coverage, Spec §Edge Cases]
- [x] CHK012 - ¿Cubre la spec el escenario de reintentar la vinculación automática después de una corrida
  exitosa previa? [Coverage, Spec §SC-004] — no sobrescribe lo ya vinculado, mismo criterio que spec 021.
- [ ] CHK013 - ¿Cubre la spec el escenario de una publicación que cambia de estado (ej. de `paused` a
  `closed`) entre dos corridas sucesivas, si ya había sido vinculada en la corrida anterior? [Gap]

## Edge Case Coverage

- [x] CHK014 - ¿Está definido el comportamiento cuando una publicación no tiene ningún SKU cargado en
  Mercado Libre? [Edge Case, Spec §Edge Cases]
- [x] CHK015 - ¿Está definido el comportamiento para publicaciones cerradas/finalizadas? [Edge Case, Spec
  §FR-003] — excluidas explícitamente.
- [ ] CHK016 - ¿Está definido qué pasa si el `scroll_id` devuelto por la API deja de ser válido a mitad del
  recorrido (expiró por tardar demasiado la corrida)? [Gap, Edge Case]

## Non-Functional Requirements

- [x] CHK017 - ¿Está documentado el rate limit real de Mercado Libre y por qué el volumen de esta corrección
  no lo compromete? [Non-Functional, research.md R5]
- [x] CHK018 - ¿Especifica la spec que la corrida sigue siendo síncrona (sin cola/background) pese al
  volumen real? [Clarity, Spec §Clarifications] — confirmado explícitamente por el usuario.

## Dependencies & Assumptions

- [x] CHK019 - ¿Está documentada la dependencia de que el catálogo en vivo reemplaza por completo a
  `ml_orden_items` como fuente del SKU, y la consecuencia de que este mecanismo específico deja de usar
  datos de órdenes? [Dependency, Spec §FR-009]
- [x] CHK020 - ¿Está validado contra datos reales (no sólo declarado como supuesto) que el modo `scan` del
  buscador de Mercado Libre funciona y no tiene el tope de 1000 del paginado clásico? [Assumption,
  research.md R1 — verificado en vivo contra la cuenta real conectada]
- [ ] CHK021 - ¿Está documentado qué pasa si el vendedor conectado no tiene ninguna publicación (catálogo
  vacío) — se distingue de un fallo de la consulta? [Gap, Assumption]

## Notes

- Ítems marcados `[x]` ya están resueltos por el texto de spec.md/research.md/plan.md/data-model.md tal
  como quedaron redactados en esta ronda.
- Ítems sin marcar (`[ ]`) son huecos genuinos de bajo-medio impacto (no bloquean planificación ni
  justifican una nueva ronda de `/speckit-clarify`): CHK003/CHK010 (sin SLA de tiempo máximo, aceptado
  explícitamente por el usuario como "varios minutos sin problema" — no hace falta un número exacto),
  CHK013 (cambio de estado entre corridas — el vínculo ya creado no se retoca, mismo criterio FR-008, así
  que es inocuo aunque no esté enunciado explícitamente), CHK016 (expiración de `scroll_id` — cae dentro del
  mismo tratamiento genérico de "la corrida falla a mitad de camino", no necesita un caso aparte), CHK021
  (catálogo vacío — se comporta igual que "0 publicaciones pendientes", ya cubierto implícitamente por el
  mismo resumen `total:0` que hoy). Quedan como notas para `/speckit-tasks`/implementación con esos
  defaults razonables.
