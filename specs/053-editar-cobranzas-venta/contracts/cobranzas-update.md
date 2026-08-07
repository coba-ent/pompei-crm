# Contract: Editar cobranza

## `PUT /ventas/{venta}/cobranzas/{cobro}`

Route name: `ventas.cobranzas.update` (análogo a `cobranzas.store` / `cobranzas.destroy`).

**Auth**: misma que el resto de rutas de `ventas.*` (sesión web autenticada).

### Request (JSON o form-encoded, vía AJAX)

| Campo | Tipo | Requerido | Regla |
|---|---|---|---|
| `cuenta_tesoreria_id` | integer | sí | debe existir en `cuentas_tesoreria` |
| `monto` | numeric | sí | `> 0` y `<= aCobrar(venta) + monto_actual_del_cobro` |
| `fecha` | date | sí | formato fecha válido |
| `nota` | string | no | — |

### Respuestas

- **200 OK**
  ```json
  { "ok": true, "cobro": { "id": 1, "fecha": "2026-08-07", "monto": 8000, "nota": "...", "cuenta_tesoreria": {"id": 3, "nombre": "Caja"} } }
  ```
- **404 Not Found**: el `{cobro}` no pertenece a `{venta}`, o `{cobro}` está soft-deleted (anulado — no editable, FR-006).
- **422 Unprocessable Entity**
  ```json
  { "ok": false, "errors": { "monto": ["El monto supera el saldo a cobrar."] } }
  ```

### Efectos colaterales

- Actualiza el `Cobro` (`monto`, `fecha`, `cuenta_tesoreria_id`, `nota`).
- Si el `Cobro` tiene `movimientoTesoreria`, lo actualiza in-place con los mismos `monto`,
  `cuenta_tesoreria_id` y `fecha` (sin crear ni anular movimientos).
- Todo dentro de una transacción de base de datos (mismo patrón que `registrarCobro`/`anularCobro`
  en `app/Services/Ingresos/Cobranzas.php`).

### No-goals de este contrato

- No cambia `venta_id` del cobro.
- No reabre un cobro anulado (eso requeriría una acción distinta, fuera de alcance).
