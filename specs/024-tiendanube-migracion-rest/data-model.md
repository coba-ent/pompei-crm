# Phase 1 — Data Model: Migración de la integración Tiendanube del servidor MCP a la Application REST clásica

**Feature**: `024-tiendanube-migracion-rest`

Sin entidades de dominio nuevas (pedidos, vínculos, movimientos ya existen desde specs 017/018). Los
cambios son: extender `tn_conexion_rest` con configuración de negocio, y retirar `tn_configuracion` +
`tn_operaciones_log` al final (Historia 3).

## 1. `tn_conexion_rest` (extendida)

Tabla ya creada por spec 022 (`TiendanubeConexionRest`, single-tenant, `actual()`). Se agregan columnas de
configuración de negocio migradas desde `tn_configuracion` (research.md R6):

| Columna | Tipo | Origen | Notas |
|---|---|---|---|
| `modo_solo_lectura` | boolean, default false | `tn_configuracion.modo_solo_lectura` | Kill-switch de escrituras (FR-017 de esta spec, hereda FR-012 de spec 015/019). |
| `creacion_automatica` | boolean, default false | `tn_configuracion.creacion_automatica` | Si una orden "lista" crea Venta sola. |
| `frecuencia_sync_minutos` | integer, nullable | `tn_configuracion.frecuencia_sync_minutos` | Cada cuánto corren los cronjobs (evaluado por el propio comando, sin cambios de spec 017/018). |
| `deposito_id` | foreignId nullable → `depositos` | `tn_configuracion.deposito_id` | Depósito del que se descuenta stock (FR-015). |
| `categoria_venta_id` | foreignId nullable → `categorias` | `tn_configuracion.categoria_venta_id` | Categoría de la Venta creada automáticamente. |
| `cuenta_tesoreria_id` | foreignId nullable → `cuentas_tesoreria` | `tn_configuracion.cuenta_tesoreria_id` | Cuenta de cobro de la Venta creada automáticamente. |
| `dias_primera_sync` | integer, nullable | `tn_configuracion.dias_primera_sync` | Ventana de días que recalcula cada corrida de `SincronizadorOrdenes` (FR-014). |
| `ultima_sync_en` | timestamp, nullable | `tn_configuracion.ultima_sync_en` | Última corrida de sincronización de órdenes. |
| `ultima_sync_resultado` | string, nullable | `tn_configuracion.ultima_sync_resultado` | Resumen textual de la última corrida. |
| `stock_ultima_sync_en` | timestamp, nullable | `tn_configuracion.stock_ultima_sync_en` | Última corrida de push de stock. |
| `stock_ultima_sync_resultado` | string, nullable | `tn_configuracion.stock_ultima_sync_resultado` | Resumen textual de la última corrida de stock. |
| `lista_precio_id` | foreignId nullable → `listas_precio` | `tn_configuracion.lista_precio_id` | Lista de Precios que dispara el push de precio (FR-016). |
| `vendedor_id` | foreignId nullable → `vendedores` | `tn_configuracion.vendedor_id` | Vendedor asignado a las Ventas creadas automáticamente (spec 020). |

`TiendanubeConexionRest` gana los mismos métodos de conveniencia que hoy tiene `TiendanubeConfiguracion`:
`deposito()`, `categoriaVenta()`, `cuentaTesoreria()`, `vendedor()`, `listaPrecio()`,
`depositoEfectivo()`/`depositoEfectivoONulo()` (idénticos, sólo cambia la clase contenedora).

**Migración de datos**: una migración de datos (no sólo de esquema) copia los valores vigentes de la fila
única de `tn_configuracion` a la fila única de `tn_conexion_rest` — ejecutada una sola vez, antes de que
Historia 3 elimine la tabla origen. Si `tn_conexion_rest` no tiene fila (`actual()` la crea al primer uso),
la migración la crea igual que `actual()` para poder escribirle los valores.

## 2. `tn_configuracion` — retirada en Historia 3

Campos que **no** se migran (son específicos del transporte MCP, sin equivalente necesario en REST):
`client_id`, `client_secret`, `access_token` (ya existe uno propio en `tn_conexion_rest`), `token_expira_en`,
`scopes_otorgados` (ídem), `productos_total`, `conectada_en` (ídem), `estado` (ídem), `ultimo_error` (ídem).
Se pierden junto con la tabla — son datos de la conexión MCP, sin sentido una vez que esa conexión se
retira.

## 3. `tn_operaciones_log` — retirada en Historia 3

Sin migración de datos: es un historial de auditoría de operaciones MCP ya ejecutadas, no configuración
viva. Se elimina junto con la tabla — spec.md FR-020 sólo exige preservar "datos de negocio" (pedidos,
vínculos, movimientos), no el log de transporte de un cliente que deja de existir.

## 4. Entidades sin cambios de estructura

- **`tn_ordenes`** (`TiendanubeOrden`) y **`tn_orden_items`** (`TiendanubeOrdenItem`): mismos campos.
  `SincronizadorOrdenes` sigue poblándolas igual, sólo cambia de dónde saca los datos crudos.
- **`tn_variante_producto`** (`TiendanubeVarianteProducto`): mismos campos y mismos índices únicos
  (garantía real de cardinalidad 1:1, spec 017). Cambia únicamente qué proceso decide crear cada fila
  (`VinculadorAutomatico` en vez de `TiendanubeVinculacionController::store()`/`ImportadorVinculaciones`).
- **`tn_conexion_rest`**: `access_token`/`store_id`/`estado`/etc. (spec 022) sin cambios — sólo se agregan
  las columnas de la sección 1.
- **`tn_rest_operaciones_log`** (`TiendanubeRestOperacionLog`, spec 022): mismo esquema, gana filas nuevas
  de operaciones de negocio (research.md R5) además de las de conexión que ya registraba.

## 5. Flujo de resolución de vínculo (reemplaza al de `tn_orden_items`/Excel)

```
Catálogo REST en vivo (GET /products, paginado)
  └─ producto (id, status)
       └─ variants[] (id, sku)
            │
            ├─ sku vacío ──────────────────► sin vincular: "sin_sku"
            ├─ sku no matchea Producto::id ─► sin vincular: "producto_no_encontrado"
            ├─ variante ya en tn_variante_producto ─► sin vincular: "ya_vinculado" (detalle: sku)
            ├─ producto ya en tn_variante_producto ─► sin vincular: "ya_vinculado" (detalle: producto)
            └─ match limpio ───────────────► crea tn_variante_producto (variant_id, tn_product_id, producto_id)
```

Reemplaza por completo el flujo anterior (spec 017 US2 + selector manual), que resolvía:
`tn_orden_items` (variantes vistas en pedidos ya sincronizados) para el selector, y un Excel exportado por
Tiendanube (columnas `SKU`/`Identificador de URL`, matcheado contra `Producto.codigo` y contra el catálogo
en vivo por slug) para la importación masiva.
