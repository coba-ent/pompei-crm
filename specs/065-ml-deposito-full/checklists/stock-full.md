# Checklist de calidad de requisitos: manejo de stock Full, no-regresión y resiliencia

**Purpose**: Validar que los **requisitos escritos** de la feature 065 son completos, claros,
consistentes y medibles, antes de implementar. No valida la implementación — valida la redacción.
**Created**: 2026-08-13
**Feature**: [spec.md](../spec.md)
**Focos**: (1) corrección del manejo de stock, (2) no-regresión sobre logística propia,
(3) resiliencia de la conversión de órdenes en Ventas
**Profundidad**: Formal (gate previo a implementar) — exigido por el principio IV de la constitución,
que obliga a rigor donde hay movimientos de stock

---

## Corrección del manejo de stock — Completitud

- [ ] CHK001 - ¿Está especificado **de qué fuente exacta** sale la existencia Full, distinguiéndola del disponible de la publicación? [Completeness, Spec §FR-009]
- [ ] CHK002 - ¿Está definido qué hacer con la existencia declarada **no vendible** por Mercado Libre (dañada, en transferencia)? [Completeness, Spec §FR-009]
- [ ] CHK003 - ¿Están definidos los requisitos de **trazabilidad** del ajuste de existencias (qué registro queda, con qué origen identificable)? [Completeness, Spec §FR-010]
- [ ] CHK004 - ¿Está especificado el **alcance del recorrido** del reflejo (todos los Full vs. sólo los marcados pendientes) y el motivo? [Completeness, Spec §FR-009a]
- [ ] CHK005 - ¿Está definido el comportamiento cuando el vínculo Full apunta a un **producto inexistente o eliminado** del CRM? [Gap]
- [ ] CHK006 - ¿Están definidos los requisitos para el caso de **publicación Full con variantes**, o está explícitamente excluido? [Coverage, Spec §Assumptions]

## Corrección del manejo de stock — Deduplicación

- [ ] CHK007 - ¿Está definida sin ambigüedad la **clave de deduplicación** de existencias y por qué es esa y no otra? [Clarity, Spec §FR-009b]
- [ ] CHK008 - ¿Está especificado qué ocurre cuando dos vínculos Full comparten clave de inventario **pero apuntan a productos distintos** del CRM? [Gap, Edge Case]
- [ ] CHK009 - ¿Está definido explícitamente que la existencia de un inventario **nunca se suma dos veces** ni una publicación pisa lo reflejado por otra? [Clarity, Spec §FR-009b]
- [ ] CHK010 - ¿Está documentado que el identificador de inventario **NO** sirve como indicador de Full, para evitar que un implementador lo use como atajo? [Consistency, Spec §Contexto]

## Corrección del manejo de stock — Idempotencia y ciclos

- [ ] CHK011 - ¿Está especificado que **no se genera ningún registro** cuando la existencia informada coincide con la del CRM? [Measurability, Spec §FR-012]
- [ ] CHK012 - ¿Es objetivamente verificable el criterio de "no generar ajuste", o admite interpretación (ej. genera movimiento en cero)? [Measurability, Spec §FR-012]
- [ ] CHK013 - ¿Está definido cómo se impide que el ajuste del reflejo desencadene un envío de vuelta hacia Mercado Libre? [Completeness, Spec §FR-013]
- [ ] CHK014 - ¿Está documentado **de qué requisito depende** que el ciclo no se forme, de modo que se detecte si esa dependencia se relaja? [Traceability, Spec §FR-013 ↔ §FR-017]
- [ ] CHK015 - ¿Está especificado el aislamiento del reflejo respecto de **otros depósitos** (que ninguno más cambie)? [Clarity, Spec §FR-011]

## Corrección del manejo de stock — Límites de escritura

- [ ] CHK016 - ¿Está declarado explícitamente que la existencia del centro de distribución de Mercado Libre **no es escribible**, con el motivo? [Completeness, Spec §Contexto, §FR-009c]
- [ ] CHK017 - ¿Está definido el comportamiento del reflejo bajo **"modo sólo lectura"**, y la justificación de por qué difiere del envío? [Clarity, Spec §FR-014a]
- [ ] CHK018 - ¿Son consistentes entre sí los requisitos de "no escribir Full" y los de "seguir escribiendo el resto"? [Consistency, Spec §FR-006 ↔ §FR-009c]

## No-regresión sobre publicaciones de logística propia

- [ ] CHK019 - ¿Existe un requisito **explícito de no-regresión** que exija comportamiento idéntico al actual para las publicaciones no-Full? [Completeness, Spec §SC-007]
- [ ] CHK020 - ¿Es el criterio de no-regresión **objetivamente medible**, o es una afirmación cualitativa no verificable? [Measurability, Spec §SC-007]
- [ ] CHK021 - ¿Está especificado que una publicación **sin clasificar** se comporta idénticamente a una de logística propia, sin excepciones? [Clarity, Spec §FR-005]
- [ ] CHK022 - ¿Está definido que el sistema **nunca asume Full ante la duda**, cubriendo el caso de dato ausente, vacío o desconocido? [Edge Case, Spec §FR-005]
- [ ] CHK023 - ¿Está especificado el comportamiento ante un valor de tipo de logística **nuevo o desconocido** que Mercado Libre introduzca a futuro? [Gap, Edge Case]
- [ ] CHK024 - ¿Están definidos los requisitos de **preservación del último valor conocido** ante fallo de la clasificación, incluyendo que no se pise con vacío? [Completeness, Spec §FR-004]
- [ ] CHK025 - ¿Está especificado que el mensaje de resultado de la sincronización es **idéntico al actual** cuando no hay publicaciones Full? [Consistency, Contracts §rutas-internas]
- [ ] CHK026 - ¿Está definido qué ocurre con una publicación que **pasa de Full a logística propia**, incluyendo el destino de la existencia ya reflejada? [Coverage, Spec §Edge Cases]
- [ ] CHK027 - ¿Está definido el caso inverso —de logística propia a Full— con la misma claridad? [Coverage, Gap]

## Resiliencia de la conversión de órdenes en Ventas

- [ ] CHK028 - ¿Existe un requisito **absoluto** de que ninguna condición de esta feature pueda impedir la conversión de una orden? [Completeness, Spec §FR-022]
- [ ] CHK029 - ¿Está definida sin ambigüedad la regla de imputación para una orden **íntegramente Full**? [Clarity, Spec §FR-020]
- [ ] CHK030 - ¿Está definida la regla para una orden **mixta**, con su justificación y sin dejar abierta la posibilidad de repartir la Venta? [Clarity, Spec §FR-020a]
- [ ] CHK031 - ¿Está especificado el fallback cuando el depósito Full **no está configurado o está inactivo**? [Coverage, Spec §FR-021]
- [ ] CHK032 - ¿Está definido el caso de una orden cuyo artículo **no está vinculado** a ninguna publicación del CRM? [Edge Case, Spec §Edge Cases]
- [ ] CHK033 - ¿Están definidos los requisitos de **auditoría** del criterio de imputación aplicado a cada Venta? [Completeness, Spec §FR-023]
- [ ] CHK034 - ¿Es verificable objetivamente el requisito de "queda registrado de forma consultable", o es demasiado vago para implementar? [Measurability, Spec §FR-023]
- [ ] CHK035 - ¿Está especificado que el depósito imputado a la Venta y el usado por el **descuento de existencias** son necesariamente el mismo? [Consistency, Gap]
- [ ] CHK036 - ¿Está definido el comportamiento cuando la Venta Full deja el depósito Full en **negativo**? [Edge Case, Spec §Edge Cases]
- [ ] CHK037 - ¿Está definido si las Ventas **ya creadas** se reimputan retroactivamente, evitando ambigüedad sobre el alcance temporal? [Clarity, Spec §Assumptions]

## Configuración — Claridad y consistencia

- [ ] CHK038 - ¿Está especificada la validación de que el depósito Full debe diferir del general, **con el motivo** y no sólo la regla? [Completeness, Spec §FR-017]
- [ ] CHK039 - ¿Está definido explícitamente que el depósito Full **no tiene fallback a un depósito por defecto**, a diferencia del general? [Consistency, Data model §ml_configuracion]
- [ ] CHK040 - ¿Es consistente el tratamiento de "depósito inactivo" entre el reflejo de stock y la imputación de Ventas? [Consistency, Spec §FR-014 ↔ §FR-021]
- [ ] CHK041 - ¿Está especificado que el sistema **no crea depósitos automáticamente**? [Clarity, Spec §FR-018]
- [ ] CHK042 - ¿Están definidos los requisitos del aviso por configuración incompleta, incluyendo **cuándo** aparece? [Completeness, Spec §FR-026]

## Requisitos no funcionales y supuestos

- [ ] CHK043 - ¿Está cuantificado el costo adicional en llamadas a la API del reflejo Full? [Measurability, Plan §Performance Goals]
- [ ] CHK044 - ¿Está documentado y validado el supuesto de que el tipo de logística de la publicación **basta** para clasificar una orden, sin consultar el envío? [Assumption, Spec §Assumptions]
- [ ] CHK045 - ¿Está definida la **frecuencia** del reflejo y de la clasificación, con justificación de por qué se reutiliza la cadencia existente? [Clarity, Spec §Assumptions]
- [ ] CHK046 - ¿Están documentados los supuestos de **volumen** y su impacto en las decisiones de diseño? [Assumption, Plan §Scale/Scope]
- [ ] CHK047 - ¿Está declarada como decisión explícita del negocio que la reposición hacia Full es **manual**, y no como una omisión? [Clarity, Spec §Assumptions]

## Ambigüedades, conflictos y trazabilidad

- [ ] CHK048 - ¿Hay conflicto entre "excluir Full del envío" (§FR-006) y "el envío sólo alcanza lo escribible" (§FR-006)? ¿Describen la misma regla sin contradecirse? [Conflict, Spec §FR-006]
- [ ] CHK049 - ¿Se usa **terminología consistente** para el depósito Full, el tipo de logística y la existencia vendible en spec, plan, data-model y contratos? [Consistency]
- [ ] CHK050 - ¿Todos los requisitos funcionales tienen al menos un escenario de aceptación que los ejercite? [Traceability]
- [ ] CHK051 - ¿Está documentada la anomalía de **fichas duplicadas en Mercado Libre** como fuera de alcance, para que no se interprete como bug de la feature? [Assumption, Spec §Assumptions]
- [ ] CHK052 - ¿Están registradas las correcciones a supuestos iniciales que resultaron falsos, para que no se reintroduzcan? [Traceability, Spec §Clarifications]

---

## Notas

- **Ítems de mayor riesgo**: CHK013/CHK014 (ciclo de sincronización), CHK038/CHK039 (validación de
  depósitos distintos) y CHK028 (resiliencia de la conversión). Un fallo en cualquiera de los tres
  produce pérdida silenciosa de datos o corte de operación, no un bug cosmético.
- **CHK023 y CHK027** apuntan a huecos probables de la spec actual: valores de logística futuros y la
  transición inversa. Resolver antes de `/speckit-tasks`.
- **CHK005, CHK008 y CHK035** son los otros huecos candidatos detectados al redactar este checklist.
