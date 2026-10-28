# Checklist de requisitos: integración con Mercado Libre (spec 116)

**Purpose**: Validar la calidad de los requisitos de integración con ML: excepción al corte de precios, consistencia tipo/precio, fallas de la API, frecuencia y costo de llamadas.
**Created**: 2026-10-28
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md)
**Profundidad**: estándar · **Audiencia**: revisor del PR (defaults: la cadena no interrumpe al usuario)

## Requirement Completeness

- [x] CHK001 - ¿Están definidos los dos sentidos del cambio detectado (Premium → Clásica y Clásica → Premium) con su lista de precios destino? [Completeness, Spec §FR-004, §FR-005]
- [x] CHK002 - ¿Está especificado qué precio se usa cuando el producto no tiene precio en la lista Premium? [Completeness, Spec §US2-3, §Assumptions]
- [x] CHK003 - ¿Está definido qué pasa con una retención de precio abierta cuando se detecta un cambio? [Completeness, Spec §FR-007]
- [x] CHK004 - ¿Se define qué se registra en el historial por cada cambio detectado, incluido el origen y la ausencia de usuario? [Completeness, Spec §FR-010]
- [x] CHK005 - ¿Están especificados los requisitos del caso "vínculo sin producto o sin precio en ninguna lista"? [Completeness, Spec §Edge Cases] *(agregado en esta revisión)*
- [x] CHK006 - ¿Se define qué sigue validándose al subir a Premium, para que el arreglo de la baja no afloje la subida? [Completeness, Spec §FR-003]

## Requirement Clarity

- [x] CHK007 - ¿Está acotada sin ambigüedad la tercera excepción al corte (cuándo aplica y cuándo no)? [Clarity, Spec §FR-006]
- [x] CHK008 - ¿Es explícito qué cuenta como "cambio detectado" frente a la primera clasificación y a tipos fuera de alcance? [Clarity, Spec §FR-004, §Edge Cases]
- [x] CHK009 - ¿Está cuantificada la frecuencia de la revisión y medida desde una referencia precisa? [Clarity, Spec §FR-012]
- [x] CHK010 - ¿Está definido el texto o contenido del aviso de cuotas (qué cuotas se nombran, también sin campaña)? [Clarity, Spec §FR-016, §US4]

## Requirement Consistency

- [x] CHK011 - ¿Es consistente FR-006 (envío sin corte) con la regla vigente de §3.2.ter.septies, y está prevista su actualización? [Consistency, Conflict resuelto en Plan §Constitution Check]
- [x] CHK012 - ¿Son consistentes FR-008 (no consolidar si está bloqueado) y FR-009 (consolidar si ML rechaza el precio)? Distinguen "no se pudo intentar" de "se intentó y falló". [Consistency, Spec §FR-008, §FR-009]
- [x] CHK013 - ¿Es consistente el cambio desde el CRM (spec 115) con la detección, sin doble registro ni cambios "consumidos"? [Consistency, Spec §FR-011, §FR-011a]
- [x] CHK014 - ¿Coinciden los resultados del historial para origen ML con los resultados existentes de la spec 115? [Consistency, Spec §FR-010, Data Model]

## Acceptance Criteria Quality

- [x] CHK015 - ¿Son medibles los criterios de éxito de tiempo de corrección del precio (en función de la frecuencia)? [Measurability, Spec §SC-002, §SC-003]
- [x] CHK016 - ¿Se puede verificar objetivamente SC-001 ("cero bloqueos cuando ML acepta")? [Measurability, Spec §SC-001]

## Scenario Coverage

- [x] CHK017 - ¿Están cubiertos los flujos de excepción: corte activo, candado tomado, ML rechaza el precio, ML rechaza el cambio de tipo? [Coverage, Spec §US1-3, §US2-5, §US2-6]
- [x] CHK018 - ¿Está cubierta la recuperación de un cambio postergado (se reintenta en la próxima corrida)? [Recovery, Spec §FR-008]
- [x] CHK019 - ¿Está cubierto el lote de la spec 115 frente a publicaciones ya cambiadas en ML? [Coverage, Spec §Edge Cases] *(agregado en esta revisión)*

## Edge Case Coverage

- [x] CHK020 - ¿Se tratan los cambios dobles entre corridas, las publicaciones pausadas o cerradas y los varios vínculos de un mismo producto? [Edge Case, Spec §Edge Cases, §FR-015a]
- [x] CHK021 - ¿Se define el comportamiento con la integración en sólo lectura o desactivada, separando lectura de escritura? [Edge Case, Spec §FR-010a]

## Non-Functional Requirements

- [x] CHK022 - ¿Está estimado el costo de llamadas a la API por corrida y por día, y justificado contra la cuota? [Non-Functional, Spec §Assumptions, Research R6]
- [x] CHK023 - ¿Hay un requisito que impida multiplicar consultas cuando el stock está bloqueado y el cron reintenta cada minuto? [Non-Functional, Spec §FR-012, §US3-2]
- [x] CHK024 - ¿Está pedido que la revisión de tipos no altere el resultado de la corrida de stock? [Non-Functional, Spec §FR-013]

## Dependencies & Assumptions

- [x] CHK025 - ¿Está explicitada como no verificada la suposición de que la API acepta la baja a Clásica, con su mitigación? [Assumption, Spec §Assumptions, §FR-002]
- [x] CHK026 - ¿Está documentada la evidencia de que la consulta de tipos disponibles para bajar no es confiable? [Dependency, Spec §Contexto, Research R1]
- [x] CHK027 - ¿Queda explícito lo que está fuera de alcance (pantalla de historial, notificaciones, Tiendanube, primera clasificación)? [Scope, Spec §Assumptions]

## Notes

- Revisión en una pasada; dos huecos (CHK005, CHK019) se cerraron agregando edge cases a la spec.
