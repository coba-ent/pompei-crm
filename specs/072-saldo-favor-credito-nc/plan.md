# Implementation Plan: Saldo a favor aplicable a nuevas Ventas y Compras

**Branch**: `072-saldo-favor-credito-nc` | **Date**: 2026-08-21 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/072-saldo-favor-credito-nc/spec.md`

## Summary

Permitir que el saldo a favor que un cliente (o proveedor) tiene por una Nota de Crédito se impute a
otro comprobante suyo, sin tocar Tesorería y sin destruir el registro del pago original.

Enfoque técnico: una tabla nueva `aplicaciones_credito` (polimórfica Venta/Compra, soft-delete) que
vincula comprobante origen → comprobante destino, y dos términos nuevos en las fórmulas derivadas
`aCobrar()`/`aPagar()` que hacen que la aplicación sea una **transferencia de saldo** y no una
creación de saldo. Como los saldos del proyecto son derivados y nunca almacenados, corregir la
fórmula propaga la consistencia a Cuenta Corriente, aging, KPIs y filtros sin migrar datos.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent, Blade + NexaDash (Bootstrap 5), DataTables server-side, Select2,
Toastr

**Storage**: MySQL/MariaDB — una tabla nueva (`aplicaciones_credito`); ninguna columna nueva en tablas
existentes

**Testing**: PHPUnit (Feature tests). Obligatorio por constitución IV (hay dinero de por medio)

**Target Platform**: Aplicación web single-tenant (XAMPP local / VPS producción)

**Project Type**: Web app monolítica Laravel

**Performance Goals**: el saldo en el selector de cliente no debe degradar el buscador — se calcula
sólo sobre la página que devuelve el Select2 (10-30 registros), nunca sobre el catálogo completo

**Constraints**:
- **Tesorería intocable**: cero `movimientos_tesoreria` nuevos, cero cambios en saldos/aging (FR-017/018/019)
- Sin migración de datos históricos
- El circuito de cobranzas con dinero no se modifica (FR-021)

**Scale/Scope**: ~23.800 ventas y ~2.400 compras en producción; volumen de aplicaciones esperado:
unidades por semana

## Constitution Check

*GATE: revisado antes de Phase 0 y después de Phase 1.*

| Principio | Estado | Notas |
|---|---|---|
| **I. Documentación de dominio como fuente de verdad** | ⚠️ Acción pendiente | Introduce entidad nueva (`aplicaciones_credito`) y una regla de negocio nueva. **Antes de `/speckit-tasks` hay que actualizar `docs/modelo_datos.md` y `docs/documentacion_principal_crm.md`**, incluyendo la divergencia deliberada respecto de Contagram |
| **II. Desarrollo spec-driven** | ✅ | Esta spec precede al código |
| **III. Corrección fiscal innegociable** | ✅ | No emite comprobantes ni toca ARCA: es una imputación interna. La NC ya resolvió su parte fiscal. Soft-delete en la tabla nueva, como exige el principio |
| **IV. Testing donde hay dinero** | ✅ | Es 100% dinero: tests obligatorios de cálculo de saldos, topes, anulación, concurrencia y **no-impacto en Tesorería** |
| **V. Convenciones Laravel + dominio en español** | ✅ | `aplicaciones_credito`, `AplicacionCredito`, snake_case, morphs estándar, FormRequests |

**Fidelidad estructural a Contagram (regla de oro de CLAUDE.md)**: esta feature **diverge
deliberadamente** de Contagram. Se relevó Contagram real (`docs/informe_contagram_notas_credito_mayores/`)
y no ofrece esta funcionalidad; el dueño del negocio dio vía libre explícita el 21/08/2026. La regla
exige documentar la divergencia, no prohibirla — queda asentada en spec, plan y `docs/`.

**Veredicto**: pasa. Sin violaciones que justificar; una acción obligatoria (actualizar docs) antes
de tasks.

## Project Structure

### Documentation (this feature)

```text
specs/072-saldo-favor-credito-nc/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── aplicaciones-credito-api.md
├── checklists/
│   └── requirements.md
└── tasks.md              # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Models/
│   ├── AplicacionCredito.php            # NUEVO
│   ├── Venta.php                        # aCobrar() + creditoRecibido/Cedido/Disponible
│   ├── Compra.php                       # aPagar() idem
│   └── NotaCreditoDebito.php            # relación aplicaciones() + guard de borrado
├── Services/Ingresos/
│   └── CreditoCliente.php               # NUEVO — cálculo y aplicación (transacción + lock)
├── Http/
│   ├── Controllers/
│   │   ├── AplicacionCreditoController.php   # NUEVO — store/destroy/disponible
│   │   ├── VentaController.php               # sqlACobrar() y kpis() con los 2 términos nuevos
│   │   ├── CompraController.php              # idem
│   │   ├── ClienteController.php             # opciones() devuelve saldo
│   │   └── ProveedorController.php           # idem
│   └── Requests/
│       └── StoreAplicacionCreditoRequest.php # NUEVO
├── Services/Tesoreria/
│   └── CuentaCorriente.php              # SQL de saldo con los 2 términos nuevos
└── Services/Informes/
    ├── VentasInformeQuery.php           # idem
    └── ComprasInformeQuery.php          # idem

database/migrations/
└── 2026_08_XX_create_aplicaciones_credito_table.php   # NUEVO

resources/
├── js/
│   ├── ventas.js                        # medio "Saldo a favor" en el modal de cobranza
│   └── compras.js                       # idem en el modal de pago
└── views/
    ├── ventas/detalle.blade.php         # línea de crédito aplicado
    └── compras/detalle.blade.php        # idem

tests/Feature/Creditos/                  # NUEVO — suite de la feature
```

**Estructura elegida**: monolito Laravel existente. No se crea módulo ni paquete aparte: la feature
vive en Ingresos/Egresos como el resto del circuito de cobranzas.

## Riesgo principal y cómo se mitiga

**El riesgo es descuadrar lo que ya cuadra.** Tres barreras:

1. **Separación física**: la aplicación de crédito no pasa por `Cobranzas::registrarCobro()` ni por
   `Tesoreria::registrarMovimiento()`. Son caminos de código distintos; no hay forma de que una
   aplicación cree un movimiento de tesorería porque nunca llama al servicio que los crea.
2. **Test de invariante**: un test que mide los siete totales de Tesorería antes y después de aplicar
   crédito y falla ante cualquier diferencia (mismo método con el que se validó el fix anterior de
   estado de cobro).
3. **Fórmula única**: los dos términos nuevos se agregan en todos los lugares que replican el cálculo
   en SQL, enumerados en `data-model.md`. La tarea de tasks debe tratarlos como un bloque atómico —
   dejar uno afuera produce exactamente el tipo de divergencia que ya se encontró entre
   `estadoCobro()` y su filtro.

## Complexity Tracking

Sin violaciones de la constitución que justificar.

Una complejidad aceptada conscientemente: la relación **polimórfica de dos extremos**
(origen y destino) es menos obvia que dos tablas separadas para Ventas y Compras. Se acepta porque
Ventas y Compras comparten semántica exacta y ya comparten `notas_credito_debito`; duplicar la tabla
duplicaría también la lógica de validación, de cálculo y de tests, que es donde está el riesgo real.
