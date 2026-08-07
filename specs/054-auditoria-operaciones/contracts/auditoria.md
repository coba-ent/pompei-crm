# Contracts: Módulo de Auditoría

Endpoints internos (misma app Laravel — no es una API pública). Todos requieren sesión autenticada
y el permiso `auditoria.ver` (nuevo, agregado a la tabla `permisos` — ver Constitution Check).

## `GET /auditoria`

Vista Blade con la pantalla "Operaciones" (filtros + tabla vacía inicial). No devuelve datos, sólo
el shell de la página (los datos se cargan por AJAX vía el endpoint de abajo, según especificación
de diseño obligatoria del proyecto: DataTables + AJAX server-side, nunca tabla estática).

## `GET /auditoria/datatable` (AJAX, usado por DataTables server-side)

**Query params** (todos opcionales, combinables con AND):

| Param | Tipo | Descripción |
|---|---|---|
| `id` | int | Filtra por `logs_auditoria.id` exacto |
| `operacion` | string | Uno de los valores de `tipo_operacion` (`venta`, `presupuesto`, `cobro`, `gasto`, `compra`, `movimiento_tesoreria`, `movimiento_stock`) |
| `usuario_id` | int | Filtra por `usuario_id`, o un valor especial para cada `origen_sistema` |
| `fecha_desde` / `fecha_hasta` | date | Rango de `created_at`; por defecto ambos = fecha de hoy (FR-005) |
| más los params estándar de DataTables server-side (`start`, `length`, `order`, `search`) |

**Response**: formato estándar DataTables (`draw`, `recordsTotal`, `recordsFiltered`, `data[]`), con
cada fila conteniendo: `id`, `created_at` (formateado), `usuario_nombre`, `tipo_accion` (label
Creó/Modificó/Eliminó/Anuló), `tipo_operacion` (label), `detalle`, `total` (formateado o vacío).

## `GET /auditoria/exportar`

Mismos query params que `/auditoria/datatable` (sin paginación — exporta todo el resultado filtrado,
no sólo la página visible). Devuelve un archivo descargable (xlsx) generado por
`AuditoriaExport::forFiltros($request)`, reusando el mismo query builder que el datatable
(ver research.md Decisión 4, para que exportar refleje siempre el filtro aplicado — FR-006/SC-004).

Si el resultado filtrado no tiene filas, responde con un error manejable por el frontend (toast,
según especificación de diseño obligatoria del CLAUDE.md) en vez de generar un archivo vacío — ver
Edge Case / Acceptance Scenario 2 de User Story 3.

## Escritura (no expuesta como endpoint HTTP)

No existe endpoint de creación manual de eventos de auditoría — se generan exclusivamente vía
`AuditoriaService::registrarEvento()`, invocado desde los Observers de cada entidad transaccional
(ver plan.md → Project Structure → `app/Observers/`). No hay contrato HTTP para esto porque no es una
acción de usuario, es un efecto colateral automático de otras acciones ya contractuadas en sus
propios módulos (Ventas, Gastos, etc.).
