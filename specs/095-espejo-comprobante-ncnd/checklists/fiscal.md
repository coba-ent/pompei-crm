# Fiscal e Importes Checklist: Espejo del comprobante de origen al crear una NC/ND

**Purpose**: Validar que los requisitos de la spec 095 están escritos con la precisión que exige una
NC/ND — un documento con consecuencias fiscales, donde un importe o un tipo de comprobante mal
definido no es un bug de UI sino un problema impositivo.
**Created**: 2026-09-02
**Feature**: [spec.md](../spec.md)

> Esta lista evalúa **cómo están escritos los requisitos**, no si el código funciona. Cada ítem
> pregunta si algo está bien especificado, no si anda bien.

## Corrección de importes

- [ ] CHK001 - ¿Está definido con qué importe exacto nace la nota cuando el comprobante tiene descuento general? [Completeness, Spec §FR-002]
- [ ] CHK002 - ¿Está especificado que el descuento general va en la cabecera y no prorrateado en las líneas, con su justificación? [Clarity, Spec §FR-003]
- [ ] CHK003 - ¿Se distingue el tratamiento del descuento en modo porcentaje del modo monto fijo? [Completeness, Spec §FR-002]
- [ ] CHK004 - ¿Está definido qué pasa con el descuento de línea cuando además se hereda el general, de modo que no se apliquen dos veces? [Consistency, Spec §FR-003]
- [ ] CHK005 - ¿El criterio de "total propuesto igual al del comprobante" está expresado de forma medible y con una línea de base concreta? [Measurability, Spec §SC-001]
- [x] CHK006 - ¿Está definido el redondeo o la tolerancia aceptable al comparar el total de la nota contra el del comprobante? [Resuelto, Spec §FR-014]
- [ ] CHK007 - ¿Está especificado cómo se comporta el importe cuando el comprobante ya tiene notas anteriores? [Coverage, Spec §FR-009]
- [ ] CHK008 - ¿Está definido qué se precarga cuando el comprobante no tiene ningún descuento? [Edge Case, Spec §US1-3]

## Tipo de comprobante y corrección fiscal

- [ ] CHK009 - ¿Está especificado de dónde se deriva el tipo de comprobante de la nota? [Completeness, Spec §FR-004]
- [ ] CHK010 - ¿Está definido el comportamiento cuando el comprobante de origen no tiene tipo (vacío o "Sin Factura")? [Edge Case, Spec §FR-004]
- [ ] CHK011 - ¿Está explicitado que el sistema no debe inferir ni inventar un tipo cuando falta? [Clarity, Spec §FR-004]
- [ ] CHK012 - ¿Está definido qué ocurre si el usuario elige un tipo distinto al del comprobante de origen? [Coverage, Spec §FR-004a]
- [ ] CHK013 - ¿La advertencia por tipo cruzado especifica su contenido y que informa sin bloquear? [Clarity, Spec §FR-004a]
- [ ] CHK014 - ¿Se resolvió la tensión entre "todo editable" y el principio de que el tipo se deriva y no se elige a mano? [Conflict, Spec §FR-008 / §FR-004a]
- [ ] CHK015 - ¿Está documentada la consecuencia de una nota con el tipo equivocado (que no se corrige editando)? [Completeness, Spec §US2]

## Anulación parcial: precargar sin imponer

- [ ] CHK016 - ¿Está explicitado que lo que se guarda es lo que quedó en pantalla y no lo precargado? [Clarity, Spec §FR-008]
- [ ] CHK017 - ¿Están definidos los requisitos para quitar líneas o cambiar cantidades sobre un formulario ya precargado? [Coverage, Spec §US3]
- [ ] CHK018 - ¿Está especificado que borrar el descuento general debe respetarse, incluso dejándolo vacío? [Completeness, Spec §US3-2]
- [ ] CHK019 - ¿Está definido el caso en que el descuento heredado en modo monto supera el subtotal de las líneas restantes? [Edge Case, Spec §FR-012]
- [ ] CHK020 - ¿El requisito de "no permitir total negativo" especifica si el sistema avisa, corrige o bloquea? [Clarity, Spec §FR-012]
- [x] CHK021 - ¿Está definido en qué momento se evalúa esa condición (al editar o al guardar)? [Resuelto, Spec §FR-015]

## Alcance y no regresión

- [ ] CHK022 - ¿Está explicitado que la edición de notas existentes no cambia? [Completeness, Spec §FR-011]
- [ ] CHK023 - ¿Está definido que las notas ya emitidas no se modifican por este cambio? [Clarity, Spec §SC-006]
- [ ] CHK024 - ¿Está especificado que el cálculo de cantidad pendiente de ajuste no se toca? [Consistency, Spec §FR-009]
- [ ] CHK025 - ¿Está resuelta la precedencia entre "total igual al comprobante" y "partir de lo pendiente"? [Conflict, Spec §FR-001 / §FR-009]
- [ ] CHK026 - ¿Está definido que el comportamiento es equivalente en Ventas y Compras, y en qué difiere? [Consistency, Spec §FR-010]
- [ ] CHK027 - ¿Está declarado explícitamente que este trabajo no requiere cambios de esquema? [Assumption, Spec §Dependencies]

## Cobertura de escenarios

- [ ] CHK028 - ¿Están definidos los requisitos para una nota sin ítems ("afecta stock = No")? [Coverage, Spec §FR-013]
- [ ] CHK029 - ¿Está especificado qué se precarga cuando el comprobante no tiene ítems con producto? [Edge Case, Spec §Edge Cases]
- [ ] CHK030 - ¿Está definido el comportamiento con comprobantes migrados a los que les falta depósito o categoría? [Edge Case, Spec §Edge Cases]
- [ ] CHK031 - ¿Están definidos los requisitos de precarga de percepciones e impuestos internos? [Completeness, Spec §FR-007]
- [ ] CHK032 - ¿Está especificado el respaldo de cada fecha cuando el comprobante no la tiene cargada? [Completeness, Spec §FR-005]
- [x] CHK033 - ¿Está definido qué ocurre si el comprobante de origen fue anulado o eliminado? [Resuelto, Spec §FR-016]

## Trazabilidad y evidencia

- [ ] CHK034 - ¿Las decisiones de diseño están respaldadas por el relevamiento de Contagram y no por criterio propio? [Traceability, Spec §Contexto]
- [ ] CHK035 - ¿El impacto está cuantificado con datos reales y verificables? [Measurability, Spec §Contexto]
- [ ] CHK036 - ¿Están identificados comprobantes concretos para verificar cada escenario? [Traceability, quickstart.md]
- [ ] CHK037 - ¿Están señalados los escenarios sin datos reales en la base, que requieren datos de prueba? [Assumption, quickstart.md]
- [ ] CHK038 - ¿El riesgo conocido fuera de alcance (validaciones al editar) está documentado sin mezclarse con el alcance? [Clarity, Spec §Riesgo conocido]

## Notas

- Los ítems sin marcar requieren ajustar la spec antes de `/speckit-tasks`.
- **CHK006, CHK021 y CHK033** eran huecos detectados al escribir esta lista y ya quedaron resueltos
  en la spec (FR-014, FR-015 y FR-016): tolerancia de medio centavo con redondeo a dos decimales,
  evaluación del total negativo al guardar y no al tipear, y comprobante de origen eliminado.
- El resto de los ítems queda para validar durante `/speckit-analyze` y la implementación.
