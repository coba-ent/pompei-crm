# Data Model: Módulo de Auditoría (Log de Operaciones)

## `logs_auditoria`

Tabla nueva, de solo lectura desde la aplicación (nunca se expone UPDATE/DELETE), poblada por
Observers de Eloquent sobre las entidades transaccionales en alcance.

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint, PK | |
| usuario_id | FK → usuarios, nullable | Nulo cuando la acción fue automática (integración) |
| usuario_nombre | string(150) | Desnormalizado al momento del evento (ver research.md Decisión 3); si `usuario_id` es nulo, contiene el label de origen (ej. "Ventas Online") |
| origen_sistema | string(50), nullable | `mercadolibre` / `tiendanube` / null (acción humana). Determina el label de `usuario_nombre` cuando no hay usuario |
| tipo_accion | enum: `creo`, `modifico`, `elimino`, `anulo` | Corresponde a la columna "Tipo" de la pantalla (Creó/Modificó/Eliminó/Anuló) |
| tipo_operacion | enum: `venta`, `presupuesto`, `cobro`, `gasto`, `compra`, `movimiento_tesoreria`, `movimiento_stock` | Corresponde a la columna "Operación" |
| entidad_tipo | string(100) | Clase del modelo de origen (`App\Models\Venta`, etc.) — para el link/lookup, no se muestra directo en UI |
| entidad_id | bigint | Id de la entidad de origen (Venta#123, Gasto#45, etc.) |
| detalle | string(255) | Texto libre humano-legible generado por el sistema según `tipo_operacion` (ej. "Venta #123 — Juan Pérez", "Gasto — Cadity Uber") |
| total | decimal(12,2), nullable | Monto de la operación al momento del evento, cuando la operación tiene un total asociado |
| created_at | timestamp | Fecha y hora del evento — también es la columna de ordenamiento por defecto (desc). Sin `updated_at`: registro inmutable |

**Índices**: `(created_at)`, `(usuario_id)`, `(tipo_operacion)`, `(entidad_tipo, entidad_id)` — para
soportar los filtros de FR-004 y el objetivo de rendimiento SC-003.

**Validaciones / invariantes**:
- Exactamente uno de `usuario_id` u `origen_sistema` está presente en cada fila con `usuario_id`
  nulo (no pueden ser ambos nulos: toda acción tiene un responsable, humano o de sistema).
- `detalle` se genera en el momento de creación del evento (no se recalcula después ni se edita).
- No existen operaciones de UPDATE/DELETE de aplicación sobre esta tabla (FR-007) — sólo INSERT vía
  `AuditoriaService::registrarEvento()`.

**Relaciones**:
- `logs_auditoria.usuario_id` → `usuarios.id` (belongsTo, nullable)
- `logs_auditoria.entidad_tipo` + `entidad_id` → relación polimórfica de sólo lectura hacia la
  entidad de origen (Venta, Presupuesto, Cobro, Gasto, Compra, MovimientoTesoreria, MovimientoStock)
  — no se define FK física (las entidades ya usan soft delete y conviene que el evento de auditoría
  sobreviva aunque la fila de origen se borre lógicamente). El evento de auditoría es autosuficiente
  (`detalle` y `total` ya quedan grabados en el momento del evento): la UI no depende de resolver ese
  link para mostrar la fila. Si en el futuro se agrega un link "ver operación" desde la pantalla de
  Auditoría, éste debe manejar el caso de la entidad ya no existir/estar soft-deleted mostrando un
  estado deshabilitado, en vez de un error 404.

## Entidades de origen (ya existentes, no se modifican)

Venta, Presupuesto, Cobro, Gasto, Compra, MovimientoTesoreria (tabla `movimientos_tesoreria`),
MovimientoStock (tabla `movimientos_stock`) — ver `docs/modelo_datos.md` §5, §6, §7 para su esquema
completo. Esta feature sólo agrega Observers sobre ellas, no les agrega columnas.
