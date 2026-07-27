# Implementation Plan: Módulo Ingresos (Presupuestos · Ventas · Otros Ingresos)

**Branch**: `008-ingresos-ventas-presupuestos` | **Date**: 2026-07-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/008-ingresos-ventas-presupuestos/spec.md`

## Summary

Módulo Ingresos con tres pantallas: Presupuestos, Ventas y Otros Ingresos, más el flujo Presupuesto →
Venta → Cobranza y las NC/ND sobre ventas. Fiel a `docs/informe_contagram_ingresos.md` (capturas 65-98).

Enfoque técnico: Presupuestos y Ventas comparten casi todo el modelo (cliente, ítems, descuentos,
conceptos extra, etiquetas), así que se modelan como dos entidades espejo con un **servicio de cálculo
de totales compartido** (`Services/Ingresos/CalculoComprobante`) para no duplicar la lógica de
subtotales/IVA/descuento/percepciones. Los **totales derivados** de una venta (A Cobrar, Cobrado,
estado del cobro) se calculan a partir de sus Cobros y NC/ND, no se almacenan como valores mutables
(mismo criterio "derivar, no guardar" que Tesorería). Cada **Cobro** dispara un movimiento de tesorería
vía `Services/Tesoreria/Tesoreria::registrarMovimiento()` (dependencia de spec 007) dentro de una
transacción; eliminar/anular una venta o cobro revierte ese movimiento (soft delete, principio III).

Presupuestos y Ventas usan **formularios de página completa** (no modal) — excepción documentada al
patrón de modales, igual que Importar Datos (spec 006) —, porque son documentos de carga extensos; el
resto (Cobranza, NC/ND, Otro Ingreso, categorías/etiquetas inline) sí son modales AJAX. Todo lo demás
respeta CLAUDE.md: DataTables server-side, Toastr, Select2 (cliente/producto/categoría/cuenta), PDFs en
el modal compartido (detalle de venta, presupuesto).

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel 12, Eloquent, Blade. `yajra/laravel-datatables`, `barryvdh/laravel-
dompdf` (ambos ya en uso). **Depende de código de spec 007** (`CuentaTesoreria`, `Tesoreria` service) y
de specs 001-006 (`Cliente`, `Producto`, `Categoria`, `ListaPrecio`, `StockService`/`movimientos_stock`).
Sin librerías nuevas.

**Storage**: MySQL (`contagram`). Tablas nuevas: `presupuestos`, `presupuesto_items`,
`presupuesto_conceptos`, `ventas`, `venta_items`, `venta_conceptos`, `cobros`, `otros_ingresos`,
`notas_credito_debito`, `nota_credito_debito_items`, `remitos`, `etiquetas`, `etiquetables`. Todas
figuran en `docs/modelo_datos.md §5` (documentadas, pendientes de implementar) y se materializan acá.

**Testing**: PHPUnit sobre SQLite en memoria. Foco (Principio IV — es dinero e impacto fiscal/stock):
cálculo de totales de presupuesto/venta (subtotal/IVA/descuento/percepciones), invariante A Cobrar =
Total + ND − NC − Cobrado, impacto de cada Cobro en el saldo de Tesorería, reversión al soft-deletear,
NC que afecta stock, idempotencia del guardado de presupuesto (no duplicar por doble submit).

**Target Platform**: Aplicación web (navegador de escritorio, `php artisan serve`).

**Project Type**: Web application monolítica Laravel (backend + Blade).

**Performance Goals**: Volumen del negocio (miles de comprobantes). Listados DataTables server-side;
totales derivados con agregación indexada.

**Constraints**: Single-tenant (sin `empresa_id`). Sin ARCA (tipo/N° de comprobante = dato, watermark).
Cobros y ventas con impacto contable → **soft delete** + reversión de movimientos de tesorería.
Dependencia dura: **no** se construye un catálogo de medios de cobro paralelo — se usan las cuentas de
Tesorería (spec 007). Si 007 no está, esta feature se bloquea (regla de oro).

**Scale/Scope**: ~13 tablas + ~13 modelos, 2 servicios de dominio (`CalculoComprobante`,
`Ventas`/`Cobranzas` orquestador que habla con Tesorería), ~4 controladores (Presupuesto, Venta,
OtroIngreso, NotaCreditoDebito) + endpoints de cobranza/remito, ~12-15 vistas/partials (2 de página
completa, resto modales/parciales/PDF), 3 entradas de sidebar ya wireadas. Abonos y Facturación
Electrónica NO se construyen acá.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ Basado en `docs/informe_contagram_ingresos.md`,
  `docs/documentacion_principal_crm.md §3` y `docs/modelo_datos.md §5`. Al cierre se marcan esas
  entidades como implementadas (tarea explícita en tasks.md).
- **II. Desarrollo spec-driven**: ✅ specify → plan → tasks → analyze → implement.
- **III. Corrección fiscal innegociable (ARCA)**: ⚠️ **Parcial por alcance acordado**. Esta spec NO emite
  comprobantes fiscales: tipo/N° son dato y el documento lleva watermark "NO VÁLIDO COMO FACTURA" —
  coherente con el comportamiento real de la cuenta de prueba de Contagram y con la decisión explícita
  del usuario. **No se viola el principio** porque no se presenta ningún comprobante como emitido/válido
  sin CAE (justamente se marca como no válido). El enganche a ARCA llega con la spec de Facturación
  Electrónica. Documentos con impacto contable (ventas, cobros, NC/ND) usan **soft delete**. Ver
  Complexity Tracking.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Tests de cálculo de totales, A Cobrar, impacto en
  Tesorería, reversión y NC-stock (ver Testing).
- **V. Convenciones Laravel + dominio en español**: ✅ `presupuestos`, `ventas`, `cobros`,
  `otros_ingresos`, `notas_credito_debito`, servicios en `Services/Ingresos`, rutas en español, sin
  `empresa_id`, reutilización de modelos existentes.

**Resultado del gate**: PASS con nota (III parcial por alcance acordado, justificado en Complexity
Tracking — no es una violación, es un límite de alcance explícito y seguro).

## Project Structure

### Documentation (this feature)

```text
specs/008-ingresos-ventas-presupuestos/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md        # Fase 1 (este comando)
├── contracts/           # Fase 1 (este comando)
│   └── ingresos-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad (ya creado)
└── tasks.md             # Fase 2 (/speckit-tasks)
```

### Source Code (repository root)

Monolito Laravel. Nuevos/modificados:

```text
app/
├── Http/Controllers/
│   ├── PresupuestoController.php        # NUEVO: listado(data) / form página completa / store-update /
│   │                                     #        estado / crearVenta / ver(doc) / pdf
│   ├── VentaController.php              # NUEVO: listado(data) / form / store-update / detalle /
│   │                                     #        cobranza(store) / remito(store) / pdf / destroy(soft)
│   ├── OtroIngresoController.php        # NUEVO: listado(data) / store-update / destroy (JSON+modal)
│   └── NotaCreditoDebitoController.php  # NUEVO: wizard NC/ND (store)
├── Http/Requests/
│   ├── StorePresupuestoRequest.php · UpdatePresupuestoRequest.php   # NUEVO
│   ├── StoreVentaRequest.php · UpdateVentaRequest.php               # NUEVO
│   ├── StoreCobroRequest.php                                        # NUEVO (monto ≤ saldo, cuenta exists)
│   ├── StoreOtroIngresoRequest.php · UpdateOtroIngresoRequest.php   # NUEVO
│   └── StoreNotaCreditoDebitoRequest.php                            # NUEVO
├── Models/
│   ├── Presupuesto.php · PresupuestoItem.php · PresupuestoConcepto.php  # NUEVO
│   ├── Venta.php · VentaItem.php · VentaConcepto.php · Cobro.php         # NUEVO (Venta softDeletes)
│   ├── OtroIngreso.php · NotaCreditoDebito.php · NotaCreditoDebitoItem.php # NUEVO
│   ├── Remito.php · Etiqueta.php                                          # NUEVO
├── Services/Ingresos/
│   ├── CalculoComprobante.php          # NUEVO: subtotales/IVA/descuento/percepciones (compartido pres/venta)
│   └── Cobranzas.php                    # NUEVO: registrar cobro → Tesoreria::registrarMovimiento (tx),
│                                         #        recomputar estado/A Cobrar, reversión en soft delete
├── Observers/
│   └── VentaObserver.php                # NUEVO: al soft-delete de Venta, revertir cobros/movimientos tesorería

resources/js/
├── presupuestos.js · ventas.js · otros-ingresos.js   # NUEVO (Select2, DataTables, modales, cálculo en vivo)

resources/views/
├── presupuestos/{index, form, documento, _modal_categoria, pdf}.blade.php   # NUEVO
├── ventas/{index, form, detalle, _modal_cobranza, _modal_ncnd, pdf, ticket}.blade.php  # NUEVO
└── otros-ingresos/{index, _modal_ingreso, _modal_categoria}.blade.php       # NUEVO

database/
├── migrations/ (13 migraciones 2026_07_26_*)          # NUEVO
├── factories/ (Presupuesto, Venta, Cobro, OtroIngreso, ...)  # NUEVO
└── seeders/ (CategoriasIngresoSeeder, EtiquetasDemoSeeder opcional)  # NUEVO

resources/views/elements/sidebar.blade.php   # MODIFICADO: activar rutas reales de Ingresos
routes/web.php                               # MODIFICADO: grupo ingresos (ver contracts/)

tests/
└── Feature/
    ├── PresupuestoTest.php · PresupuestoCalculoTest.php        # NUEVO
    ├── VentaCobranzaTest.php (impacto Tesorería, A Cobrar, soft delete) # NUEVO
    ├── OtroIngresoTest.php (pendiente no impacta) · NotaCreditoDebitoTest.php (stock) # NUEVO
```

**Structure Decision**: un controlador por pantalla/recurso (Presupuesto, Venta, OtroIngreso, NC/ND) +
dos servicios de dominio: `CalculoComprobante` (cálculo puro de totales, compartido entre Presupuesto y
Venta, testeable aislado) y `Cobranzas` (orquesta el cobro y su reflejo en Tesorería en una
transacción). Este último es el **único** lugar que llama a `Tesoreria::registrarMovimiento()`, para que
la integración Ingresos↔Tesorería viva en un solo punto testeable (SC-002/SC-005). `VentaObserver`
garantiza la reversión en Tesorería ante soft delete.

## Complexity Tracking

| Violación potencial | Por qué se acepta | Alternativa más simple descartada porque |
|---|---|---|
| Principio III cumplido de forma **parcial** (sin CAE/ARCA) | Alcance acordado con el usuario: Ingresos primero, Facturación Electrónica después. No se presenta ningún comprobante como válido — el documento lleva watermark "NO VÁLIDO COMO FACTURA", idéntico al comportamiento real de Contagram sin AFIP habilitado. | Construir Ingresos + ARCA junto sería un módulo enorme y ARCA aún no está relevado en detalle; separar reduce riesgo y respeta el flujo spec-driven. El enganche (tipo/N° de comprobante como dato) queda listo para conectar CAE luego sin rehacer el modelo. |
| Dependencia dura con spec 007 (Tesorería) | La regla de oro prohíbe simplificar la dependencia (medios de cobro = cuentas de Tesorería reales). | Un catálogo de medios de cobro propio duplicaría Tesorería y habría que reconciliarlo después — peor. Se ordena 007 antes que 008. |
