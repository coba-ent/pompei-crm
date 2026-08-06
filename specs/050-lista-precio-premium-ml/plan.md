# Implementation Plan: Lista de Precios diferenciada para publicaciones Premium de Mercado Libre

**Branch**: `050-lista-precio-premium-ml` | **Date**: 2026-08-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/050-lista-precio-premium-ml/spec.md`

## Summary

Hoy `SincronizadorPrecios` usa una única `ml_configuracion.lista_precio_id` para sincronizar precios
hacia TODAS las publicaciones vinculadas de Mercado Libre, sin distinguir si son Premium (`gold_pro`) o
Clásica (`gold_special`). Esta feature agrega una segunda Lista de Precios opcional
(`lista_precio_id_premium`) específica para publicaciones Premium, persiste el tipo de publicación por
vínculo (`ml_publicacion_producto.listing_type_id`, mantenido por un comando programado diario nuevo), y
extiende `SincronizadorPrecios` para resolver la lista correcta por publicación individual en los tres
disparadores existentes (observer de cambio de precio, botón "Sincronizar precios ahora", cambio de
lista configurada), con fallback a la lista general si la Premium no tiene precio cargado.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent, `Illuminate\Support\Facades\Http` (ya envuelto por
`ClienteMercadoLibre`), Select2 (NexaDash) para el campo nuevo de configuración

**Storage**: MySQL — 2 columnas nuevas en `ml_publicacion_producto`, 2 columnas nuevas en
`ml_configuracion` (ver [data-model.md](data-model.md))

**Testing**: PHPUnit (`tests/Feature/Integraciones/`), mismo patrón que
`MercadoLibreSincronizarPreciosTest.php` existente

**Target Platform**: Hosting compartido (demo, sin `crontab`, scheduler vía hPanel) + VPS propio (systemd
+ cron real) — mismas dos plataformas que ya corren el resto de la integración de Mercado Libre

**Project Type**: Web application (Laravel monolito + Blade/NexaDash)

**Performance Goals**: N/A — volumen bajo (270 publicaciones vinculadas hoy), sin requisito de
throughput distinto del resto de la integración

**Constraints**: Respetar los cortes ya existentes de `SincronizadorPrecios`/`ClienteMercadoLibre`
(kill-switch de escrituras, función avanzada "mercadolibre" activa, un único candado por operación);
no agregar llamadas a la API de ML en la corrida de stock (cada 15 min) — la actualización de tipo de
publicación corre en su propio comando diario (Clarification 2026-08-06)

**Scale/Scope**: 270 publicaciones vinculadas actuales (30 Premium / 240 Clásica), cuenta real Pompei
Sanitarios — single-tenant, sin necesidad de escalar más allá de eso

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `docs/documentacion_principal_crm.md` §3.2.bis
  y `docs/modelo_datos.md` §10 ya documentan la Lista de Precios de Mercado Libre (spec 016) — se
  actualizan en el mismo cambio que esta spec (antes de `/speckit-tasks`, por la regla del proyecto) para
  reflejar `lista_precio_id_premium` y `listing_type_id`. **PASS** (pendiente de ejecutar la
  actualización, no de decidir si corresponde).
- **II. Desarrollo spec-driven**: esta es una feature de negocio (cambia una integración real con dinero
  de por medio) — pasa por specify→clarify→plan→checklist→tasks→analyze antes de implementar, sin
  excepción. **PASS**.
- **III. Corrección fiscal innegociable (ARCA)**: no aplica — esta feature no toca comprobantes fiscales
  ni CAE, es sincronización de precios de catálogo hacia Mercado Libre (explícitamente fuera de la ruta
  de facturación, ver data-model.md "Sin cambios en `ml_ordenes`"). **N/A**.
- **IV. Testing donde hay dinero o impacto fiscal**: SÍ aplica — la resolución de qué precio se envía a
  Mercado Libre es lógica de dinero (aunque no fiscal). Se requieren tests para: resolución de lista por
  tipo de publicación (Premium vs no), fallback a lista general sin precio en la Premium, los tres
  disparadores (observer/botón/cambio de config), y el comando de actualización de tipo (incluida la
  tolerancia a fallos de la API). **PASS** (a cumplir en `/speckit-tasks`).
- **V. Convenciones Laravel + dominio en español**: columnas nuevas en snake_case español
  (`lista_precio_id_premium`, `listing_type_id` — este último se mantiene en inglés porque es el nombre
  literal del campo que informa la API de Mercado Libre, mismo criterio ya usado para `ml_item_id`,
  `ml_order_id`, etc. en el resto del módulo). Comando Artisan seguido de sus pares (`mercadolibre:*`),
  Observer/Service ya existentes se extienden en vez de romper el patrón MVC. **PASS**.

Sin violaciones — no hace falta Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/050-lista-precio-premium-ml/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── rutas-internas.md
└── tasks.md             # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
app/
├── Console/Commands/
│   └── SincronizarTiposPublicacionMercadoLibre.php   # NUEVO — comando diario (research.md §R3)
├── Http/
│   ├── Controllers/Integraciones/
│   │   └── MercadoLibreConfiguracionController.php   # guardarVentas(): agrega lista_precio_id_premium + trigger de push (contracts §1)
│   └── Requests/Integraciones/
│       └── GuardarConfiguracionVentasMercadoLibreRequest.php  # regla nueva: lista_precio_id_premium
├── Models/Integraciones/
│   ├── MercadoLibreConfiguracion.php                 # fillable + relación listaPrecioPremium()
│   └── MercadoLibrePublicacionProducto.php           # fillable + esPremium() (data-model.md)
├── Services/MercadoLibre/
│   ├── ClienteMercadoLibre.php                        # sin cambios (GET /items ya soportado por peticion() genérico)
│   ├── SincronizadorPrecios.php                       # resolución de lista por vínculo (research.md §R5)
│   └── SincronizadorTiposPublicacion.php               # NUEVO — service que consulta /items en bulk y persiste listing_type_id (usado por el comando)

database/migrations/
└── 2026_08_06_XXXXXX_add_listing_type_lista_premium_mercadolibre.php  # NUEVO — 4 columnas (data-model.md)

resources/views/configuracion/mercadolibre/
└── index.blade.php                                    # campo Select2 nuevo "Lista de Precios ML Premium"

resources/js/
└── configuracion-mercadolibre.js (o equivalente ya existente)  # setea/lee lista_precio_id_premium igual que lista_precio_id

bootstrap/app.php                                       # withSchedule(): registra el comando nuevo

tests/Feature/Integraciones/
├── MercadoLibreSincronizarPreciosTest.php              # extiende casos: Premium usa lista Premium, fallback a general
└── MercadoLibreSincronizarTiposPublicacionTest.php     # NUEVO — comando, backfill, tolerancia a fallos de API
```

**Structure Decision**: Laravel monolito existente — se extienden los archivos ya vigentes de la
integración de Mercado Libre (specs 011/012/013/016/036) en vez de crear una estructura paralela. Único
archivo nuevo de dominio real es `SincronizadorTiposPublicacion` (Service) + su comando Artisan, siguiendo
exactamente el mismo patrón que `SincronizadorStock`/`SincronizarStockMercadoLibre` ya presentes en el
repo.

## Complexity Tracking

*Sin violaciones al Constitution Check — sección no aplica.*
