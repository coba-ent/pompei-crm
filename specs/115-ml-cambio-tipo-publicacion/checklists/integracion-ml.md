# Checklist de calidad de requisitos: integración ML (precios, orden de operaciones, corte 084, lote y tabla)

**Purpose**: validar que los requisitos de la spec 115 estén completos, claros y consistentes antes de `tasks`
**Created**: 2026-10-15
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md)
**Profundidad**: estándar · **Audiencia**: revisor del PR / autor

## Requirement Completeness

- [x] CHK001 - ¿Se define qué lista de precios corresponde a cada tipo destino, sin ambigüedad sobre el fallback? [Completeness, Spec §FR-005, Research R4]
- [x] CHK002 - ¿Se especifica qué pasa con la publicación si el segundo paso del cambio falla, en ambos sentidos? [Completeness, Spec §FR-008]
- [x] CHK003 - ¿Se define el rollback cuando no hay precio anterior conocido para restituir (→Premium con tipo rechazado)? [Gap → resuelto, Spec §FR-008]
- [x] CHK004 - ¿Se documenta qué se registra de cada intento (usuario, tipos, precios, resultado) y dónde? [Completeness, Spec §FR-012, Data Model]
- [x] CHK005 - ¿Están definidos los resultados posibles de un intento y su significado? [Completeness, Data Model `resultado`]
- [x] CHK006 - ¿Se especifica qué muestra la confirmación (individual y lote), incluido el aviso de comisión? [Completeness, Spec §FR-003, §FR-004, §FR-014]

## Requirement Clarity

- [x] CHK007 - ¿Está explícito el orden de operaciones para cada sentido? [Clarity, Spec §FR-008]
- [x] CHK008 - ¿Es claro cuándo la acción se ofrece como "Pasar a Premium", "Pasar a Clásica" o "Cambiar tipo"? [Clarity, Spec §FR-002]
- [x] CHK009 - ¿Se distingue "omitida" (anticipada en la confirmación) de "fallida" (rechazada al ejecutar) en el lote? [Clarity → resuelto, Spec §FR-014]
- [x] CHK010 - ¿Está acotado el tamaño máximo de una selección? [Clarity → resuelto, Spec §FR-015b]

## Requirement Consistency

- [x] CHK011 - ¿Es consistente FR-009 (bajada que saltea el corte) con el principio de spec 084 "ninguna bajada sin que una persona la haya visto", y quedó documentado en la doc de dominio? [Consistency, Spec §FR-009, doc §3.2.ter.septies]
- [x] CHK012 - ¿Es consistente FR-005 (sin fallback) con spec 050 FR-008 (fallback a la lista general en la sincronización normal), con el alcance de cada uno explicitado? [Consistency, Research R4, doc §3.2.ter.octies]
- [x] CHK013 - ¿Coinciden el registro de FR-012 y la tabla de historial del data model? [Conflict → resuelto, Spec §FR-012]
- [x] CHK014 - ¿La confirmación del lote promete sólo lo que puede saber sin consultar a ML por cada publicación? [Conflict → resuelto, Spec §FR-014 vs Contracts §3]
- [x] CHK015 - ¿Se usa la misma terminología de tipos (Clásica / Premium / Sin clasificar) en spec, contratos y doc? [Consistency]

## Acceptance Criteria Quality

- [x] CHK016 - ¿SC-002 y SC-003 se pueden verificar objetivamente (chequeo CRM ↔ API, historial)? [Measurability, Spec §SC-002, §SC-003]
- [x] CHK017 - ¿SC-004 fija volumen y tiempo concretos para el lote? [Measurability, Spec §SC-004]

## Scenario & Edge Case Coverage

- [x] CHK018 - ¿Se cubre el tipo desactualizado en el CRM (cambiado desde ML)? [Coverage, Spec Edge Cases, §FR-006]
- [x] CHK019 - ¿Se cubre la publicación sin precio publicado conocido (corte "sin referencia")? [Coverage, Spec §FR-015c, Research R3]
- [x] CHK020 - ¿Se cubre una retención de precio abierta sobre la publicación? [Coverage, Spec §FR-009]
- [x] CHK021 - ¿Se cubre la concurrencia con otras sincronizaciones de precio y con otro lote? [Coverage, Spec §FR-011, §FR-015a]
- [x] CHK022 - ¿Se cubren los cortes de función desactivada / sólo lectura / sin cuenta? [Coverage, Spec §FR-011]
- [x] CHK023 - ¿Se cubren tipos fuera de alcance (Gratuita) y vínculos sin producto? [Coverage, Clarifications, Contracts §1]
- [x] CHK024 - ¿Se cubre que precio destino = precio publicado (sólo cambia el tipo)? [Edge Case, Spec Edge Cases]

## Tabla de Vinculaciones

- [x] CHK025 - ¿Se define cómo se identifica el producto del CRM (ID, código, nombre) y por qué campos se busca? [Completeness, Spec §FR-018]
- [x] CHK026 - ¿Se define la persistencia del filtro de tipo y su resolución del lado del servidor? [Clarity, Spec §FR-017]
- [x] CHK027 - ¿Las columnas nuevas (selección, tipo) respetan las reglas obligatorias de tablas (colvis sin índices, columnas fijas)? [Consistency, Research R7, CLAUDE.md #1]

## Dependencies & Assumptions

- [x] CHK028 - ¿Está documentada la fuente y fecha de la verificación de la API de ML usada? [Dependency, Research R1]
- [x] CHK029 - ¿Está explicitado que las pruebas funcionales no van contra la cuenta real del cliente? [Assumption, Spec Assumptions, Quickstart]
- [x] CHK030 - ¿Se dejó registrado el riesgo conocido del contrato de precios de ML como fuera de alcance? [Assumption, Research R8]

## Notes

- CHK003, CHK009, CHK010, CHK013 y CHK014 fallaban en la primera pasada; se corrigieron en `spec.md`
  (FR-008, FR-012, FR-014, FR-015b) antes de pasar a `tasks`.
