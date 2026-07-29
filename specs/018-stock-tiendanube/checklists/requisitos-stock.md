# Requisitos Checklist: Sincronización de stock del CRM hacia Tiendanube

**Purpose**: Validar la calidad de los requisitos de la spec 018 — consistencia de patrón con la spec 013
(Mercado Libre), cobertura de anti-rebote/consolidación/concurrencia, y tratamiento explícito de la
dependencia de secuencia con la spec 017 — antes de generar `tasks.md`.
**Created**: 2026-07-29
**Feature**: [spec.md](../spec.md)

**Note**: Generado por `/speckit-checklist`. Enfoque: consistencia con la 013, anti-rebote/consolidación/
concurrencia, dependencia de secuencia con la 017 (ver user input de esta invocación).

## Consistencia de patrón con la spec 013

- [x] CHK001 - ¿Cada requisito que replica una decisión ya tomada por la spec 013 cita explícitamente el
      FR/mecanismo equivalente de esa spec, en vez de re-derivar la regla desde cero? [Consistency, Spec §Clarifications, §FR-001-FR-020]
- [x] CHK002 - ¿Están alineados los identificadores FR-### con los de la spec 013 cuando el requisito es
      el mismo o una adaptación directa, de forma que ambas specs sean comparables lado a lado? [Consistency, Spec §Requirements]
- [x] CHK003 - ¿Documenta la spec, para cada punto donde diverge de la 013 (vinculación por variante en
      vez de publicación, límite de tasa distinto, sin OAuth), la razón concreta de la divergencia en vez
      de dejarla implícita? [Clarity, Spec §Contexto y fuentes]

## Anti-rebote (exclusión de bucle Tiendanube → CRM → Tiendanube)

- [x] CHK004 - ¿Especifica el requisito de exclusión de bucle (FR-002) el campo/valor exacto que lo
      determina (origen de la Venta = "tiendanube"), en vez de una descripción ambigua de "que no
      rebote"? [Clarity, Spec §FR-002]
- [x] CHK005 - ¿Cubre la spec el caso en que un mismo producto tuvo, entre dos corridas, tanto un
      movimiento excluido (orden de Tiendanube) como uno elegible (Venta manual), especificando qué
      ocurre en ese cruce? [Coverage, Spec §Edge Cases, US2 Acceptance Scenario 3]
- [x] CHK006 - ¿Es objetivamente verificable el criterio de exclusión (SC-002), o depende de una
      interpretación subjetiva de "redundante"? [Measurability, Spec §SC-002]

## Consolidación (un único envío por vínculo por corrida)

- [x] CHK007 - ¿Especifica la spec, sin ambigüedad, que el valor enviado es "el stock actual al momento
      de la corrida" y no un acumulado o delta de movimientos? [Clarity, Spec §FR-003]
- [x] CHK008 - ¿Cubre la spec el caso límite de movimientos que se cancelan entre sí (el valor final
      coincide con el último enviado), indicando si igual se reenvía o se omite? [Edge Case, Spec §FR-003]
- [x] CHK009 - ¿Es medible el criterio de consolidación (SC-003) de forma que una prueba automatizada
      pueda contar solicitudes reales contra la API y compararlas con la cantidad de movimientos? [Measurability, Spec §SC-003]

## Concurrencia y candados

- [x] CHK010 - ¿Especifica la spec que el candado de la sincronización de stock de Tiendanube es
      independiente del candado de sincronización de órdenes de Tiendanube y del candado de stock de
      Mercado Libre, evitando bloqueos cruzados no intencionados entre integraciones distintas? [Consistency, Spec §FR-008]
- [x] CHK011 - ¿Define la spec el comportamiento observable (no sólo interno) cuando el usuario dispara
      una segunda sincronización manual mientras la primera sigue en curso? [Completeness, Spec §US3 Acceptance Scenario 3]
- [x] CHK012 - ¿Se documenta qué ocurre si el proceso que ejecuta la sincronización de stock se interrumpe
      a mitad de camino (caída del proceso, no sólo "corrida programada")? [Gap, Edge Cases]

## Dependencia de secuencia con la spec 017

- [x] CHK013 - ¿Deja la spec (o su plan asociado) constancia explícita de que la infraestructura de la
      que depende (`tn_variante_producto`, controladores de Ingresos → Tiendanube) debe existir en código
      antes de que ésta pueda implementarse, y no sólo estar especificada? [Dependency, Plan §"Advertencia de secuencia de implementación"]
- [x] CHK014 - ¿Está identificado con precisión qué atributo de la vinculación variante↔producto no
      capturaba la spec 017 y que ésta necesita agregar (`tn_product_id`), en vez de asumir tácitamente
      que ya existe? [Completeness, Research R6, Data-model §tn_variante_producto]
- [x] CHK015 - ¿Especifica la spec qué hacer si, en el futuro, un vínculo variante↔producto quedara sin
      `tn_product_id` completo (por ejemplo por un cambio manual en la base) al momento de sincronizar
      stock? [Gap, Edge Case]

## Rechazos y manejo de errores por vínculo

- [x] CHK016 - ¿Enumera la spec los motivos concretos de rechazo no transitorio que debe distinguir de
      un rechazo transitorio (límite de tasa, falla de red), en vez de tratarlos todos igual? [Clarity, Spec §FR-014]
- [x] CHK017 - ¿Es verificable de forma objetiva que un rechazo de un vínculo no afecta el resultado de
      los demás vínculos de la misma corrida (SC-006)? [Measurability, Spec §SC-006]
- [x] CHK018 - ¿Define la spec un tope máximo de reintentos o de tiempo de espera creciente antes de
      marcar definitivamente un envío como error, o queda abierto sin límite explícito? [Gap, Spec §FR-013]

## Visibilidad y retención

- [x] CHK019 - ¿Especifica la spec el nivel mínimo de detalle que debe mostrarse por vínculo con error
      (motivo + fecha), de forma consistente con lo ya exigido para órdenes que requieren atención? [Consistency, Spec §FR-017]
- [x] CHK020 - ¿Queda claro que no se requiere una tabla de historial propia más allá del historial de
      operaciones ya existente, evitando ambigüedad sobre dónde vive el estado de retención? [Clarity, Spec §FR-020]

## Notes

- CHK012, CHK015 y CHK018 se cerraron agregando edge cases explícitos y el requisito **FR-005a** al
  `spec.md` (interrupción a mitad de camino, reintentos sin tope adicional al ya fijado por FR-014, y
  vínculo con `tn_product_id` incompleto tratado como error de datos propio).
- Los 20 ítems del checklist pasan contra el contenido actual de `spec.md`, `plan.md`, `research.md` y
  `data-model.md`.
