# Riesgos Checklist: Sincronización de stock del CRM hacia Mercado Libre

**Purpose**: Validar la calidad de los requisitos (no la implementación) en los seis riesgos propios de
esta feature: prevención de bucle, consolidación de movimientos, piso en cero, no concurrencia,
continuidad ante rechazo puntual, y respeto del kill-switch de sólo lectura.
**Created**: 2026-07-28
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md) · [research.md](../research.md)

**Note**: Generado por `/speckit-checklist`. Foco: reviewer de spec/plan antes de `/speckit-tasks`,
profundidad estándar (los seis riesgos indicados por el usuario + huecos de cobertura detectados).

## Prevención de bucle (orden de Mercado Libre → no rebota)

- [x] CHK001 - ¿Está definido el mecanismo exacto para distinguir un movimiento originado en una orden de
  Mercado Libre de cualquier otro? [Clarity, Spec §FR-002, Clarifications Q2]
- [x] CHK002 - ¿Existe un escenario de aceptación que cubra la coexistencia de un movimiento excluido
  (orden ML) y uno no excluido (Venta manual) del mismo producto entre dos corridas? [Coverage, Spec
  §User Story 2 AC3]
- [x] CHK003 - ¿Se especifica qué pasa si el movimiento no tiene `origen` alguno (ajuste manual, sin
  Venta asociada)? [Edge Case, Spec §Edge Cases]

## Consolidación de movimientos en un único envío

- [x] CHK004 - ¿Está cuantificado qué significa "un único valor" frente a "un acumulado de movimientos"?
  [Clarity, Spec §FR-003]
- [x] CHK005 - ¿Define la spec si un producto cuyo stock volvió exactamente a su valor original entre dos
  corridas (cambios que se cancelan entre sí) igual debe enviarse, o puede omitirse como no-op? [Gap,
  Spec §FR-003] — **corregido**: FR-003 ahora aclara explícitamente que se envía igual, sin detección de
  no-op.
- [x] CHK006 - ¿Hay un criterio de éxito medible para verificar la consolidación sin instrumentación
  interna (contar llamadas, no movimientos)? [Measurability, Spec §SC-003]

## Piso en cero (nunca cantidad negativa)

- [x] CHK007 - ¿Está definido el valor exacto a publicar cuando el stock local es negativo? [Clarity,
  Spec §FR-004]
- [x] CHK008 - ¿Se distingue explícitamente entre el valor que se muestra dentro del CRM (puede ser
  negativo) y el que se envía a Mercado Libre (nunca negativo)? [Consistency, Spec §FR-004]
- [x] CHK009 - ¿Hay un criterio de éxito verificable al 100% de los casos para el piso en cero? [Measurability,
  Spec §SC-004]

## No concurrencia

- [x] CHK010 - ¿Cubre el requisito de no concurrencia tanto la corrida programada como la manual bajo
  demanda? [Coverage, Spec §FR-008, User Story 3 AC3]
- [x] CHK011 - ¿Especifica la spec qué le pasa a la sincronización descartada por solapamiento (se
  pierde, se reintenta, queda registrada)? [Completeness, Spec §User Story 3 AC3]
- [x] CHK012 - ¿Se documenta si el candado de stock es independiente del candado de la sincronización de
  órdenes, o si comparten el mismo? [Ambiguity, Spec §FR-008] — **corregido**: FR-008 ahora aclara que es
  independiente del de la spec 012 (FR-014).

## Continuidad ante el rechazo de un vínculo puntual

- [x] CHK013 - ¿Define la spec que un rechazo individual no debe interrumpir el procesamiento del resto
  de los vínculos de la misma corrida? [Clarity, Spec §FR-015]
- [ ] CHK014 - ¿Se especifica, a nivel de requisito, el criterio para distinguir un rechazo "transitorio"
  (se reintenta) de uno "definitivo" (se marca error y se sigue)? [Gap, Spec §FR-013/FR-014] — la
  distinción funcional está clara (reintentar vs. marcar error), pero el spec no fija ningún criterio
  propio para clasificar un rechazo como uno u otro; queda delegado enteramente a la implementación
  (research.md §R7), lo que puede volver difícil de verificar el requisito de forma independiente.
- [x] CHK015 - ¿Existe un requisito sobre qué pasa con un vínculo que sigue fallando en corridas
  sucesivas (se reintenta indefinidamente, se lo excluye)? [Completeness, Spec §FR-014, User Story 4 AC4]

## Kill-switch de sólo lectura y disponibilidad

- [x] CHK016 - ¿Está definido que la sincronización de stock es una operación de escritura y por lo tanto
  sujeta al kill-switch? [Clarity, Spec §FR-009]
- [x] CHK017 - ¿Es consistente el tratamiento de "cambios pendientes" entre el corte por modo sólo
  lectura/función desactivada (FR-009) y el corte por conexión caída (FR-010)? [Consistency, Spec
  §FR-009 vs §FR-010] — **corregido**: FR-009 ahora repite explícitamente la garantía de conservación de
  pendientes.
- [x] CHK018 - ¿Hay un criterio de éxito verificable para "cero envíos" bajo kill-switch activo?
  [Measurability, Spec §SC-005]

## Visibilidad y trazabilidad

- [x] CHK019 - ¿Especifica la spec qué estados de sincronización debe distinguir el usuario en pantalla?
  [Completeness, Spec §FR-017]
- [x] CHK020 - ¿El requisito de visibilidad (FR-017) incluye mostrar el motivo y la fecha del último
  rechazo, tal como lo exige el escenario de aceptación correspondiente? [Consistency, Spec §FR-017 vs
  User Story 4 AC3] — **corregido**: FR-017 ahora exige explícitamente motivo y fecha del último rechazo.
- [x] CHK021 - ¿Se define dónde queda registrado cada intento de envío para auditoría, sin duplicar la
  necesidad de una tabla nueva? [Completeness, Spec §FR-016, §Key Entities]

## Dependencias y supuestos

- [x] CHK022 - ¿Está documentado y justificado el supuesto de que el depósito configurado para Mercado
  Libre es la única fuente de stock a publicar? [Assumption, Spec §Clarifications, §Assumptions]
- [x] CHK023 - ¿Se documenta explícitamente que el disparador (Compras) puede no estar generando
  movimientos todavía, y que eso no invalida el alcance de la spec? [Dependency, Spec §Assumptions]
- [x] CHK024 - ¿Están enumeradas las specs de las que depende ésta, con su estado (implementada)?
  [Traceability, Spec §Dependencies]

## Notes

- 23/24 ítems en verde tras una iteración: se corrigieron directamente en `spec.md` los 4 hallazgos
  reales (CHK005, CHK012, CHK017, CHK020) — no se difieren a `/speckit-analyze`, siguiendo el principio I
  de la constitución (no dejar inconsistencias detectadas en silencio).
- **CHK014 queda deliberadamente sin marcar**: la distinción entre rechazo transitorio y definitivo tiene
  un criterio de negocio razonable en FR-013/FR-014 (exceso de solicitudes/fallas temporales vs.
  publicación pausada/cerrada/inexistente/"otro rechazo definitivo"), pero ese último término es un
  catch-all no cerrado por diseño — no es enumerable a nivel de negocio sin enumerar códigos HTTP
  (detalle de implementación, ya resuelto en `research.md §R7` reutilizando `ClienteMercadoLibre`). Se
  acepta como ambigüedad residual normal de una spec a este nivel, mismo criterio que la spec 012 aplicó
  a "número acotado" de reintentos (FR-020).
