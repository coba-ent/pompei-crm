# Contratos: endpoints nuevos

## `GET informes/cuenta-corriente` — `informes.cuenta-corriente.index`

Shell de la pantalla: tabs "Saldos Clientes" (default) / "Movimientos", filtros, tablas vacías que se llenan por AJAX.

## `GET informes/cuenta-corriente/saldos` — `informes.cuenta-corriente.saldos.data`

DataTables server-side. Parámetros de filtro: `cliente_id` (opcional).

Respuesta (shape DataTables estándar `draw`/`recordsTotal`/`recordsFiltered`/`data`), cada fila:

```json
{
  "cliente_id": 1,
  "cliente_nombre": "CRISTIAN 1156071555",
  "a_vencer": 0,
  "vencido_0_30": 0,
  "vencido_31_60": 0,
  "vencido_61_90": 0,
  "vencido_mas_90": 3851308.51,
  "total": 3851308.51
}
```

Ordenable por `total` (FR-003).

## `GET informes/cuenta-corriente/movimientos` — `informes.cuenta-corriente.movimientos.data`

DataTables server-side. Parámetros de filtro: `cliente_id` (opcional), `operacion` (`venta`/`cobro`/`nota_credito`/`nota_debito`, opcional), `fecha_desde`/`fecha_hasta` (sobre `fecha_emision`, opcionales).

Respuesta, cada fila: ver tabla "Vista derivada: fila de Movimientos" en `data-model.md`.

## Fuera de contrato (no se agrega)

- Sin exportación CSV/PDF en esta iteración (research.md R4).
- Sin endpoints de escritura — toda esta feature es GET/sólo lectura (FR-008).
