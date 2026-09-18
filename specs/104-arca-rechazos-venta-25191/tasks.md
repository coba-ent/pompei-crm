# Tasks — spec 104

`[P]` = paralelizable dentro del bloque.

**Regla de esta feature**: `CalculoComprobante` alimenta Venta, Compra, Presupuesto, Notas, el Libro
IVA y la cuenta corriente, que ya concilian peso por peso contra Contagram. Cada test que verifica
que el IVA nuevo cierra tiene al lado uno que verifica que **lo viejo no se movió**.

---

## Fase 0 — Las mediciones de referencia ⚠️

- [ ] **T001** ⚠️ Guardar el estado de hoy **antes de tocar nada**: las 521 ventas con desvío (ids y
      totales), el total del Libro IVA del período abierto y el saldo de Cta Cte de clientes. Sin
      esto no hay forma de probar SC-003.

## Fase 1 — El IVA ⚠️

- [ ] **T002** ⚠️ **EL TEST QUE REPRODUCE LA 25191.** 4 líneas, 15% de descuento general, 21%, con
      los importes reales. Hoy tiene que **fallar**: IVA guardado 355.660,45 vs 355.660,42 esperado.
- [ ] **T003** El cambio en `CalculoComprobante::calcular()`: `subtotal_con_iva` se deriva de
      `$subtotalFinal`, no de `$subtotalConIvaLinea`.
- [ ] **T004** `[P]` Test: sin descuento general el resultado **no cambia** — el caso que hoy ya
      cierra tiene que seguir dando idéntico.
- [ ] **T005** `[P]` Test por alícuota: 10,5 / 21 / 27 / 5 / 2,5 y 0 (FR-003).
- [ ] **T006** `[P]` Test: descuento por línea + general combinados.
- [ ] **T007** `[P]` Test: descuento general cargado como **monto**, no porcentaje.
- [ ] **T008** `[P]` Test: línea negativa conserva el signo.

## Fase 2 — Los otros tres comprobantes

- [ ] **T009** `[P]` Test en Compra (FR-005).
- [ ] **T010** `[P]` Test en Presupuesto.
- [ ] **T011** ⚠️ Test que confirma que las **Notas NO cambian** (FR-005a). Verificado en
      producción: `nota_credito_debito_items` no tiene `subtotal_con_iva` — guarda `precio` e
      `iva_pct` y deriva el IVA al consultarse. El test fija que su desglose por alícuota da
      idéntico antes y después.
- [ ] **T011a** `[P]` Test en ventas convertidas desde **Mercado Libre** (`ConversorOrdenAVenta`).
- [ ] **T011b** `[P]` Test en ventas convertidas desde **Tiendanube** (su `ConversorOrdenAVenta`).

## Fase 3 — Que nada viejo se mueva ⚠️

- [ ] **T012** ⚠️ Re-correr la consulta de T001: las **mismas 521**, con los mismos totales. Si
      aparece una de más o una cambió, el fix tocó datos históricos y hay que frenar.
- [ ] **T013** `[P]` Libro IVA del período: idéntico al de T001.
- [ ] **T014** `[P]` Cta Cte de clientes: idéntica.
- [ ] **T015** Suite completa, separando lo propio de fallas preexistentes.

## Fase 4 — La Condición de IVA ⚠️ (investigación)

- [ ] **T016** ⚠️ Consultar `FEParamGetCondicionIvaReceptor` contra ARCA **producción**. Es consulta,
      no emisión: no genera comprobantes.
- [ ] **T017** Volcar la respuesta a `contracts/condiciones-iva-arca.md`: código, descripción y clase
      de comprobante de **cada** fila.
- [ ] **T018** Comparar contra las **cinco** filas de `condiciones_iva` (no sólo Responsable
      Inscripto) y corregir las que no coincidan.
- [ ] **T019** Test del mapeo corregido.
- [ ] **T020** Validación previa de FR-009, **sólo si T016 confirma** que ARCA restringe por clase de
      comprobante. Si no, esta tarea se cae y se documenta por qué.

## Fase 5 — La prueba real

- [ ] **T021** Actualizar `docs/documentacion_principal_crm.md` con el criterio de derivación del IVA
      y la tabla de condiciones.
- [ ] **T022** ⚠️ **Requiere OK del usuario.** Deploy y emitir la **25191** contra ARCA real.
- [ ] **T023** Verificar CAE (SC-001) y que el IVA declarado coincida con el desglose.
- [ ] **T024** Emitir una Factura A nueva con descuento general y confirmar que cierra (SC-002).

---

## Dependencias

```
T001 (mediciones) ──► Fase 1 (IVA) ──► Fase 2 (comprobantes) ──► Fase 3 (no-regresión)
Fase 4 (ARCA) es independiente: puede ir en paralelo
Fase 3 + Fase 4 ──► Fase 5 (producción)
```

## Nota de entrega

**T001, T002 y T012 no se saltean.** T001 es lo único que permite demostrar que no se rompió nada
viejo; T002 es lo único que prueba que el fix ataca el bug real y no otro; T012 es el que avisa si
el cambio se llevó puesto el histórico ya conciliado.

**T016 va antes que cualquier decisión sobre condiciones de IVA.** El mapeo actual se ve correcto y
ARCA igual lo rechaza: sin la tabla real, cualquier cambio ahí es adivinanza.
