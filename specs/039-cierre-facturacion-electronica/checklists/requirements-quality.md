# Specification Quality Checklist: Cierre de Facturación Electrónica — PDF NC/ND, Mi Perfil y Recibos

**Purpose**: Validar la calidad de los requerimientos antes de pasar a tasks/implementación
**Created**: 2026-08-03
**Feature**: [spec.md](../spec.md)
**Depth**: Standard | **Audience**: Reviewer (pre-tasks)

## Requirement Completeness

- [x] CHK001 - ¿Están definidos los campos obligatorios vs. opcionales de Mi Perfil (Razón Social, CUIT, Domicilio Fiscal, Condición de IVA vs. Ingresos Brutos)? [Completeness, Spec §FR-005]
- [x] CHK002 - ¿Está especificado qué pasa con el PDF cuando Mi Perfil no tiene datos cargados? [Completeness, Spec §FR-008]
- [x] CHK003 - ¿Están definidos los datos mínimos que debe mostrar un Recibo (emisor, contraparte, medio, monto, fecha, número)? [Completeness, Spec §FR-011]
- [ ] CHK004 - ¿Está especificado el formato/tamaño máximo aceptado para el logo de Mi Perfil? [Gap]

## Requirement Clarity

- [x] CHK005 - ¿Está definido explícitamente qué significa "aprobado" para decidir si el PDF de NC/ND oculta el watermark? [Clarity, Spec §FR-004]
- [x] CHK006 - ¿Está cuantificado el número correlativo de Recibo (de dónde sale, con qué prefijo)? [Clarity, Spec Assumptions / research.md §3]
- [ ] CHK007 - ¿Está especificado si "Condición de IVA" de Mi Perfil usa el mismo catálogo cerrado que Cliente/Proveedor o un campo libre? [Ambiguity, Spec §FR-005]

## Requirement Consistency

- [x] CHK008 - ¿Es consistente el criterio de "ocultar watermark" entre el PDF de Venta (spec 034) y el PDF de NC/ND nuevo? [Consistency, Spec §FR-004]
- [x] CHK009 - ¿Es consistente el patrón de acceso (modal + AJAX, permisos por rol) entre Mi Perfil y el resto de Configuración & Ajustes? [Consistency, Spec §FR-006, §FR-013]

## Scenario Coverage

- [x] CHK010 - ¿Están cubiertos los escenarios de éxito y de comprobante sin CAE para el PDF de NC/ND? [Coverage, Spec User Story 1]
- [x] CHK011 - ¿Está cubierto el escenario de Recibo tanto para Cobranza (Venta) como para Pago (Compra/Proveedor)? [Coverage, Spec User Story 3]
- [x] CHK012 - ¿Está cubierto el caso de un usuario sin permisos intentando acceder a Mi Perfil? [Coverage, Spec User Story 2, Acceptance Scenario 4]

## Edge Case Coverage

- [x] CHK013 - ¿Está definido el comportamiento cuando el comprobante de Venta ajustado por la NC/ND ya no está disponible? [Edge Case, Spec Edge Cases]
- [x] CHK014 - ¿Está definido el comportamiento ante un logo corrupto o de formato inválido? [Edge Case, Spec Edge Cases, FR-014]
- [x] CHK015 - ¿Está definido el comportamiento al intentar ver el Recibo de una Cobranza eliminada/anulada? [Edge Case, Spec Edge Cases]

## Non-Functional Requirements

- [x] CHK016 - ¿Están definidos objetivos de performance para la generación de los PDFs nuevos? [Completeness, Plan Technical Context]
- [ ] CHK017 - ¿Está especificado algún requisito de accesibilidad para la pantalla Mi Perfil (más allá de las reglas de diseño generales del proyecto)? [Gap]

## Dependencies & Assumptions

- [x] CHK018 - ¿Está documentada la dependencia de esta feature con `ComprobanteFiscal` de spec 034? [Dependency, Spec Key Entities]
- [x] CHK019 - ¿Está documentada explícitamente la brecha de relevamiento (sin capturas reales) para Mi Perfil y Recibos? [Assumption, Spec Assumptions]
- [x] CHK020 - ¿Está validada la suposición de single-tenant (una sola fila de Mi Perfil) contra la constitución del proyecto? [Assumption, Plan Constitution Check]

## Notes

- CHK004, CHK007 y CHK017 quedan como gaps menores — no bloquean `/speckit-tasks`: tienen defaults razonables ya aplicados en el plan (reutilizar validación de imagen estándar del proyecto, catálogo cerrado de Condición de IVA ya usado en Cliente/Proveedor, reglas de accesibilidad generales del template NexaDash) y no cambian el diseño de datos ni la arquitectura.
