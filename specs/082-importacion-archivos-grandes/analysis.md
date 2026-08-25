# Análisis de consistencia entre artefactos

**Fecha**: 2026-08-25 | **Feature**: 082-importacion-archivos-grandes

Chequeo no destructivo de consistencia entre `spec.md`, `plan.md` y `tasks.md`, más los artefactos de
apoyo. **Los hallazgos ya fueron corregidos** (regla del proyecto: los fixes de `analyze` se aplican
directamente, no se consultan).

## Resultado final

| Chequeo | Estado |
|---|---|
| Requisitos funcionales (FR-001..FR-019) con tarea que los cubra | ✅ 19/19 |
| Criterios de éxito (SC-001..SC-007) verificables por alguna tarea o checklist | ✅ 7/7 |
| User stories con tareas asignadas | ✅ US1, US2, US3 |
| Constitución respetada | ✅ sin violaciones |
| Documentación de dominio actualizada antes de `tasks` | ✅ §2.4 |
| Contradicciones entre artefactos | ✅ ninguna pendiente |

**30 tareas** en 5 fases, con MVP entregable al terminar la Phase 2.

## Hallazgos corregidos

### H1 — Tres requisitos sin ninguna tarea que los verificara (severidad: alta)

La primera versión de `tasks.md` dejaba afuera:

- **FR-006** (progreso informado por tanda): se asumió cubierto porque el progreso ya existe hoy,
  pero el `total` **cambia de origen** (pasa a leerse de la fuente de filas en vez del array
  completo). Un error ahí deja la barra de progreso mintiendo en toda importación grande.
- **FR-017** (automapeo por encabezado y alias): mismo problema, y más riesgoso. Los encabezados
  ahora vienen del NDJSON. Si el volcado altera tipos o espacios, el automapeo de `Stock Local` /
  `Stock Full` / `Punto de Reposición` se rompe **en silencio** — que es exactamente el bug que la
  spec 074 ya tuvo que arreglar una vez.
- **FR-016** (reglas de mapeo del Paso 2): sin verificación explícita.

**Corrección**: se agregaron **T013** (progreso), **T014** (automapeo) y **T015** (reglas de mapeo).

### H2 — SC-003 (10.000 filas) sin tarea que lo probara (severidad: media)

El criterio declara soporte para 10.000 filas como margen de crecimiento, pero todas las pruebas
apuntaban a las 9.632 del catálogo actual. Probar sólo el tamaño actual no valida el margen que la
spec promete.

**Corrección**: se agregó **T028** con una prueba explícita a 10.000 filas.

### H3 — Falta de trazabilidad explícita requisito ↔ tarea (severidad: media)

Las tareas describían bien el trabajo pero no citaban qué requisito cubría cada una, así que la
cobertura sólo podía verificarse leyendo e infiriendo. Fue justamente lo que ocultó H1 y H2.

**Corrección**: cada tarea cita ahora su(s) FR/SC. La cobertura es verificable mecánicamente:

```bash
comm -23 <(grep -o 'FR-[0-9]\{3\}' spec.md | sort -u) \
         <(grep -o 'FR-[0-9]\{3\}' tasks.md | sort -u)   # vacío = cobertura completa
```

### H4 — Numeración de fases inconsistente entre `plan.md` y `tasks.md` (severidad: baja)

El plan usaba fases A–F y las tareas fases 1–5, sin correspondencia declarada.

**Corrección**: se agregó una tabla de mapeo al principio de `tasks.md`.

## Riesgos que quedan señalados (no son defectos, son advertencias para implementar)

### R1 — El orden T017 → T020 es crítico

Implementar el reintento del frontend (T020) **antes** que la idempotencia de la tanda (T017)
produciría un bug peor que el que se está arreglando: en el escenario real del incidente (PHP termina
la tanda, nginx corta la respuesta), el reintento reprocesaría filas ya aplicadas y **duplicaría los
snapshots de deshacer**, con el "estado anterior" ya pisado por el primer intento. El undo quedaría
restaurando valores incorrectos.

Está marcado en `tasks.md` (sección Dependencies), en `research.md` (Decisión 3 y 5) y como ítem del
checklist de robustez.

### R2 — El comportamiento heredado de `$limite = null`

Hoy, con `$limite === null`, `importar()` **ignora el `offset`** y procesa todo el archivo. Es
contraintuitivo y ya causó un error real durante la resolución manual del incidente del 25/08.

El refactor debe **preservarlo** (los tests existentes y las llamadas por CLI dependen de él), no
"arreglarlo" de paso. Cubierto por T005 y documentado en el contrato.

### R3 — Verificación en producción

El VPS está en uso real. Las pruebas de T027 y T028 van **en local**. La validación post-despliegue es
sólo de lectura, y el cambio de `fastcgi_read_timeout` requiere autorización explícita.

Además, el VPS está en el commit `143beadc` y local tiene `93287e00` (Punto de Reposición) sin
desplegar: el próximo deploy lleva ambas cosas.

## Cobertura por requisito

| Requisito | Tareas |
|---|---|
| FR-001 interpretar una sola vez | T001, T004, T006 |
| FR-002 cada tanda lee sólo lo suyo | T001, T002, T004, T010 |
| FR-003 memoria independiente del total | T003, T008 |
| FR-004 estado transitorio, se borra | T009 |
| FR-005 tanda de 250, ajustable | T007 |
| FR-006 progreso por tanda | T013 |
| FR-007 reintento 3x con backoff | T020 |
| FR-008 error claro + retomar | T021 |
| FR-009 retoma exacta, sin repetir ni saltear | T017, T018 |
| FR-010 una sola corrida | T018, T019 |
| FR-011 upsert por Id sin cambios | T016 |
| FR-012 fila inválida no aborta | T016 |
| FR-013 snapshot + auditoría | T016, T019 |
| FR-014 sin cambios → sin eventos | T012 |
| FR-015 stock atómico | T016 |
| FR-016 reglas de mapeo del Paso 2 | T015 |
| FR-017 automapeo y alias | T014 |
| FR-018 archivos chicos idénticos | T005, T011 |
| FR-019 las tres entidades | T024, T025, T026 |
