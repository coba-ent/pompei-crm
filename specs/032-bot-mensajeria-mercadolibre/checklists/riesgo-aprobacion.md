# Riesgo y Aprobación Humana Checklist: Mensajería de Mercado Libre (lectura y respuesta manual)

**Purpose**: Validar que los requerimientos que protegen contra el mayor riesgo de esta feature —
duplicados por reintentos de webhook, doble respuesta a un mismo mensaje, y auditoría de qué se envió y
quién lo hizo — estén completos, claros, consistentes y verificables antes de pasar a tareas.
**Created**: 2026-08-02
**Feature**: [spec.md](../spec.md)

**Foco elegido** (sin input adicional del usuario, derivado del dominio de la feature): el riesgo
dominante no es de dinero sino de **política de Mercado Libre y confianza del negocio** —enviar algo
dos veces puede dañar la relación con compradores o la cuenta real de ML—, así que el checklist se
concentra ahí en vez de en UX general o performance. (Nota: el alcance de IA/bot quedó fuera de esta
spec, ver `spec.md` → Contexto y fuentes — los ítems sobre proveedor de IA se removieron de este
checklist.)

## Requirement Completeness

- [ ] CHK001 - ¿Está especificado qué pasa si el usuario cierra la conversación sin confirmar el envío
      (se pierde el borrador escrito, o queda guardado como pendiente)? [Gap, Spec §FR-005]
- [ ] CHK002 - ¿Está especificado quién puede ver la auditoría de respuestas enviadas (todos los que
      tienen `mensajeria.ver`, o un permiso más restrictivo)? [Gap, Spec §FR-010]
- [ ] CHK003 - ¿Están definidos los requerimientos de qué pasa si Mercado Libre marca como
      `UNDER_REVIEW` una pregunta ya respondida por el sistema? [Gap]

## Requirement Clarity

- [ ] CHK004 - ¿Está cuantificado qué significa "rápido" para la respuesta del webhook (FR-003), más
      allá de la referencia informal a los reintentos de ML? [Ambiguity, Spec §FR-003, research.md R3]
- [ ] CHK005 - ¿Es medible el criterio "mensaje claro" para el error de envío fallido (FR-008), o queda
      a interpretación de quién implemente? [Clarity, Spec §FR-008]

## Requirement Consistency

- [ ] CHK006 - ¿Es consistente el requerimiento de idempotencia (FR-004) con la clave de agrupación de
      conversaciones definida en Key Entities (comprador+publicación para Preguntas, orden para
      post-venta)? [Consistency, Spec §Key Entities, data-model.md]
- [ ] CHK007 - ¿Es consistente FR-007 (impedir doble respuesta) con el Edge Case de condición de carrera
      entre dos usuarios (gana el primer envío confirmado)? [Consistency, Spec §Edge Cases]

## Acceptance Criteria Quality

- [ ] CHK008 - ¿SC-002 ("0% de respuestas enviadas sin confirmación humana") es objetivamente
      verificable con la auditoría definida en FR-006, o hace falta un campo adicional para probarlo?
      [Measurability, Spec §SC-002/FR-006]
- [ ] CHK009 - ¿SC-003 ("0% de duplicados") especifica sobre qué universo se mide (por conversación, por
      mensaje, por notificación recibida)? [Clarity, Spec §SC-003]

## Edge Case Coverage

- [ ] CHK010 - ¿Están cubiertos los requerimientos para el caso en que Mercado Libre rechaza el envío
      por su propia moderación de contenido (datos de contacto externos, etc.)? [Coverage, Spec §Edge Cases]

## Non-Functional Requirements

- [ ] CHK011 - ¿Están especificados requerimientos de retención de la auditoría de respuestas enviadas
      (por cuánto tiempo se conserva, si se puede purgar)? [Gap]
- [ ] CHK012 - ¿Está especificado qué pasa con conversaciones si se desconecta la cuenta de Mercado
      Libre (spec 011) mientras hay conversaciones abiertas? [Gap, Dependency]

## Dependencies & Assumptions

- [ ] CHK013 - ¿Está documentada y validada la asunción de que el scope de OAuth de mensajería ya está
      habilitado, incluyendo qué pasa si en producción se detecta que no lo está? [Assumption, Spec §Assumptions]
- [ ] CHK014 - ¿Está documentada la dependencia del endpoint exacto de mensajería post-venta (marcado
      como riesgo abierto en research.md R2) como algo a resolver antes de dar por completada la
      implementación de esa parte, y no sólo como nota informal? [Dependency, research.md R2]

## Notes

- Este checklist no reemplaza los Feature tests listados en `plan.md` (Testing) — es una validación de
  que los *requerimientos* sobre este riesgo están bien escritos, no de que el código funcione.
- Los ítems marcados `[Gap]` son candidatos a resolverse como Assumptions adicionales en `spec.md` o
  quedar explícitamente fuera de alcance antes de `/speckit-tasks`, según impacto.
