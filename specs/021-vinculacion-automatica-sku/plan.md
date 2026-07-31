# Implementation Plan: Vinculación automática por SKU (Mercado Libre y Tiendanube)

**Branch**: `021-vinculacion-automatica-sku` | **Date**: 2026-07-30 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/021-vinculacion-automatica-sku/spec.md`

## Summary

Reemplaza el alta manual por selector de la vinculación publicación↔producto de Mercado Libre por un
mecanismo 100% automático: el SKU del vendedor visto en órdenes ya sincronizadas se compara directo
contra el `id` (clave primaria) de `productos` — sin campo nuevo, porque el negocio va a asignar a
propósito ese `id` al dar de alta los productos que hoy sólo existen en Mercado Libre. Para Tiendanube,
mantiene el alta manual ya existente (spec 017) y agrega una importación masiva que usa el archivo de
productos que el propio Tiendanube exporta: el SKU del archivo resuelve el producto del CRM por
`codigo`, y el "Identificador de URL" del archivo resuelve los ids reales de Tiendanube (`product_id`/
`variant_id`) consultando el catálogo en vivo vía la tool `list_products` del MCP ya conectado
(`admin-mcp.tiendanube.com`) — sin depender de que el producto haya vendido alguna vez.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: Eloquent · `maatwebsite/excel` ^3.1 (lectura del archivo de Tiendanube, ya en
uso — ver `ImportacionController`/`ImportadorFilas` de la spec 006) · `App\Services\MercadoLibre\
ClienteMercadoLibre` (ya existente, spec 011/012 — GET `/users/{user_id}/items/search?seller_sku=X`
confirmado funcionando en vivo contra la cuenta real) · `App\Services\Tiendanube\ClienteTiendanube` (ya
existente, spec 019 — tool `list_products` del MCP, confirmada sin exponer SKU pero sí `id`/
`product_url` por producto y variante) · Yajra DataTables · Bootstrap 5 + NexaDash · Toastr — sin
dependencias nuevas.

**Storage**: MySQL. Sin migraciones de esquema: no se agrega ninguna columna. `ml_publicacion_producto`
y `tn_variante_producto` (specs 012/013/016 y 017/018) se siguen poblando con los mismos campos que ya
tienen — cambia únicamente el mecanismo que decide qué fila crear.

**Testing**: PHPUnit (Feature tests). Casos: vinculación automática de ML con match exacto de `id`, sin
match, SKU vacío, dos publicaciones al mismo producto (la segunda "ya vinculado"), publicación con
variantes excluida; importador de Tiendanube con match exacto de `codigo`, match por número inicial, sin
match, slug no encontrado en el catálogo en vivo, fila ya vinculada, no-sobrescritura al reimportar,
rechazo temprano de archivo sin columnas SKU/"Identificador de URL". Las llamadas a
`ClienteMercadoLibre`/`ClienteTiendanube` se testean con `Http::fake()`, mismo patrón que
`MercadoLibreVinculacionTest`/`TiendanubeVinculacionTest` ya existentes.

**Target Platform**: mismo que el resto del CRM (hosting compartido / VPS) — sin tareas programadas
nuevas; la vinculación automática de ML y la importación de TN son ambas acciones manuales disparadas
por el operador (spec.md Clarifications).

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: la vinculación automática de ML recorre publicaciones vistas en órdenes
sincronizadas (decenas a un par de cientos) en una sola request síncrona. La importación de Tiendanube
procesa hasta ~100-200 filas y pagina el catálogo en vivo (102 productos hoy → 3 páginas de 50 vía
`list_products`, dentro del rate limit documentado de Tiendanube — burst 40, 2 req/s sostenido) en la
misma request síncrona.

**Constraints**: el SKU de Mercado Libre sólo se conoce a partir de órdenes ya sincronizadas (sin
consulta al catálogo de ML en vivo, fuera de alcance). El SKU de Tiendanube nunca viaja por la
integración conectada (confirmado exhaustivamente: `list_products`/`search_products` con
`fields_needed: null` no lo exponen en ningún campo) — sólo sale del archivo exportado a mano. El
catálogo en vivo de Tiendanube sólo se usa para resolver `product_id`/`variant_id` a partir del slug,
nunca para SKU.

**Scale/Scope**: 1 pantalla existente simplificada (ML: pierde alta manual, gana botón de vinculación
automática) + 1 pantalla existente ampliada (TN: gana modal de importación, sin tocar el alta manual). 2
servicios nuevos (uno por canal, independientes). 2 endpoints nuevos (vincular automáticamente en ML,
importar en TN) — sin endpoint de plantilla: el archivo de Tiendanube es el export nativo tal cual
(FR-009), no un formato propio de esta spec.

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | `docs/documentacion_principal_crm.md` §5.2/§5.3 y `docs/modelo_datos.md` se actualizan antes de `/speckit-tasks` con el nuevo mecanismo (reemplaza las referencias a la spec 021 original, descartada). |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 021 (reemplazo) escrita, clarificada con 5 preguntas resueltas —incluidas dos verificaciones empíricas en vivo contra las cuentas reales conectadas— antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | Sin impacto: no toca comprobantes, CAE, ni importes. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa (alcance acotado, mismo criterio que la spec 021 original) | Una vinculación mal resuelta afecta qué producto se descuenta de stock al convertir una orden (specs 012/017/018) — se testea con el mismo rigor: no-sobrescritura, resolución exacta vs. parcial, motivos de fallo. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Servicios `App\Services\MercadoLibre\VinculadorAutomatico` / `App\Services\Tiendanube\ImportadorVinculaciones` — independientes, sin clase compartida entre canales (mismo criterio que `ConversorOrdenAVenta`, research.md de spec 017 R3). |

Sin contradicciones que resolver.

### Re-evaluación post-Fase 1

✅ Pasa. El diseño de la Fase 1 reutiliza infraestructura 100% existente: `ClienteMercadoLibre` y
`ClienteTiendanube` para las dos únicas llamadas externas necesarias (ambas ya usadas por specs previas,
sólo se agregan las operaciones puntuales `buscar_sku_ml`/`list_products`), `maatwebsite/excel` para leer
el archivo de Tiendanube (ya usado por spec 006), y las tablas `ml_publicacion_producto`/
`tn_variante_producto` sin ningún cambio de esquema. No se introduce arquitectura nueva.

## Project Structure

### Documentation (this feature)

```text
specs/021-vinculacion-automatica-sku/
├── plan.md              # Este archivo
├── research.md          # Fase 0 — decisiones técnicas
├── data-model.md        # Fase 1 — sin cambios de esquema; documenta el flujo de resolución
├── quickstart.md        # Fase 1 — guía de validación end-to-end
├── contracts/
│   └── rutas-internas.md # Fase 1 — contrato de endpoints nuevos/modificados
├── checklists/
│   └── requirements.md
└── tasks.md              # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Ingresos/
│   ├── MercadoLibreVinculacionController.php # EXTENDER — quita store(); agrega vincularAutomaticamente()
│   └── TiendanubeVinculacionController.php   # EXTENDER — agrega importar()
├── Http/Requests/Integraciones/
│   └── ImportarVinculacionesTiendanubeRequest.php # NUEVO — valida el archivo subido (sólo TN, ML no tiene upload)
├── Services/MercadoLibre/
│   └── VinculadorAutomatico.php              # NUEVO — recorre publicaciones pendientes, resuelve por id, crea vínculos
├── Services/Tiendanube/
│   └── ImportadorVinculaciones.php           # NUEVO — parsea el archivo, resuelve producto por codigo y
│                                              #          Tiendanube real por slug vía ClienteTiendanube::leer('list_products', ...)
resources/views/ingresos/mercadolibre/
│   └── vinculaciones.blade.php               # EXTENDER — quita el modal de alta manual; agrega botón "Vincular automáticamente"
resources/views/ingresos/tiendanube/
│   └── vinculaciones.blade.php               # EXTENDER — agrega botón + modal de importación (alta manual queda igual)
resources/js/
│   ├── mercadolibre-vinculaciones.js         # EXTENDER — quita el modal de alta; agrega handler del botón automático
│   └── tiendanube-vinculaciones.js           # EXTENDER — agrega handler del modal de importación
routes/web.php                                # EXTENDER — reemplaza POST store (ML) por POST vincular-automaticamente; agrega POST importar (TN)
tests/Feature/Integraciones/
│   ├── MercadoLibreVinculacionAutomaticaTest.php # NUEVO
│   ├── TiendanubeImportadorVinculacionesTest.php # NUEVO — tests del servicio
│   └── TiendanubeImportarVinculacionesEndpointTest.php # NUEVO — tests del endpoint
docs/documentacion_principal_crm.md           # ACTUALIZAR — §5.2/§5.3
docs/modelo_datos.md                          # ACTUALIZAR — nota de "sin columna nueva" + flujo de resolución
```

**Structure Decision**: se respeta la organización vigente y la separación estricta entre integraciones
(nada compartido entre `Services/MercadoLibre/` y `Services/Tiendanube/`). No se crean pantallas nuevas:
se extienden/simplifican las dos pantallas de vinculación ya existentes. Se quita únicamente el método
`store()` de `MercadoLibreVinculacionController` y la ruta POST `/` — **`VincularPublicacionRequest` NO
se elimina**: sigue siendo la FormRequest de `update()`, que la usa hoy como type-hint y queda sin
cambios (clarificación: editar/eliminar siguen disponibles).

## Enfoque técnico por área

### 1. Vinculación automática de Mercado Libre

`VinculadorAutomatico::ejecutar()`: recorre `MercadoLibreOrdenItem` sin variante (`ml_variation_id`
null, FR-007) agrupado por `ml_item_id` (mismo criterio "más reciente" que ya usa
`publicacionesPendientes()`), excluye los ya vinculados (`whereNotIn` sobre
`MercadoLibrePublicacionProducto::pluck('ml_item_id')`, patrón ya usado). Por cada publicación pendiente:

1. `sku_vendedor` vacío → fallo "sin SKU".
2. `Producto::find((int) $sku)` (sin filtrar por `activo`, clarificación) → si no existe, fallo "SKU no
   corresponde a ningún producto".
3. Producto ya vinculado a otra publicación → fallo "producto ya vinculado".
4. Si pasa todo: crea `MercadoLibrePublicacionProducto` (mismos campos que el alta manual actual, sin
   `vinculada_por` de usuario — o con el usuario que dispara el botón, a definir en tasks).

Controlador: `vincularAutomaticamente()` reemplaza a `store()`; devuelve el mismo formato de resumen que
ya usaban los importadores de la spec 021 original (`vinculadas`/`fallidas`/`detalle_fallidas`).

### 2. Importación de Tiendanube desde el export nativo

`ImportadorVinculaciones::importar($rutaArchivo, $usuario)`:

1. Parsea con `Excel::toArray()` (ya usado, spec 006), localizando las columnas `SKU` e
   `Identificador de URL` por nombre de encabezado (no por posición — el export real de Tiendanube tiene
   25 columnas en total, separador `;`, codificación no-UTF8 confirmada en el archivo real).
2. Por fila: `Producto::where('codigo', $sku)->first()` (match exacto); si no hay, probar
   `Producto::where('codigo', 'like', $sku.' %')` (número inicial, 6/86 casos reales).
3. Resolver el producto real de Tiendanube: `ClienteTiendanube::leer('list_products', ['page_size' =>
   50, 'page' => N, 'fields_needed' => ['id', 'product_url']])`, paginado hasta agotar
   `pagination.total_pages`, buscando la fila cuyo `product_url` (slug, ignorando el dominio) coincida
   con el "Identificador de URL" de esa fila del archivo. Cachear el catálogo completo en memoria durante
   la corrida de importación (una sola consulta paginada por importación, no una por fila).
4. Ya vinculado (por `variant_id` o por `producto_id`) → fallo "ya vinculado" con detalle de qué lado.
5. Si todo resuelve: crea `TiendanubeVarianteProducto` (mismos campos que el alta manual).

### 3. Interfaz

Mercado Libre: el botón "Nueva vinculación" + modal de selector desaparecen; se agrega "Vincular
automáticamente" que dispara el endpoint y muestra el resumen en un modal simple (o vía Toastr + reload
de tabla si el volumen de detalle no amerita modal — a definir en tasks según UX). `update()`/
`destroy()` por fila quedan igual que hoy.

Tiendanube: se agrega "Importar desde Tiendanube" junto al botón de alta manual existente, con un modal
de subida de archivo (sin plantilla propia — el archivo es el export nativo) y resumen de
vinculadas/fallidas con motivo, reflejado de inmediato en la tabla (`tabla.ajax.reload(null, false)` con
el modal abierto, mismo criterio FR-016 que la spec 021 original tenía resuelto).

## Complexity Tracking

*(vacío — sin violaciones que justificar; ver Constitution Check)*
