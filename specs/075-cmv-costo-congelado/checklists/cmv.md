# Checklist de calidad de requisitos: cálculo del CMV con costo congelado

**Purpose**: Validar que los **requisitos escritos** de la spec 075 sean completos, claros,
consistentes y verificables antes de generar tareas. No valida la implementación: valida el texto.
**Created**: 2026-08-24
**Feature**: [spec.md](../spec.md)

> Estos ítems son "tests unitarios del castellano de la spec". Se responden leyendo `spec.md`,
> `plan.md`, `research.md`, `data-model.md` y `contracts/cmv-api.md` — no ejecutando código.

## Fundamento de la regla de negocio

- [x] CHK001 - ¿La regla del CMV está enunciada como una fórmula única y sin ambigüedad? [Clarity, Spec §Regla de negocio corregida]
- [x] CHK002 - ¿La evidencia que sustenta la regla está documentada con cifras verificables y con la ruta de los archivos fuente, y no como afirmación? [Traceability, Research §R1]
- [x] CHK003 - ¿Está explicitado por qué la premisa anterior (promedio de compras) queda refutada, en lugar de sólo reemplazarla? [Completeness, Research §R2]
- [x] CHK004 - ¿La spec distingue explícitamente "Costo Actual" de "CMV" y justifica que difieran? [Clarity, Spec §FR-006]
- [x] CHK005 - ¿Se documenta el criterio metodológico que evita repetir el error (validar con volumen y cruzando dos variables independientes)? [Completeness, Research §R2]

## Distinción NULL vs 0 (el punto que más fácil se rompe)

- [x] CHK006 - ¿Está especificado que la columna de costo congelado es nullable y **sin default**? [Completeness, Data Model §1]
- [x] CHK007 - ¿Está escrito qué significa `NULL` y qué significa `0`, como dos estados semánticamente distintos? [Clarity, Data Model §1]
- [x] CHK008 - ¿Está documentada la consecuencia concreta de equivocarse (un `default 0` desploma el CMV histórico)? [Completeness, Plan §Riesgos]
- [x] CHK009 - ¿Se identifica explícitamente el anti-patrón `NULLIF(costo_unitario, 0)` como prohibido y con su razón? [Ambiguity resuelta, Contracts §I2]
- [x] CHK010 - ¿El orden de precedencia del `COALESCE` está fijado sin dejar lugar a interpretación? [Clarity, Data Model §5]

## No-regresión histórica (verificable)

- [x] CHK011 - ¿El requisito de no-regresión está expresado como criterio medible y no como intención? [Measurability, Spec §SC-003]
- [x] CHK012 - ¿Está especificado que la línea de base debe tomarse **antes** de aplicar la migración? [Completeness, Quickstart §Escenario 2]
- [x] CHK013 - ¿Está definido el comportamiento de las líneas históricas sin ambigüedad (fallback, no cero)? [Clarity, Spec §FR-003]
- [x] CHK014 - ¿Está documentado el síntoma que delataría la falla, para que sea diagnosticable? [Coverage, Quickstart §Escenario 2]
- [x] CHK015 - ¿Se reconoce explícitamente que el informe convivirá con dos criterios y se acepta como decisión, no como omisión? [Assumption, Spec §Assumptions]

## Cobertura de los puntos de creación de líneas

- [x] CHK016 - ¿Están enumerados **todos** los puntos donde se crea una línea de venta, con archivo y línea? [Completeness, Research §R4]
- [x] CHK017 - ¿Está especificado el comportamiento para cada canal de origen (manual, presupuesto, Mercado Libre, Tiendanube)? [Coverage, Data Model §1]
- [x] CHK018 - ¿Está definido que los comandos de migración **no** congelan, y por qué? [Clarity, Data Model §1]
- [x] CHK019 - ¿El requisito de cobertura total de canales es objetivamente verificable? [Measurability, Spec §SC-004]
- [x] CHK020 - ¿Están contemplados los 6 puntos de creación de ítems de nota de crédito/débito? [Completeness, Research §R6]
- [x] CHK021 - ¿Está justificada la decisión de NO usar un model event como punto único, con su alternativa descartada? [Traceability, Research §R4]

## Edición de ventas (FR-009)

- [x] CHK022 - ¿Está documentado que la edición borra y recrea los ítems, como restricción que condiciona el diseño? [Completeness, Research §R5]
- [x] CHK023 - ¿Está definido el criterio de correspondencia entre líneas viejas y nuevas, incluyendo el caso de producto repetido? [Clarity, Contracts §Regla de conservación]
- [x] CHK024 - ¿Está especificado qué pasa con una línea **agregada** durante la edición, distinguiéndola de las preexistentes? [Coverage, Spec §FR-009]
- [x] CHK025 - ¿Está definido el comportamiento para líneas sin `producto_id`? [Edge Case, Data Model §1]
- [x] CHK026 - ¿Está justificado por qué no se rediseña `update()` a un diff incremental? [Traceability, Research §R5]

## Notas de crédito y débito (FR-008)

- [x] CHK027 - ¿Está especificado el origen del costo para cada valor del campo `origen`? [Completeness, Data Model §2]
- [x] CHK028 - ¿Está cubierto el caso de nota **sin venta asociada** (`venta_id` nullable)? [Edge Case, Data Model §2]
- [x] CHK029 - ¿Está definido qué pasa cuando la venta original es histórica y no tiene costo congelado? [Edge Case, Spec §FR-008]
- [x] CHK030 - ¿Está documentado que no existe referencia al `venta_item` de origen y cómo se resuelve la correspondencia? [Gap resuelto, Research §R6]
- [x] CHK031 - ¿Está fijado el manejo del signo (costo en positivo, signo desde la cantidad) sin ramas por tipo? [Consistency, Contracts §I5]

## Contradicción con la documentación de dominio

- [x] CHK032 - ¿La contradicción con `documentacion_principal_crm.md` y `modelo_datos.md` está declarada explícitamente y no resuelta en silencio? [Conflict, Plan §Constitution Check]
- [x] CHK033 - ¿La resolución de la contradicción tiene un requisito propio con momento definido (antes de `tasks`)? [Traceability, Spec §FR-011]
- [x] CHK034 - ¿Están enumerados **todos** los lugares que afirman la premisa vieja (docs, docblock, spec 068)? [Completeness, Spec §Contexto]
- [x] CHK035 - ¿El criterio de éxito de la corrección documental es verificable? [Measurability, Spec §SC-005]

## Frontera del alcance

- [x] CHK036 - ¿Cada exclusión de alcance distingue si es por decisión del usuario o por evidencia? [Clarity, Spec §Out of Scope]
- [x] CHK037 - ¿La exclusión del Informe de Compras está sustentada con datos y no asumida por simetría? [Traceability, Research §R3]
- [x] CHK038 - ¿La exclusión de "Cantidad Prod./Serv." explica por qué la lógica actual es correcta? [Clarity, Spec §Out of Scope]
- [x] CHK039 - ¿La exclusión de "Precio Neto" declara honestamente que la causa **no fue confirmada**? [Assumption, Spec §Out of Scope]
- [x] CHK040 - ¿El backfill descartado queda documentado con método exacto y limitaciones conocidas, para una spec futura? [Completeness, Research §R9]

## Calidad de los criterios de aceptación

- [x] CHK041 - ¿Cada invariante del contrato tiene una razón de existir escrita (qué se rompe si falla)? [Measurability, Contracts §1.3]
- [x] CHK042 - ¿Los criterios de éxito son medibles y comparan contra una línea de base numérica conocida? [Measurability, Spec §SC-001]
- [x] CHK043 - ¿Cada User Story tiene un test independiente que la valida por separado? [Coverage, Spec §User Scenarios]
- [x] CHK044 - ¿Está justificado por qué la User Story 2 es P1 y no una mejora posterior? [Clarity, Spec §User Story 2]
- [x] CHK045 - ¿Se declara la limitación conocida de que los tests corren en SQLite y producción es MySQL estricto? [Assumption, Plan §Constraints]

## Ítems sin resolver

Ninguno. Los 45 ítems pasan.

## Notes

**Riesgo residual identificado, no bloqueante**: el ítem CHK015 documenta que el informe convivirá
con dos criterios de CMV por tiempo indefinido. Está aceptado explícitamente por el usuario, pero
`plan.md §Riesgos` deja abierto para `/speckit-tasks` si conviene una nota al pie o un tooltip que se
lo aclare al cliente. No bloquea la implementación.

**Observación sobre CHK039**: es el único punto de la spec donde una causa queda declarada como no
confirmada. Está bien así —es preferible a afirmarla— pero conviene que quede registrado como
pendiente de diagnóstico y no se pierda.
