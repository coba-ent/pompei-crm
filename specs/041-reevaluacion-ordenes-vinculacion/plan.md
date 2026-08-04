# Implementation Plan: Reevaluación automática de órdenes por vinculación tardía

**Branch**: `041-reevaluacion-ordenes-vinculacion` | **Date**: 2026-08-03 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/041-reevaluacion-ordenes-vinculacion/spec.md`

## Summary

Cuando se vincula una publicación de MercadoLibre o una variante de TiendaNube a un producto del
CRM *después* de haber sincronizado órdenes que la referencian, esas órdenes quedan con el estado
`requiere_atencion` desactualizado (detectado en producción: 395/396 órdenes de ML). La solución
tiene dos mecanismos simétricos por canal, reusando la lógica de negocio ya existente
(`EvaluadorConvertibilidad` de cada canal, sin reimplementar reglas):

1. **Evento-driven**: un `Observer` de Eloquent sobre `MercadoLibrePublicacionProducto` (ML) y
   sobre `TiendanubeVarianteProducto` (TN) que, tras `saved`/`deleted` y después del commit de la
   transacción, reevalúa las órdenes `requiere_atencion` (no convertidas) que referencian el
   `ml_item_id`/`variant_id` tocado, y dispara creación automática de venta si corresponde. Sigue
   el mismo patrón que ya usa el proyecto (`PrecioProductoObserver` + `DB::afterCommit`).
2. **On-view**: en el endpoint AJAX server-side (`datatable()`) de cada listado de órdenes
   pendientes (ML y TN), reevaluar antes de armar la respuesta las órdenes en `requiere_atencion`
   del canal, como red de seguridad.

Se extrae la reevaluación puntual de una orden a un servicio reusable por canal
(`ReevaluadorOrden`), consumido tanto por el nuevo Observer, la barrida on-view, como (a futuro)
el propio `SincronizadorOrdenes` — sin tocar su comportamiento actual en esta feature.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12 (Eloquent ORM)

**Primary Dependencies**: Laravel Observers (`ModelObserver` + `DB::afterCommit`), yajra/laravel-datatables (server-side processing ya en uso), servicios de dominio existentes (`EvaluadorConvertibilidad`, `ResolutorCliente`, `ConversorOrdenAVenta` — uno por canal, ML y TN)

**Storage**: MySQL/MariaDB — tablas existentes `ml_ordenes`, `ml_orden_items`, `ml_publicacion_producto`, `tn_ordenes`, `tn_orden_items`, `tiendanube_variante_producto` (o equivalente); sin migraciones nuevas

**Testing**: PHPUnit/Pest (el que ya use el proyecto para Feature/Unit tests de Services) — obligatorio por Principio IV de la constitución sólo si hay impacto de dinero; esta feature dispara creación automática de ventas (dinero), así que sí requiere test

**Target Platform**: Laravel 12 backend, sin cambios de frontend más allá del comportamiento ya existente del listado (no se agrega UI nueva)

**Project Type**: Web application (Laravel monolito, Blade + AJAX) — feature puramente de backend/dominio

**Performance Goals**: la barrida on-view debe reevaluar el total de órdenes `requiere_atencion` del canal (cientos, no miles, per SC-003/Assumptions) sin agregar demora perceptible a la carga del listado

**Constraints**: no romper el comportamiento actual de `SincronizadorOrdenes` (no se refactoriza en esta feature, sólo se extrae lógica común a un servicio que ambos puedan usar más adelante); no reevaluar órdenes con `venta_id` no nulo (convertidas) ni `cancelada`

**Scale/Scope**: 2 canales (ML, TN) × 2 mecanismos (evento, on-view) = 4 puntos de integración; reusa 100% de las reglas de negocio existentes, no las duplica

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (docs como fuente de verdad)**: esta feature no introduce entidades, campos ni
  reglas de negocio nuevas — sólo cambia *cuándo* se dispara una evaluación que ya existe. No
  requiere cambios en `docs/documentacion_principal_crm.md` ni `docs/modelo_datos.md`. ✅ PASS
  (se revalida en Phase 1 por si el data-model revela lo contrario).
- **Principio II (spec-driven)**: se está siguiendo la cadena completa specify→clarify→plan→
  checklist→tasks→analyze. ✅ PASS.
- **Principio III (corrección fiscal ARCA)**: no toca emisión de comprobantes ni CAE. Si la
  reevaluación dispara creación automática de venta, reusa `ConversorOrdenAVenta` tal cual existe
  hoy (mismo candado `Cache::lock`, misma transacción atómica, mismo manejo de `ErrorConversion`)
  — no se le agrega ni quita ninguna garantía fiscal. ✅ PASS.
- **Principio IV (testing con impacto de dinero)**: el Observer puede terminar creando una Venta
  automáticamente. Se requieren tests de: (a) el Observer dispara reevaluación sólo de las
  órdenes correctas, (b) una orden que pasa a `Lista` con `creacion_automatica` activo termina en
  venta creada, (c) órdenes con `venta_id` o `cancelada` nunca se tocan. Ver Fase de tests en
  tasks. ✅ PASS (queda como requisito de tasks, no como violación).
- **Principio V (convenciones Laravel + español)**: se sigue el patrón Observer ya establecido en
  el proyecto (`app/Observers/`, registro en `AppServiceProvider::boot()`), nombres en español
  (`ReevaluadorOrden`), sin Global Scope por tenant (no aplica, single-tenant). ✅ PASS.

No hay violaciones que requieran `Complexity Tracking`.

## Project Structure

### Documentation (this feature)

```text
specs/041-reevaluacion-ordenes-vinculacion/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md         # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Observers/
│   ├── MercadoLibrePublicacionProductoObserver.php   # NUEVO — evento-driven ML
│   └── TiendanubeVarianteProductoObserver.php        # NUEVO — evento-driven TN
├── Services/
│   ├── MercadoLibre/
│   │   ├── EvaluadorConvertibilidad.php              # existente, reusado sin cambios
│   │   ├── ConversorOrdenAVenta.php                  # existente, reusado sin cambios
│   │   ├── ResolutorCliente.php                      # existente, reusado sin cambios
│   │   ├── SincronizadorOrdenes.php                  # existente, no se modifica en esta feature
│   │   └── ReevaluadorOrdenes.php                    # NUEVO — orquesta evaluar+creación automática
│   └── Tiendanube/
│       ├── EvaluadorConvertibilidad.php              # existente, reusado sin cambios
│       ├── ConversorOrdenAVenta.php                  # existente, reusado sin cambios
│       ├── ResolutorCliente.php                      # existente, reusado sin cambios
│       ├── SincronizadorOrdenes.php                  # existente, no se modifica en esta feature
│       └── ReevaluadorOrdenes.php                    # NUEVO — orquesta evaluar+creación automática
├── Http/Controllers/Ingresos/
│   ├── MercadoLibreVentaController.php               # MODIFICADO — datatable() reevalúa antes de listar
│   └── TiendanubeVentaController.php                 # MODIFICADO — datatable() reevalúa antes de listar
└── Providers/
    └── AppServiceProvider.php                        # MODIFICADO — registrar los 2 Observers nuevos

tests/
├── Unit/Services/MercadoLibre/ReevaluadorOrdenesTest.php     # NUEVO
├── Unit/Services/Tiendanube/ReevaluadorOrdenesTest.php       # NUEVO
├── Feature/MercadoLibre/VinculacionReevaluaOrdenesTest.php   # NUEVO (Observer end-to-end)
└── Feature/Tiendanube/VinculacionReevaluaOrdenesTest.php     # NUEVO (Observer end-to-end)
```

**Structure Decision**: Laravel monolito existente, Opción 1 (single project) — no hay separación
frontend/backend como proyectos distintos, todo vive en `app/`. Se agrega un servicio
`ReevaluadorOrdenes` por canal (sigue la separación por namespace `App\Services\{MercadoLibre,Tiendanube}`
ya usada en todo el código de integraciones) que centraliza "reevaluar una orden puntual + disparar
creación automática si corresponde", para que tanto el Observer como el `datatable()` on-view lo
llamen sin duplicar el bloque de lógica que hoy sólo vive inline en
`SincronizadorOrdenes::procesarOrden()`/`intentarCreacionAutomatica()`.

## Complexity Tracking

*Sin violaciones a justificar — tabla omitida.*
