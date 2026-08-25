# Contracts: Exportar Movimientos de Cuenta Corriente

Todas las rutas son `GET`, sólo lectura, dentro del grupo `informes.ver` ya existente. Mismo criterio
de auth/middleware que el resto de las rutas de Cta Cte.

## Clientes

```
GET informes/cuenta-corriente/movimientos/exportar
GET informes/cuenta-corriente/movimientos/pdf
```

**Query params** (mismos que ya acepta `movimientosData` hoy, todos opcionales):
- `cliente_id[]` — array de ids
- `operacion[]` — subset de `venta|cobro|nota_credito|nota_debito|saldo_inicial`
- `fecha_desde`, `fecha_hasta` — `YYYY-MM-DD`

**Respuestas**:
- `exportar` → `200`, `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`,
  `Content-Disposition: attachment; filename="Informe Cuentas Corrientes Movimientos de Clientes {fecha} {hora} Hs.xlsx"`
- `pdf` → `200`, `Content-Type: application/pdf`, `Content-Disposition: inline`, apaisado A4

## Proveedores

```
GET informes/cuenta-corriente-proveedores/movimientos/exportar
GET informes/cuenta-corriente-proveedores/movimientos/pdf
```

**Query params**: espejo de Clientes con `proveedor_id[]` en vez de `cliente_id[]`.

**Respuestas**: mismo contrato que Clientes, título de archivo "...de Proveedores...".

## Sin cambios de contrato en las rutas existentes

`informes/cuenta-corriente/movimientos` (datos JSON del DataTable) y su espejo de proveedores **no
cambian** — el export es un endpoint nuevo y separado, no una variante del mismo.
