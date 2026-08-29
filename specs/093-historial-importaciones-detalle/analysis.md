# Análisis de consistencia — spec 093

Chequeo cruzado entre `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/` y
`tasks.md`. **Los hallazgos ya están corregidos.**

**Fecha**: 2026-08-28

---

## Cobertura

Los 24 requisitos funcionales tienen tarea, y los que definen comportamiento tienen test.

| Bloque | Requisitos | Tareas |
|--------|-----------|--------|
| Informe (US1) | FR-001 … FR-011 | T001–T011 |
| Archivo (US2) | FR-012 … FR-017 | T012–T018 |
| Limpieza (US3) | FR-018 … FR-022 | T019–T023 |
| Transversales | FR-023, FR-024 | T009, T010, T018 |

---

## Hallazgos y correcciones

### H1 — Faltaba la tarea de documentación (severidad: media) ✅ corregido

El principio I de la constitución obliga a actualizar la documentación de dominio. `modelo_datos.md`
ya se actualizó **antes** de escribir las tareas (las 3 columnas nuevas y la nota de que los
snapshots no son temporales), pero `documentacion_principal_crm.md` no tenía tarea asignada.

**Corrección**: agregada **T027**.

### H2 — El riesgo de rendimiento no tenía criterio de aceptación (severidad: media) ✅ corregido

El plan advertía que comparar por fila daría más de 3.000 consultas, pero ninguna tarea verificaba
que no pasara. Una feature de sólo lectura que tarda 40 segundos se deja de usar.

**Corrección**: **T011** mide el informe sobre la corrida real de 1.117 filas, y el criterio es
explícito: si no responde, se revisa T006 antes de seguir.

### H3 — La limpieza es destructiva y no tenía red (severidad: alta) ✅ corregido

**T021** creaba el comando y nada indicaba cómo estrenarlo. La limpieza borra archivos **no
referenciados por ninguna fila** —incluidos los 23 huérfanos actuales— y un error de criterio ahí se
lleva evidencia que no se puede recuperar.

**Corrección**: `--dry-run` en el contrato y en T021, y **T023** exige que la primera corrida en
producción sea en seco y con la lista revisada antes de borrar.

---

## Consistencias verificadas

- **El informe compara contra el estado actual** y lo dice: FR-005, Decisión 1, el título del
  informe y `advertencia_metodo` en el contrato coinciden. Es la limitación central de la feature y
  está declarada en los cuatro lugares.
- **Los tres estados del archivo** (FR-015, data-model, contrato, T018) se derivan, no se guardan
  como enum, y los tres artefactos lo dicen igual.
- **El formato del JSON del snapshot** está documentado en research, data-model y fijado por T002.
  Es el error que ya se cometió una vez.
- **No se toca el importador** (FR-024): ninguna tarea lo modifica. T016 escribe la copia fuera del
  camino crítico.
- **Los `limite_*` se reusan** (T007) en vez de inventar un mecanismo de detección nuevo.
- **Los huérfanos no se asocian retroactivamente**: research, data-model y T023 dicen lo mismo, y la
  razón (los UUID no quedaron registrados) está escrita.

---

## Riesgos vivos

1. **T006 es la tarea de fondo.** Si se implementa consulta por fila, la feature nace inusable y el
   problema recién se ve con datos reales, no en los tests con 3 filas.
2. **La limpieza borra evidencia.** T023 la protege, pero depende de que alguien mire la lista del
   dry-run en vez de confirmar de memoria.
3. **Si algún día se purgan los snapshots**, esta feature se queda sin fuente. Por eso quedó la
   advertencia en `modelo_datos.md` y no sólo en la spec: el que vaya a purgarlos va a mirar ahí.

---

## Veredicto

**Listo para implementar.** Un hallazgo alto y dos medios, los tres corregidos. Sin requisitos
huérfanos, sin tareas sin requisito, sin contradicciones entre artefactos.
