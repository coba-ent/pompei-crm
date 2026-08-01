# Implementation Plan: Conversión manual en lote de órdenes a Venta (Tiendanube y MercadoLibre)

**Branch**: `025-conversion-manual-lote-ordenes` | **Date**: 2026-07-31 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/025-conversion-manual-lote-ordenes/spec.md`

## Summary

Agregar, en los listados de órdenes de Ingresos > Tiendanube e Ingresos > MercadoLibre, un botón
"Transformar todas en Venta" que dispara un POST síncrono. El endpoint recorre todas las órdenes en
`estado_conversion = Lista` de la conexión vigente y llama, una por una, al `ConversorOrdenAVenta`
ya existente de cada integración (`convertir(..., automatica: false)`), que ya resuelve reglas de
negocio, candado por orden y atomicidad. El resultado (total, convertidas, fallidas + detalle por
orden fallida con `motivo`/`motivo_detalle`) se devuelve como JSON y se pinta en un modal Bootstrap,
reusando exactamente el patrón ya construido para "Vincular automáticamente" (spec 021/023,
`resources/js/mercadolibre-vinculaciones.js` / `tiendanube-vinculaciones.js`). No se agrega ninguna
entidad, columna ni cola nueva — es una orquestación fina sobre servicios existentes.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12)

**Primary Dependencies**: Eloquent, `App\Services\{Tiendanube,MercadoLibre}\ConversorOrdenAVenta` (ya existentes), Bootstrap 5 (NexaDash) + jQuery/DataTables ya cargados en las vistas de listado

**Storage**: MySQL — no requiere migración; reutiliza `tn_ordenes` / `ml_ordenes` y sus columnas `estado_conversion`, `motivo`, `motivo_detalle`, `venta_id`, `convertida_por`, `convertida_en`, ya existentes

**Testing**: Pest/PHPUnit (Feature tests sobre el nuevo endpoint), siguiendo el patrón de `TiendanubeConversionTest.php` / `MercadoLibreVinculacionAutomaticaTest.php`

**Target Platform**: Web (Laravel Blade + AJAX), mismo entorno que el resto del CRM

**Project Type**: Web application (monolito Laravel, sin frontend separado)

**Performance Goals**: Procesar de forma síncrona dentro de un único request los volúmenes típicos del negocio (decenas, ocasionalmente unos pocos cientos de órdenes "Lista") sin timeout perceptible para el usuario (spec.md Assumptions)

**Constraints**: Ejecución 100% síncrona en el request HTTP (sin colas ni polling, por decisión explícita del usuario); debe respetar los guardrails ya existentes (función avanzada, modo solo lectura) y no debe poder generar una Venta duplicada por carrera con la sincronización automática

**Scale/Scope**: 2 endpoints nuevos (uno por integración), 2 métodos de orquestación en lote (uno por `ConversorOrdenAVenta`), 2 botones + 2 modales en vistas ya existentes, sin nuevas entidades

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: se actualiza `docs/documentacion_principal_crm.md` §3.2.bis/§3.2.quater para documentar el botón nuevo, antes de `/speckit-tasks`. PASS (planificado).
- **II. Desarrollo spec-driven**: esta feature sigue specify→clarify→plan→checklist→tasks→analyze. PASS.
- **III. Corrección fiscal (ARCA)**: no toca comprobantes fiscales directamente — reusa el mismo camino de conversión a Venta que ya dispara `CalculoComprobante`/CAE vía el flujo existente. No introduce excepciones al principio. PASS.
- **IV. Testing donde hay dinero o impacto fiscal**: la conversión en lote termina generando Ventas (dinero) — se agregan Feature tests que cubren: conversión OK de un lote, lote con fallidas y su detalle, guardrails bloqueando el batch, no-duplicación ante carrera con sync automático. PASS (planificado en tasks).
- **V. Convenciones Laravel + dominio en español**: nombres nuevos en español (`convertirTodasLasListas`, `TransformarEnVenta`, etc.), sin `empresa_id`. PASS.

No hay violaciones que requieran justificación — **Complexity Tracking** no aplica.

## Project Structure

### Documentation (this feature)

```text
specs/025-conversion-manual-lote-ordenes/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Services/
│   ├── Tiendanube/
│   │   └── ConversorOrdenAVenta.php        # + método convertirTodasLasListas(?int $usuarioId): array
│   └── MercadoLibre/
│       └── ConversorOrdenAVenta.php        # + método convertirTodasLasListas(?int $usuarioId): array
├── Http/
│   └── Controllers/
│       └── Ingresos/
│           ├── TiendanubeVentaController.php     # + acción transformarTodasEnVenta()
│           └── MercadoLibreVentaController.php   # + acción transformarTodasEnVenta()

resources/
├── views/
│   └── ingresos/
│       ├── tiendanube/index.blade.php      # + botón header + partial de modal de resultado
│       └── mercadolibre/index.blade.php    # + botón header + partial de modal de resultado
└── js/
    ├── tiendanube-ventas.js                # + inicializarTransformarTodasEnVenta() (patrón vinculaciones.js)
    └── mercadolibre-ventas.js              # + inicializarTransformarTodasEnVenta() (patrón vinculaciones.js)

routes/web.php                              # + 2 rutas POST bajo los grupos ingresos.tiendanube / ingresos.mercadolibre

tests/Feature/Integraciones/
├── TiendanubeTransformarEnVentaTest.php    # nuevo
└── MercadoLibreTransformarEnVentaTest.php  # nuevo

docs/documentacion_principal_crm.md         # actualización §3.2.bis / §3.2.quater
```

**Structure Decision**: Monolito Laravel existente — no se crean módulos ni carpetas nuevas. La
feature se implementa como una extensión simétrica de dos stacks paralelos ya existentes
(Tiendanube y MercadoLibre), reusando el mismo patrón de UI/JS que "Vincular automáticamente"
(specs 021/023/024) y el mismo servicio de conversión que la conversión manual individual (specs
012/017).

## Complexity Tracking

*No aplica — sin violaciones de la constitución.*
