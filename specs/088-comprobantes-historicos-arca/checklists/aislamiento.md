# Aislamiento y Riesgo Fiscal Checklist: Comprobantes Históricos con CAE Real de ARCA

**Purpose**: Validar que los requisitos de aislamiento (cero impacto en tesorería/stock/otros
informes) y de corrección fiscal están completos, sin ambigüedad y son verificables, antes de pasar a
tareas — es el foco explícito y no negociable del usuario para esta feature.
**Created**: 2026-08-28
**Feature**: [spec.md](../spec.md), [plan.md](../plan.md), [data-model.md](../data-model.md)

## Completitud del aislamiento

- [x] CHK001 - ¿Está especificado, para cada módulo existente que hoy suma o lista ventas (Reporte Final, KPIs, Stock, Cuenta Corriente, Tesorería), que estos comprobantes no deben aparecer? [Completeness, Spec §FR-006]
- [x] CHK002 - ¿Está explícitamente prohibida la creación de cobros, movimientos de tesorería, movimientos de stock y remitos como efecto de esta carga? [Completeness, Spec §FR-005]
- [x] CHK003 - ¿Especifica la spec que la estructura de datos es ajena al modelo `Venta`, en vez de depender de un flag de exclusión auditado módulo por módulo? [Completeness, Spec §FR-004, Plan §Regla de oro]
- [x] CHK004 - ¿Está definido qué pasa si en el futuro aparece un caso similar (más comprobantes históricos por recuperar)? [Coverage, Spec §FR-007]

## Claridad de los criterios de aislamiento

- [x] CHK005 - ¿Es "no debe sumar en ningún lado" (instrucción del usuario) traducible a una lista cerrada y verificable de módulos concretos, en vez de quedar como una afirmación general? [Clarity, Spec §User Story 3]
- [x] CHK006 - ¿Está cuantificado el criterio de éxito del aislamiento como una comparación antes/después, en vez de una afirmación cualitativa ("no debería afectar")? [Measurability, Spec §SC-002, SC-003]

## Riesgo técnico específico de esta integración

- [x] CHK007 - ¿Identifica el plan el mecanismo concreto por el cual un comprobante histórico podría terminar mostrando los datos de una venta real (o viceversa), y no sólo una afirmación de que "no va a pasar"? [Clarity, Plan §El riesgo real de esta feature]
- [x] CHK008 - ¿Está especificado cómo se distingue un comprobante histórico de un comprobante real dentro del código compartido (Libro IVA Ventas, IVA Digital), sin depender de una propiedad que pueda coincidir por casualidad (como el rango de `id`)? [Clarity, Plan §Decisión 2]
- [x] CHK009 - ¿Requiere la spec/plan un test que reproduzca el caso límite exacto (un histórico y una venta real con el mismo `id`) en vez de sólo tests con datos que no coinciden por casualidad? [Coverage, Edge Case, Plan §Estrategia de test #5]

## Consistencia entre módulos que consumen los mismos datos

- [x] CHK010 - ¿Es consistente el criterio de clasificación "siempre aprobado por ARCA, nunca manual" entre el Libro IVA Ventas y el envío al contador (spec 087), que reusa el mismo filtro? [Consistency, Spec §FR-010]
- [x] CHK011 - ¿Especifica el plan que el Libro IVA Ventas y el IVA Digital deben mostrar exactamente el mismo conjunto de comprobantes históricos para el mismo período (misma fuente, sin una segunda derivación)? [Consistency, Plan §Arquitectura]

## No-invariantes y límites explícitos

- [x] CHK012 - ¿Documenta la spec/plan explícitamente qué NO tiene esta tabla (relación a cliente obligatoria, soft delete, flujo de edición) y por qué, en vez de dejarlo a inferir? [Clarity, Data-model §4]
- [x] CHK013 - ¿Está fuera de alcance, de forma explícita, la corrección de la base de datos anterior y la resolución fiscal del comprobante duplicado — evitando que la implementación intente "de más" resolver algo que es decisión del usuario/contador? [Completeness, Spec §Out of Scope]

## Verificabilidad contra la fuente de verdad (ARCA)

- [x] CHK014 - ¿Puede cada uno de los 14 valores cargados (neto, IVA, total, CAE) verificarse independientemente contra la respuesta ya obtenida de ARCA, en vez de depender de la palabra de una sola fuente (la base anterior)? [Measurability, Data-model §2]
- [x] CHK015 - ¿Especifica la spec una verificación manual contra MySQL real antes de deployar, dado el precedente ya documentado de un bug real que sólo apareció fuera de SQLite? [Coverage, Plan §Estrategia de test #6, Quickstart]

## Notes

Todos los ítems ya pasan sobre spec.md/plan.md/data-model.md tal como quedaron escritos tras
`/speckit-clarify` — no se generó ninguna brecha nueva. Este checklist queda como referencia de qué se
validó explícitamente, dado que el aislamiento total es el requisito no negociable del usuario para
esta feature (no un detalle más entre otros).
