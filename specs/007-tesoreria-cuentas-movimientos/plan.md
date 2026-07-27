# Implementation Plan: Módulo Tesorería (Cuentas y Movimientos)

**Branch**: `007-tesoreria-cuentas-movimientos` | **Date**: 2026-07-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/007-tesoreria-cuentas-movimientos/spec.md`

## Summary

Módulo Tesorería: panel financiero centralizado single-tenant con dos pestañas (Saldos, Movimientos),
configuración de cuentas (CRUD por modales), transferencias internas (partida doble) y ficha/ledger por
cuenta con saldo corrido. Fiel a `docs/informe_contagram_tesoreria.md` (capturas 144-162).

Enfoque técnico: dos tablas nuevas — `cuentas_tesoreria` (catálogo de cuentas con tipo, visibilidad,
flag de sistema) y `movimientos_tesoreria` (ledger con origen **polimórfico** para que Ventas/Compras/
Gastos enganchen después sin que Tesorería los conozca). El **saldo de una cuenta es siempre derivado**
(`Saldo Inicial + Σ ingresos − Σ egresos` hasta una fecha), nunca una columna mutable — se calcula con
agregación SQL, y el balance corrido de la ficha con una **función de ventana** (`SUM(...) OVER (ORDER
BY fecha, id)`), exactamente el mismo patrón ya usado y validado en el Informe de Stock (spec 003,
`InformeStockController`). Una transferencia se registra como **dos filas** vinculadas (egreso + ingreso)
dentro de una transacción. Un `Services/Tesoreria/Tesoreria.php` concentra la creación de movimientos
(saldo inicial, transferencia, y el método público `registrarMovimiento()` que los módulos futuros
usarán) para que la regla de partida doble y el cálculo de saldos vivan en un solo lugar.

Todo el frontend respeta las reglas obligatorias de `CLAUDE.md`: DataTables server-side (ledger,
configuración de cuentas), altas/ediciones por modales Bootstrap + AJAX, Toastr, Select2 en los
selectores de cuenta (mostrando saldo), y el modal PDF compartido para el "Exportar a PDF" del informe
Movimientos.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: Laravel Framework 12, Eloquent ORM, Blade. `yajra/laravel-datatables` (ya en
uso en el proyecto para server-side) y `barryvdh/laravel-dompdf` (ya en uso para PDFs inline, patrón
del modal compartido). Sin librerías nuevas. Función de ventana SQL — requiere MySQL 8.0+ (ya asumido
por el Informe de Stock, spec 003, que ya usa `OVER (PARTITION BY ...)`).

**Storage**: MySQL (XAMPP local, DB `contagram`). **Dos tablas nuevas**: `cuentas_tesoreria`,
`movimientos_tesoreria`. Ambas figuran hoy como "descartadas/pendientes" en `docs/modelo_datos.md §6`
y se reincorporan con este spec (se documenta la reincorporación al cierre, principio I).

**Testing**: PHPUnit sobre SQLite en memoria para la lógica; **excepción**: los tests del saldo corrido
por función de ventana corren contra MySQL (SQLite no soporta `SUM() OVER` idéntico) o se cubren con un
cálculo equivalente en el test — se decide en research.md §5. Foco de tests (Principio IV: es dinero):
cálculo de saldos a fecha, invariante de partida doble (total no cambia), balance corrido fila a fila,
bloqueo de borrado de cuenta con movimientos, no-edición de cuentas del sistema.

**Target Platform**: Aplicación web (navegador de escritorio, `php artisan serve` en dev).

**Project Type**: Web application monolítica Laravel (backend + Blade en el mismo proyecto).

**Performance Goals**: Volumen del negocio (decenas de cuentas, miles de movimientos). Saldos y ledger
por agregación SQL indexada; sin necesidad de cache ni cola en esta versión.

**Constraints**: Single-tenant (sin `empresa_id`). El saldo **nunca** se almacena como columna mutable
(evita desincronización — SC-003/SC-005). Partida doble siempre atómica (transacción). Descubierto
permitido (sin bloqueo por saldo negativo — FR-013).

**Scale/Scope**: 2 tablas + 2 modelos nuevos, 1 servicio de dominio, 2 controladores
(`TesoreriaController` para Saldos/Movimientos/config/transferencias, `CuentaTesoreriaController` para
el CRUD de cuentas y su ledger), ~2 seeders (cuentas del sistema + cuentas demo del relevamiento), ~6-8
vistas/partials, 1 entrada de sidebar ya wireada. Los generadores de Cobros/Pagos/Gastos NO se
construyen acá (FR-030).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: ✅ El plan se basa en
  `docs/informe_contagram_tesoreria.md`, `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md`.
  Al cierre se actualizan estos dos últimos (nueva sección Tesorería + tablas reincorporadas) — tarea
  explícita en tasks.md.
- **II. Desarrollo spec-driven**: ✅ Flujo specify → plan → tasks → analyze → implement.
- **III. Corrección fiscal innegociable (ARCA)**: N/A directa — Tesorería no emite comprobantes. Punto
  de contacto: la columna "N° Factura" del ledger es sólo lectura del dato que el módulo de origen
  guardó; no valida ni emite CAE. Los movimientos con impacto contable (cobros/pagos, cuando existan)
  usarán **soft delete** (principio III) — se prevé la columna/trait en el modelo de movimientos.
- **IV. Testing donde hay dinero o impacto fiscal**: ✅ Es un módulo íntegramente de dinero. Se
  planifican tests para saldos, partida doble, balance corrido y reglas de borrado/sistema (ver
  Testing arriba).
- **V. Convenciones Laravel + dominio en español**: ✅ `cuentas_tesoreria`, `movimientos_tesoreria`,
  `Tesoreria` service, rutas `tesoreria`/`cuentas`, sin `empresa_id`, Observers/Service para el
  recálculo, relación polimórfica estándar (`origen morphTo`).

**Resultado del gate**: PASS. Sin violaciones que justificar (Complexity Tracking vacío).

## Project Structure

### Documentation (this feature)

```text
specs/007-tesoreria-cuentas-movimientos/
├── plan.md              # Este archivo
├── spec.md              # Especificación (ya creada)
├── research.md          # Fase 0 (este comando)
├── data-model.md        # Fase 1 (este comando)
├── quickstart.md        # Fase 1 (este comando)
├── contracts/           # Fase 1 (este comando)
│   └── tesoreria-rutas.md
├── checklists/
│   └── requirements.md  # Checklist de calidad de la spec (ya creado)
└── tasks.md             # Fase 2 (/speckit-tasks — NO lo crea este comando)
```

### Source Code (repository root)

Monolito Laravel existente. Archivos nuevos/modificados de esta feature:

```text
app/
├── Http/Controllers/
│   ├── TesoreriaController.php          # NUEVO: Saldos (index) / Movimientos (informe) / config cuentas
│   │                                     #        (JSON) / transferencia (store) / export PDF informe
│   └── CuentaTesoreriaController.php     # NUEVO: CRUD de cuentas (store/update/destroy JSON) +
│                                          #        ficha/ledger (show + data() server-side) + export
├── Http/Requests/
│   ├── StoreCuentaTesoreriaRequest.php   # NUEVO: nombre, tipo (in A Cobrar/A Pagar/Banco/Efectivo),
│   │                                      #        saldo_inicial, fecha
│   ├── UpdateCuentaTesoreriaRequest.php  # NUEVO: sin tipo (inmutable); + visibilidad
│   └── StoreTransferenciaRequest.php     # NUEVO: cuenta_salida≠cuenta_entrada, monto>0, fecha
├── Models/
│   ├── CuentaTesoreria.php               # NUEVO: scopes visibles/porTipo, tieneOperaciones(), saldoA()
│   └── MovimientoTesoreria.php           # NUEVO: morphTo origen, soft delete, scopes
├── Services/Tesoreria/
│   └── Tesoreria.php                     # NUEVO: registrarSaldoInicial(), transferir() [partida doble
│                                          #        atómica], registrarMovimiento() [API para módulos
│                                          #        futuros], saldos()/flujo() para las vistas
resources/js/
└── tesoreria.js                          # NUEVO: DataTables (ledger, config), modales AJAX (cuenta,
                                           #        transferencia), Select2 con saldo, toggles del informe

resources/views/tesoreria/
├── saldos.blade.php                      # NUEVO: pestaña Saldos (A Cobrar / A Pagar / Disponible)
├── movimientos.blade.php                 # NUEVO: pestaña Movimientos (informe flujo de caja)
├── cuenta.blade.php                      # NUEVO: ficha/ledger de una cuenta
├── _modal_cuenta.blade.php               # NUEVO: alta/edición de cuenta
├── _modal_transferencia.blade.php        # NUEVO: Movimiento entre Cuentas
├── _config_cuentas.blade.php             # NUEVO: tabla "Ajustes Cuentas Tesorería" agrupada por tipo
└── pdf/
    └── movimientos.blade.php             # NUEVO: PDF inline del informe Movimientos (modal compartido)

database/
├── migrations/
│   ├── 2026_07_25_060001_create_cuentas_tesoreria_table.php     # NUEVO
│   └── 2026_07_25_060002_create_movimientos_tesoreria_table.php # NUEVO
├── factories/
│   ├── CuentaTesoreriaFactory.php        # NUEVO
│   └── MovimientoTesoreriaFactory.php    # NUEVO
└── seeders/
    └── CuentasTesoreriaSeeder.php        # NUEVO: cuentas del sistema (Cheque de Terceros/Propio) +
                                           #        cuentas demo del relevamiento

resources/views/elements/sidebar.blade.php  # MODIFICADO: activar rutas reales de Tesorería (placeholder)
routes/web.php                              # MODIFICADO: grupo de rutas tesoreria/* (ver contracts/)

tests/
└── Feature/
    ├── TesoreriaCuentaTest.php            # NUEVO: CRUD, tipo inmutable, ocultar, cuenta del sistema,
    │                                       #        bloqueo de borrado con movimientos
    ├── TesoreriaTransferenciaTest.php     # NUEVO: partida doble, invariante de total, validaciones
    └── TesoreriaSaldosLedgerTest.php      # NUEVO: saldo a fecha de corte, balance corrido, filtros
```

**Structure Decision**: Dos controladores por responsabilidad de pantalla —`TesoreriaController` para
las vistas globales (Saldos, Movimientos, configuración de cuentas, transferencia) y
`CuentaTesoreriaController` para el recurso "cuenta" (CRUD + su ficha/ledger). Toda la lógica de dinero
(crear movimientos, partida doble, cálculo de saldos y flujo) se concentra en `Services/Tesoreria/
Tesoreria.php`, para que sea el **único punto** que los módulos futuros (Ingresos/Egresos) invoquen al
registrar cobros/pagos (FR-030) y para mantener testeable la regla de partida doble aislada de HTTP.

## Complexity Tracking

> No aplica — el Constitution Check pasó sin violaciones. Sin desviaciones que justificar.
