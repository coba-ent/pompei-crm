# Checklist de calidad de requisitos: Integridad de dinero y tesorería

**Purpose**: Validar que los requisitos de la spec 110 estén completos, claros y sin contradicciones
**antes** de implementar, con foco en el área de mayor riesgo: saldos de tesorería y de cuenta
corriente.

**Created**: 2026-09-24
**Feature**: [spec.md](../spec.md)
**Foco**: integridad de dinero (principio IV de la constitución: testing donde hay dinero)
**Audiencia**: revisor de la spec, antes de `/speckit-tasks`

> Estos ítems evalúan **cómo están escritos los requisitos**, no si el código funciona. La
> validación funcional vive en [quickstart.md](../quickstart.md).

## Completitud de los requisitos

- [x] CHK001 — ¿Está especificado qué importe se guarda en el registro del cobro (recibido vs neto)? [Completeness, data-model §1]
- [x] CHK002 — ¿Está definido el signo del movimiento de vuelto? [Completeness, research §2]
- [x] CHK003 — ¿Están definidos los tres casos de edición (tenía y sigue / no tenía y ahora sí / tenía y ahora no)? [Completeness, contracts §PUT]
- [x] CHK004 — ¿Está especificado qué pasa con el recibo imprimible cuando hay vuelto? [Completeness, Spec §FR-016]
- [x] CHK005 — ¿Están definidos los mensajes de error de cada validación de rechazo? [Completeness, contracts §Errores]
- [x] CHK006 — ¿Está documentado el comportamiento cuando no hay cuenta de vuelto configurada? [Completeness, Spec §US2-3]

## Claridad y ausencia de ambigüedad

- [x] CHK007 — ¿El término "vuelto" está definido sin ambigüedad respecto de "saldo a favor" y "devolución"? [Clarity, Spec §Contexto + §Fuera de alcance]
- [x] CHK008 — ¿La relación entre "recibido", "vuelto" y "neto" está expresada como fórmula verificable? [Measurability, contracts §Convención]
- [x] CHK009 — ¿Está explícito que esta spec NO habilita sobrepagos? [Clarity, research §6]
- [x] CHK010 — ¿Está aclarado que la conversión de moneda ocurre fuera del sistema? [Clarity, Spec §Assumptions]

## Consistencia entre requisitos

- [x] CHK011 — ¿FR-002 (imputar el neto) y FR-007 (el neto salda la venta) son consistentes entre sí? [Consistency]
- [x] CHK012 — ¿Los escenarios de aceptación de US1 coinciden con las reglas de FR-006/007? [Consistency, Spec §US1]
- [x] CHK013 — ¿La decisión de no tocar la fórmula de saldo es consistente con imputar el neto? [Consistency, research §5]
- [x] CHK014 — ¿El contrato de la API usa la misma convención de nombres que el modelo de datos? [Consistency, contracts §Convención]

## Cobertura de escenarios

- [x] CHK015 — ¿Hay requisitos para el flujo principal (cobranza con vuelto)? [Coverage, Spec §US1]
- [x] CHK016 — ¿Hay requisitos para el flujo sin vuelto (compatibilidad hacia atrás)? [Coverage, Spec §FR-014]
- [x] CHK017 — ¿Hay requisitos para editar y para anular? [Coverage, Spec §US3]
- [x] CHK018 — ¿Está cubierto el caso de vuelto desde la misma cuenta del ingreso? [Coverage, Spec §Edge Cases]
- [x] CHK019 — ¿Está cubierto el caso de cuenta de vuelto desactivada o eliminada? [Coverage, Spec §Edge Cases]

## Integridad del dinero (crítico — principio IV)

- [x] CHK020 — ¿Está especificada la atomicidad de los dos movimientos? [Completeness, Spec §FR-008]
- [x] CHK021 — ¿Está identificado el riesgo de que anular deje un movimiento vivo (saldo fantasma)? [Risk, plan §Riesgo principal]
- [x] CHK022 — ¿Está definida la barrera técnica contra ese riesgo (filtrar el morph por tipo)? [Completeness, data-model §4]
- [x] CHK023 — ¿Hay un criterio verificable de que los saldos históricos no cambian? [Measurability, Spec §SC-006 + quickstart §4.2]
- [x] CHK024 — ¿Está especificado que el vuelto queda fuera del informe de Gastos, y cómo se garantiza? [Completeness, Spec §FR-005 + research §1]

## Criterios de aceptación medibles

- [x] CHK025 — ¿Los criterios de éxito son verificables sin conocer la implementación? [Measurability, Spec §SC-001..006]
- [x] CHK026 — ¿SC-004 (saldos coinciden peso por peso) tiene un procedimiento de verificación? [Measurability, quickstart §3.3]
- [x] CHK027 — ¿Cada FR tiene al menos un escenario de aceptación o paso de validación asociado? [Traceability]

## Supuestos y dependencias

- [x] CHK028 — ¿Está documentado el supuesto de que el vuelto es siempre inmediato? [Assumption, Spec §Assumptions]
- [x] CHK029 — ¿Está documentado que los tests en SQLite no validan el ENUM? [Dependency, plan §Riesgo secundario]
- [x] CHK030 — ¿Está documentado el impacto de la migración sobre datos de producción? [Dependency, data-model §5]

## Hallazgos de esta pasada

La revisión encontró **tres huecos**, todos corregidos antes de cerrar el checklist:

1. **CHK004 — El recibo imprimible no estaba contemplado** (hallazgo más importante). La spec
   original no decía nada sobre `reciboCobranza`, que imprime `$cobro->monto`. Como esa columna pasa
   a guardar el **neto**, un cliente que entregó $155.000 habría recibido un comprobante que dice
   $140.000 — un documento que se le entrega en mano al cliente y que contradice la operación real.
   Se agregó **FR-016** y su contrato.

2. **CHK001 — La semántica de `cobros.monto` era implícita.** Que la columna guarde el neto y no el
   recibido es contraintuitivo y, sin documentarlo, el primero que lea la tabla va a malinterpretar
   los datos. Se documentó de forma prominente en `data-model.md` §1 con su rationale.

3. **CHK009 — Riesgo de malentendido de alcance.** El pedido original era "que la validación no me
   lo impida", lo que sugiere habilitar sobrepagos. La clarificación de FR-007 lo volvió **más
   estricto**, no menos. Se explicitó en `research.md` §6 para que nadie implemente sobrepagos por
   inercia del pedido original.

## Notes

- 30/30 ítems pasan. Los requisitos están listos para `/speckit-tasks`.
- **Pendiente obligatorio antes de `tasks`** (principio I de la constitución): actualizar
  `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` con el tipo `vuelto`, las columnas
  nuevas y la regla del neto.
