# Specification Quality Checklist: Historial de importaciones — archivo e informe

**Purpose**: Validar la spec antes de planificar
**Created**: 2026-08-28
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notas de la validación

Tres correcciones aplicadas antes de cerrar:

1. **"Qué hizo la importación" era una promesa que el dato no puede sostener.** El snapshot guarda
   el estado anterior; el posterior no existe en ningún lado. Corregido: el informe mide **"qué
   cambió desde la importación"**, lo dice en su título y marca las filas con actividad posterior.
   Un informe que le atribuye a la importación un movimiento que hizo una venta es peor que no tener
   informe.
2. **Faltaba distinguir "sin detalle" de "sin cambios".** La corrida 1 tiene 0 filas de snapshot;
   presentarla como "0 cambios" haría parecer inofensiva una importación de la que no se sabe nada.
   Agregados FR-007 y el escenario 4 de la US1. Lo mismo para el archivo: "nunca se guardó" y
   "venció" son estados distintos (FR-015).
3. **SC-001 no era verificable.** Decía "se puede rastrear una importación". Reescrito contra el caso
   real que originó la spec: llegar al −181 de EMBALAJE JPD desde el historial, sin consultar la base.

**Sobre nombrar tablas y columnas en la spec**: aparecen `importacion_filas_snapshot` y sus columnas
JSON. Se dejó a propósito — no es un detalle de implementación filtrado, sino el hecho que define el
tamaño de la feature (los datos ya existen) y la trampa concreta que hizo fallar el primer intento
de armar el informe a mano (el formato del JSON).
