# Analysis: consistencia entre spec, plan y tasks

**Feature**: 083-prevalidacion-importacion | **Fecha**: 2026-08-26

## Resultado: ✅ consistente (con 3 hallazgos, ya corregidos)

## Cobertura de requisitos

| Métrica | Valor |
|---|---|
| Requisitos funcionales (FR) | 30 |
| Criterios de éxito (SC) | 10 |
| Tareas | 50 |
| FR sin tarea que lo cubra | **0** (tras los fixes) |
| SC sin tarea que lo cubra | **0** (tras los fixes) |
| Referencias en tasks a requisitos inexistentes | 0 |
| IDs de tarea duplicados | 0 |
| Numeración correlativa T001–T050 | OK |

## Hallazgos y fixes aplicados

Los tres son huecos de trazabilidad, no contradicciones. Se corrigieron directamente sobre
`tasks.md`, sin consultar (regla de la cadena del proyecto).

### 1. FR-006 sin tarea — *corregido*

**FR-006** ("con cero errores el asistente debe permitir confirmar y proceder como hoy") no estaba
cubierto: todas las tareas apuntaban al camino de bloqueo, ninguna al camino feliz.

**Riesgo real que evitaba el fix**: implementar el bloqueo y que un archivo perfectamente válido
quedara también trabado, por un borde en la condición. Es el error clásico de implementar sólo la
mitad de una regla booleana.

**Fix**: T024 ahora explicita habilitar el botón con cero errores; T032 agrega el caso de archivo
totalmente válido que confirma y termina bien.

### 2. SC-001 y SC-002 sin tarea — *corregido*

Los dos criterios de éxito más importantes de la feature —saber los conteos antes de escribir, y que
una planilla inválida no escriba— no estaban citados por ninguna tarea.

**Fix**: T027 (SC-001), T029 (SC-002) y T044 (los dos, en la verificación manual).

### 3. Cambio de diseño a mitad de la cadena — *propagado*

Durante la cadena, el usuario precisó que la confirmación es un **modal**, no una pantalla nueva, y
pidió que además muestre **qué campos se van a editar** en las actualizaciones.

**Propagado a**: `spec.md` (US1 con dos escenarios nuevos, FR-005b, FR-005c, SC-002b, edge cases y
Key Entities), `plan.md` (estructura de vistas y Fase D), `contracts/validador-filas.md` (campo
`campos` + invariante I7), `data-model.md` (`campos_afectados`), `quickstart.md` (paso 3b),
`checklists/calidad.md` y `docs/documentacion_principal_crm.md` §2.4.

**Efecto secundario positivo**: al ser modal y no pantalla nueva, la feature deja de alterar la
estructura de pasos del asistente y pasa a cumplir la regla de diseño #2 del proyecto (modales +
AJAX). La divergencia respecto de Contagram real se reduce a la existencia del modal, ya documentada.

## Consistencia entre documentos

| Chequeo | Estado |
|---|---|
| Las historias de la spec tienen fases correspondientes en el plan | ✅ US1→2 y 4, US2→3, US3→5, US4→6 |
| Las fases del plan tienen tareas | ✅ tabla de mapeo al inicio de tasks.md |
| El contrato refleja los requisitos que dice cubrir | ✅ I1↔FR-002, I2↔FR-003, I3↔FR-018/019, I4↔FR-020, I6↔FR-012/013, I7↔FR-005b |
| `data-model.md` coincide con el plan en "sin cambios de esquema" | ✅ |
| El quickstart verifica los SC declarados | ✅ |
| La checklist de calidad cubre los cuatro defectos originales | ✅ una sección por defecto |

## Riesgos declarados (no son inconsistencias)

1. **FR-005 revierte la tolerancia por fila** de las specs 006/026. Decisión explícita del usuario,
   documentada en la spec, en el plan y en §2.4, con su caso límite (una fila mala en 9.000 bloquea
   el archivo entero). Si molesta en el uso diario, revertirlo es tocar un solo requisito.
2. **T020 queda deliberadamente abierta**: dónde vive el informe de prevalidación se decide **con una
   medición** durante la implementación, no ahora. El caso que manda es 10.000 filas todas con error.
   Es una decisión que necesita un dato que todavía no existe; fijarla ahora sería inventar.
3. **El riesgo estructural de la feature** es que el validador y el importador se desincronicen con
   el tiempo. Está mitigado por diseño (comparten el código) y por T006, que los compara fila por
   fila. Si ese test se borra, la garantía se cae.

## Verificado contra el código (no asumido)

| Afirmación | Cómo se verificó |
|---|---|
| PhpSpreadsheet calcula las fórmulas del archivo real | Ejecutado sobre `Ferrum nuevos (2).xlsx`: `getCalculatedValue()` devuelve el valor correcto en ambas columnas. 51 ms / 148 filas |
| `precio_venta` no tiene alias | Leído en `DefinicionCamposImportables.php:91` |
| El export escribe `Precio venta` | Leído en `ProductosExport::headings()` |
| Sólo existe `ProductosExport` | `ls app/Exports/` |
| El locale de la app es `en` y no hay `lang/` | Leído en `config/app.php:95` |
| El resumen se contamina entre importaciones | **Reproducido con test**: 2 clientes creados, el resumen informó 1002 |
| `validarFila()` corta en el primer error | Leído: `return [false, $validator->errors()->first()]` |
| El deshacer no borra ni libera ids | Ejecutado sobre una corrida real de 124 productos |
