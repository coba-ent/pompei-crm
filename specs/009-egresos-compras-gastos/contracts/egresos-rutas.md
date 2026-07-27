# Contrato de Rutas — Módulo Egresos

Rutas web (Blade + AJAX). Middleware `auth` + permisos `compras.*` / `gastos.*`. JSON para operaciones
AJAX; PDFs con `Content-Disposition: inline` (modal compartido). Español.

## Compras (`/compras`)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| GET | `/compras` | `compras.index` | Listado (KPIs + DataTable). |
| GET | `/compras/data` | `compras.data` | JSON server-side del listado. |
| GET | `/compras/nueva` | `compras.create` | **Página completa** de alta. |
| POST | `/compras` | `compras.store` | `StoreCompraRequest`. Idempotente (token anti doble-submit). |
| GET | `/compras/{compra}/editar` | `compras.edit` | Página completa de edición. |
| PUT | `/compras/{compra}` | `compras.update` | `UpdateCompraRequest`. |
| DELETE | `/compras/{compra}` | `compras.destroy` | **Soft delete** + reversión de pagos/movimientos tesorería. |
| GET | `/compras/{compra}` | `compras.show` | Detalle (barra de ecuación, pagos, documento watermark, NC/ND). |
| GET | `/compras/{compra}/pdf` | `compras.pdf` | Detalle como PDF inline. |
| POST | `/compras/{compra}/pagos` | `compras.pagos.store` | `StorePagoRequest`: monto (≤ A Pagar), cuenta_tesoreria_id (exists, visible), fecha, nota. Registra movimiento de tesorería. JSON. |
| DELETE | `/compras/{compra}/pagos/{pago}` | `compras.pagos.destroy` | Anula pago + su movimiento de tesorería. |
| POST | `/compras/{compra}/retenciones` | `compras.retenciones.store` | `StoreRetencionRequest`: fecha, monto, tipo, nro_comprobante, descripción. JSON. |
| POST | `/compras/{compra}/notas` | `compras.notas.store` | `StoreNotaCreditoDebitoRequest` (reutilizado de spec 008, ahora acepta `compra_id`). |
| POST | `/compras/{compra}/remitos` | `compras.remitos.store` | Crea remito (encabezado). |
| GET | `/compras/opciones/*` | — | Select2: `proveedores.opciones`, `productos.opciones` (ya existen, se reutilizan); `tesoreria.cuentas.opciones` (spec 007) para el modal de Pago. |

## Gastos (`/gastos`)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| GET | `/gastos` | `gastos.index` | Listado (sin KPIs, 7 columnas disponibles). |
| GET | `/gastos/data` | `gastos.data` | JSON server-side. |
| POST | `/gastos` | `gastos.store` | `StoreGastoRequest`. Si no pendiente → movimiento de tesorería. JSON/modal. |
| PUT | `/gastos/{gasto}` | `gastos.update` | `UpdateGastoRequest`. Conciliar pendiente → genera movimiento. Reabre el mismo modal ("Editar Gasto"). |
| DELETE | `/gastos/{gasto}` | `gastos.destroy` | Soft delete + reversión directa si tenía movimiento (sin Observer, research §6). |

No existe `gastos.show`: no hay ficha de detalle propia (research §10).

## Categorías de compra/gasto (inline)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| POST | `/categorias-gasto` | `categorias.gasto.store` | "Crear Categoría de Gasto" (tipo=gasto, `categoria_padre_id` null). JSON. |
| POST | `/categorias-gasto/{categoria}/subcategorias` | `categorias.gasto.subcategorias.store` | "Crear Subcategoría" (tipo=gasto, `categoria_padre_id` = la categoría elegida). JSON. |
| POST | `/categorias-compra` | `categorias.compra.store` | "Crear Categoría de Compras" (tipo=compra) — ya usado hoy desde Proveedores; se reutiliza sin cambios. |

## Reglas de validación (FormRequests)

- **StoreCompraRequest / UpdateCompraRequest**: `proveedor_id` required|exists; `items`
  required|array|min:1; `items.*.producto_id` nullable|exists; `items.*.cantidad`
  required|numeric|gt:0; `items.*.precio_unitario` required|numeric|gte:0; `items.*.iva_pct`
  nullable|in:2.5,5,10.5,21,27,exento,no_gravado (sin default forzado, research §2); `fecha_emision`
  required|date; `mes_imputacion_iva` nullable|date. Token anti doble-submit.
- **StorePagoRequest**: `cuenta_tesoreria_id` required|exists:cuentas_tesoreria,id (+ visible); `monto`
  required|numeric|gt:0|lte:<A Pagar de la compra>; `fecha` required|date.
- **StoreRetencionRequest**: `fecha` required|date; `monto` required|numeric|gt:0; `tipo_retencion`
  required|string; `pago_id` seteado automáticamente desde la ruta (exactamente uno de `cobro_id`/
  `pago_id`, nunca ambos).
- **StoreGastoRequest / UpdateGastoRequest**: `fecha` required|date; `monto` required|numeric|gt:0;
  `categoria_id` required|exists (tipo=gasto, hoja del árbol); `cuenta_tesoreria_id`
  required_unless:pendiente,true|exists; `descripcion` nullable|string; `pendiente` boolean.
- **StoreNotaCreditoDebitoRequest** (reutilizado, spec 008): se agrega la validación `exactly one of
  venta_id, compra_id` (`prohibits`/`required_without` cruzados) al esquema ya existente.

## Errores (JSON)

- Validación: 422 `{message, errors}` mostrados en modal/toast sin recargar.
- Regla de negocio: 422 con mensaje claro (ej. "El monto supera el saldo a pagar", "La cuenta de
  tesorería no está disponible", "Debe elegir una subcategoría de Gasto").
