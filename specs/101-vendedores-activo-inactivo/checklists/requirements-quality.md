# Checklist: Calidad de requisitos — Vendedores activo/inactivo

**Purpose**: Validar que los requisitos de spec.md están completos, no ambiguos, consistentes y
medibles antes de pasar a tasks/implementación.
**Created**: 2026-09-08
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado el valor inicial de estado para vendedores ya existentes al momento del cambio, no sólo para altas nuevas? [Completeness, Spec §FR-001]
- [x] CHK002 - ¿Está enumerada explícitamente la lista completa de puntos de consumo (selects) que deben excluir vendedores inactivos, en vez de una descripción genérica? [Completeness, Spec §FR-003]
- [x] CHK003 - ¿Está definido qué pasa con el buscador inline de Venta/Presupuesto (spec 020) respecto del estado, o sólo se cubre la pantalla de gestión nueva? [Completeness, Spec §FR-007]
- [x] CHK004 - ¿Están definidos requisitos de permisos/rol para quién puede cambiar el estado de un vendedor? [Gap, Spec §Assumptions]

## Requirement Clarity

- [x] CHK005 - ¿Está definida sin ambigüedad la diferencia entre "eliminar" (FR-008) y "desactivar" (FR-002), incluyendo cuándo corresponde cada una? [Clarity, Spec §FR-002, §FR-008]
- [x] CHK006 - ¿Está cuantificado "no figuren en los listados" (pedido original) con la lista concreta de listados afectados, en vez de quedar como término vago? [Clarity, Spec §FR-003]
- [x] CHK007 - ¿Es medible el criterio de "sin recargar la página" para las operaciones de alta/edición/cambio de estado? [Measurability, Spec §FR-006, §SC-004]

## Requirement Consistency

- [x] CHK008 - ¿Es consistente el comportamiento de exclusión de inactivos entre los distintos puntos de asignación (Venta, Presupuesto, Tiendanube, MercadoLibre, Vendedor por defecto)? [Consistency, Spec §FR-003]
- [x] CHK009 - ¿Es consistente la regla de unicidad de nombre (FR-009) con la posibilidad de reactivar un vendedor inactivo, sin conflicto entre ambas reglas? [Consistency, Spec §FR-009]

## Scenario Coverage

- [x] CHK010 - ¿Están cubiertos los tres escenarios principales (desactivar sin romper historial, gestión centralizada, vendedor por defecto inactivo) con al menos un criterio de aceptación cada uno? [Coverage, Spec §User Story 1-3]
- [x] CHK011 - ¿Está definido el comportamiento cuando todos los vendedores están inactivos (select de asignación vacío)? [Edge Case, Spec §Edge Cases]
- [x] CHK012 - ¿Está definido el comportamiento de reactivación repetida (activar/desactivar varias veces) sin efectos secundarios? [Edge Case, Spec §Edge Cases]

## Non-Functional Requirements

- [x] CHK013 - ¿Especifica la spec el estándar de notificación (toast) y ausencia de recarga como requisito no funcional de UX, en línea con el resto del proyecto? [Completeness, Spec §FR-006]
- [x] CHK014 - ¿Está definido un límite de tiempo o volumen esperado para la carga de la tabla de vendedores en el nuevo tab? [Gap, Spec §Assumptions]

## Dependencies & Assumptions

- [x] CHK015 - ¿Está documentada la dependencia de que la nueva pantalla es una divergencia deliberada respecto de Contagram real, con la justificación correspondiente? [Traceability, Spec §Assumptions]
- [x] CHK016 - ¿Está documentado que los informes/reportes que usan Vendedor como dimensión no se ven afectados por esta feature? [Assumption, Spec §Assumptions]

## Notes

- Todos los ítems pasan. CHK004 y CHK014 se cerraron agregando dos supuestos explícitos a
  `spec.md §Assumptions` (rol Admin heredado de spec 043; catálogo chico sin límite de
  rendimiento explícito, igual que Depósitos).
