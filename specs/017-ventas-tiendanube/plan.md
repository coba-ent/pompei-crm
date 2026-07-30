# Implementation Plan: Ventas de Tiendanube — listado, vinculación de variantes y conversión a Venta del CRM

**Branch**: `017-ventas-tiendanube` | **Date**: 2026-07-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/017-ventas-tiendanube/spec.md`

## Summary

> ⚠️ **Corrección post-spec 019**: este plan se escribió asumiendo la conexión REST de spec 015
> (`store_id`+`access_token`, `ClienteTiendanube::obtener()/enviar()` contra `api.tiendanube.com`). Esa
> spec quedó inutilizable y fue reemplazada por `specs/019-tiendanube-conexion-mcp/` — OAuth 2.1 contra
> `admin-mcp.tiendanube.com`, `ClienteTiendanube` reescrito para JSON-RPC (`leer()`/`escribir()` con
> nombres de tool). La sección "Enfoque técnico por área" abajo ya refleja la interfaz real, verificada
> contra la cuenta real de Tiendanube el 30/07/2026.

Sincronizar las órdenes de venta de Tiendanube hacia el CRM, listarlas en una pantalla nueva dentro de
Ingresos, y convertirlas en Ventas del CRM —manual o automáticamente— con su cobranza y su movimiento de
stock. Es la contraparte de la spec 012 (Mercado Libre) sobre la infraestructura de conexión ya
construida en la spec 019 (`ClienteTiendanube`, `TiendanubeConfiguracion`, `TiendanubeOperacionLog`).

El enfoque técnico reutiliza al máximo lo ya construido: `StockDeVenta` (spec 012, ya generalizado para
cualquier Venta — sólo se le agrega una rama `tiendanube`), `Cobranzas`, `CalculoComprobante` (spec 008),
el patrón de estados de conversión con 5 valores y sus enums (`EstadoConversion`/`MotivoRequiereAtencion`
de Mercado Libre, replicados en el namespace `Tiendanube`, no reutilizados directamente — son
independientes porque sus motivos de bloqueo difieren), `Cache::lock` para exclusión mutua, y el mismo
mecanismo de portabilidad hosting-compartido/VPS. Lo genuinamente nuevo: traducción del formato de
Tiendanube (3 campos de estado en vez de 1), la tabla de vinculación **variante**↔producto (no
publicación↔producto), el orquestador de conversión, el sincronizador programado, y la exclusión
`storefront = "meli"`.

**A diferencia de la spec 012, no hay ninguna brecha de arquitectura que cerrar**: `StockDeVenta` ya es
un servicio compartido (spec 012 lo dejó así deliberadamente), así que esta spec sólo lo extiende con una
rama nueva, sin Constitution Check de contradicción que resolver.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12

**Primary Dependencies**: Eloquent · Laravel HTTP Client (`Http`) · Laravel Scheduler · `Cache::lock` ·
Yajra DataTables (server-side) · Bootstrap 5 + NexaDash · Select2 · Toastr — todas ya en uso, ninguna
nueva.

**Storage**: MySQL. Tablas nuevas: `tn_ordenes`, `tn_orden_items`, `tn_variante_producto`. Columnas
nuevas en `tn_configuracion` y en `clientes` (`tn_customer_id`). Reutiliza `ventas`/`venta_items`/`cobros`
sin cambios de esquema salvo el enum `origen` (agregar `'tiendanube'`).

**Testing**: PHPUnit (Feature tests). `Http::fake()` para simular las tools JSON-RPC de
`admin-mcp.tiendanube.com`, mismo patrón que `tests/Feature/Integraciones/` de la spec 019.

**Target Platform**: hosting compartido (tarea programada del sistema) y VPS con colas. Mismo código,
distinta configuración — igual que Mercado Libre, y sin la restricción de infraestructura pública que sí
tiene Mercado Libre (spec 019 no la requiere para operar día a día: nada de esta spec cambia eso, porque
no se usan webhooks, Clarifications del spec).

**Project Type**: aplicación web monolítica (Laravel + Blade), single-tenant.

**Performance Goals**: una corrida con hasta ~200 órdenes nuevas debe completarse dentro del límite de
ejecución típico de hosting compartido; la conversión de una orden individual responde de inmediato.
Respeta el límite de tasa de `admin-mcp.tiendanube.com` — **corrección post-019**: no está verificado
públicamente para las tools MCP (el "leaky bucket ~2/s, ráfagas de 40" de la versión original venía de
la documentación REST pública); se trata como piso conservador, mismo criterio que spec 019 research.md.

**Constraints**: sin procesos de larga duración garantizados · sin webhooks (decisión deliberada,
Clarifications) · la primera corrida no debe arrastrar el historial completo de la tienda · debe excluir
`storefront = "meli"` (corrección post-019: en un filtro posterior a la consulta — `list_orders` no
admite excluirlo en la propia llamada, no existe el parámetro `channels`).

**Scale/Scope**: un único negocio, una única tienda de Tiendanube. Volumen esperado similar al de
Mercado Libre. 2 pantallas nuevas (listado + vinculación de variantes) + extensión de la pantalla de
configuración de Tiendanube ya existente (spec 019).

## Constitution Check

*GATE: debe pasar antes de la Fase 0. Re-evaluado tras la Fase 1.*

| Principio | Estado | Justificación |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | Sin contradicción: `docs §5.3` ya anotaba esta spec como continuación directa de la 015; se corrige únicamente la numeración (016 quedó ocupada por un feature no relacionado, ver spec.md). Actualización de ambos documentos antes de `/speckit-tasks`. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 017 escrita y clarificada antes de planear. |
| **III. Corrección fiscal innegociable** | ✅ Pasa | El tipo de comprobante se **deriva** del tipo de documento (FR-039/FR-040), nunca se elige a mano al crear. Las Ventas de Tiendanube no emiten CAE (siguen siendo no fiscales, mismo sello que Mercado Libre). Soft delete ya vigente en Ventas. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Tests obligatorios sobre: desagregación de IVA, derivación del comprobante, idempotencia/concurrencia de la conversión, exclusión de `storefront=meli`, movimiento de stock, imputación de la cobranza. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Tablas `tn_*`, columnas y rutas en español; snake_case; sin `empresa_id`. |

Sin contradicciones que resolver — a diferencia de la spec 012, `StockDeVenta` ya existe como servicio
compartido, así que no hay Constitution Check de brecha arquitectónica pendiente.

### Re-evaluación post-Fase 1

✅ Pasa. El diseño de la Fase 1 no introduce patrones nuevos que el proyecto no use ya: reutiliza
`Cache::lock`, `DB::transaction`, el patrón de enums de estado/motivo, y el patrón de configuración
FK-opcional ya usado tres veces (`deposito_id`/`categoria_venta_id` de Mercado Libre, y ahora también
`cuenta_tesoreria_id` propio). La única pieza nueva de verdad es el mapeo de tres campos de estado de
Tiendanube a los cinco estados de conversión ya conocidos (FR-007a), que es lógica de traducción, no
arquitectura nueva.

## Project Structure

### Documentation (this feature)

```text
specs/017-ventas-tiendanube/
├── plan.md              # Este archivo
├── research.md          # Fase 0 — decisiones técnicas
├── data-model.md         # Fase 1 — entidades, columnas, índices, transiciones
├── quickstart.md         # Fase 1 — guía de validación end-to-end
├── contracts/
│   └── rutas-internas.md # Fase 1 — contrato de endpoints del CRM
├── checklists/
│   └── requirements.md
└── tasks.md              # Generado por /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/Tiendanube/
│   ├── EstadoConexion.php                     # existente (spec 015)
│   ├── EstadoConversion.php                   # NUEVO — mismos 5 valores que Mercado Libre, propio
│   └── MotivoRequiereAtencion.php              # NUEVO — motivos propios de Tiendanube
├── Models/Integraciones/
│   ├── TiendanubeConfiguracion.php             # EXTENDER — columnas nuevas
│   ├── TiendanubeOrden.php                     # NUEVO
│   ├── TiendanubeOrdenItem.php                 # NUEVO
│   └── TiendanubeVarianteProducto.php          # NUEVO — vinculación 1:1
├── Services/Tiendanube/
│   ├── ClienteTiendanube.php                   # existente (spec 019, JSON-RPC/MCP) — NO se toca
│   ├── TraductorOrdenes.php                    # NUEVO — mapea 3 estados TN → 5 estados CRM
│   ├── SincronizadorOrdenes.php                # NUEVO — paginación, exclusión meli, idempotencia
│   ├── ConversorOrdenAVenta.php                # NUEVO — orquesta la conversión
│   └── ResolutorCliente.php                    # NUEVO — email, alta y ambigüedad
├── Services/Ingresos/
│   ├── StockDeVenta.php                        # EXTENDER — rama 'tiendanube' en resolverDeposito()
│   └── Cobranzas.php                           # existente — se reutiliza sin cambios
├── Http/Controllers/Ingresos/
│   ├── TiendanubeVentaController.php           # NUEVO — listado, sincronizar, convertir
│   └── TiendanubeVinculacionController.php     # NUEVO — pantalla de vinculación de variantes
├── Http/Controllers/Integraciones/
│   └── TiendanubeConfiguracionController.php   # EXTENDER (spec 015) — agrega $listasDepositos/etc.
├── Http/Requests/Integraciones/                # existente — se agregan requests de ventas TN
├── Console/Commands/
│   └── SincronizarOrdenesTiendanube.php        # NUEVO — comando de la tarea programada
└── Observers/
    └── VentaObserver.php                       # existente — ya cubre reintegro de stock, sin cambios

database/migrations/                            # 3 tablas nuevas + 2 alter (tn_configuracion, clientes)
resources/views/ingresos/tiendanube/            # NUEVO — listado, vinculaciones, conversión
resources/js/tiendanube-ventas.js               # NUEVO
routes/web.php                                  # EXTENDER — grupo Ingresos
tests/Feature/Integraciones/                    # NUEVO — tests de esta spec
```

**Structure Decision**: se respeta la organización vigente. Modelos bajo
`app/Models/Integraciones/`, servicios de dominio bajo `app/Services/Tiendanube/` (mismo nivel que
`Services/MercadoLibre/`), controladores bajo `app/Http/Controllers/Ingresos/` (mismo criterio que
`MercadoLibreVentaController`/`MercadoLibreVinculacionController`: la pantalla pertenece
funcionalmente a Ingresos, no a Configuración).

## Enfoque técnico por área

### 1. Traducción de estados

`TraductorOrdenes` mapea `status`+`payment_status` de Tiendanube a los 5 valores de `EstadoConversion`
(tabla de FR-007a). `fulfillment_status` (corrección post-019: no `shipping_status`) se persiste como
dato informativo (`tn_ordenes.fulfillment_status`) sin participar del mapeo. La lógica vive en un traductor separado (no inline en el sincronizador) por el
mismo motivo que la spec 012 aisló `TraductorOrdenes` de Mercado Libre: aísla el formato externo del
resto del sistema.

### 2. Sincronización

`SincronizadorOrdenes` corre bajo `Cache::lock` propio (independiente del de Mercado Libre — son
integraciones distintas). Antes de paginar verifica función desactivada / modo sólo lectura / conexión
caída (spec 019), registrando un único intento bloqueado. Llama a
`ClienteTiendanube::leer('list_orders', ['status' => ['open','closed','cancelled'], 'completed_at_from'
=> ..., 'completed_at_to' => now(), 'page' => $pagina, 'limit' => 50])` — **corrección post-019**: la
tool real no tiene `updated_at_min`/`created_at_min` ni parámetro `channels`; la "incrementalidad" se
logra re-consultando en cada corrida la ventana completa de `dias_primera_sync` días (FR-016 corregido)
y haciendo *upsert* por `id` (no `number`) sobre lo que traiga, y la exclusión de `storefront=meli` es
de una sola capa, después de traer cada orden (`TraductorOrdenes`, no en la propia consulta — no existe
`channels`). Si la creación automática está activa, delega cada orden apta a `ConversorOrdenAVenta`.

### 3. Conversión

`ConversorOrdenAVenta` — mismo camino para manual y automática. Candado por orden, revalida que siga sin
convertir, y en una única transacción crea Venta, ítems, cobranza (contra la cuenta de Tesorería
**configurada**, resuelta por FK en vez del `where('nombre', 'Mercado Pago')` que usa hoy
`ConversorOrdenAVenta` de Mercado Libre — divergencia deliberada, research.md R5) y movimientos de stock.
Antes de abrir la transacción resuelve precondiciones: variante vinculada por cada línea, cliente
inequívoco, moneda válida, cuenta de Tesorería activa.

### 4. Cliente y comprobante

`ResolutorCliente` empareja por `tn_customer_id` primero, `email` después (persistiendo el id la primera
vez, FR-036a) — los datos del comprador vienen **embebidos en la propia respuesta de `list_orders`**
(`order.customer.*`), sin necesitar una llamada aparte a `list_customers`. El tipo de comprobante se
deriva **primero** de la condición de IVA que el Cliente emparejado ya tenga cargada en el CRM (mismo
`CalculoComprobante` que cualquier Venta manual) y, sólo para Clientes nuevos o sin esa condición
cargada, se aproxima por longitud de `customer.cpf_cnpj` (FR-039/040, corrección post-019: no existe
`billing_document_type`) — a diferencia de Mercado Libre, que siempre usa la condición de IVA que
informa la API porque siempre la tiene; acá esa fuente no existe (y en la práctica tampoco `cpf_cnpj`,
verificado vacío en las 9 órdenes reales de la tienda), así que el dato ya cargado en el CRM pasa a ser
la fuente primaria antes de aproximar, y Consumidor Final/B es el resultado dominante en la práctica.

### 5. Stock

`StockDeVenta::resolverDeposito()` (app/Services/Ingresos/StockDeVenta.php:83-91) se extiende con una
rama `$venta->origen === 'tiendanube' → TiendanubeConfiguracion::actual()->depositoEfectivo()`, mismo
patrón que la rama `mercadolibre` ya existente. `depositoEfectivo()` se agrega a `TiendanubeConfiguracion`
calcado de `MercadoLibreConfiguracion::depositoEfectivo()`.

### 6. Interfaz

Listado con DataTables server-side y panel de filtros; vinculaciones y configuración por modales AJAX
con Toastr; selector de producto con Select2 vía el endpoint `productos.opciones` ya existente. La
pantalla de conversión reutiliza el formulario de página completa de "Nueva Venta" (misma excepción ya
documentada y usada por la spec 012).

### 7. Menú lateral

La entrada "Tiendanube" en Ingresos se muestra sólo con la función avanzada "Tiendanube" activa —mismo
mecanismo condicional que Mercado Libre y Abonos.

## Complexity Tracking

*(vacío — sin violaciones que justificar; ver Constitution Check)*
