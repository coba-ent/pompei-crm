# Análisis de consistencia — spec 094

Chequeo cruzado entre `spec.md`, `plan.md`, `research.md`, `checklists/seguridad.md` y `tasks.md`.
**Los hallazgos ya están corregidos.**

**Fecha**: 2026-08-31

---

## Cobertura

| Bloque | Requisitos | Tareas |
|---|---|---|
| Lectura del Excel | FR-008-bis, FR-008-ter, FR-021, FR-022 | T001–T005 |
| Matcheo | FR-001 … FR-005 | T006–T009 |
| Corte | FR-006, FR-007 | T010–T012 |
| Garantía | FR-012 … FR-015 | T013–T018 |
| Comando | FR-017 … FR-020 | T019–T023 |
| Verificación de saldos | FR-016 | T024 |
| Depósitos | FR-010, FR-011 | T004 |

Los 25 requisitos tienen tarea. Los que definen comportamiento tienen test.

---

## Hallazgos y correcciones

### H1 — El alcance real del hueco estaba subdimensionado (severidad: alta) ✅ corregido

La spec decía "el histórico arranca en enero 2024" sin cuantificar qué queda afuera. Medido:
**10.996 de las 23.736 ventas legacy son de 2021–2023** — el **46%**. Y 2023 solo tiene 4.845, más
que cualquier año que sí se carga.

Es un dato que cambia la expectativa: después de esta carga, **casi la mitad de las ventas legacy
siguen sin movimiento**. Alguien que abra el historial de un producto viejo va a seguir viendo un
saldo que no se explica por sus movimientos — el mismo síntoma que originó la spec, sólo que
corrido a 2023.

**Corrección**: agregada la sección "Lo que queda afuera" a la spec, con el número explícito, y una
nota en SC-001 de que el criterio de éxito aplica a productos con actividad desde 2024.

### H2 — `usuario_id` sin decisión declarada (severidad: media) ✅ corregido

El Excel trae una columna `Usuario` ("Info Pompei", "Juan Ignacio Conlon", "Ventas Online") que son
usuarios de **Contagram**, no del CRM. El plan los descartaba en una fila de tabla, sin justificarlo.

Mapearlos por nombre sería adivinar; dejarlos en `NULL` pierde información real. Se optó por `NULL`
porque un `usuario_id` equivocado atribuye una operación a una persona que no la hizo, y eso es peor
que no tener el dato. **Corrección**: la descripción del movimiento conserva el nombre del usuario de
Contagram como texto, así la información no se pierde aunque no sea una relación.

### H3 — El tipo `ajuste` no estaba mapeado por operación (severidad: media) ✅ corregido

El plan decía "`entrada` si cantidad > 0, `salida` si < 0; `ajuste` para las manuales", pero no
definía cuáles son las manuales. Hay **19 valores distintos** de `Operación` en el dato real.

**Corrección**: mapeo explícito en el plan. `Venta`/`Compra`/`Nota de Crédito` → `entrada`/`salida`
según signo; `Aumento`/`Disminución`/`Importación`/`Sincronización`/`Registro Inicial` → `ajuste`.
Las variantes `... Eliminado/a` siguen el tipo de su operación base.

### H4 — La verificación de saldos no tenía criterio de falla (severidad: media) ✅ corregido

FR-016 y T024 comparaban el acumulado contra `Saldo Stock` pero no decían qué diferencia es
aceptable. Como faltan 2021–2023, **toda** diferencia de saldo absoluto es esperable, así que
comparar el valor absoluto no informa nada.

**Corrección**: la verificación compara **deltas entre movimientos consecutivos**, no saldos
absolutos. Si entre dos movimientos de un producto el saldo de Contagram saltó 3 unidades y el
movimiento dice 2, eso **sí** es un error de carga. El offset constante por el hueco de 2021–2023 se
ignora por construcción.

### H5 — Faltaba el caso de un producto con movimientos en dos depósitos (severidad: baja) ✅ corregido

Un producto puede tener stock en Local y en Full, con saldos independientes. La verificación de H4
tiene que agrupar por **producto + depósito**, no sólo por producto.

**Corrección**: explicitado en T024 y en la Decisión 8.

---

## Consistencias verificadas

- **El descarte de cantidad 0** aparece en spec (FR-008-bis), research (Decisión 2), plan (pieza 1) y
  tasks (T001, T005) con el mismo número: 22.326 de 53.844. Es el hallazgo que más cambia el
  resultado y está en los cuatro artefactos.
- **El corte por "ya tiene movimiento"** y su razón (las 83 compras con fecha del 06/08) coinciden en
  FR-006, Decisión 5 y T010. El test que lo protege está nombrado explícitamente.
- **La inserción sin Eloquent** está en FR-013, Decisión 6, el plan y T017, siempre con la misma
  justificación: los 31.518 `created` marcarían el catálogo entero como pendiente.
- **`stocks` y `movimientos_stock` son tablas separadas** — verificado: 9.174 filas en `stocks`. El
  comando no la toca y T018 lo fija.
- **Ningún movimiento actual tiene `origen_type` NULL con tipo distinto de `ajuste`** (verificado: 0
  casos), así que cargar las filas huérfanas como `ajuste` es consistente con lo que ya existe.
- **La Fase 6 no se saltea**: la regla "nunca probar en producción" aparece en el checklist y en la
  nota de entrega de tasks.

---

## Riesgos vivos

1. **El hueco de 2021–2023 (H1) es la limitación de fondo.** No es un defecto de la implementación:
   es que la fuente no está. Si el usuario después quiere el histórico completo, la vía es conseguir
   esos dos Excel y volver a correr el mismo comando — que por FR-019 no duplica nada.
2. **T017 es la tarea crítica.** Si la inserción se hace por Eloquent, el daño no se ve en la base:
   se ve al día siguiente, cuando el cron publica stock histórico en Mercado Libre.
3. **La verificación de saldos (T024) puede reportar mucho ruido** por el hueco de 2021–2023. Si
   inunda la salida, el riesgo es que se ignore. Por eso H4 la reorientó a deltas.

---

## Veredicto

**Listo para implementar.** Un hallazgo alto, tres medios y uno bajo, los cinco corregidos. Sin
requisitos huérfanos ni contradicciones entre artefactos.

La propiedad que sostiene toda la spec es estructural, no procedimental: **el stock actual vive en
`stocks` y esta carga sólo escribe en `movimientos_stock`.** No depende de que nadie tenga cuidado.
