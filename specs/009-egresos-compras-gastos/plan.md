# Implementation Plan: Módulo Egresos (Compras · Gastos)

**Branch**: `009-egresos-compras-gastos` | **Date**: 2026-07-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/009-egresos-compras-gastos/spec.md`

## Summary

Módulo Egresos con dos pantallas: Compras (`/compras`) y Gastos (`/gastos`). Compras es el espejo
estructural de Ventas (spec 008, ya implementada): mismo esqueleto de listado/KPIs/filtros/formulario
de página completa/ficha de detalle, con Proveedor en vez de Cliente, pagos en vez de cobranzas, y sin
IVA preseleccionado en los ítems. Gastos es un modelo aparte, mucho más simple: alta por modal, sin
ficha de detalle, sin documento fiscal, categorías propias jerárquicas.

Enfoque técnico: **reutilizar, no reinventar**, los servicios ya construidos en spec 008. Compras
reutiliza `Services/Ingresos/CalculoComprobante` para el cálculo de subtotales/IVA/descuento/
percepciones (el mismo servicio que ya usan Presupuesto y Venta — sólo cambia que el IVA por ítem puede
quedar `null` en vez de defaultear a `21`). Se agrega un servicio paralelo a `Cobranzas`:
`Services/Egresos/Pagos`, único punto que llama a `Tesoreria::registrarMovimiento()` para Compras y
Gastos (`tipo=pago` / `tipo=gasto`), dentro de una transacción, con reversión en soft delete (mismo
patrón que `VentaObserver`). Los totales derivados de una Compra (A Pagar, Pagado, estado) se calculan
a partir de sus Pagos y NC/ND, no se almacenan como valores mutables (mismo criterio "derivar, no
guardar" que Ventas/Tesorería — ver Clarifications de spec.md).

Compras usa **formulario de página completa** (no modal), igual excepción documentada que Presupuesto/
Venta. Gastos, en cambio, es el primer documento de Egresos/Ingresos que **sí** respeta el patrón modal
al 100% (sin página propia siquiera para "ver"), reutilizando el patrón ya visto en Otro Ingreso (spec
008) — es su análogo casi exacto del lado de Egresos. Todo lo demás respeta CLAUDE.md: DataTables
server-side, Toastr, Select2 (proveedor/producto/categoría/cuenta), PDFs en el modal compartido (detalle
de compra).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel 12, Eloquent, Blade, `yajra/laravel-datatables`,
`barryvdh/laravel-dompdf` (ya en uso, sin librerías nuevas). **Depende de código de spec 007**
(`CuentaTesoreria`, `Tesoreria` service) y de **spec 008** (`Services/Ingresos/CalculoComprobante`,
tabla `retenciones`, tabla `categorias`, catálogo `etiquetas`/`etiquetables` si se decide etiquetar
Compras) y de specs 001-003/006 (`Proveedor`, `Producto`, `Categoria`).

**Storage**: MySQL (`contagram`). Tablas nuevas: `compras`, `compra_items`, `compra_conceptos`,
`pagos`, `gastos`. Todas figuran en `docs/modelo_datos.md §7` (documentadas, pendientes de implementar)
y se materializan acá. La tabla `retenciones` (spec 008/modelo_datos.md §5) ya existe — esta spec sólo
agrega el flujo que la puebla desde Compras (`pago_id`).

**Testing**: PHPUnit sobre SQLite en memoria. Foco (Principio IV — es dinero e impacto fiscal/stock):
cálculo de totales de compra (subtotal/IVA opcional/descuento/percepciones), invariante A Pagar = Total
+ ND − NC − Pagado, impacto de cada Pago/Gasto en el saldo de Tesorería (egreso), reversión al
soft-deletear una Compra pagada, idempotencia del guardado de compra (no duplicar por doble submit), y
que un Gasto "pendiente" no impacte Tesorería.

**Target Platform**: Aplicación web (navegador de escritorio, `php artisan serve`).

**Project Type**: Web application monolítica Laravel (backend + Blade).

**Performance Goals**: Volumen del negocio (miles de comprobantes). Listados DataTables server-side;
totales derivados con agregación indexada (mismo patrón que Ventas/Tesorería).

**Constraints**: Single-tenant (sin `empresa_id`). Sin ARCA (tipo/N° de comprobante de Compra = dato,
watermark "NO VÁLIDO COMO FACTURA"). Compras/Pagos/Gastos con impacto contable → **soft delete**
(Principio III, que nombra explícitamente "gastos" entre los documentos que lo exigen), con reversión de
movimientos de tesorería: en Compra vía `CompraObserver` (documento con pagos/notas dependientes); en
Gasto, de forma directa en `GastoController::destroy()` sin Observer intermedio, por ser un documento
atómico sin cadena de documentos dependientes (ver Complexity Tracking) — **soft delete igual**, sólo se
omite la capa de Observer. Dependencia dura: **no** se construye un catálogo de medios de pago paralelo
— se usan las cuentas de Tesorería (spec 007), mismo catálogo que Ventas/Otros Ingresos. Si 007 no está,
esta feature se bloquea (regla de oro).

**Scale/Scope**: 5 tablas nuevas + 5 modelos, 1 servicio de dominio nuevo (`Services/Egresos/Pagos`,
análogo a `Cobranzas`) + reutilización de `CalculoComprobante`, 2 controladores (Compra, Gasto) +
reutilización de `NotaCreditoDebitoController` (genérico ya construido en spec 008, sólo se extiende
para aceptar `compra_id` además de `venta_id`), ~10 vistas/partials (1 de página completa + detalle, el
resto modales/parciales/PDF de Compra; 1 sola vista de Gasto: index + modal), 1 entrada de sidebar
(Egresos) a wirear con sus dos ítems.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ Basado en
  `docs/informe_contagram_egresos.md`, `docs/documentacion_principal_crm.md §4` y
  `docs/modelo_datos.md §7` (ya actualizados en el mismo cambio que originó esta spec). Al cierre se
  marcan esas entidades como implementadas (tarea explícita en tasks.md).
- **II. Desarrollo spec-driven**: ✅ specify → clarify → plan → tasks → analyze → implement.
- **III. Corrección fiscal innegociable (ARCA)**: ⚠️ **Parcial por alcance acordado** (mismo criterio ya
  aceptado en spec 008). Esta spec NO emite comprobantes fiscales: tipo/N° de Compra son dato y el
  documento lleva watermark "NO VÁLIDO COMO FACTURA" — coherente con el comportamiento real de la cuenta
  de prueba de Contagram. **No se viola el principio** porque no se presenta ningún comprobante como
  emitido/válido sin CAE. Documentos con impacto contable (compras, pagos, **y gastos** — la Constitución
  nombra "gastos" explícitamente en su lista) usan **soft delete**. Gasto no tiene Observer (no hay
  cadena de documentos dependientes) pero sí usa `SoftDeletes` igual que Compra/Pago. Ver Complexity
  Tracking.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Tests de cálculo de totales, A Pagar, impacto en
  Tesorería (pago y gasto), reversión y NC-stock si aplica (ver Testing).
- **V. Convenciones Laravel + dominio en español**: ✅ `compras`, `compra_items`, `pagos`, `gastos`,
  servicio en `Services/Egresos`, rutas en español (`/compras`, `/gastos`), sin `empresa_id`,
  reutilización de modelos existentes (Proveedor, Producto, Categoria, CuentaTesoreria).

**Resultado del gate**: PASS con nota (III parcial por alcance acordado, igual que spec 008 — no es una
violación, es un límite de alcance explícito y seguro).

## Project Structure

### Documentation (this feature)

```text
specs/009-egresos-compras-gastos/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada, con Clarifications)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md        # Fase 1 (este comando)
├── contracts/           # Fase 1 (este comando)
│   └── egresos-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad (ya creado)
└── tasks.md              # Fase 2 (/speckit-tasks)
```

### Source Code (repository root)

Monolito Laravel. Nuevos/modificados:

```text
app/
├── Http/Controllers/
│   ├── CompraController.php             # NUEVO: listado(data) / form página completa / store-update /
│   │                                     #        detalle / pago(store) / remito(store) / pdf /
│   │                                     #        destroy(soft)
│   └── GastoController.php              # NUEVO: listado(data) / store-update / destroy (JSON+modal)
├── Http/Requests/
│   ├── StoreCompraRequest.php · UpdateCompraRequest.php             # NUEVO
│   ├── StorePagoRequest.php                                         # NUEVO (monto ≤ saldo, cuenta exists)
│   ├── StoreGastoRequest.php · UpdateGastoRequest.php               # NUEVO
│   └── StoreRetencionRequest.php                                    # NUEVO (reutilizable Compra/Venta)
├── Http/Requests/ (MODIFICADO)
│   └── StoreNotaCreditoDebitoRequest.php  # acepta compra_id además de venta_id (uno de los dos)
├── Models/
│   ├── Compra.php · CompraItem.php · CompraConcepto.php  # NUEVO (Compra softDeletes)
│   ├── Pago.php                                          # NUEVO
│   ├── Gasto.php                                         # NUEVO
│   └── Retencion.php                                     # NUEVO (documentada en spec 008, no construida
│                                                          #        hasta ahora — se crea acá)
├── Models/ (MODIFICADO)
│   └── NotaCreditoDebito.php     # agrega relación compra() junto a venta(), exactamente una de las dos
├── Services/Egresos/
│   └── Pagos.php                 # NUEVO: registrar pago/gasto → Tesoreria::registrarMovimiento (tx),
│                                  #        recomputar A Pagar/estado de Compra, reversión en soft delete
├── Observers/
│   └── CompraObserver.php        # NUEVO: al soft-delete de Compra, revertir pagos/movimientos tesorería

resources/js/
├── compras.js · gastos.js        # NUEVO (Select2, DataTables, modales, cálculo en vivo)

resources/views/
├── compras/{index, form, detalle, _modal_pago, _modal_retencion, pdf, _row_actions}.blade.php  # NUEVO
└── gastos/{index, _modal_gasto, _modal_categoria, _row_actions}.blade.php                       # NUEVO

database/
├── migrations/ (5 migraciones 2026_07_25_*: compras, compra_items, compra_conceptos, pagos, gastos,
│                más una migración de creación de `retenciones` que spec 008 documentó sin construir)
├── factories/ (Compra, Pago, Gasto, Retencion)   # NUEVO
└── seeders/ (CategoriasGastoSeeder opcional: Empleados/Impuestos/Marketing/Oficina/Otros Gastos/
             Servicios Profesionales, con subcategorías del informe)                          # NUEVO

resources/views/elements/sidebar.blade.php   # MODIFICADO: activar rutas reales de Egresos (Compras, Gastos)
routes/web.php                               # MODIFICADO: grupo compras + grupo gastos (ver contracts/)

tests/
└── Feature/
    ├── CompraTest.php · CompraCalculoTest.php                       # NUEVO
    ├── CompraPagoTest.php (impacto Tesorería, A Pagar, soft delete)  # NUEVO
    ├── GastoTest.php (pendiente no impacta)                         # NUEVO
    └── NotaCreditoDebitoCompraTest.php (si afecta stock)             # NUEVO
```

**Structure Decision**: un controlador por pantalla/recurso (Compra, Gasto) + reutilización del
controlador genérico de NC/ND (spec 008) extendido para aceptar `compra_id`. Un solo servicio de dominio
nuevo, `Pagos` (análogo exacto a `Cobranzas` de spec 008), que orquesta tanto el pago de una Compra como
el alta de un Gasto contra Tesorería — es el **único** lugar que llama a
`Tesoreria::registrarMovimiento()` desde Egresos, igual criterio que Ingresos (SC-002/SC-004 de esta
spec). `CompraObserver` garantiza la reversión en Tesorería ante soft delete de una Compra pagada. Gasto
no necesita Observer: al no tener documentos dependientes (sin NC/ND, sin pagos parciales — es "todo o
nada"), su eliminación revierte su propio movimiento de tesorería directamente en el controlador,
igual que ya hace Tesorería con sus movimientos nativos (spec 007).

## Complexity Tracking

| Violación potencial | Por qué se acepta | Alternativa más simple descartada porque |
|---|---|---|
| Principio III cumplido de forma **parcial** (Compra sin CAE/ARCA) | Alcance acordado con el usuario, mismo criterio que Ventas (spec 008): Egresos primero, Facturación Electrónica después. No se presenta ningún comprobante como válido — watermark "NO VÁLIDO COMO FACTURA". | Construir Egresos + ARCA junto sería un módulo enorme y ARCA aún no está relevado en detalle; separar reduce riesgo. El enganche (tipo/N° de comprobante como dato) queda listo para conectar CAE luego. |
| Dependencia dura con spec 007 (Tesorería) y reutilización de spec 008 (`CalculoComprobante`, `retenciones`, patrón `Cobranzas`→`Pagos`) | La regla de oro prohíbe simplificar la dependencia (medios de pago = cuentas de Tesorería reales) o duplicar lógica ya construida y testeada. | Un catálogo de medios de pago propio o un cálculo de totales paralelo duplicaría spec 007/008 y habría que reconciliarlo después — peor. Se ordena 009 después de 007 y 008. |
| Gasto sin Observer propio (revierte su movimiento de tesorería directo en el controller, no vía evento; **sí usa SoftDeletes**, exigido por el Principio III que nombra "gastos" explícitamente) | Gasto es un documento atómico sin cadena de documentos dependientes (sin pagos parciales, sin NC/ND) — el mismo criterio que ya usa Tesorería para sus movimientos nativos (Saldo Inicial, Movimiento entre Cuentas), que tampoco usan Observer. | Un Observer agregaría una capa de indirección sin beneficio real para un caso de un único movimiento 1:1; se reserva el patrón Observer para documentos con múltiples movimientos vinculados (Compra/Venta). El soft delete en sí no se descarta — eso violaría la Constitución. |
