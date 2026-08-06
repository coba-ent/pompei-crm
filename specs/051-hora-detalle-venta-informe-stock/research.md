# Phase 0 Research: Hora en Movimientos de Stock y Detalle de Venta en Informe de Stock

No quedaron `NEEDS CLARIFICATION` en el Technical Context del plan (stack, storage y testing ya
están fijados por el resto del proyecto). Este documento resuelve las decisiones técnicas puntuales
del feature.

## D1 — Cómo pasar `movimientos_stock.fecha` de DATE a hora real

**Decision**: Migración `ALTER TABLE movimientos_stock MODIFY fecha DATETIME NOT NULL`, sin
columna nueva. Se actualiza el cast del modelo `MovimientoStock::$casts['fecha']` de `date` a
`datetime`. Los 4 puntos de escritura en `StockService` (`ajustar`, `transferir`, `mover` usado
por `registrarSalida`/`registrarEntrada`) cambian su default de `now()->toDateString()` a
`now()` (Carbon completo, que Eloquent persiste como DATETIME).

**Rationale**: Casi todos los callers (`StockDeVenta`, ajuste manual, transferencia, la salida por
baja/edición de Compra) usan el default `now()` cuando no pasan `$fecha` explícita. La única
excepción confirmada es `StockDeCompra::aplicarAlta()` (`app/Services/Egresos/StockDeCompra.php:34`),
que pasa explícitamente `fecha: $compra->fecha_emision->toDateString()` — un string de fecha sin
hora, a propósito, para que el histórico refleje la fecha de emisión del comprobante (potencialmente
retroactiva) y no el momento de carga. Ese caso queda **fuera de alcance** (FR-001 lo excluye
explícitamente): sigue persistiendo con hora `00:00:00`, igual que hoy. Cambiar sólo el default de
`now()->toDateString()` a `now()` cubre el resto de los casos reales sin tocar firmas ni callers.
Reusar la misma columna (en vez de agregar `hora` separada) evita duplicar el campo que
ya es la base del `ORDER BY` y de la función de ventana del saldo corrido — con `datetime` el
`ORDER BY mov.fecha, mov.id` ya ordena por fecha+hora sin cambiar la query.

**Alternatives considered**:
- *Agregar columna `hora` separada*: descartado — obliga a concatenar `fecha`+`hora` en cada
  `ORDER BY`/función de ventana, más superficie de cambio para el mismo resultado.
- *Usar `created_at` como criterio de orden*: descartado — `created_at` no es el campo de negocio
  ("cuándo ocurrió el movimiento") sino el de auditoría técnica; si algún día se permite backdatear
  un ajuste, `fecha` y `created_at` divergirían y el orden quedaría mal.

## D2 — Migración de registros existentes (sin hora real)

**Decision**: La migración `MODIFY` conserva el valor de fecha existente; MySQL completa la parte
horaria en `00:00:00` automáticamente al ensanchar DATE→DATETIME. No se escribe una migración de
datos aparte.

**Rationale**: Coincide con la Assumption ya documentada en spec.md (hora de referencia
`00:00:00` para movimientos históricos). El criterio de desempate para movimientos del mismo día
sigue siendo `mov.id` (orden de alta), igual que hoy — no cambia el `ORDER BY` existente, sólo se
le suma precisión horaria a los movimientos nuevos.

**Alternatives considered**: Backfill de horas estimadas (por ejemplo tomando `created_at` de cada
movimiento histórico) — descartado por alcance: agrega riesgo (podría reordenar el saldo corrido
histórico ya validado) sin pedido explícito del usuario.

## D3 — Cómo enriquecer la columna "Detalle" con datos de la Venta

**Decision**: Agregar dos `leftJoin` más en `InformeStockController::baseQuery()` — a `ventas`
(condicionado a `mov.origen_type = 'App\\Models\\Venta'` vía `ON`) y de ahí a `clientes` — y una
columna calculada `detalle` con `CASE`/`COALESCE` en SQL: si hay venta, arma
`"{tipo_comprobante} {nro_comprobante}"` + `" - {cliente.nombre}"` cuando hay cliente; si no,
cae al `descripcion` existente.

**Rationale**: Seguí el patrón ya usado en el mismo método para `productos`/`depositos`/`users`
(joins explícitos sobre la subconsulta de ventana, sin tocarla). Resolverlo en SQL evita tener que
paginar/cargar relaciones Eloquent por fila en un endpoint server-side de DataTables (que ya trae
paginación/orden/filtro resueltos por Yajra sobre el query builder).

**Alternatives considered**:
- *Resolver el detalle en PHP con `editColumn('detalle', ...)` cargando la Venta por
  `origen_id`*: descartado — con DataTables server-side dispararía una query por fila (N+1) salvo
  que se precargue todo el lote, lo cual es más complejo que el join SQL directo para un dato de
  sólo lectura.
- *Reemplazar la columna `descripcion` en vez de agregar `detalle`*: descartado — mantener
  `descripcion` intacta y exponer `detalle` como columna derivada dejx más claro en el contrato
  JSON qué es dato crudo vs. calculado, y es más fácil de revertir si hiciera falta.

## D4 — Ventas de Mercado Libre / Tiendanube

**Decision**: No requiere tratamiento especial — esas ventas son filas de la misma tabla `ventas`
con `tipo_comprobante`/`nro_comprobante`/`cliente_id` (cuando aplica), así que el mismo join las
cubre sin lógica condicional adicional por origen.

**Rationale**: Confirmado en `app/Services/Ingresos/StockDeVenta.php` — `resolverDeposito()`
distingue origen ML/Tiendanube/manual sólo para elegir el depósito, pero el `$origen` pasado a
`registrarSalida`/`registrarEntrada` es siempre la instancia de `Venta`, sin importar su canal.
