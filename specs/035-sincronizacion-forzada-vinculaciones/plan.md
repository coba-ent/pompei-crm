# Implementation Plan: Sincronización forzada y eliminación masiva de Vinculaciones

**Branch**: `035-sincronizacion-forzada-vinculaciones` | **Date**: 2026-08-03 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/035-sincronizacion-forzada-vinculaciones/spec.md`

## Summary

Agregar, en las pantallas de Vinculaciones de Tiendanube y Mercado Libre, dos botones nuevos:
"Sincronización forzada" (recorre TODOS los vínculos activos de la integración —no sólo los
pendientes— y reenvía stock y precio reales a la plataforma externa) y "Eliminar todas las
vinculaciones" (borra todos los registros de vínculo del lado CRM, sin tocar la plataforma externa).
Ambas acciones respetan los mismos cortes (función avanzada desactivada, modo sólo lectura, sin
conexión) y el mismo candado de concurrencia que ya usan `SincronizadorStock`/`SincronizadorPrecios`.

Enfoque técnico: para precio, `SincronizadorPrecios::sincronizarListaCompleta()` ya existe en ambas
integraciones y ya recorre TODOS los vínculos (no sólo pendientes) — se reutiliza tal cual, sin
cambios. Para stock, no existe hoy un equivalente "todos"; se agrega un método nuevo
`sincronizarTodos()` en cada `SincronizadorStock` (Tiendanube y Mercado Libre), calcado de
`sincronizar()` pero iterando sobre todos los vínculos en vez de `::pendientes()`. Un método nuevo
`sincronizacionForzada()` en cada `VinculacionController` existente orquesta ambos sincronizadores y
arma el toast combinado; un método `eliminarTodas()` en esos mismos controllers hace el borrado
masivo.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent (MySQL), `Cache::lock` (candado, `CACHE_STORE=database` en
producción), Toastr (NexaDash) para notificaciones, Bootstrap 5 modal para confirmación destructiva

**Storage**: MySQL — tablas `mercado_libre_publicacion_productos` y `tiendanube_variante_productos`
(vínculos existentes, sin cambios de esquema)

**Testing**: PHPUnit (Feature tests), con el cliente HTTP de cada integración (`ClienteMercadoLibre`,
`ClienteTiendanubeRest`) mockeado/fake — **sin requests reales** contra las APIs de Tiendanube/Mercado
Libre, porque el proyecto está conectado contra la cuenta real de un cliente (ver Assumptions del
spec). La validación funcional end-to-end la hace el usuario manualmente en el entorno real.

**Target Platform**: Web (Blade + AJAX), mismo patrón que el resto del CRM

**Project Type**: Aplicación web monolítica (Laravel)

**Performance Goals**: N/A — acción manual, ejecución síncrona aceptada explícitamente por el volumen
actual de vínculos (decenas a unos pocos cientos, ver Assumptions del spec)

**Constraints**: Ejecución síncrona (el usuario espera con spinner); un request de escritura por
vínculo por plataforma (sin loteo, ninguna integración lo soporta hoy); debe respetar el candado
existente (`Cache::lock`) para no correr en paralelo con el cron ni con "Sincronizar ahora"

**Scale/Scope**: 2 pantallas (Vinculaciones Tiendanube, Vinculaciones Mercado Libre), ~84 vínculos en
producción hoy (VPS), crecimiento esperado a algunos cientos

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: se leyeron `docs/documentacion_principal_crm.md`
  (secciones de Mercado Libre y Tiendanube, líneas ~464-710) antes de especificar. Esta feature agrega
  dos acciones nuevas a un flujo ya documentado; al cerrar `/speckit-tasks` se actualiza ese doc con los
  botones nuevos antes de continuar (ver tarea de documentación). **PASA**, con esa actualización
  pendiente marcada explícitamente.
- **II. Desarrollo spec-driven**: esta es la feature de negocio en curso, pasando por el flujo completo
  `specify → clarify → plan → checklist → tasks → analyze`. **PASA**.
- **III. Corrección fiscal (ARCA)**: no aplica — feature ajena a facturación electrónica. **N/A**.
- **IV. Testing donde hay dinero o impacto fiscal**: esta feature toca movimientos de stock/precio
  publicados externamente (no fiscal, pero con impacto de negocio real: sobreventa por stock mal
  publicado). Se exige test automatizado (con cliente HTTP mockeado, ver Technical Context) para: el
  corte por modo sólo lectura, el corte por función desactivada, la continuidad ante error puntual de
  un vínculo, y el borrado masivo. **PASA**, tareas de test incluidas en `/speckit-tasks`.
- **V. Convenciones Laravel + dominio en español**: nombres de métodos/rutas/vistas en español,
  consistente con `sincronizarStock`/`sincronizarPrecios`/`destroy` ya existentes. **PASA**.

Sin violaciones — no aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/035-sincronizacion-forzada-vinculaciones/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (rutas HTTP)
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Services/
│   ├── MercadoLibre/
│   │   ├── SincronizadorStock.php        # + método sincronizarTodos()
│   │   └── SincronizadorPrecios.php      # sin cambios (sincronizarListaCompleta() ya sirve)
│   └── Tiendanube/
│       ├── SincronizadorStock.php        # + método sincronizarTodos()
│       └── SincronizadorPrecios.php      # sin cambios (sincronizarListaCompleta() ya sirve)
├── Http/Controllers/Ingresos/
│   ├── MercadoLibreVinculacionController.php   # + sincronizacionForzada(), + eliminarTodas()
│   └── TiendanubeVinculacionController.php     # + sincronizacionForzada(), + eliminarTodas()
resources/
├── views/ingresos/mercadolibre/vinculaciones/index.blade.php   # + 2 botones
├── views/ingresos/tiendanube/vinculaciones/index.blade.php     # + 2 botones
├── js/mercadolibre-vinculaciones.js (o inline)                 # handlers AJAX + toast + modal confirm
└── js/tiendanube-vinculaciones.js (o inline)
routes/web.php    # + 4 rutas (sincronizacion-forzada y eliminar-todas por integración)
tests/Feature/
├── MercadoLibre/SincronizacionForzadaTest.php
├── MercadoLibre/EliminarTodasVinculacionesTest.php
├── Tiendanube/SincronizacionForzadaTest.php
└── Tiendanube/EliminarTodasVinculacionesTest.php
docs/documentacion_principal_crm.md   # actualizar secciones ML/TN con los botones nuevos
```

**Structure Decision**: Se reutiliza la estructura MVC ya establecida para estas dos integraciones
(`app/Services/{MercadoLibre,Tiendanube}/`, `app/Http/Controllers/Ingresos/`, vistas bajo
`resources/views/ingresos/`). No se crean carpetas ni patrones nuevos — la feature extiende clases y
vistas existentes, agregando métodos y dos rutas por integración, siguiendo el mismo patrón que
"Sincronizar ahora"/"Sincronizar stock ahora"/"Sincronizar precios ahora" ya implementados.

## Complexity Tracking

*Sin violaciones al Constitution Check — sección no aplica.*
