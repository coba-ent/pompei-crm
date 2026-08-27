# Análisis de consistencia — spec 084

Chequeo cruzado entre `spec.md`, `plan.md`, `data-model.md`, `contracts/`, `quickstart.md` y
`tasks.md`. **Los hallazgos ya están corregidos**; quedan acá para que se vea qué se revisó.

**Fecha**: 2026-08-26

---

## Cobertura de requisitos

Los 34 requisitos funcionales (FR-001 a FR-033 más FR-001a) tienen al menos una tarea que los
implementa y, los del corte, un test que los fija.

| Bloque | Requisitos | Tareas |
|--------|-----------|--------|
| Corte (US1) | FR-001 … FR-015, FR-001a | T006–T022c |
| Cambio de lista (US2) | FR-016 … FR-019 | T023–T028 |
| Chequeo (US3) | FR-020 … FR-027 | T029–T036 |
| Ventanas silenciosas (US4) | FR-028 … FR-030 | T037–T040 |
| Transversales | FR-031 … FR-033 | T021, T020, T036 |

---

## Hallazgos y correcciones

### H1 — FR-003 no tenía tarea (severidad: alta) ✅ corregido

La spec exige que el umbral sea **configurable desde la pantalla**, y el plan lo nombra, pero las
tareas sólo creaban la columna con su default. **No había ninguna tarea que agregara el campo a la
interfaz ni que validara el rango.**

Se habría implementado el corte con un umbral del 20% imposible de cambiar sin tocar la base — que
funciona, pero incumple el requisito y deja al usuario sin la perilla justo cuando la necesita.

**Corrección**: agregadas **T022b** (campo + validación 0–100 + texto de ayuda) y **T022c** (test de
rango y de que el cambio surte efecto inmediato).

### H2 — El texto de ayuda del umbral no estaba especificado (severidad: media) ✅ corregido

`data-model.md` deja claro que umbral `100` **no** apaga el corte —sigue reteniendo precio inválido y
sin referencia— pero eso no aparecía en ninguna tarea de interfaz. Es una trampa concreta: alguien
que quiera desactivar el corte va a poner 100 y va a creer que lo apagó.

**Corrección**: T022b incluye explícitamente el texto de ayuda con el comportamiento de los dos
extremos.

### H3 — T037 invierte una aserción existente sin decirlo (severidad: media) ✅ corregido

`PrecioProductoObserverPremiumTest::test_un_vinculo_sin_tipo_todavia_recibe_la_lista_general` afirma
hoy que un vínculo sin tipo **sí** recibe el precio general. La US4 pide lo contrario. La tarea
cambiaba la aserción sin registrar que se estaba invirtiendo una decisión documentada.

**Corrección**: T037 lleva ahora la advertencia y pide dejar el cambio de intención escrito en el
docblock del test. Un test que cambia de sentido sin explicación es una trampa para quien lo lea
después.

---

## Consistencias verificadas (sin hallazgos)

- **Un solo lugar decide la lista por tipo.** FR-021, Decisión 9 y T031 dicen lo mismo y ninguna
  tarea reimplementa `resolverListaPrecio()`. Es la causa raíz del incidente del 25/08 y la
  restricción se sostiene en los cuatro artefactos.
- **`precio_pendiente` no se reusa para retenciones.** Decisión 4, data-model y T015 coinciden.
- **`NULL` retiene.** FR-005, Decisión 1, edge cases, quickstart caso 4 y T008: la misma regla, con
  el mismo sentido, en los cinco lugares. Es la regla más fácil de invertir por descuido.
- **El borde del umbral.** Clarificaciones, FR-002 ("mayor"), quickstart caso 3 y T006 concuerdan en
  que la caída igual al umbral **pasa**.
- **Orden de rollout.** Decisión 5, quickstart y T044 dicen lo mismo, y la dependencia T032 → T044
  está explicitada.
- **Los contratos cubren todos los endpoints** que las tareas construyen, y ninguno sobra.
- **Las especificaciones de diseño obligatorias** (DataTables AJAX, modales sin recarga, toasts,
  Select2) están en FR-033 y bajadas a T020, T028 y T036.

---

## Riesgos que quedan vivos (no son defectos de los artefactos)

1. **T013 es la tarea delicada de toda la feature.** Meter una condición nueva dentro de
   `enviarUno()` puede alterar el orden de los cortes existentes. El criterio de aceptación es duro y
   está escrito: `SincronizadorPreciosTest` tiene que seguir en verde **sin tocarlo**.
2. **El rollout puede salir mal por apuro.** Si alguien corre las migraciones y activa el corte sin
   poblar `precio_publicado`, el sistema retiene todo y la reacción natural es desactivarlo. Está en
   T044, en el quickstart, en el research y en la documentación principal: cuatro veces, a propósito.
3. **Tiendanube sigue expuesta** (CHK032). No es un defecto de esta spec —está fuera de alcance por
   decisión— pero es la brecha conocida más grande que queda después de implementarla.

---

## Veredicto

**Listo para implementar.** Un hallazgo de severidad alta y dos medios, los tres corregidos. Sin
requisitos huérfanos, sin tareas sin requisito, sin contradicciones entre artefactos.
