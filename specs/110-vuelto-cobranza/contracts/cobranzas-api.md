# Contrato — Endpoints de Cobranza con vuelto (spec 110)

**Fecha**: 2026-09-24

Todos los campos nuevos son **opcionales**. Una request sin ellos se comporta exactamente como hoy
(FR-014).

## Convención de nombres

| Nombre | Significado |
|---|---|
| `monto` | **Importe recibido** del cliente (lo que entra a la cuenta de cobro). |
| `vuelto` | Importe devuelto al cliente en el acto. |
| *neto* | `monto − vuelto`. Es lo que se imputa a la venta y lo que se guarda en `cobros.monto`. |

⚠️ El request habla de **recibido**; la columna `cobros.monto` guarda el **neto**. La conversión la
hace el controlador. Ver `data-model.md` §1.

---

## POST `/ventas/{venta}/cobranzas` — `ventas.cobranzas.store`

### Request

| Campo | Tipo | Obligatorio | Reglas |
|---|---|---|---|
| `cuenta_tesoreria_id` | int | sí | `exists:cuentas_tesoreria,id` |
| `monto` | decimal | sí | `gt:0` — importe recibido |
| `vuelto` | decimal | no | `gte:0`, `lt:monto` (FR-006) |
| `cuenta_vuelto_id` | int | condicional | Obligatoria si `vuelto > 0` (FR-011). `exists:cuentas_tesoreria,id` |
| `fecha` | date (ISO) | sí | — |
| `nota` | string | no | — |

### Regla de negocio central (FR-007)

```
monto − vuelto == saldo pendiente de la venta
```

Estricto en ambos sentidos: se rechaza tanto si el neto deja saldo pendiente como si lo supera.

Implementación: la validación `lte` de `StoreCobroRequest` pasa a medirse contra el **neto**
(`monto − vuelto`) en lugar del monto crudo, más una regla que exige igualdad con el saldo cuando
hay vuelto.

⚠️ **Esto NO habilita sobrepagos.** Una cobranza sin vuelto sigue topeada por el saldo, igual que
hoy (`research.md` Decisión 6).

### Errores (422, formato actual `{ok:false, errors:{...}}`)

| Caso | Campo | Mensaje |
|---|---|---|
| `vuelto >= monto` | `vuelto` | "El vuelto no puede ser mayor o igual al importe recibido." |
| `vuelto > 0` sin cuenta | `cuenta_vuelto_id` | "Elegí de qué cuenta sale el vuelto." |
| Neto ≠ saldo pendiente | `monto` | "El importe recibido menos el vuelto debe ser igual al saldo a cobrar ($X)." |
| Neto ≤ 0 | `monto` | "El importe a imputar debe ser mayor a cero." |

### Respuesta 200

```json
{
  "ok": true,
  "cobro": {
    "id": 123,
    "monto": 140000.00,
    "recibido": 155000.00,
    "vuelto": 15000.00,
    "cuenta_vuelto": "Caja Local",
    "fecha": "2026-09-24"
  },
  "a_cobrar": 0,
  "estado_cobro": "cobrada"
}
```

`a_cobrar` y `estado_cobro` ya venían en la respuesta actual y **no cambian de semántica**: se
calculan sobre el neto.

### Efectos

Dentro de **una sola transacción** (FR-008), en `Cobranzas::registrarCobro()`:

1. `cobros` ← fila con `monto` = neto, `vuelto`, `cuenta_vuelto_id`
2. `movimientos_tesoreria` ← `tipo='cobro'`, monto **+recibido**, cuenta de cobro
3. `movimientos_tesoreria` ← `tipo='vuelto'`, monto **−vuelto**, cuenta de vuelto *(sólo si hay vuelto)*

Los dos movimientos comparten `origen_type=Cobro` / `origen_id`.

---

## PUT `/ventas/{venta}/cobranzas/{cobro}` — `ventas.cobranzas.update`

Mismos campos y reglas que el POST. El tope se calcula como `saldo pendiente + neto actual del
cobro` (el cobro que se edita se "devuelve" antes de recalcular), consistente con
`UpdateCobroRequest` hoy.

### Efectos (FR-012)

- Actualiza la fila de `cobros`
- Actualiza **in-place** el movimiento `tipo='cobro'` (no anula+recrea, igual que hoy)
- Sobre el movimiento `tipo='vuelto'`, según el caso:
  - Tenía vuelto y sigue teniendo → actualiza monto/cuenta/fecha
  - No tenía y ahora sí → lo crea
  - Tenía y ahora no (`vuelto` = 0 o vacío) → lo soft-deletea
- Todo en una transacción

---

## DELETE `/ventas/{venta}/cobranzas/{cobro}` — `ventas.cobranzas.destroy`

### Efectos (FR-013)

Soft-delete del cobro **y de sus dos movimientos**. Ninguno de los dos puede quedar vivo.

⚠️ **Punto de falla identificado**: `anularCobro()` resuelve el movimiento vía
`$cobro->movimientoTesoreria`, que es un `morphOne` sin filtrar. Con dos movimientos devuelve uno
cualquiera. Si devuelve el de vuelto, **el ingreso queda vivo en la cuenta** — el mismo incidente
que su docblock documenta. La relación debe filtrarse por `tipo` (ver `data-model.md` §4).

---

## GET `/ventas/{venta}/cobranzas/{cobro}/recibo` — `ventas.cobranzas.recibo`

PDF `Content-Disposition: inline` para el modal compartido (`window.AppPdf.abrir`).

### Cambio requerido (FR-016)

Hoy imprime `$cobro->monto`, que pasa a ser el **neto**. Un cliente que entregó $155.000 recibiría
un recibo que dice $140.000.

Cuando el cobro tiene vuelto, el recibo debe mostrar las tres cifras:

```
Recibido:        $ 155.000,00
Vuelto:          $  15.000,00
Neto imputado:   $ 140.000,00
```

Sin vuelto, el recibo queda **exactamente como hoy** (una sola cifra).

---

## GET `/configuracion/ventas` y PUT — cuenta de vuelto por defecto

Se suma `cuenta_vuelto_id` (nullable) al formulario de Configuración & Ajustes → Ventas, con las
mismas reglas que los demás defaults de esa pantalla.

El modal de cobranza lee ese valor para **preseleccionar** el select de cuenta de vuelto. Cambiarlo
en el modal **no** persiste en la configuración global (FR-010).

---

## Contrato de UI (CLAUDE.md, obligatorio)

- El campo de vuelto vive en el **modal de cobranza existente**, enviado por AJAX sin recargar
- Los dos selects de cuenta usan **Select2** con `dropdownParent` = el modal
- Errores y confirmaciones por **toast de NexaDash**, nunca `alert()` ni flash con recarga
- El campo de fecha sigue siendo `<input type="text" data-fecha-ar>` con `AppFecha` — **nunca**
  `<input type="date">`
- El PDF del recibo se abre con `window.AppPdf.abrir(url, titulo)`
- El campo de vuelto se muestra **siempre**, vacío por defecto: un campo que aparece sólo al superar
  el saldo obligaría a cargar mal el monto primero para que aparezca
