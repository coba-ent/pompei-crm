# Contrato de Rutas — Módulo Ingresos

Rutas web (Blade + AJAX). Middleware `auth` + permisos `ingresos.*` / `ventas.*` / `presupuestos.*`.
JSON para operaciones AJAX; PDFs con `Content-Disposition: inline` (modal compartido). Español.

## Presupuestos (`/presupuestos`)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| GET | `/presupuestos` | `presupuestos.index` | Listado (KPIs + DataTable). |
| GET | `/presupuestos/data` | `presupuestos.data` | JSON server-side del listado. |
| GET | `/presupuestos/nuevo` | `presupuestos.create` | **Página completa** de alta. |
| POST | `/presupuestos` | `presupuestos.store` | `StorePresupuestoRequest`. Idempotente (token anti doble-submit). |
| GET | `/presupuestos/{presupuesto}/editar` | `presupuestos.edit` | Página completa de edición. |
| PUT | `/presupuestos/{presupuesto}` | `presupuestos.update` | `UpdatePresupuestoRequest`. |
| DELETE | `/presupuestos/{presupuesto}` | `presupuestos.destroy` | Eliminar. |
| PATCH | `/presupuestos/{presupuesto}/estado` | `presupuestos.estado` | Cambiar estado (pendiente/rechazado/aceptado). JSON. |
| GET | `/presupuestos/{presupuesto}` | `presupuestos.show` | **Documento imprimible** (página, no modal). |
| GET | `/presupuestos/{presupuesto}/pdf` | `presupuestos.pdf` | PDF inline (modal compartido). |
| POST | `/presupuestos/{presupuesto}/crear-venta` | `presupuestos.crearVenta` | Convierte → redirige a `ventas.create?presupuesto=ID`. Marca convertido. |

## Ventas (`/ventas`)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| GET | `/ventas` | `ventas.index` | Listado (19 columnas + DataTable). |
| GET | `/ventas/data` | `ventas.data` | JSON server-side. |
| GET | `/ventas/nueva` | `ventas.create` | Página completa. Acepta `?presupuesto=ID` (pre-carga). |
| POST | `/ventas` | `ventas.store` | `StoreVentaRequest`. Genera N° de comprobante. |
| GET | `/ventas/{venta}/editar` | `ventas.edit` | Página completa. |
| PUT | `/ventas/{venta}` | `ventas.update` | `UpdateVentaRequest`. |
| DELETE | `/ventas/{venta}` | `ventas.destroy` | **Soft delete** + reversión de cobros/movimientos tesorería. |
| GET | `/ventas/{venta}` | `ventas.show` | Detalle (barra de ecuación, cobranzas, documento watermark, NC/ND). |
| GET | `/ventas/{venta}/pdf` | `ventas.pdf` | Detalle como PDF inline. |
| GET | `/ventas/{venta}/ticket` | `ventas.ticket` | Imprimir Ticket (PDF inline). |
| POST | `/ventas/{venta}/cobranzas` | `ventas.cobranzas.store` | `StoreCobroRequest`: monto (≤ A Cobrar), cuenta_tesoreria_id (exists, visible), fecha, nota. Registra movimiento de tesorería. JSON. |
| DELETE | `/ventas/{venta}/cobranzas/{cobro}` | `ventas.cobranzas.destroy` | Anula cobro + su movimiento de tesorería. |
| POST | `/ventas/{venta}/notas` | `ventas.notas.store` | `StoreNotaCreditoDebitoRequest` (wizard NC/ND; si afecta stock → movimientos_stock). |
| POST | `/ventas/{venta}/remitos` | `ventas.remitos.store` | Crea remito (encabezado). |
| GET | `/ventas/opciones/*` | — | Select2: `clientes.opciones`, `productos.opciones` (ya existen, se reutilizan); `tesoreria.cuentas.opciones` (spec 007) para el modal de Cobranza. |

## Otros Ingresos (`/otros-ingresos`)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| GET | `/otros-ingresos` | `otros-ingresos.index` | Listado (7 columnas). |
| GET | `/otros-ingresos/data` | `otros-ingresos.data` | JSON server-side. |
| POST | `/otros-ingresos` | `otros-ingresos.store` | `StoreOtroIngresoRequest`. Si no pendiente → movimiento de tesorería. JSON/modal. |
| PUT | `/otros-ingresos/{otroIngreso}` | `otros-ingresos.update` | `UpdateOtroIngresoRequest`. Conciliar pendiente → genera movimiento. |
| DELETE | `/otros-ingresos/{otroIngreso}` | `otros-ingresos.destroy` | Soft delete + reversión si tenía movimiento. |

## Categorías de ingreso/venta (inline)

| Método | URI | Nombre | Descripción |
|---|---|---|---|
| POST | `/categorias-ingreso` | `categorias.ingreso.store` | "Crear Categoría de Ingreso" (tipo=ingreso). JSON. |
| POST | `/categorias-venta` | `categorias.venta.store` | "Crear Categoría de ventas" (tipo=venta). JSON. |

## Reglas de validación (FormRequests)

- **StorePresupuestoRequest / StoreVentaRequest**: `cliente_id` required|exists; `items` required|array|min:1;
  `items.*.producto_id` nullable|exists; `items.*.cantidad` required|numeric|gt:0; `items.*.precio_unitario`
  required|numeric|gte:0; `descuento_general_pct` nullable|numeric|between:0,100; `fecha_emision`
  required|date; `tipo_comprobante` (venta) required|in:A,B,C,E. Token anti doble-submit.
- **StoreCobroRequest**: `cuenta_tesoreria_id` required|exists:cuentas_tesoreria,id (+ visible);
  `monto` required|numeric|gt:0|lte:<A Cobrar de la venta>; `fecha` required|date.
- **StoreOtroIngresoRequest**: `fecha` required|date; `monto` required|numeric|gt:0; `categoria_id`
  required|exists (tipo=ingreso); `cuenta_tesoreria_id` required_unless:pendiente,true|exists; `pendiente`
  boolean.
- **StoreNotaCreditoDebitoRequest**: `tipo` in:credito,debito; `afecta_stock` boolean; si afecta_stock →
  `items` required|array; si no → `descripcion` required; `fecha_emision` date; `monto` numeric|gt:0.

## Errores (JSON)

- Validación: 422 `{message, errors}` mostrados en modal/toast sin recargar.
- Regla de negocio: 422 con mensaje claro (ej. "El presupuesto ya fue convertido en venta", "El monto
  supera el saldo a cobrar", "La cuenta de tesorería no está disponible").
