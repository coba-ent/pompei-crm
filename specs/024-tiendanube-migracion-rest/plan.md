# Implementation Plan: Migración de la integración Tiendanube del servidor MCP a la Application REST clásica

**Branch**: `024-tiendanube-migracion-rest` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/024-tiendanube-migracion-rest/spec.md`

## Summary

Reemplaza `ClienteTiendanube` (JSON-RPC contra `admin-mcp.tiendanube.com`, spec 019) por un cliente REST
nuevo (`ClienteTiendanubeRest`, generalización de `VerificadorConexionRest` de spec 022) que habla contra
`api.tiendanube.com` usando la conexión ya validada de `tn_conexion_rest`. Los tres consumidores existentes
(`SincronizadorOrdenes`, `SincronizadorStock`, `SincronizadorPrecios`) cambian de dependencia sin cambiar su
lógica de negocio (mismos cortes, mismo loteo donde ya existía, mismo comportamiento observable), porque el
cliente nuevo devuelve el mismo value-object `RespuestaTiendanube` que ya consumen. La vinculación de
productos se reescribe con un servicio nuevo (`VinculadorAutomatico`, mismo patrón que
`App\Services\MercadoLibre\VinculadorAutomatico` de spec 023) que recorre el catálogo REST en vivo y
compara `variants[].sku` contra `Producto::id` — reemplazando tanto `TiendanubeVinculacionController::variantesPendientes()`
(fuente: `tn_orden_items`) como `ImportadorVinculaciones` (fuente: Excel + slug), que se eliminan. Al
final, una vez validado todo en producción, se retira `ClienteTiendanube`, `TiendanubeOAuthController`,
`TiendanubeConfiguracionController` (apartado MCP), la tabla `tn_configuracion` y `tn_operaciones_log` — no
sin antes migrar a `tn_conexion_rest` los campos de **configuración de negocio** que hoy viven en
`tn_configuracion` junto a las credenciales MCP (`deposito_id`, `categoria_venta_id`, `cuenta_tesoreria_id`,
`lista_precio_id`, `vendedor_id`, `dias_primera_sync`, `frecuencia_sync_minutos`, `creacion_automatica`,
`modo_solo_lectura`, y los timestamps de última sincronización) — hallazgo clave de esta fase de
planificación: esa tabla no es sólo credenciales MCP, también es donde vive toda la configuración
operativa de specs 017/018 que los sincronizadores migrados siguen necesitando.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: `Illuminate\Support\Facades\Http` (cliente HTTP nativo, mismo usado por
`VerificadorConexionRest` y `ClienteMercadoLibre`) — sin dependencias nuevas de Composer.

**Storage**: MySQL. Una migración nueva agrega a `tn_conexion_rest` los campos de configuración de negocio
migrados desde `tn_configuracion` (ver Summary); una migración de datos copia los valores vigentes; al
final (Historia 3) una migración elimina `tn_configuracion` y `tn_operaciones_log`. `tn_ordenes`,
`tn_orden_items`, `tn_variante_producto` no cambian de esquema.

**Testing**: PHPUnit (Feature tests), `Http::fake()` simulando `api.tiendanube.com/v1/{store_id}/products`
(paginado + `variants[].sku`), `.../orders` y `PUT .../products/{id}/variants/{id}` — mismo patrón que
`TiendanubeConexionRestTest`/`TiendanubeConexionRestErroresTest` (spec 022) y
`MercadoLibreVinculacionAutomaticaTest` (spec 023).

**Target Platform**: mismo hosting compartido / XAMPP local que el resto del CRM. Cronjobs existentes
(`tiendanube:sincronizar-ordenes`, `tiendanube:sincronizar-stock`, `everyMinute()`) se mantienen tal cual —
sin webhooks en esta spec (spec.md Clarifications).

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: catálogo y volumen de pedidos de Tiendanube muy por debajo del de Mercado Libre
(spec.md Assumptions) — paginado REST estándar (`page`/`per_page`) alcanza sin necesitar un modo `scan`
como sí hizo falta en spec 023.

**Constraints**: la REST API clásica de Tiendanube no expone un endpoint de actualización batch de
stock/precio (a diferencia de la tool MCP `update_stock_and_price`, que aceptaba hasta 50 en un lote,
research.md 018 R6) — la actualización es `PUT /{store_id}/products/{product_id}/variants/{variant_id}`,
una llamada por variante (research.md R4 de esta spec). `SincronizadorStock` deja de lotear en una sola
request y pasa a iterar una request por vínculo pendiente, dentro del mismo bucle que ya existía.

**Scale/Scope**: 1 cliente HTTP nuevo, 1 servicio de vinculación nuevo, 3 servicios existentes con su
dependencia cambiada (sin cambios de firma pública), 2 controladores/rutas retirados al final, 1 tabla
extendida + 2 tablas retiradas al final.

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | `docs/documentacion_principal_crm.md` §5.3 y `docs/modelo_datos.md` §11 se actualizan antes de `/speckit-tasks`, reemplazando la descripción del modelo MCP por el modelo REST y documentando el traslado de configuración de negocio a `tn_conexion_rest`. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 024 escrita, sin `[NEEDS CLARIFICATION]` pendientes (3 decisiones ya resueltas con el usuario antes de escribirla), checklist de calidad en verde. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | Sin impacto directo en comprobantes/CAE. Impacto indirecto: `SincronizadorOrdenes` sigue alimentando `ConversorOrdenAVenta` (creación automática de Venta) — el comportamiento de esa conversión no cambia, sólo el transporte con el que se obtienen los datos crudos de la orden. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Se testea con el mismo rigor que spec 017/018/023: no-sobrescritura de vínculos, resolución exacta de SKU, aborto sin crear vínculos parciales ante fallo de catálogo, continuidad de stock/precio ante rechazo puntual, y ahora también la migración de configuración (ningún valor de negocio se pierde al retirar `tn_configuracion`). |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Nombres en español, mismo namespace `App\Services\Tiendanube`, se reutiliza el patrón ya validado de `App\Services\MercadoLibre\VinculadorAutomatico` sin duplicar convenciones nuevas. |

Sin contradicciones que resolver.

### Re-evaluación post-Fase 1

✅ Pasa. El diseño de la Fase 1 confirma que ningún consumidor de negocio (`SincronizadorOrdenes`,
`SincronizadorStock`, `SincronizadorPrecios`, el nuevo `VinculadorAutomatico`) necesita conocer si está
hablando con MCP o REST — todos dependen de `RespuestaTiendanube` (value-object ya existente, sin cambios)
y del método `leer()`/`escribir()` del cliente inyectado. El cambio de cliente es, por diseño, una
sustitución de dependencia (`ClienteTiendanube` → `ClienteTiendanubeRest`) sin tocar la lógica de negocio
de esos tres servicios más allá de lo estrictamente forzado por la ausencia de batch en REST (stock). El
hallazgo de la configuración de negocio mezclada en `tn_configuracion` se resuelve con una migración de
datos explícita antes del retiro (Historia 3), sin dejarlo implícito.

## Project Structure

### Documentation (this feature)

```text
specs/024-tiendanube-migracion-rest/
├── plan.md              # Este archivo
├── research.md          # Fase 0 — decisiones técnicas (paginación REST, endpoint de variantes, migración de config)
├── data-model.md         # Fase 1 — tn_conexion_rest extendida, retiro de tn_configuracion/tn_operaciones_log
├── quickstart.md         # Fase 1 — guía de validación end-to-end (3 historias)
├── contracts/
│   ├── api-tiendanube-rest.md   # Fase 1 — contrato REST consumido (products, orders, variants)
│   └── rutas-internas.md        # Fase 1 — rutas nuevas/retiradas del CRM
├── checklists/
│   └── requirements.md
└── tasks.md              # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Services/Tiendanube/
│   ├── ClienteTiendanubeRest.php         # NUEVO — reemplaza a ClienteTiendanube como dependencia de negocio
│   ├── ClienteTiendanube.php             # RETIRADO en Historia 3 (MCP)
│   ├── VerificadorConexionRest.php       # SIN CAMBIOS (spec 022) — ClienteTiendanubeRest lo generaliza, no lo reemplaza
│   ├── RespuestaTiendanube.php           # SIN CAMBIOS — value-object reutilizado por el cliente nuevo
│   ├── VinculadorAutomatico.php          # NUEVO — catálogo en vivo + SKU directo (mismo patrón spec 023)
│   ├── ImportadorVinculaciones.php       # RETIRADO — reemplazado por VinculadorAutomatico
│   ├── SincronizadorOrdenes.php          # MODIFICADO — inyecta ClienteTiendanubeRest, list_orders → GET /orders
│   ├── SincronizadorStock.php            # MODIFICADO — inyecta ClienteTiendanubeRest, deja de lotear (PUT por variante)
│   ├── SincronizadorPrecios.php          # MODIFICADO — inyecta ClienteTiendanubeRest, PUT por variante (ya era 1 a 1)
│   └── Excepciones/
│       └── VinculacionAutomaticaFallidaException.php  # NUEVO — catálogo en vivo falló a mitad de la corrida
├── Http/Controllers/
│   ├── Ingresos/TiendanubeVinculacionController.php    # MODIFICADO — vincularAutomaticamente() nuevo; store()/importar()/variantesPendientes() retirados
│   └── Integraciones/
│       ├── TiendanubeOAuthController.php         # RETIRADO en Historia 3
│       └── TiendanubeConfiguracionController.php # MODIFICADO en Historia 3 — apartado MCP retirado, apartado REST (spec 022) sin cambios
├── Models/Integraciones/
│   ├── TiendanubeConfiguracion.php       # RETIRADO en Historia 3 (tras migrar su configuración de negocio)
│   ├── TiendanubeOperacionLog.php        # RETIRADO en Historia 3
│   └── TiendanubeConexionRest.php        # MODIFICADO — nuevos campos de configuración de negocio (fillable/casts)
database/migrations/
├── 2026_XX_XX_add_configuracion_negocio_to_tn_conexion_rest_table.php  # NUEVO
├── 2026_XX_XX_migrar_configuracion_tn_configuracion_a_tn_conexion_rest.php  # NUEVO — migración de datos
└── 2026_XX_XX_drop_tn_configuracion_y_tn_operaciones_log_tables.php    # NUEVO — Historia 3, al final
resources/views/
├── ingresos/tiendanube/vinculaciones.blade.php     # MODIFICADO — botón "Vincular automáticamente" (igual patrón ML), retira selector manual/importación
└── configuracion/tiendanube/index.blade.php        # MODIFICADO en Historia 3 — retira el apartado MCP
tests/Feature/Integraciones/
├── TiendanubeVinculacionAutomaticaTest.php       # NUEVO
├── TiendanubeSincronizacionOrdenesRestTest.php   # NUEVO (o reescritura del test existente de spec 017)
├── TiendanubeSincronizacionStockRestTest.php     # NUEVO (o reescritura del test existente de spec 018)
├── TiendanubeSincronizacionPreciosRestTest.php   # NUEVO (o reescritura del test existente de spec 018 ampliación)
└── TiendanubeRetiroMcpTest.php                   # NUEVO — Historia 3, confirma que nada depende ya de MCP
docs/documentacion_principal_crm.md  # ACTUALIZAR — §5.3
docs/modelo_datos.md                 # ACTUALIZAR — §11
```

**Structure Decision**: migración de dependencia + un reemplazo estructural (vinculación). No se toca la
capa de presentación de órdenes (`TiendanubeVentaController`, vista de listado) ni la estructura de
`TiendanubeVarianteProducto` — el contrato HTTP externo de esas pantallas es idéntico. El cambio real de
superficie de UI es la pantalla de vinculaciones (pierde el selector manual y la importación por Excel, gana
un botón "Vincular automáticamente", igual a como quedó Mercado Libre en spec 023) y, al final, la pantalla
de Configuración → Tiendanube (pierde el apartado MCP).

## Enfoque técnico

### 1. `ClienteTiendanubeRest` (generalización de `VerificadorConexionRest`)

- Mismo esquema de reintentos/backoff que `VerificadorConexionRest` (1/2/4s, hasta 3 intentos en 429/5xx,
  `Retry-After`, 401/404 sin reintento) y mismos headers (`Authentication: bearer`, `User-Agent`), pero
  parametrizado por verbo (`get`/`post`/`put`) y ruta, en vez de un único `GET /store` hardcodeado.
- Constructor toma `TiendanubeConexionRest::actual()` (o la recibe inyectada) para `access_token`/`store_id`
  — 401/404 marcan `estado = Caida` en esa misma fila, igual que `TiendanubeConexionRestController::estadoRest()`
  ya hace hoy.
- Métodos públicos con la misma forma que `ClienteTiendanube` para minimizar el cambio en los consumidores:
  `leer(string $recurso, array $query = []): RespuestaTiendanube` (GET, pagina automáticamente si el
  llamador lo pide) y `escribir(string $metodo, string $recurso, array $payload = []): RespuestaTiendanube`
  (POST/PUT). Ambos devuelven `RespuestaTiendanube` (sin cambios en esa clase).
- Guards que hoy vive en `ClienteTiendanube::peticion()` (función avanzada activa, `modo_solo_lectura`) se
  reevalúan contra los campos migrados a `TiendanubeConexionRest` (ver punto 4) en vez de
  `TiendanubeConfiguracion`.
- Historial: registra en `TiendanubeRestOperacionLog` (tabla `tn_rest_operaciones_log`, ya creada por spec
  022 junto con `tn_conexion_rest` — hoy sólo la usa `TiendanubeConexionRestController` para
  conectar/verificar/desconectar). `ClienteTiendanubeRest` suma ahí sus propias operaciones de negocio
  (`orders`, `products`, `variants`) con el mismo esquema de columnas que ya usa `ClienteTiendanube` para
  `tn_operaciones_log` — no hace falta ninguna tabla nueva.

### 2. `VinculadorAutomatico` (Tiendanube)

Mismo enfoque que `App\Services\MercadoLibre\VinculadorAutomatico` (spec 023), adaptado a que Tiendanube
expone `sku` directo en el propio listado (sin multiget):

1. Excluir variantes ya vinculadas: `whereNotIn` sobre `TiendanubeVarianteProducto::pluck('variant_id')`.
2. Recorrer `GET /{store_id}/products` paginado (`page`/`per_page`) vía `ClienteTiendanubeRest::leer()`,
   hasta que una página devuelva menos de `per_page` resultados (o vacía).
3. Si `escribir()`/`leer()` devuelve `fallo()` en cualquier página (tras agotar los reintentos del
   cliente), lanzar `VinculacionAutomaticaFallidaException` y abortar toda la corrida sin crear ningún
   vínculo (spec.md Assumptions) — mismo patrón que ML.
4. Por cada producto de la página, por cada entrada de `variants[]`: tomar `sku` directo (sin llamada de
   detalle adicional — a diferencia de ML). Estado del producto (`closed` se excluye, spec.md Edge Cases) se
   evalúa a nivel producto, no de variante.
5. Por cada variante con SKU: mismo flujo de validación que spec 021/023 (`sin_sku`,
   `producto_no_encontrado`, `ya_vinculado` con detalle `sku`/`producto`, o creación del vínculo vía
   `Producto::find((int) $sku)` sin excluir inactivos) — a diferencia de ML, acá no se excluye por tener
   "variantes" (una variante de Tiendanube **es** la unidad de vinculación, spec.md Edge Cases).
6. Mismo formato de resumen (`total`/`vinculadas`/`fallidas`/`detalle_fallidas`).

### 3. Sincronizadores existentes (órdenes, stock, precio)

- **`SincronizadorOrdenes`**: `$this->cliente->leer('list_orders', [...])` → `$this->clienteRest->leer('orders', ['page' => ..., 'per_page' => 50, 'created_at_min' => ..., 'created_at_max' => ..., 'status' => ...])` (research.md confirma el nombre exacto de los parámetros REST). El resto de `paginarYProcesar()`/`procesarOrden()` no cambia — sigue upsert por `tn_order_id`, sigue resolviendo `producto_id` vía `TiendanubeVarianteProducto`, sigue evaluando convertibilidad igual.
- **`SincronizadorStock`**: dado que REST no batchea (Technical Context), `enviarLote()` deja de armar un único payload de hasta 50 y pasa a iterar `PUT /products/{product_id}/variants/{variant_id}` uno por uno dentro del mismo `foreach` — cada resultado se aplica directo al vínculo correspondiente (más simple que el parseo de `datos['results']` que hoy es "no verificado empíricamente" para MCP). `TAMANO_LOTE` deja de tener sentido como agrupador de request y pasa a ser, a lo sumo, un tamaño de chunk para no acumular demasiadas respuestas en memoria de una corrida — a decidir en /tasks si se conserva.
- **`SincronizadorPrecios`**: ya enviaba de a uno (`update_stock_and_price` con un único `update`) — cambia sólo la llamada interna a `PUT /products/{id}/variants/{id}` con `{"price": ...}`, sin cambios de flujo.

### 4. Migración de configuración de negocio (`tn_configuracion` → `tn_conexion_rest`)

Antes de poder apagar `tn_configuracion` (Historia 3), `tn_conexion_rest` gana los campos operativos que
hoy sólo viven en la tabla MCP y que los sincronizadores migrados siguen necesitando: `modo_solo_lectura`,
`creacion_automatica`, `frecuencia_sync_minutos`, `deposito_id`, `categoria_venta_id`,
`cuenta_tesoreria_id`, `dias_primera_sync`, `ultima_sync_en`, `ultima_sync_resultado`,
`stock_ultima_sync_en`, `stock_ultima_sync_resultado`, `lista_precio_id`, `vendedor_id` — mismos tipos y
mismo comportamiento (`depositoEfectivo()`/`depositoEfectivoONulo()` se trasladan tal cual a
`TiendanubeConexionRest`). Una migración de datos (no reversible más allá de re-copiar) transfiere los
valores vigentes de la fila única de `tn_configuracion` a la fila única de `tn_conexion_rest` antes de que
Historia 3 elimine la tabla origen. La pantalla de Configuración → Tiendanube que hoy edita estos campos
sobre el apartado MCP (`TiendanubeConfiguracionController::ventas()`) pasa a editarlos sobre el apartado
REST.

## Complexity Tracking

*(vacío — sin violaciones que justificar; ver Constitution Check)*
