# Robustez de Datos — Checklist de Calidad de Requisitos: Importador de Productos

**Purpose**: Validar la **calidad de la redacción de los requisitos** (completitud, claridad,
consistencia, medibilidad y cobertura) de la spec 074, antes de generar tareas e implementar.
Esto NO es un plan de pruebas: no evalúa si el sistema funciona, sino si los requisitos están
bien escritos, son inequívocos y están completos.

**Created**: 2026-08-22
**Feature**: [spec.md](../spec.md)

**Enfoque solicitado**: cobertura de los cuatro orígenes de cambio de precio; verificabilidad de los
criterios de concurrencia; no-regresión del importador y de las integraciones ML/Tiendanube;
cumplimiento del Principio I (docs de dominio por el nuevo valor de enum).

**Profundidad**: formal (release gate) — justificada por Principio IV de la constitución: la feature
toca stock y precios, ambas áreas de testing obligatorio.
**Audiencia**: revisor, antes de `/speckit-tasks`.

---

## Cobertura de los orígenes de cambio de precio

- [x] CHK001 ¿Está definido el conjunto **cerrado** de orígenes de cambio de precio que la spec exige distinguir, sin dejarlo como lista abierta de ejemplos? [Completeness, Spec §FR-008]
- [x] CHK002 ¿Está especificado el comportamiento requerido cuando un cambio de precio llega por un camino que **no declara** su origen? [Coverage, Spec §Clarifications]
- [x] CHK003 ¿Están documentados los caminos de escritura de precio que quedan **fuera** del alcance de la auditoría, en lugar de asumirse cubiertos? [Completeness, Spec §FR-009a]
- [x] CHK004 ¿La spec justifica por qué la edición masiva de precios se incorporó al alcance pese a no estar en el pedido original, de modo que un revisor entienda que no es scope creep? [Clarity, Spec §Clarifications]
- [x] CHK005 ¿Está definido si la copia de producto debe auditarse como creación de precios o tratarse como caso aparte? [Clarity, Spec §FR-008]
- [x] CHK006 ¿Se especifica qué ocurre con los cambios de precio originados en las integraciones entrantes (si existieran), o se declara explícitamente que no existen? [Gap, Coverage]
- [x] CHK007 ¿Los requisitos distinguen sin ambigüedad entre "precio por lista" y "precio de venta base / costo" del producto, dado que la edición masiva modifica ambos en la misma operación? [Ambiguity, Spec §Fuera de alcance]

## Verificabilidad de los criterios de concurrencia

- [x] CHK008 ¿El criterio de corrección ante concurrencia está expresado de forma **objetivamente verificable**, y no como "el resultado es consistente"? [Measurability, Spec §US2 esc. 2]
- [x] CHK009 ¿La spec evita fijar un valor final único predeterminado para el escenario concurrente, reconociendo que depende del orden de commit? [Consistency, Spec §US2 esc. 2]
- [x] CHK010 ¿Está definido el invariante que debe cumplirse siempre (reconciliación entre la cantidad actual y la suma del histórico), como criterio de aceptación explícito? [Measurability, Spec §SC-003]
- [x] CHK011 ¿Están especificados los requisitos para el caso en que la fila de stock **todavía no existe** al momento de fijar la cantidad? [Edge Case, Coverage]
- [x] CHK012 ¿Está definido si una cantidad deseada negativa es válida, y con qué comportamiento? [Gap, Edge Case]
- [x] CHK013 ¿Está especificado el comportamiento requerido cuando dos filas de la misma planilla apuntan al mismo producto y depósito? [Coverage, Spec §Edge Cases]
- [x] CHK014 ¿La spec define el alcance de la exclusividad requerida (qué recurso exacto queda protegido) sin sobre-especificar el mecanismo? [Clarity, Spec §FR-001]
- [x] CHK015 ¿Está declarado si el criterio de concurrencia es verificable en el entorno de tests del proyecto, o requiere validación manual documentada? [Assumption, Gap]

## Completitud del registro de auditoría

- [x] CHK016 ¿Está especificado el conjunto **mínimo** de datos que debe contener cada evento, de forma que un revisor pueda determinar si falta alguno? [Completeness, Spec §FR-007]
- [x] CHK017 ¿Está definido qué se registra cuando **no existía** precio anterior, en lugar de dejarlo implícito? [Coverage, Spec §US1 esc. 3]
- [x] CHK018 ¿Está definido qué se registra cuando un precio se **elimina**? [Coverage, Spec §US1 esc. 4]
- [x] CHK019 ¿El criterio de "cambio real" (que evita registrar escrituras sin cambio de valor) está definido de forma inequívoca respecto de la precisión decimal? [Ambiguity, Spec §FR-010]
- [x] CHK020 ¿Está especificado qué ocurre con el registro si el nombre del producto o de la lista cambian **después** del evento? [Edge Case, Gap]
- [x] CHK021 ¿Está definido el comportamiento requerido cuando el texto del evento excede el espacio disponible? [Edge Case, Spec §Assumptions]
- [x] CHK022 ¿Los requisitos de inmutabilidad del registro son consistentes con los del mecanismo de auditoría preexistente que se reutiliza? [Consistency, Spec §FR-013]
- [x] CHK023 ¿Está definido quién es el "usuario responsable" cuando la operación no tiene usuario autenticado? [Gap, Spec §Assumptions]

## No-regresión: importador e integraciones

- [x] CHK024 ¿Están enumerados de forma explícita los comportamientos del importador que **deben permanecer idénticos**, en lugar de un genérico "no romper nada"? [Completeness, Spec §FR-015, §FR-016]
- [x] CHK025 ¿Los textos exactos que deben preservarse en el histórico de movimientos están especificados literalmente, de modo que la no-regresión sea verificable? [Measurability, Spec §FR-003]
- [x] CHK026 ¿Está definido el requisito de no-regresión de la sincronización de precios hacia las integraciones externas? [Coverage, Spec §FR-017]
- [x] CHK027 ¿La spec advierte sobre el **efecto secundario** de que los borrados de precio pasen a disparar la sincronización externa (comportamiento que hoy no ocurre), o queda como cambio silencioso? [Gap, Conflict]
- [x] CHK028 ¿Está especificado que la auditoría no debe alterar los valores efectivamente guardados ni el resultado de la operación? [Clarity, Spec §FR-014]
- [x] CHK029 ¿Están definidos los requisitos de independencia entre las distintas responsabilidades que conviven en el mismo punto de captura (auditoría vs sincronización externa)? [Coverage, Gap]

## Calidad de los criterios de aceptación

- [x] CHK030 ¿El criterio de performance está **cuantificado** (volumen y margen de tiempo concretos) en lugar de expresado con un adjetivo? [Measurability, Spec §SC-005]
- [x] CHK031 ¿Los criterios de éxito son verificables sin conocer la implementación elegida? [Measurability, Spec §Success Criteria]
- [x] CHK032 ¿Existe un criterio de éxito que cubra específicamente el origen de mayor riesgo (edición masiva)? [Coverage, Spec §SC-007]
- [x] CHK033 ¿El criterio de "cero ruido por reimportación idéntica" está expresado de forma binaria y comprobable? [Measurability, Spec §SC-004]
- [x] CHK034 ¿Cada requisito funcional tiene al menos un escenario de aceptación o criterio de éxito que lo respalde? [Traceability]
- [x] CHK035 ¿Está definido el comportamiento esperado ante un fallo del registro de auditoría, como criterio verificable y no como principio general? [Measurability, Spec §FR-012, §SC-006]

## Dependencias, supuestos y gobernanza

- [x] CHK036 ¿Está declarado el supuesto de que existe un punto único de escritura que cubre todos los orígenes, junto con el plan si ese supuesto resultara falso? [Assumption, Spec §Assumptions]
- [x] CHK037 ¿Está identificado el cambio de modelo de datos que la feature introduce (nuevo valor del conjunto de operaciones auditables)? [Completeness, Spec §Key Entities]
- [x] CHK038 ¿Está registrada la obligación del **Principio I** de actualizar `docs/modelo_datos.md` y `docs/documentacion_principal_crm.md` **antes** de `/speckit-tasks`? [Traceability, Constitución §I]
- [x] CHK039 ¿La spec declara explícitamente que la excepción de FR-009a debe quedar asentada en la documentación de dominio, y no sólo en la spec? [Consistency, Spec §FR-009a]
- [x] CHK040 ¿Está declarado el supuesto de volumen (planillas de miles de filas, unidades de listas y depósitos) que sostiene el criterio de performance? [Assumption, Spec §Assumptions]
- [x] CHK041 ¿Está definido si la reversión del cambio de esquema es posible y bajo qué condiciones? [Gap, Recovery]
- [x] CHK042 ¿Los requisitos de testing obligatorio derivados del **Principio IV** (stock y dinero) están reflejados como exigencia de la feature? [Traceability, Constitución §IV]

## Ambigüedades y conflictos pendientes

- [x] CHK043 ¿Queda claro que el alcance excluye la reversión en bloque de una importación, sin que ningún criterio de éxito la presuponga implícitamente? [Consistency, Spec §Fuera de alcance]
- [x] CHK044 ¿Existe algún requisito que dependa de auditar cambios de stock, cuando la spec declara ese punto fuera de alcance por estar ya cubierto? [Conflict, Spec §Fuera de alcance]
- [x] CHK045 ¿La terminología es consistente a lo largo de la spec (un único término para el origen, para el valor absoluto de stock y para el evento de auditoría)? [Consistency]
- [x] CHK046 ¿Está resuelto si el rótulo de origen debe ser filtrable por sí mismo, o alcanza con que sea legible dentro del detalle? [Ambiguity, Spec §FR-008]

---

## Notes

- Marcar con `[x]` a medida que se validan; anotar hallazgos inline.
- **Ítems de mayor riesgo si fallan**: CHK002 (un camino nuevo sin auditar es exactamente el bug que
  esta spec vino a cerrar), CHK008/CHK010 (sin un invariante verificable, la corrección de concurrencia
  no se puede dar por probada), CHK027 (efecto secundario real sobre las integraciones), CHK038
  (bloquea el avance a `/speckit-tasks` por constitución).
- Este checklist valida la **spec**, no la implementación. La validación de la implementación está en
  [quickstart.md](../quickstart.md).
- Complementa a [requirements.md](./requirements.md), que cubre la calidad general de la spec; éste
  cubre las dimensiones específicas de integridad de datos de esta feature.
