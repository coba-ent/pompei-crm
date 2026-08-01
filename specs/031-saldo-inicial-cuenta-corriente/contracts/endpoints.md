# Contratos: endpoints afectados (spec 029, sin endpoints nuevos)

No se agregan rutas nuevas. Se modifica el shape de la respuesta de dos endpoints ya existentes.

## `GET informes/cuenta-corriente/saldos` — `informes.cuenta-corriente.saldos.data`

Sin cambios de contrato (mismas columnas). Cambia únicamente el valor de los montos cuando el cliente
tiene `saldo_inicial ≠ 0`, y ahora pueden aparecer clientes que antes no aparecían (sólo tenían saldo
inicial, ninguna Venta).

## `GET informes/cuenta-corriente/movimientos` — `informes.cuenta-corriente.movimientos.data`

- El filtro `operacion` ahora acepta también `saldo_inicial` (antes: `venta`/`cobro`/`nota_credito`/
  `nota_debito`).
- Puede aparecer una fila nueva por cliente con `operacion: "saldo_inicial"`:

```json
{
  "id": 6,
  "fecha_emision": "2026-03-01",
  "cliente_id": 6,
  "operacion": "saldo_inicial",
  "categoria": null,
  "total_venta": null,
  "cobrado": null,
  "a_cobrar": 50000,
  "nro_comprobante": null,
  "medio_cobro": null,
  "descripcion": null
}
```

## Dashboard (spec 010) — sin endpoint propio de esta feature

El bloque "Cuentas a Cobrar"/"Cuentas a Pagar" consume `CuentaCorriente::aging()` directamente (no vía
HTTP) — cambia el monto que ya muestra, sin cambios de contrato/endpoint.
