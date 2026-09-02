# Implementation Plan: Espejo del comprobante de origen al crear una NC/ND

**Branch**: `095-espejo-comprobante-ncnd` | **Date**: 2026-09-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/095-espejo-comprobante-ncnd/spec.md`

## Summary

Al crear una NC/ND, el formulario precarga hoy sólo los ítems: el resto de la cabecera nace vacío y
el total arranca en `$0,00`. La consecuencia medida es una nota por un importe equivocado cuando el
comprobante tenía descuento general — $11.497,80 de más en la venta 24740, con 9.203 ventas y 374
compras expuestas.

El trabajo consiste en **alimentar el formulario con la cabecera del comprobante de origen**. No hay
migración ni lógica de cálculo nueva: la tabla `notas_credito_debito` ya tiene los campos de
descuento general y tipo de comprobante, y el front ya sabe calcular totales con descuento de
cabecera (`recalcular()` en `notas-credito-debito.js`). Lo que falta es que esos datos **lleguen**.

Dos agregados de comportamiento salen de las clarificaciones: una advertencia cuando el tipo de
comprobante elegido difiere del origen (FR-004a), y un aviso cuando el descuento general en modo
monto supera el subtotal restante (FR-012).

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12

**Primary Dependencies**: Eloquent, Blade, jQuery + Select2 (template NexaDash), Vite

**Storage**: MySQL — **sin cambios de esquema**. `notas_credito_debito` ya tiene
`descuento_general_tipo`, `descuento_general_pct`, `descuento_general_monto` y `tipo_comprobante`.

**Testing**: PHPUnit (Feature + Unit). Principio IV: obligatorio por tocar cálculo de importes y
descuentos.

**Target Platform**: aplicación web, navegador de escritorio

**Project Type**: web (Laravel monolítico con Blade + JS por pantalla)

**Performance Goals**: la precarga no agrega llamadas nuevas al abrir el formulario; los datos de
cabecera viajan en el HTML que ya se renderiza.

**Constraints**:
- Precargar nunca debe impedir editar (FR-008).
- La edición de notas existentes no cambia (FR-011).
- No se toca el cálculo de cantidad pendiente de ajuste, ya validado (FR-009).

**Scale/Scope**: 2 flujos (Ventas y Compras), 1 vista compartida, 1 archivo JS, 1 servicio de
precarga. 860 notas existentes que no se modifican.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Cómo se cumple |
| --- | --- | --- |
| **I. Documentación de dominio como fuente de verdad** | ✅ Pasa | El criterio "espejo" y la decisión de no prorratear el descuento salen de un relevamiento directo de Contagram real (02/09/2026), no de criterio propio. Coincide con lo que `documentacion_principal_crm.md` §3.6 ya describe del paso 2. |
| **II. Desarrollo spec-driven** | ✅ Pasa | Spec 095 con 4 clarificaciones resueltas antes de planear. |
| **III. Corrección fiscal innegociable (ARCA)** | ✅ Pasa, y **mejora** | El principio exige que el tipo de comprobante se derive y no se elija a mano. Hoy nace vacío (hay 13 notas con el tipo cruzado); FR-004 lo deriva del comprobante y FR-004a advierte si se cambia. No se toca la emisión de CAE ni el soft delete. |
| **IV. Testing donde hay dinero o impacto fiscal** | ✅ Pasa | Toca descuentos y totales: tests obligatorios sobre la precarga de descuento (porcentaje y monto), tipo de comprobante, y que la edición no cambie. |
| **V. Convenciones Laravel + dominio en español** | ✅ Pasa | Se extiende un servicio existente y se mantiene la nomenclatura del dominio. |

**Resultado**: sin violaciones. No se requiere Complexity Tracking.

**Re-evaluación post-diseño (Phase 1)**: sin cambios. El diseño no introduce entidades, migraciones
ni endpoints nuevos; reutiliza el servicio de precarga y la vista existentes.

## Project Structure

### Documentation (this feature)

```text
specs/095-espejo-comprobante-ncnd/
├── plan.md              # Este archivo
├── spec.md              # Especificación con 4 clarificaciones
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/           # Phase 1
│   └── precarga-ncnd.md
├── checklists/
│   └── requirements.md
└── tasks.md             # Lo genera /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/
│   └── NotaCreditoDebitoController.php     # create() y createCompra(): pasan la cabecera a la vista
└── Services/
    └── AjustesPendientesNotaCreditoDebito.php  # suma la cabecera del comprobante a lo que ya expone

resources/
├── views/notas-credito-debito/
│   └── form.blade.php                      # amplía window.NotaFormData.comprobanteOrigen
└── js/
    └── notas-credito-debito.js             # aplica la cabecera precargada + los 2 avisos

tests/Feature/
└── NotaCreditoDebitoPrecargaTest.php       # precarga en Ventas y Compras, y no-regresión de edición
                                            # (los tests de NC/ND van sueltos en Feature/, sin subcarpeta)
```

**Structure Decision**: Laravel monolítico con Blade y un bundle JS por pantalla, como el resto del
proyecto. No se crean capas nuevas: el punto de extensión natural es el servicio que ya arma la
precarga de ítems, más los dos métodos `create` del controlador que ya reciben el comprobante.

## Phase 0: Research

Ver [research.md](./research.md). Las decisiones que podían bloquear el diseño ya estaban resueltas
antes de planear:

1. **Dónde vive el descuento general en la nota** — resuelto por observación de Contagram: en la
   cabecera, sin prorratear.
2. **Por dónde viajan los datos de cabecera** — decisión de diseño documentada en research.
3. **Cómo se comporta el tipo de comprobante** — resuelto en clarificación Q3.
4. **Qué se precarga sin ítems** — resuelto en clarificación Q4.

## Phase 1: Design & Contracts

- [data-model.md](./data-model.md): entidades involucradas y de dónde sale cada campo precargado.
  No hay cambios de esquema.
- [contracts/precarga-ncnd.md](./contracts/precarga-ncnd.md): forma de los datos de cabecera que la
  vista entrega al front, y reglas de precedencia.
- [quickstart.md](./quickstart.md): cómo verificar el resultado contra comprobantes reales de la base.

## Complexity Tracking

No aplica: el Constitution Check pasó sin violaciones.
