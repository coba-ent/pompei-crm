# Specification Quality Checklist: Importador de Datos — Campos Completos

**Purpose**: Validar la calidad y completitud de los requisitos antes de pasar a `/speckit-tasks`
**Created**: 2026-07-31
**Feature**: [spec.md](../spec.md) · [plan.md](../plan.md) · [data-model.md](../data-model.md)
**Depth**: Standard · **Audience**: Autor + revisión de PR · **Foco**: completitud/claridad del
diccionario de campos ampliado y cobertura de casos borde de los dos parseos nuevos (fecha, booleano)

## Requirement Completeness

- [x] CHK001 - ¿Está especificado el listado completo de campos nuevos por cada una de las 3 solapas
  (Clientes, Proveedores, Productos), incluyendo cuáles NO aplican a cada entidad? [Completeness, Spec
  §FR-001/FR-002/FR-007, data-model.md]
- [x] CHK002 - ¿Está documentado qué pasa cuando una celda del campo booleano viene vacía (default) vs.
  cuando trae un valor no reconocido (fila fallida)? [Completeness, Spec §FR-008]
- [x] CHK003 - ¿Está definido el comportamiento cuando "Lista de Precios" no matchea ningún registro
  existente (advertencia, no fallo)? [Completeness, Spec §FR-004, Edge Cases]
- [x] CHK004 - ¿Está definido qué ocurre si el usuario mapea dos columnas del archivo (ej. "DNI" y
  "CUIT") al mismo campo destino? [Completeness, Spec Edge Cases]

## Requirement Clarity

- [x] CHK005 - ¿Están enumerados explícitamente los formatos de fecha aceptados para "Fecha de Saldo
  Inicial", sin dejar la interpretación abierta? [Clarity, Spec §FR-005, Clarifications]
- [x] CHK006 - ¿Están enumerados explícitamente los valores de celda que se interpretan como verdadero/
  falso para los 3 campos booleanos de Producto? [Clarity, Spec §FR-008, Clarifications]
- [x] CHK007 - ¿Está aclarado si "Tipo de Documento" valida contra un catálogo fijo o acepta cualquier
  texto? [Clarity, Spec Clarifications]
- [x] CHK008 - ¿Es medible/verificable el criterio de "campo obligatorio" para cada entidad en esta
  ampliación (ninguno de los campos nuevos es obligatorio)? [Clarity, Spec §FR-001-FR-008]

## Requirement Consistency

- [x] CHK009 - ¿Es consistente el criterio de resolución por nombre de "Lista de Precios" (Clientes) con
  el ya usado para "Proveedor"/"Categoría"/"Condición de IVA" en specs anteriores? [Consistency, data-
  model.md §Reglas de validación]
- [x] CHK010 - ¿Es consistente el criterio de "fila fallida vs. advertencia" entre los campos nuevos y el
  ya vigente en spec 006 (fk-por-nombre = advertencia; validación de formato = fallo)? [Consistency,
  research.md]
- [x] CHK011 - ¿Los nombres de campo destino (etiquetas) nuevos siguen el mismo estilo (Título, sin
  abreviar) que los campos ya existentes en el diccionario? [Consistency, data-model.md]

## Scenario Coverage

- [x] CHK012 - ¿Cubre la especificación el escenario de importar Clientes con el bloque fiscal completo
  de punta a punta (User Story 1)? [Coverage, Spec §User Story 1]
- [x] CHK013 - ¿Cubre la especificación el escenario equivalente para Proveedores, incluyendo qué campos
  quedan excluidos? [Coverage, Spec §User Story 2]
- [x] CHK014 - ¿Cubre la especificación el escenario de Productos con los 3 booleanos nuevos, incluyendo
  el caso de celda vacía y el de valor no reconocido? [Coverage, Spec §User Story 3]

## Edge Case Coverage

- [x] CHK015 - ¿Está definido el comportamiento ante una fecha con contenido no interpretable (no vacía,
  pero inválida en los 3 formatos aceptados)? [Edge Case, Spec Edge Cases]
- [x] CHK016 - ¿Está definido el comportamiento ante un valor booleano con contenido no interpretable?
  [Edge Case, Spec Edge Cases]
- [x] CHK017 - ¿Está definido qué pasa si se reimporta el mismo archivo dos veces (duplicados)? [Edge
  Case, Gap] — Nota: cubierto por remisión explícita a la Assumption ya vigente de spec 006 ("sin
  detección de duplicados en esta versión"); esta feature no cambia ese comportamiento.

## Dependencies & Assumptions

- [x] CHK018 - ¿Está documentada la exclusión explícita de "Punto Reposición" como brecha pendiente, con
  su razón? [Assumption, Spec §Assumptions]
- [x] CHK019 - ¿Está documentada la dependencia de que los campos nuevos ya existen en los modelos (sin
  migraciones)? [Dependency, Spec §FR-009, data-model.md]
- [x] CHK020 - ¿Está validada la asunción del formato de fecha exportado por Excel/Sheets (no texto
  ambiguo) contra al menos un archivo real del negocio? [Assumption, Spec §Assumptions] — Validado contra
  `public/imports/clientes.xlsx`/`proveedores.xlsx` (columna "Fecha Saldo Inicial" en formato fecha
  nativa de Excel).

## Notes

- Todos los ítems pasan: el alcance y los formatos aceptados se definieron explícitamente en las fases
  `/speckit-specify` y `/speckit-clarify` a partir del análisis de los 3 archivos reales del negocio, sin
  dejar decisiones implícitas.
- Sin items pendientes — listo para `/speckit-tasks`.
