# Research — Productos & Servicios (Fase 0)

Decisiones técnicas para resolver los puntos abiertos del plan antes del diseño. No hubo marcadores
`NEEDS CLARIFICATION` en la spec; las decisiones de abajo consolidan los defaults elegidos y las
dependencias con el módulo Clientes ya implementado.

## D1 — Reutilización del patrón de Clientes

- **Decisión**: replicar la arquitectura de `001-clientes`: controlador que responde JSON, DataTable
  server-side con `yajra/laravel-datatables-oracle`, modal Bootstrap por AJAX, toasts Toastr, entry JS
  propio (`productos.js`) registrado en Vite, y página en `config/dz.php` (pagelevel) con los assets.
- **Rationale**: el patrón ya está probado y cumple las reglas de UX obligatorias del `CLAUDE.md`. Se
  minimiza el riesgo y se mantiene consistencia visual/comportamental entre módulos.
- **Alternativas descartadas**: DataTable client-side (no cumple "server-side por AJAX" y no escala a
  1.000+ filas); Livewire/Inertia (introduce stack nuevo, innecesario y fuera del patrón del proyecto).

## D2 — Unicidad global de SKU (producto ∪ variante)

- **Decisión**: el SKU no vacío debe ser único considerando **ambos** conjuntos: `productos.codigo` y
  `producto_variantes.sku`. Se implementa con una regla de validación `SkuUnico` que consulta las dos
  tablas (excluyendo el propio registro en edición), además de índices `unique` por tabla como red de
  seguridad a nivel de base.
- **Rationale**: TiendaNube/MercadoLibre usan el SKU como clave de sincronización de inventario; un SKU
  repetido entre un producto y una variante de otro producto rompería el mapeo. El `unique` por tabla
  solo no alcanza (no cubre colisiones entre las dos tablas), de ahí la regla en capa de aplicación.
- **Alternativas descartadas**: tabla única de "ítems vendibles" (producto y variante en una sola
  tabla) — más limpio para unicidad pero se aparta del modelo de datos ya documentado (`docs/modelo_datos.md`
  §2 define productos y producto_variantes separadas); no se cambia el modelo por esto.

## D3 — Modelado del stock: foto + histórico

- **Decisión**: dos tablas. `stocks` guarda la **cantidad actual** (único por
  `producto_id + variante_id + deposito_id`); `movimientos_stock` guarda el **histórico**
  (entrada/salida/ajuste). Un `StockService` aplica cada ajuste dentro de una **transacción**:
  inserta/actualiza la fila de `stocks` y registra el `movimiento_stock` correspondiente.
- **Rationale**: es el modelo ya documentado (`docs/modelo_datos.md` §2) y evita recalcular el stock
  sumando todo el histórico en cada consulta de listado (performance). La transacción garantiza SC-003
  (foto y histórico siempre consistentes).
- **Alternativas descartadas**: solo histórico y derivar la foto on-the-fly (más simple pero lento en
  listados con miles de productos); solo foto sin histórico (incumple FR-018, se pierde trazabilidad).

## D4 — Stock por variante vs por producto

- **Decisión**: `stocks.variante_id` y `movimientos_stock.variante_id` son **nullable**. Si el producto
  tiene variantes, el stock se lleva por variante+depósito (variante_id no nulo); si no, por
  producto+depósito (variante_id nulo). El servicio decide según el ítem recibido.
- **Rationale**: coincide con el modelo documentado y con la realidad del negocio (un producto con
  talles lleva stock por talle). Evita duplicar tablas.
- **Alternativas descartadas**: exigir siempre variante (obligaría a crear una variante "única" ficticia
  para todo producto — ruido innecesario).

## D5 — Depósitos y depósito por defecto

- **Decisión**: crear la tabla `depositos` en esta feature y sembrar un depósito **"Principal"**
  (activo) para que el alta inicial de stock no requiera configurar depósitos primero. Baja lógica
  (`activo`), no eliminación si tiene stock/movimientos (misma filosofía que el resto).
- **Rationale**: el multidepósito es opcional en Contagram (se activa en Funciones Avanzadas); un
  depósito por defecto permite operar el stock desde el día uno sin fricción.
- **Alternativas descartadas**: no modelar depósito y llevar stock "global" — se aparta del modelo
  documentado y bloquearía el multidepósito futuro.

## D6 — Servicio no controla stock

- **Decisión**: `productos.tipo` enum(`producto`,`servicio`). Para `servicio`, la UI no muestra la
  sección de stock y el `StockService`/`AjusteStockRequest` rechazan cualquier intento de ajuste
  (validación: el ítem debe ser tipo producto). No se crean filas en `stocks`/`movimientos_stock` para
  servicios (SC-007).
- **Rationale**: regla de dominio explícita (`docs/…` §5.2: "los servicios no controlan stock").
- **Alternativas descartadas**: permitir stock 0 en servicios — ambiguo y contradice el dominio.

## D7 — Ajuste manual puede dejar stock negativo

- **Decisión**: un ajuste manual de disminución puede dejar el stock negativo; el movimiento se
  registra igual. No se bloquea por stock insuficiente en el ajuste manual.
- **Rationale**: el ajuste manual refleja un conteo/corrección real; la política "no vender sin stock"
  pertenece al módulo Ventas (Funciones Avanzadas → "Ventas sin stock"), no a los productos. Documentado
  en Assumptions de la spec.
- **Alternativas descartadas**: impedir negativo en ajuste — podría trabar una corrección legítima de
  inventario.

## D8 — Regla "no eliminar con operaciones" extensible

- **Decisión**: `Producto::tieneOperaciones()` (y análogo en variante) hoy considera únicamente la
  existencia de `movimientos_stock`. Queda como costura para sumar ventas/compras cuando esos módulos
  existan, igual que `Cliente::tieneOperaciones()` en 001-clientes.
- **Rationale**: consistencia con el patrón ya establecido; permite implementar la regla completa sin
  las tablas de Ventas/Compras todavía.
- **Alternativas descartadas**: dejar la eliminación libre por ahora — rompería la trazabilidad apenas
  existan operaciones.

## D9 — Dependencia con Proveedores

- **Decisión**: `productos.proveedor_id` es **nullable** y su FK se agrega sólo si la tabla
  `proveedores` ya existe al correr las migraciones; si no existe aún, el campo se deja como columna
  nullable sin constraint (o con FK diferida) y el selector de proveedor queda vacío/oculto hasta que
  exista el módulo Proveedores.
- **Rationale**: Proveedores es una feature aparte (aún no implementada). No se bloquea Productos por
  eso, y el campo opcional no rompe nada.
- **Alternativas descartadas**: adelantar la creación de `proveedores` en esta feature — ampliaría el
  alcance y se pisaría con la spec de Proveedores.

## D10 — Precios por lista reutilizando `listas_precio`

- **Decisión**: `precios_producto` (producto_id, lista_precio_id, precio), único por
  (producto_id, lista_precio_id). Reutiliza la tabla `listas_precio` ya creada y sembrada en Clientes.
  Ausencia de precio de lista ⇒ se usa `productos.precio_venta` base (FR-015).
- **Rationale**: coincide con el modelo documentado; no se recrea el catálogo de listas.
- **Alternativas descartadas**: guardar precios de lista como JSON en `productos` — dificulta consultas
  y joins que Ventas necesitará.
