# Checklist: Calidad de requisitos — Filtros de Compras

**Purpose**: Validar que los requisitos del panel de filtros de Compras están completos, sin ambigüedad y son verificables antes de pasar a tasks/implementación.
**Created**: 2026-08-11
**Feature**: [spec.md](../spec.md)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado el criterio de combinación (AND/OR) entre los 12 filtros y dentro de cada filtro múltiple? [Completeness, Spec §FR-011, Assumptions]
- [x] CHK002 - ¿Está definido qué pasa cuando un filtro de selección múltiple (Proveedor, Categoría, Etiqueta, Usuario) se deja vacío? [Completeness, Spec §FR-002/FR-003]
- [x] CHK003 - ¿Están especificados los 3 valores exactos que debe ofrecer el filtro "Estado del Pago"? [Completeness, Spec §FR-009a, Clarifications]
- [x] CHK004 - ¿Está definido contra qué campos concretos busca "Tipo y N° de Factura" (comprobante interno vs. fiscal)? [Completeness, Spec §FR-005]
- [x] CHK005 - ¿Está especificado el comportamiento del filtro "Facturado" en términos de un dato ya existente del sistema? [Completeness, Spec §FR-006, Assumptions]
- [ ] CHK006 - ¿Está documentado si el selector de columnas visibles tiene una lista cerrada de columnas o es abierta/extensible? [Gap, Spec §FR-010]

## Requirement Clarity

- [x] CHK007 - ¿Está cuantificado "selección múltiple" en términos de operador lógico (OR) en vez de dejarlo como término vago? [Clarity, Spec §FR-002]
- [x] CHK008 - ¿Está aclarado que el control "Vencimiento" es un segundo rango de fechas independiente y no un selector de tipo sobre el rango de Emisión? [Clarity, Spec §FR-009, Clarifications — corregido tras detectar ambigüedad inicial]
- [x] CHK009 - ¿Está definido con precisión qué significa que una compra "coincida" con el filtro Medio de pago cuando tiene múltiples pagos con medios distintos? [Clarity, Spec Edge Cases]

## Requirement Consistency

- [x] CHK010 - ¿Es consistente el criterio de exclusión de registros con fecha nula (Servicio, Vencimiento) entre la sección Edge Cases y los Functional Requirements? [Consistency, Spec §FR-008/FR-009 vs Edge Cases]
- [x] CHK011 - ¿Es consistente el alcance declarado (solo listado/filtros, sin tocar NC/ND) con el resto de la spec? [Consistency, Spec §Assumptions]

## Acceptance Criteria Quality

- [x] CHK012 - ¿Son medibles/verificables los Success Criteria sin conocer detalles de implementación? [Measurability, Spec §Success Criteria]
- [x] CHK013 - ¿Tiene cada uno de los 12 filtros al menos un escenario de aceptación asociado? [Coverage, Spec §User Story 2]

## Scenario Coverage

- [x] CHK014 - ¿Hay un escenario que cubra la combinación de 2+ filtros simultáneos (AND entre campos)? [Coverage, Spec §Acceptance Scenarios]
- [x] CHK015 - ¿Hay un escenario que cubra limpiar/resetear los filtros aplicados? [Coverage, Spec §Acceptance Scenarios]
- [ ] CHK016 - ¿Hay un escenario que cubra qué pasa si el usuario aplica un filtro y luego navega a Nueva Compra y vuelve (persistencia o no del filtro aplicado)? [Gap]

## Edge Case Coverage

- [x] CHK017 - ¿Están cubiertos los casos de datos faltantes/nulos en compras históricas (Depósito, Etiqueta, Usuario, Vencimiento, Servicio) frente a cada filtro nuevo? [Edge Case, Spec §Edge Cases]
- [x] CHK018 - ¿Está cubierto el caso de un filtro (Id) combinado con un filtro de selección (Proveedor) que se contradicen entre sí? [Edge Case, Spec §Edge Cases]
- [x] CHK019 - ¿Está cubierto el caso de rango inválido (Desde posterior a Hasta)? [Edge Case, Spec §Edge Cases]

## Dependencies & Assumptions

- [x] CHK020 - ¿Está documentada la dependencia de que Compra necesita una relación de Etiquetas que hoy no existe? [Dependency, Spec §FR-013]
- [x] CHK021 - ¿Está documentado el supuesto de que compras históricas no tendrán backfill de Usuario? [Assumption, Spec §Assumptions]
- [x] CHK022 - ¿Está registrada la fuente (captura real vs. documentación existente) usada para resolver la discrepancia de 7 vs. 12 filtros? [Traceability, Spec §Assumptions]

## Ambiguities & Conflicts

- [x] CHK023 - ¿Quedó resuelta (sin dejarla abierta) la contradicción entre `docs/modelo_datos.md` ("Compras no usa etiquetas") y la captura real? [Conflict, Spec §FR-015, Assumptions]
- [ ] CHK024 - ¿Está definido qué pasa si dos filtros de texto libre (Nota Interna, Tipo y N° de Factura) reciben caracteres especiales de SQL LIKE (%, _)? [Gap, Ambiguity]

## Notes

- CHK006, CHK016 y CHK024 quedan como gaps de bajo impacto (no bloquean planificación): la lista de columnas ya tiene un piso mínimo documentado (CUIT, Servicio Desde/Hasta, Teléfono, Mail) y el manejo de caracteres especiales en LIKE es un detalle de implementación estándar (mismo comportamiento ya aceptado en Ventas, sin incidentes reportados). Se dejan marcados sin resolver para que quien implemente los tenga presentes, sin bloquear el avance a `tasks`.
