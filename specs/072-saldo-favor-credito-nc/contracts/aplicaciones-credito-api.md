# Contrato: API de aplicación de crédito

**Spec**: [../spec.md](../spec.md) · **Data model**: [../data-model.md](../data-model.md)

Todos los endpoints devuelven JSON y siguen el patrón del proyecto: `{ok: bool, mensaje: string}` en
éxito, `{ok: false, errors: {campo: [msg]}}` con HTTP 422 en error de validación.

## 1. Crédito disponible de un comprobante

```
GET /ventas/{venta}/credito-disponible
GET /compras/{compra}/credito-disponible
```

Devuelve el crédito que el cliente/proveedor de ese comprobante tiene para aplicarle. Lo consume el
modal de cobranza/pago para decidir si ofrece el medio "Saldo a favor" (FR-006).

**200**:

```json
{
  "ok": true,
  "disponible_total": 30771.29,
  "saldo_pendiente": 27306.00,
  "aplicable": 27306.00,
  "origenes": [
    {
      "comprobante_id": 24582,
      "comprobante_label": "Venta 24582",
      "nota_credito_debito_id": 855,
      "nota_label": "NC B 0001-00000123",
      "fecha": "2026-08-19",
      "disponible": 30771.29
    }
  ]
}
```

- `aplicable` = `min(disponible_total, saldo_pendiente)`; es el máximo que el modal debe permitir.
- `origenes` viene ordenado del más antiguo al más nuevo (FR-008).
- Si no hay crédito: `disponible_total: 0` y `origenes: []`. El front NO ofrece la opción.

## 2. Aplicar crédito

```
POST /ventas/{venta}/aplicaciones-credito
POST /compras/{compra}/aplicaciones-credito
```

El `{venta}`/`{compra}` de la URL es el **destino**. El origen lo elige el servidor consumiendo del
más antiguo al más nuevo, salvo que el cliente mande `origen_id` explícito.

**Request**:

```json
{
  "monto": 27306.00,
  "fecha": "2026-08-20",
  "nota": "Devolución cambio de producto",
  "origen_id": null
}
```

**Validación**:

| Campo | Regla |
|---|---|
| `monto` | requerido, numérico, `> 0`, `<= aplicable` |
| `fecha` | requerida, fecha válida (ISO hacia el backend, regla 6 de CLAUDE.md) |
| `nota` | opcional, texto |
| `origen_id` | opcional; si viene, debe ser un comprobante del mismo tipo, mismo cliente/proveedor, distinto del destino y con crédito disponible suficiente |

**201**:

```json
{
  "ok": true,
  "mensaje": "Saldo a favor aplicado.",
  "aplicaciones": [
    {"id": 1, "origen_id": 24582, "monto": 27306.00, "nota_credito_debito_id": 855}
  ],
  "a_cobrar": 0,
  "estado_cobro": "cobrada",
  "credito_disponible_restante": 3465.29
}
```

Puede devolver más de una fila en `aplicaciones` si el monto se cubrió con varios orígenes.

**422** — casos:

| Situación | Mensaje |
|---|---|
| Monto mayor al saldo del comprobante | "El monto supera el saldo a cobrar." |
| Monto mayor al crédito disponible | "El monto supera el saldo a favor disponible del cliente." |
| Sin crédito disponible | "El cliente no tiene saldo a favor para aplicar." |
| Origen = destino | "No se puede aplicar el saldo a favor de un comprobante sobre sí mismo." |
| Origen de otro cliente | "El comprobante de origen es de otro cliente." |

**409** — conflicto de concurrencia: "El saldo a favor cambió mientras se aplicaba. Volvé a intentar."

## 3. Anular una aplicación

```
DELETE /ventas/{venta}/aplicaciones-credito/{aplicacion}
DELETE /compras/{compra}/aplicaciones-credito/{aplicacion}
```

Soft-delete. El crédito vuelve a estar disponible en el origen (FR-011).

**200**: `{"ok": true, "mensaje": "Aplicación anulada.", "a_cobrar": 27306.00, "estado_cobro": "sin_cobrar"}`

## 4. Cambios en endpoints existentes

### `GET /clientes/opciones` y equivalente de proveedores (FR-014)

Se agrega `saldo` a cada elemento de `data`, sin quitar ningún campo actual:

```json
{"data": [{"id": 18431, "nombre": "FLORENCIA 1159751732", "saldo": -3465.29}]}
```

Convención de signo, la misma que ya usa Cuenta Corriente: **negativo = saldo a favor del cliente**,
positivo = deuda. `0` se devuelve igual y el front decide no mostrarlo.

### `DELETE` de Nota de Crédito (FR-012)

Pasa a devolver **422** cuando la nota tiene aplicaciones vivas:

```json
{"ok": false, "mensaje": "La Nota de Crédito tiene saldo aplicado a otros comprobantes. Anulá primero esas aplicaciones."}
```

### Detalle de Venta/Compra (FR-015)

La sección de Cobranzas/Pagos lista también las aplicaciones de crédito, marcadas como tales, con
link al comprobante de origen. **No** se mezclan en el total cobrado con dinero: se muestran en su
propia línea para que "Cobrado" siga significando plata que entró.

## Invariantes verificables (FR-017/018/019, SC-003)

Después de cualquier `POST`/`DELETE` de este contrato, contra la misma base:

1. `SELECT COUNT(*), SUM(monto) FROM movimientos_tesoreria` — sin cambios.
2. Totales de Tesorería A Cobrar / A Pagar / Disponible — sin cambios.
3. `CuentaCorriente::aging('cliente')` y `aging('proveedor')` — sin cambios.
4. Saldo de cuenta corriente del cliente/proveedor involucrado — **sin cambios** (la aplicación
   reubica saldo entre sus comprobantes, no lo altera).
5. `SELECT SUM(monto) FROM cobros` / `pagos` — sin cambios.
