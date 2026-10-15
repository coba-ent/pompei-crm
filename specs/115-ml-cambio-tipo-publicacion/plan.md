# Implementation Plan: Cambio de tipo de publicación de Mercado Libre (Clásica ↔ Premium)

**Branch**: `115-ml-cambio-tipo-publicacion` | **Date**: 2026-10-15 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/115-ml-cambio-tipo-publicacion/spec.md`

## Summary

Desde Vinculaciones ML se podrá pasar una publicación (o una selección) de Clásica a Premium y al
revés. Cada cambio es en realidad **tipo + precio**: un servicio nuevo `CambiadorTipoPublicacion`
previsualiza (consulta a ML el tipo vigente y los tipos disponibles, resuelve el precio de la lista
del tipo destino y bloquea si falta) y ejecuta en el orden que nunca deja la publicación por debajo
del precio de su tipo (subir precio → cambiar a Premium; cambiar a Clásica → bajar precio). El precio
se envía siempre por el único punto que hace el PUT (`SincronizadorPrecios::enviarUno()`), con la
excepción del corte de spec 084 reservada a confirmaciones humanas. El lote corre en un job en
segundo plano con estado en cache (patrón de la Sincronización forzada). Cada intento queda en una
tabla de historial nueva. La tabla de Vinculaciones suma casillas de selección, columna "Tipo" con
filtro y la identificación del producto del CRM (ID + código + nombre, buscable).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12; JS ES2015+ (jQuery) compilado con Vite

**Primary Dependencies**: Yajra DataTables (server-side), DataTables + colReorder + colvis, Select2, Toastr (NexaDash); cliente propio `ClienteMercadoLibre`

**Storage**: MySQL — tabla nueva `ml_cambios_tipo_publicacion`; usa `ml_publicacion_producto`, `ml_configuracion`, `precios_producto`, `retenciones_precio_ml`; estado del lote en Cache

**Testing**: PHPUnit Feature tests con `Http::fake()` (SQLite en memoria) + validación manual en navegador local

**Target Platform**: VPS Linux (producción), XAMPP local (desarrollo)

**Project Type**: Web app monolítica Laravel + Blade

**Performance Goals**: cambio individual < 10 s (≤ 5 llamadas a ML); lote de 50 < 2 min (SC-004)

**Constraints**: nunca precio por debajo del tipo (FR-008); un solo punto de PUT de precio (spec 084); sin correr en paralelo con otras sincronizaciones de precio (candado `ml:sincronizar_precios`); no probar contra la cuenta real del cliente

**Scale/Scope**: ~270 vínculos ML; 1 pantalla modificada, 5 endpoints nuevos, 1 job, 1 servicio, 1 tabla

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Cumple | Nota |
|---|---|---|
| I. Docs de dominio como fuente de verdad | ✅ | Leídos §3.2.ter.quinquies (listas por tipo), §3.2.ter.septies (corte 084), §5.2. Se actualizan `documentacion_principal_crm.md` (nueva §3.2.ter.octies + nota en 084 sobre la segunda excepción humana) y `modelo_datos.md` (tabla nueva) antes de `tasks`. |
| II. Spec-driven | ✅ | Cadena completa specify→analyze. |
| III. Corrección fiscal | N/A | No toca comprobantes ni ARCA. |
| IV. Testing donde hay dinero | ✅ | Toca precios publicados: tests obligatorios del orden de operaciones, bloqueos, rollback, excepción del corte y lote. |
| V. Convenciones Laravel + español | ✅ | Servicio/Job/Controller/Migración en español, mismo patrón que specs 035/050/084. |

Reglas de diseño de `CLAUDE.md`: DataTables AJAX con stateSave/colvis/colReorder (se corrige además
el `colvis` por índice que hoy viola la regla #1), modales + AJAX, Toastr, Select2 en el filtro.

**Resultado**: PASA, sin violaciones. Re-chequeo post-diseño: PASA (ver research R3: la ampliación del
uso de `omitirCorte` es coherente con el principio de spec 084 "ninguna bajada sin que una persona la
haya visto" y queda documentada).

## Project Structure

### Documentation (this feature)

```text
specs/115-ml-cambio-tipo-publicacion/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── rutas-internas.md
├── checklists/
│   ├── requirements.md
│   └── integracion-ml.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Services/MercadoLibre/
│   ├── CambiadorTipoPublicacion.php        # NUEVO: previsualizar() / ejecutar()
│   └── SincronizadorPrecios.php            # + listaPrecioParaTipo(); resolverListaPrecio() delega
├── Jobs/
│   └── CambioTipoPublicacionLote.php       # NUEVO: lote en segundo plano + estado en Cache
├── Models/Integraciones/
│   ├── MercadoLibreCambioTipoPublicacion.php  # NUEVO: historial
│   └── MercadoLibrePublicacionProducto.php    # + constantes de tipo, tipo_etiqueta
└── Http/Controllers/Ingresos/
    ├── MercadoLibreCambioTipoController.php   # NUEVO
    └── MercadoLibreVinculacionController.php  # datatable: tipo, filtro, producto id/código

database/migrations/
└── 2026_10_15_000001_create_ml_cambios_tipo_publicacion_table.php

resources/views/ingresos/mercadolibre/
├── vinculaciones.blade.php                 # checkbox, columnas, filtro Tipo, botones masivos, modales
└── _row_actions_vinculacion.blade.php      # + "Pasar a Premium/Clásica"

resources/js/mercadolibre-vinculaciones.js  # selección, modales, polling del lote

routes/web.php                              # rutas del grupo ingresos.mercadolibre.vinculaciones

tests/Feature/Integraciones/
├── CambioTipoPublicacionTest.php
├── CambioTipoPublicacionLoteTest.php
└── MercadoLibreVinculacionTablaTipoTest.php
```

**Structure Decision**: monolito Laravel existente; todo vive junto a lo ya construido de la
integración ML (specs 012/035/050/084).

## Complexity Tracking

Sin violaciones que justificar.
