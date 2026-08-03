# Implementation Plan: Vinculación múltiple Producto ↔ Publicaciones (Mercado Libre y Tiendanube)

**Branch**: `036-vinculacion-multiple-ml` | **Date**: 2026-08-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/036-vinculacion-multiple-ml/spec.md`

## Summary

Cambiar la vinculación Producto↔integración de 1:1 a 1:N en Mercado Libre (`ml_publicacion_producto`)
y Tiendanube (`tn_variante_producto`): un Producto del CRM podrá tener varias publicaciones/variantes
vinculadas simultáneamente, y el stock/precio se sincroniza a todas ellas, no sólo a la primera que
encuentre el sistema. Enfoque técnico: (1) quitar el `unique()` sobre `producto_id` en ambas tablas de
vínculo, conservando el `unique()` sobre `ml_item_id`/`variant_id`; (2) cambiar los dos
`VinculadorAutomatico` (ML y Tiendanube) para que dejen de rechazar por "ya_vinculado" cuando el
producto ya tiene otro vínculo, y en cambio creen el vínculo adicional; (3) cambiar
`MovimientoStockObserver` y `PrecioProductoObserver` para que marquen como pendientes TODOS los
vínculos de un producto (`->get()` en vez de `->first()`), en ambas integraciones. Los sincronizadores
de stock (`SincronizadorStock` de ML, equivalente de Tiendanube) ya iteran por vínculo individual y no
requieren cambios estructurales — sólo se benefician de que los observers ahora les entreguen más de
un pendiente por producto.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, Observers (`MovimientoStockObserver`, `PrecioProductoObserver`),
servicios existentes `App\Services\MercadoLibre\*` y `App\Services\Tiendanube\*`

**Storage**: MySQL/MariaDB — tablas `ml_publicacion_producto` y `tn_variante_producto` (migración que
elimina el índice único sobre `producto_id` en ambas)

**Testing**: PHPUnit (Feature/Unit), siguiendo el patrón ya usado en specs 021/023/024 para los
vinculadores automáticos y en spec 013 para la sincronización de stock

**Target Platform**: Servidor Laravel existente (VPS de producción, `pompeisanitarioscontable.cloud`)

**Project Type**: Web application (backend Laravel monolítico con Blade) — este feature es
exclusivamente backend, sin superficie nueva de UI más allá de que las pantallas existentes de
vinculación deben poder listar más de un vínculo por producto (ver FR-010)

**Performance Goals**: N/A — mismo volumen de operaciones que hoy (≤300 publicaciones/variantes por
integración), sin cambio de orden de magnitud

**Constraints**: No romper compatibilidad con los flujos ya existentes de "Sincronización forzada"
(spec 035) ni con `MercadoLibreOrden`/conversión a Venta (spec 012), que siguen resolviendo el vínculo
correspondiente a una publicación puntual y no se ven afectados por el cambio de cardinalidad del lado
del producto.

**Scale/Scope**: 2 tablas de vínculo, 2 servicios `VinculadorAutomatico`, 2 observers compartidos
(`MovimientoStockObserver`, `PrecioProductoObserver`), sin tocar los sincronizadores de stock/precio en
sí (ya iteran por vínculo).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: aplica. `docs/modelo_datos.md` documenta
  `ml_publicacion_producto`/`tn_variante_producto` como 1:1 — se DEBE actualizar a 1:N en el mismo
  cambio, antes de `/speckit-tasks` (ver plan §Post-Design Constitution Check). PASA (con acción
  pendiente registrada, no bloqueante para continuar el flujo).
- **II. Desarrollo spec-driven**: cumplido — esta es una feature de negocio (corrige comportamiento de
  integraciones con impacto en stock real) y pasa por specify→clarify→plan→tasks→analyze. PASA.
- **III. Corrección fiscal innegociable (ARCA)**: no aplica — este feature no toca facturación ni CAE.
  N/A.
- **IV. Testing donde hay dinero o impacto fiscal**: aplica — "movimientos de stock" está listado
  explícitamente como área que DEBE tener tests. Se requieren tests para: (a) ambos
  `VinculadorAutomatico` creando múltiples vínculos por producto, (b) ambos observers marcando todos
  los vínculos de un producto como pendientes. PASA (con esa obligación explícita para `/speckit-tasks`).
- **V. Convenciones Laravel + dominio en español**: sin cambios de nomenclatura, se mantienen los
  nombres ya existentes (`ml_publicacion_producto`, `tn_variante_producto`, español, snake_case). PASA.

Sin violaciones que requieran justificación en Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/036-vinculacion-multiple-ml/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command) — N/A, sin contratos externos nuevos
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
database/migrations/
└── 2026_08_XX_XXXXXX_quitar_unique_producto_id_vinculaciones.php   # nueva: drop unique en ambas tablas

app/Services/MercadoLibre/
└── VinculadorAutomatico.php        # procesar(): dejar de rechazar 'ya_vinculado' por producto

app/Services/Tiendanube/
└── VinculadorAutomatico.php        # procesar(): mismo cambio, paridad con ML

app/Observers/
├── MovimientoStockObserver.php     # ->first() → ->get() + loop, ambas integraciones
└── PrecioProductoObserver.php      # ->first() → ->get() + loop, ambas integraciones

app/Models/Integraciones/
├── MercadoLibrePublicacionProducto.php   # sin cambios de comportamiento, revisar scopes existentes
└── TiendanubeVarianteProducto.php        # ídem

tests/Feature/Integraciones/
├── MercadoLibreVinculacionMultipleTest.php     # nuevo
├── TiendanubeVinculacionMultipleTest.php       # nuevo
└── (extender tests existentes de MovimientoStockObserver/PrecioProductoObserver si existen)
```

**Structure Decision**: Backend puro dentro de la app Laravel monolítica existente — no se crea
ninguna carpeta ni módulo nuevo, se modifican archivos ya existentes de las integraciones ML/Tiendanube
más una migración nueva. Sin cambios de frontend obligatorios más allá de que las vistas ya existentes
que listan vínculos deben soportar mostrar varios por producto (se evalúa en Phase 1 si ya lo soportan
o si hace falta un ajuste menor).

## Complexity Tracking

*Sin violaciones a justificar — tabla omitida.*
