# Tasks — spec 109

`[P]` = paralelizable dentro del bloque.

**Regla de esta feature**: el observer corre en **cada** movimiento de stock del sistema y vive
dentro del guardado de ventas y compras. Un error acá no rompe la sincronización: rompe guardar una
venta. Cada test de lo que ahora sí se marca va acompañado de uno de lo que **no debe cambiar**.

---

## Fase 0 — Las mediciones previas

- [ ] **T001** Correr la suite del observer **antes de tocar nada** y anotar el verde de partida.
- [ ] **T002** Registrar el conteo de escrituras de stock del día en `ml_operaciones_log`, para
      medir después cuánto sube el volumen.

## Fase 1 — Mercado Libre ⚠️

- [ ] **T003** ⚠️ **EL TEST QUE REPRODUCE EL 28/09.** Producto con dos publicaciones, venta de ML
      por una de ellas: **las dos** quedan pendientes. Hoy falla.
- [ ] **T004** Reescribir los 3 tests que exigen el comportamiento viejo, cada uno con su docblock
      explicando por qué cambió:
      - `test_convertir_orden_de_mercadolibre_no_marca_pendiente_el_vinculo`
      - `test_orden_de_ml_marca_pendientes_las_otras_publicaciones_del_producto`
      - `test_venta_manual_sobre_mismo_producto_si_marca_pendiente_tras_una_orden_ml`
- [ ] **T005** Quitar la exclusión en `ramaMercadoLibre()`.
- [ ] **T006** `[P]` ⚠️ **NO-REGRESIÓN**: venta manual, compra y ajuste siguen marcando todas las
      publicaciones. Es el camino de la mayoría de los movimientos y no debe moverse.
- [ ] **T007** `[P]` ⚠️ **NO-REGRESIÓN**: un movimiento en un depósito distinto al de ML **no marca
      nada** (FR-006). El corte por depósito se conserva.
- [ ] **T008** `[P]` Test: producto con **una sola** publicación vendida por ML también queda
      pendiente.
- [ ] **T009** `[P]` Test de FR-002: el stock que se publica se lee **al enviar**, no al marcar.

## Fase 2 — Tiendanube

- [ ] **T010** ⚠️ Test equivalente con `ramaTiendanube()`.
- [ ] **T011** Revisar los tests existentes de Tiendanube y reescribir los que exijan lo viejo.
- [ ] **T012** Quitar la exclusión ahí.

## Fase 3 — Limpieza

- [ ] **T013** Borrar `publicacionesDeLaOrdenMl()`, `variantesDeLaOrdenTn()` y `ventaDeOrigen()`
      más los imports que queden sin uso. ⚠️ Verificar con grep que nadie más los llame.
- [ ] **T014** Comentario explicando **por qué** se marca también la que vendió: el valor que viaja
      es el del CRM leído al enviar, y sin esto un empujón con datos viejos queda sin corregir
      (caso real del 28/09, MLA1808325052).

## Fase 4 — Verificación

- [ ] **T015** Suite completa, comparando contra el verde de T001.
- [ ] **T016** Actualizar `docs/documentacion_principal_crm.md` §3.2.ter con el criterio nuevo.
- [ ] **T017** Actualizar el runbook `.claude/skills/chequeo-stock/SKILL.md`: hoy dice que una
      desfasada sin `[BLOQUEADA]` es "una congelada". Con este cambio esa causa desaparece.

## Fase 5 — Producción

- [ ] **T018** Deploy.
- [ ] **T019** Correr `scripts/stock/comparar_mercadolibre.php`: ninguna desfasada fuera de las
      `under_review`.
- [ ] **T020** Comparar el volumen de escrituras contra T002.
- [ ] **T021** Confirmar en el chequeo de rutina siguiente que no aparecen desfasadas por esta causa.

---

## Dependencias

```
T001/T002 ──► Fase 1 (ML) ──► Fase 2 (TN) ──► Fase 3 ──► Fase 4 ──► Fase 5
```

## Nota de entrega

**T003, T006 y T007 no se saltean.** T003 prueba que el cambio ataca el bug real; T006 y T007 son lo
único que garantiza que no se rompió el camino que hoy **sí** funciona.
