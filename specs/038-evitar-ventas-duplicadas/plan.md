# Implementation Plan: Evitar ventas duplicadas por reconversión de órdenes de Mercado Libre y Tiendanube

**Branch**: `038-evitar-ventas-duplicadas` | **Date**: 2026-08-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/038-evitar-ventas-duplicadas/spec.md`

## Summary

Hoy la Venta no guarda ninguna referencia al pedido de Mercado Libre/Tiendanube que la originó;
el único vínculo vive en `ml_ordenes.venta_id`/`tn_ordenes.venta_id` (orden→venta). Si esa fila
de orden se borra y el pedido vuelve a sincronizarse, el sistema lo trata como orden nueva y
permite convertirla otra vez, generando una Venta duplicada (doble cobro, doble stock).

Enfoque técnico: (1) agregar a `ventas` dos columnas nullable únicas (`ml_order_id`,
`tn_order_id`), completadas al convertir y usadas por cada `ConversorOrdenAVenta` para rechazar
una conversión si ya existe una Venta con ese pedido de origen — esta es la red de seguridad que
sobrevive al borrado+resincronización de la orden. (2) blindar el borrado de `MercadoLibreOrden`
y `TiendanubeOrden` a nivel de modelo (evento Eloquent `deleting`), rechazándolo cuando
`venta_id` no es null, para que valga tanto desde un futuro endpoint de UI como desde
`tinker`/scripts de mantenimiento. (3) backfill por comando artisan de las Ventas históricas
mercadolibre/tiendanube cuya orden de origen sigue existiendo.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Eloquent ORM, MySQL/MariaDB (migraciones), Laravel Console (comando de backfill)

**Storage**: MySQL/MariaDB — nuevas columnas en `ventas`, sin cambios de esquema en `ml_ordenes`/`tn_ordenes` (ya tienen `venta_id`)

**Testing**: PHPUnit/Pest (según convención existente del repo) — feature tests sobre `ConversorOrdenAVenta` (ML y Tiendanube) y sobre el guard de borrado de ambos modelos de orden

**Target Platform**: Servidor Laravel existente (VPS de producción + demo), sin componente nuevo de infraestructura

**Project Type**: Web application (Laravel monolito, backend + Blade) — cambio backend puro, sin UI nueva obligatoria

**Performance Goals**: N/A — operación puntual por conversión/borrado, sin impacto de escala

**Constraints**: Single-tenant (sin `empresa_id`); `ventas` ya usa soft delete (constitución §III) — la referencia al pedido de origen debe respetar unicidad también contra Ventas soft-deleted, para que una Venta borrada siga bloqueando la reconversión (edge case del spec)

**Scale/Scope**: Dos integraciones (Mercado Libre, Tiendanube), pocas decenas de conversiones por día — sin necesidad de índices especiales más allá de la unicidad

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: aplica. `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` deben actualizarse con las nuevas columnas de `ventas` y la regla de bloqueo de borrado antes de `/speckit-tasks`. PASA (pendiente de ejecutar, no de diseño).
- **II. Desarrollo spec-driven**: esta feature nació de detectar el gap en conversación, pero pasa por specify→clarify→plan→checklist→tasks→analyze como corresponde a funcionalidad de negocio. PASA.
- **III. Corrección fiscal innegociable (ARCA)**: no se toca el flujo fiscal (CAE, tipo de comprobante); el guard de borrado además refuerza la política de soft-delete existente en `ventas`, alineado con el principio (no se borra físicamente un documento con impacto contable). PASA.
- **IV. Testing donde hay dinero o impacto fiscal**: la conversión de orden en Venta mueve dinero (cobro en Tesorería) y stock — el rechazo de duplicados y el guard de borrado DEBEN tener tests. Se incorpora al plan de tasks. PASA.
- **V. Convenciones Laravel + dominio en español**: columnas nuevas en español/consistentes con lo existente (`ml_order_id`/`tn_order_id` ya son el nombre usado en `ml_ordenes`/`tn_ordenes`, se reusa el mismo nombre en `ventas` por consistencia de dominio en vez de inventar un término nuevo). PASA.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/038-evitar-ventas-duplicadas/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command) — n/a, ver nota abajo
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

No se genera `contracts/`: la feature no expone una interfaz externa nueva (no hay endpoint HTTP
nuevo; el rechazo de conversión duplicada reutiliza la respuesta ya contractual de
`ConversorOrdenAVenta::convertir()` — `{ok: false, mensaje, venta_id}` — y el guard de borrado es
interno al modelo, sin ruta nueva).

### Source Code (repository root)

```text
app/
├── Models/
│   ├── Venta.php                                  # + columnas ml_order_id/tn_order_id en $fillable
│   └── Integraciones/
│       ├── MercadoLibreOrden.php                  # + boot(): guard de borrado si venta_id no es null
│       └── TiendanubeOrden.php                    # + boot(): guard de borrado si venta_id no es null
├── Services/
│   ├── MercadoLibre/ConversorOrdenAVenta.php       # + chequeo de duplicado por ml_order_id antes de crear la Venta
│   └── Tiendanube/ConversorOrdenAVenta.php         # + chequeo de duplicado por tn_order_id antes de crear la Venta
└── Console/Commands/
    └── BackfillReferenciaPedidoVentas.php          # comando artisan de backfill (FR-010), un solo uso operativo

database/migrations/
└── <timestamp>_add_ml_tn_order_id_to_ventas_table.php   # columnas nullable + índices únicos SIN scope por deleted_at: el índice debe seguir bloqueando aunque la Venta esté soft-deleted (edge case del spec)

tests/
└── Feature/
    ├── MercadoLibre/ConversionDuplicadaTest.php
    ├── Tiendanube/ConversionDuplicadaTest.php
    └── Integraciones/BorradoOrdenConVentaTest.php
```

**Structure Decision**: Laravel monolito existente (Option 1, single project). No se agregan
capas ni proyectos nuevos: dos migraciones/columnas, dos guards de modelo, dos chequeos de
servicio (mismo patrón en ML y Tiendanube, sin abstracción compartida porque hoy tampoco
comparten código entre integraciones — mantener el precedente del repo).

## Complexity Tracking

*No aplica — sin violaciones de la Constitution Check.*
