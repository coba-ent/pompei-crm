# Tasks — spec 099

`[P]` = paralelizable dentro del bloque.

**Regla de esta feature**: esta validación es lo único que impide emitir una NC/ND por más de lo
facturado. Cada test que verifica que algo *ahora se puede* tiene al lado uno que verifica que lo
que *no se debe poder* sigue sin poderse.

---

## Fase 1 — Reproducir el bug

- [ ] **T001** Test que reproduce la compra **2478**: 3 líneas del mismo producto (+1, −1, +1), una
      nota ya emitida sobre la tercera. Intentar ajustar la línea libre tiene que **fallar antes del
      fix** con "la cantidad máxima disponible para ajustar es 0". Si el test pasa de entrada, el
      escenario está mal armado y todo lo que sigue no prueba nada.
- [ ] **T002** `[P]` Test de la contradicción (SC-003): `itemsDisponibles()` ofrece la línea 12022 y
      la validación la rechaza. Es el hallazgo que originó la spec y merece quedar fijado.

## Fase 2 — La decisión del modo

- [ ] **T003** `[P]` `AjustesPendientesNotaCreditoDebitoTest`: `topeDelRenglon()` con
      `item_origen_id` devuelve el pendiente **de esa línea**, no el del producto agregado.
- [ ] **T004** `[P]` Test: **sin** `item_origen_id` devuelve el agregado — el comportamiento de hoy
      (FR-002), que es lo que cubre las notas históricas.
- [ ] **T005** `[P]` ⚠️ Test: un `item_origen_id` **de otro comprobante** NO saltea el tope. Cae al
      agregado y no lanza excepción (FR-003). Es el caso que un renglón manipulado podría usar para
      emitir una nota por más de lo facturado.
- [ ] **T006** `topeDelRenglon()` en el servicio. La decisión del modo vive **acá y en un solo
      lugar**: el bug existe porque `itemsDisponibles()` y la validación la tomaban cada uno por su
      cuenta.

## Fase 3 — Los dos requests

- [ ] **T007** `StoreNotaCreditoDebitoRequest` usa `topeDelRenglon()`, pasando
      `item_origen_id ?? null`.
- [ ] **T008** `UpdateNotaCreditoDebitoRequest` ídem, conservando la exclusión de la nota en edición
      (FR-004).
- [ ] **T009** `[P]` Test de edición: editar la nota 883 sigue permitiendo su propia cantidad —si la
      nota en edición no se excluyera, editarla sin tocar nada daría error.

## Fase 4 — Que no se debilite el tope ⚠️

- [ ] **T010** ⚠️ **SC-002.** Test: ajustar **de nuevo** la línea ya cubierta por la nota 883 sigue
      siendo rechazado. Este test es tan importante como T001: si T001 pasa y T010 falla, el fix
      rompió la única protección que hay contra emitir de más.
- [ ] **T011** `[P]` Test (FR-008): un comprobante **sin producto repetido** valida exactamente igual
      que antes del cambio.
- [ ] **T012** `[P]` Test: la línea negativa se valida sola, sin compensar contra las positivas.

## Fase 5 — Que el usuario entienda

- [ ] **T013** Mensaje de error con el importe de la línea cuando el modo es por línea (FR-005). En
      modo agregado el texto no cambia.
- [ ] **T014** Selector con **importe y pendiente** por opción cuando hay más de una línea del mismo
      producto (FR-006). Sin esto, la compra 2478 muestra tres renglones que dicen "99999" y nada
      más: el fix desbloquea la operación pero el usuario elige a ciegas.

## Fase 6 — Verificación real

- [ ] **T015** Reproducir la compra 2478 sobre la **copia local** y confirmar: la línea 12022 acepta
      1, la 12023 sigue en 0.
- [ ] **T016** Verificar en el **navegador** que el selector distingue las tres líneas y que el alta
      completa termina bien. La suite en verde no alcanza — ya pasó en este proyecto que MySQL
      estricto se comportara distinto de SQLite.
- [ ] **T017** Suite completa: separar las fallas propias de las **13 preexistentes** del Informe de
      Ventas y las de la spec 096/098 que están en curso en el working tree.
- [ ] **T018** Actualizar `docs/documentacion_principal_crm.md`: la validación de NC/ND es por línea
      cuando el renglón la identifica, y **queda la brecha conocida de Ventas**, fuera de alcance por
      decisión del usuario.

---

## Dependencias

```
Fase 1 (reproducir)  ──►  Fase 2 (servicio)  ──►  Fase 3 (requests)  ──►  Fase 4 (no debilitar)
                                                        └──►  Fase 5 (UX)
Fase 6 (verificación real) al final
```

## Nota de entrega

**T010 no es opcional ni se deja para después.** Es el test que distingue "arreglé el bug" de
"rompí la validación": los dos hacen que la compra 2478 deje de dar error.
