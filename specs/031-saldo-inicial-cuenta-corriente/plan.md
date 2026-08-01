# Implementation Plan: Saldo Inicial en Cuenta Corriente

**Branch**: `031-saldo-inicial-cuenta-corriente` | **Date**: 2026-08-01 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/031-saldo-inicial-cuenta-corriente/spec.md`

## Summary

`Cliente.saldo_inicial`/`saldo_inicial_fecha` (y su equivalente en `Proveedor`) ya se cargan y
guardan en la ficha, pero `App\Services\Tesoreria\CuentaCorriente::aging()`/`porCliente()` los ignora
por completo — sólo suma `Venta::aCobrar()`/`Compra::aPagar()`. Esta feature extiende ambos métodos
para sumar el saldo inicial al bucket de antigüedad que le corresponda (según `saldo_inicial_fecha`),
afectando el Dashboard (spec 010), "Saldos Clientes" (spec 029) y el cálculo de aging de Proveedores
(consumido hoy sólo por el Dashboard). También agrega una fila sintética "Saldo Inicial" al tab
"Movimientos" de spec 029, para sostener el invariante ya probado ahí (suma de A Cobrar = Total de
Saldos Clientes) también para clientes con saldo inicial.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 12) + Blade + JavaScript (jQuery/DataTables/Select2) — mismo
stack que spec 029, sin dependencias nuevas.

**Primary Dependencies**: Ninguna nueva — se extiende `App\Services\Tesoreria\CuentaCorriente` y
`Informes\CuentaCorrienteController` (`queryMovimientos()`) ya existentes.

**Storage**: MySQL — sin migraciones nuevas; se reutilizan las columnas `saldo_inicial`/
`saldo_inicial_fecha` ya existentes en `clientes` y `proveedores`.

**Testing**: PHPUnit. Tests obligatorios (Constitución IV, cuenta corriente = "dinero") para: bucket
correcto del saldo inicial solo (sin Ventas), suma saldo inicial + Venta en el mismo bucket y en
buckets distintos, saldo inicial negativo resta del total, saldo inicial ≈0 no aparece, invariante
Dashboard=Saldos Clientes tras el cambio, invariante Movimientos=Saldos Clientes incluyendo la fila
"Saldo Inicial", filtro `operacion=saldo_inicial` en Movimientos, y cero regresión para clientes sin
saldo inicial (reejecutar los tests ya existentes de spec 029/010).

**Target Platform**: Web (mismo alcance que el resto del CRM).

**Project Type**: Web application (Laravel monolito) — extensión de un servicio y un controller ya
existentes, sin pantallas nuevas.

**Performance Goals**: Una query adicional acotada (`Cliente`/`Proveedor::where('saldo_inicial', '!=',
0)`) por llamada a `aging()`/`porCliente()` — sin N+1, mismo estándar que spec 029 (research.md R6).

**Constraints**: No se puede romper el invariante ya testeado por spec 029 (Total de Saldos Clientes ==
Cuentas a Cobrar del Dashboard, y suma de Movimientos == Total de Saldos Clientes) — al contrario, se
extiende para que también cubra el caso con saldo inicial.

**Scale/Scope**: Extensión de 1 servicio (`CuentaCorriente`) y 1 controller (`CuentaCorrienteController`,
método `queryMovimientos()`) + 2 archivos de frontend ya existentes (`index.blade.php` — nueva opción
de filtro, `informe-cuenta-corriente.js` — nueva etiqueta de operación). Sin pantallas, rutas ni
controllers nuevos.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Documentación de dominio como fuente de verdad**: `docs/modelo_datos.md` ya documenta
  `saldo_inicial`/`saldo_inicial_fecha` como "sin uso funcional en el aging" — este plan actualiza esa
  nota (Phase 1) para reflejar que ya se incorporó. `documentacion_principal_crm.md` §6.3/§6.4 se
  actualizan para dejar de decir que el saldo inicial está fuera del cálculo. PASA.
- **II. Desarrollo spec-driven**: flujo completo specify→clarify→plan→checklist→tasks→analyze. PASA.
- **III. Corrección fiscal (ARCA)**: no aplica — no emite comprobantes ni CAE, sólo extiende un cálculo
  de aging ya existente. N/A.
- **IV. Testing donde hay dinero o impacto fiscal**: esta feature modifica un **cálculo de saldos de
  cuenta corriente** ya en producción (Dashboard + Informe) — está explícitamente en el alcance
  obligatorio de testing de la constitución, y además hay que probar que NO cambia el resultado para
  el caso ya cubierto (sin saldo inicial). GATE: no se puede cerrar `/speckit-tasks` sin tareas de test
  para ambos casos (con y sin saldo inicial) y para los tres invariantes (Dashboard, Movimientos,
  cero regresión).
- **V. Convenciones Laravel + dominio en español**: reutiliza nombres ya existentes (`saldo_inicial`,
  `saldo_inicial_fecha`), agrega el valor de enum `'saldo_inicial'` como nuevo tipo de `operacion` en
  Movimientos — consistente con `venta`/`cobro`/`nota_credito`/`nota_debito` ya usados. PASA.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/031-saldo-inicial-cuenta-corriente/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
└── tasks.md
```

### Source Code (repository root)

```text
app/Services/Tesoreria/CuentaCorriente.php
└── aging() y porCliente() — agregar el recorrido de Cliente/Proveedor con saldo_inicial ≠ 0,
    sumado a los mismos buckets que ya calculan (research.md R1/R2/R3)

app/Http/Controllers/Informes/CuentaCorrienteController.php
└── queryMovimientos() — agregar el 4to SELECT del UNION (fila "Saldo Inicial", research.md R4)
    OPERACIONES_DISPONIBLES — agregar 'saldo_inicial'

resources/views/informes/cuenta-corriente/index.blade.php
└── agregar opción "Saldo Inicial" al <select> de filtro Operación (tab Movimientos)

resources/js/informe-cuenta-corriente.js
└── ETIQUETAS_OPERACION — agregar 'saldo_inicial': 'Saldo Inicial'

docs/documentacion_principal_crm.md, docs/modelo_datos.md
└── actualizar la nota de "sin uso funcional en el aging" (saldo_inicial ya se usa)
```

**Structure Decision**: Cambio acotado a la lógica de dos archivos ya existentes (`CuentaCorriente`
service, `CuentaCorrienteController`) más 2 archivos de frontend ya existentes — no se crean
controllers, rutas, vistas ni servicios nuevos. Proveedores sigue sin pantalla propia de Cuenta
Corriente (FR-010) — sólo se corrige el cálculo compartido que el Dashboard ya consume para ese lado.

## Complexity Tracking

*(vacío — sin violaciones de la constitución que requieran justificar)*
