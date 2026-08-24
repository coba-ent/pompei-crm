# Checklist de calidad de requisitos: fidelidad del Informe de Ventas

**Purpose**: validar que los requisitos de la spec 076 estén completos, claros y sin contradicciones
antes de generar tareas. **No** valida la implementación: valida cómo está escrita la spec.
**Created**: 2026-08-24
**Feature**: [spec.md](../spec.md)

**Foco** (del pedido): que ninguna de las cuatro salidas quede con el criterio viejo, que el
desglose impositivo impute a una sola columna, y que la documentación de dominio equivocada quede
corregida con registro.
**Profundidad**: alta — es cálculo de importes e IVA, que la constitución marca como zona de riesgo.
**Audiencia**: revisor antes de `/speckit-tasks`.

---

## Cobertura de las cuatro salidas

- [ ] CHK001 - ¿Está enumerado explícitamente **cuáles** son las cuatro salidas afectadas, sin dejar el conjunto abierto a interpretación? [Clarity, Spec §FR-003]
- [ ] CHK002 - ¿Hay un requisito que obligue a que las cuatro muestren el **mismo** importe para la misma línea, y no sólo a que cada una sea correcta por su cuenta? [Completeness, Spec §SC-004]
- [ ] CHK003 - ¿Está especificado qué pasa con los consumidores del motor que la spec **no** enumera —Rankings y "Arma tu Informe"—, que también leen la columna afectada? [Gap, Spec §FR-018]
- [ ] CHK004 - ¿Se distingue con claridad qué salida conserva su estructura previa y cuál la cambia, para que "corregir el resumen" no se lea como "unificarlo con el detallado"? [Ambiguity, Spec §FR-007 vs §FR-020]
- [ ] CHK005 - ¿Está definido el comportamiento del formato contable de negativos **por salida**, o queda como una regla global que contradiría la sumabilidad en Excel? [Consistency, Spec §FR-016]

## El importe por línea

- [ ] CHK006 - ¿La definición del importe de línea es aritmética y verificable, o se apoya en un adjetivo como "el importe que corresponde"? [Measurability, Spec §FR-001]
- [ ] CHK007 - ¿Está fijado que la suma debe cerrar **exacto** y no "aproximadamente", con una tolerancia declarada? [Clarity, Spec §FR-002, §SC-001]
- [ ] CHK008 - ¿Está especificado **dónde** se absorbe el residuo del redondeo cuando el prorrateo no divide exacto? [Completeness, Research §R2]
- [ ] CHK009 - ¿El criterio de prorrateo de conceptos extra está definido de forma que dos implementadores lleguen al mismo número? [Clarity, Clarifications]
- [ ] CHK010 - ¿Está declarado el comportamiento cuando el neto del comprobante es cero y el prorrateo dividiría por cero? [Edge Case, Gap]
- [ ] CHK011 - ¿Se especifica el signo del importe para notas de crédito y de débito sin necesidad de una rama por tipo de comprobante? [Consistency, Spec §FR-004]
- [ ] CHK012 - ¿Está cubierto el caso de la nota migrada sin ítems, donde el importe de línea y el del comprobante coinciden? [Edge Case, Spec §Edge Cases]

## Desglose impositivo

- [ ] CHK013 - ¿El requisito de imputación dice "a una sola" columna de forma exclusiva y verificable, o admite que una línea aporte a dos? [Measurability, Spec §FR-011]
- [ ] CHK014 - ¿Están enumerados **todos** los valores posibles del campo de IVA, incluido el caso nulo o vacío? [Completeness, Data model §3]
- [ ] CHK015 - ¿Está definido a qué columna imputa una línea cuyo IVA no es ni un porcentaje conocido ni una condición reconocida? [Edge Case, Gap]
- [ ] CHK016 - ¿Se especifica si las columnas sin valor van en cero o vacías, dado que en Excel no son lo mismo al sumar? [Ambiguity, Gap]
- [ ] CHK017 - ¿Está dicho de dónde salen las columnas de percepciones e impuestos internos y con qué criterio se reparten, o queda implícito? [Completeness, Spec §FR-012]

## Fidelidad estructural

- [ ] CHK018 - ¿Las 44 columnas están enumeradas con su rótulo exacto y su orden, sin depender de que alguien abra el archivo de referencia? [Completeness, Spec §FR-009]
- [ ] CHK019 - ¿Está declarada y justificada la duplicación del rótulo "Tipo", para que no se corrija por prolijidad? [Clarity, Contracts §5]
- [ ] CHK020 - ¿Se indica qué contenido lleva cada columna nueva cuando el dato no existe, con el valor literal que usa Contagram? [Completeness, Clarifications]
- [ ] CHK021 - ¿Está identificada la fuente de verdad de cada afirmación estructural —captura o archivo—, de modo que sea reverificable? [Traceability, Spec §Contexto]
- [ ] CHK022 - ¿Está explícito que el identificador mostrado es el del CRM y nunca el del sistema de origen? [Clarity, Spec §Assumptions]
- [ ] CHK023 - ¿Se especifica el formato de la fecha en el archivo exportado, dado que hay una divergencia observada entre fecha real y texto? [Gap]

## Consistencia con lo ya decidido

- [ ] CHK024 - ¿La spec reconoce explícitamente que contradice a la documentación de dominio, en lugar de cambiarla en silencio? [Consistency, Spec §FR-005]
- [ ] CHK025 - ¿Exige que la corrección documental deje registro de qué decía antes y con qué evidencia se corrigió, y no sólo el texto nuevo? [Completeness, Spec §FR-005]
- [ ] CHK026 - ¿Está identificado el test existente que afirma lo contrario y qué hacer con él? [Traceability, Research §R5]
- [ ] CHK027 - ¿Se distingue lo que es divergencia deliberada ya decidida de lo que es error a corregir, para no "arreglar" una decisión? [Ambiguity, Spec §FR-018, §FR-020]
- [ ] CHK028 - ¿Está declarado el impacto sobre el motor de tablas dinámicas, que ya consume la columna que se modifica? [Dependency, Research §R7]

## Criterios de aceptación

- [ ] CHK029 - ¿Los criterios de éxito son medibles sin conocer la implementación? [Measurability, Spec §Success Criteria]
- [ ] CHK030 - ¿Hay un criterio que capture el estado **anterior** para poder demostrar la mejora, y no sólo el esperado? [Completeness, Quickstart §Escenario 0]
- [ ] CHK031 - ¿El criterio de volumen está cuantificado con un número, o dice "varios miles"? [Clarity, Spec §SC-006]
- [ ] CHK032 - ¿Está definido qué diferencia contra Contagram es aceptable y cuál no, distinguiendo la que explica la spec 075? [Clarity, Spec §SC-003]

## Riesgos y dependencias

- [ ] CHK033 - ¿Está documentado el riesgo de que la lectura del comprobante fiscal multiplique filas, con su condición de disparo? [Dependency, Research §R3]
- [ ] CHK034 - ¿Se declara que la suite verde no alcanza como evidencia, por la diferencia entre el motor de tests y el de producción? [Assumption, Plan §Riesgos]
- [ ] CHK035 - ¿Está delimitado por escrito qué queda fuera de alcance y por qué, de modo que no se expanda al implementar? [Clarity, Spec §FR-018, §FR-019]
- [ ] CHK036 - ¿Se indica qué hacer si al implementar aparece que el Informe de Compras tiene el mismo defecto? [Gap, Spec §Assumptions]

---

## Notas

- Los ítems se escriben como preguntas sobre **cómo está redactada la spec**, no sobre si el sistema
  funciona. La validación funcional vive en `quickstart.md`.
- CHK003, CHK010, CHK015, CHK016, CHK023 y CHK036 apuntan a huecos que sospecho reales; se resuelven
  en `/speckit-analyze` actualizando la spec, no marcándolos a mano.
