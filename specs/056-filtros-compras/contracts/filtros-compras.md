# Contrato: query params de `GET /compras/data` (DataTables server-side)

Endpoint existente (`route('compras.data')` → `CompraController::data()`). No cambia de método HTTP ni de ruta; se amplía la cantidad de query params que `queryFiltrada()` reconoce. Todos son opcionales — ausencia de un param equivale a "sin ese filtro".

| Param | Tipo | Formato | Comportamiento |
|---|---|---|---|
| `id` | int | `id=123` | Igualdad exacta contra `compras.id` |
| `proveedor_id` | int o int[] | `proveedor_id[]=1&proveedor_id[]=2` | `whereIn`; también acepta escalar único por compatibilidad |
| `categoria_id` | int o int[] | igual patrón que `proveedor_id` | `whereIn` |
| `estado_pago` | string enum | `a_pagar` \| `parcial` \| `pagado` \| `vencido` | Igualdad contra el estado derivado (ver data-model.md); `vencido` es un criterio adicional (vto. pasado + saldo pendiente > 0), no un estado de `Compra::estadoPago()` |
| `factura_buscar` | string | texto libre | `LIKE` sobre tipo y n° de comprobante (interno o fiscal) |
| `etiqueta_id` | int o int[] | igual patrón que `proveedor_id` | `whereHas('etiquetas', whereIn)` |
| `facturado` | string enum | `1` \| `0` | `1` = `whereHas('comprobanteFiscal')`, `0` = `whereDoesntHave` |
| `medio_pago_id` | int | `medio_pago_id=3` | `whereHas('pagos', where cuenta_tesoreria_id)` |
| `usuario_id` | int o int[] | igual patrón que `proveedor_id` | `whereIn` sobre `creado_por_id` |
| `nota_interna` | string | texto libre | `LIKE` |
| `deposito_id` | int | `deposito_id=2` | igualdad |
| `servicio_desde` | date | `YYYY-MM-DD` | `whereDate('servicio_desde', '>=', ...)` |
| `servicio_hasta` | date | `YYYY-MM-DD` | `whereDate('servicio_hasta', '<=', ...)` |
| `emision_desde` / `emision_hasta` | date | `YYYY-MM-DD` | ya existente (sin cambios), sobre `fecha_emision` |
| `vencimiento_desde` / `vencimiento_hasta` | date | `YYYY-MM-DD` | **nuevo**, sobre `fecha_vto_pago` |

Todos los filtros se combinan con AND entre nombres de param distintos. Respuesta: sin cambios en el formato — sigue siendo el JSON estándar de Yajra DataTables ya consumido por `compras.js`.
