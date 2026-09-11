# Checklist: Calidad de requisitos — Columna Punto de Reposición

**Purpose**: Validar que los requisitos de la spec/plan estén completos, claros y sin ambigüedad antes
de pasar a tasks/implementación. Foco: consistencia con el resto del listado dinámico de Productos, y
el caso "0 = sin control" (ya identificado como el punto más frágil de esta feature).
**Created**: 2026-09-10
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificada la posición exacta de la columna nueva respecto a las columnas
      dinámicas existentes (Stock total, stock por depósito, listas de precio)? [Completeness, Spec
      §FR-002, Plan §Structure Decision]
- [x] CHK002 - ¿Está definido el comportamiento quando `punto_reposicion` es `null`/no seteado, además
      del caso `0`? [Completeness, Spec §FR-003] — Resuelto: la columna es `NOT NULL default 0` (spec
      073), no existe el caso `null` en la base.
- [x] CHK003 - ¿Está especificado qué endpoint/mecanismo expone el dato (server-side vs client-side)?
      [Completeness, Plan §Technical Context]

## Requirement Clarity

- [x] CHK004 - ¿Está cuantificado qué significa "el mismo indicador de sin control" que ya usa el modal
      (no queda como una frase vaga)? [Clarity, Spec §FR-003] — Resuelto: referencia explícita al
      `placeholder="Sin control"` ya existente en el modal.
- [x] CHK005 - ¿Está claro si la columna acepta formato/decimales o siempre es un entero puro? [Clarity,
      Spec §FR-004]

## Requirement Consistency

- [x] CHK006 - ¿Es consistente el criterio de "Servicio → sin control" con el criterio ya vigente para
      Servicios en las columnas de Stock (`—` en vez de `0`)? [Consistency, Spec §Edge Cases, Data-model
      §Representación en el listado]
- [x] CHK007 - ¿El plan reutiliza el mismo patrón (`editColumn`) que ya usan `stock_total` y
      `stock_deposito_{id}` en el mismo controller, en vez de introducir un mecanismo distinto?
      [Consistency, Plan §Summary]
- [x] CHK008 - ¿Se verificó que el export a Excel existente y el listado en pantalla no queden
      divergentes entre sí después de este cambio? [Consistency, Plan §Nota de consistencia verificada]

## Acceptance Criteria Quality

- [x] CHK009 - ¿Los criterios de éxito son verificables sin conocer la implementación (no mencionan
      DataTables, Yajra ni nombres de archivo)? [Measurability, Spec §Success Criteria]
- [x] CHK010 - ¿Está definido cómo se objetiviza SC-003 ("sin degradar el tiempo de carga") — hay un
      criterio de comparación (antes/después) en vez de un umbral inventado? [Measurability, Spec
      §SC-003, Plan §Performance Goals]

## Scenario Coverage

- [x] CHK011 - ¿Están cubiertos los tres flujos principales (ver el valor, ordenar, mostrar/ocultar)
      como historias de usuario independientes y testeables? [Coverage, Spec §User Scenarios]
- [x] CHK012 - ¿Está definido el comportamiento de ordenamiento cuando hay múltiples productos con el
      mismo valor de Punto de Reposición (empate)? [Gap] — Sin requisito explícito: se asume el
      comportamiento default de Yajra/DataTables (orden estable por el criterio secundario ya vigente
      en la tabla), consistente con cómo se maneja hoy el empate en `stock_total` y las demás columnas
      numéricas del mismo listado — no amerita un requisito nuevo.

## Non-Functional Requirements

- [x] CHK013 - ¿Está declarado que esta columna no agrega ninguna subquery/JOIN nueva (a diferencia de
      las columnas dinámicas de lista de precio/depósito)? [Completeness, Plan §Performance Goals]

## Dependencies & Assumptions

- [x] CHK014 - ¿Está documentada la dependencia de que `productos.punto_reposicion` y su edición en el
      modal (spec 073) ya funcionan correctamente y no se tocan en esta spec? [Assumption, Spec
      §Assumptions]
- [x] CHK015 - ¿Está identificado el archivo de test existente donde corresponde agregar los casos
      nuevos, en vez de asumir que hay que crear uno? [Dependency, Plan §Testing]

## Ambiguities & Conflicts

- [x] CHK016 - ¿Está resuelta explícitamente la tensión entre "fidelidad estructural a Contagram"
      (principio rector) y el pedido del usuario de agregar una columna que Contagram real no tiene?
      [Conflict, Spec §Contexto de negocio, Plan §Constitution Check] — Resuelto: divergencia
      deliberada y documentada, confirmada con el usuario, con FR-010 exigiendo la actualización de
      `documentacion_principal_crm.md`.

## Notes

- Todos los ítems cerraron en verde en la primera pasada: las dos decisiones de mayor riesgo (posición
  de columna, divergencia con Contagram real) ya se habían resuelto con el usuario antes de escribir la
  spec, y el resto se apoya en patrones ya existentes en el mismo controller (`stock_deposito_*`).
- Ningún ítem quedó abierto — no bloquea el avance a `/speckit-tasks`.
