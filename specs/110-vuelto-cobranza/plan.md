# Implementation Plan — Vuelto en la cobranza de una Venta (spec 110)

**Branch**: `110-vuelto-cobranza` | **Fecha**: 2026-09-24 | **Spec**: [spec.md](spec.md)

## Summary

Permitir registrar, dentro de la cobranza de una Venta, el vuelto entregado al cliente en el acto —
el caso relevado es el pago en dólares, donde el importe recibido supera el saldo. El sistema
registra **dos movimientos de tesorería reales** (ingreso por lo recibido, egreso por el vuelto) e
imputa a la venta únicamente el **neto**, eliminando los tres pasos sucios actuales: Gasto falso,
Nota de Débito de cuadre y total de venta inflado.

## Technical Context

**Lenguaje/versión**: PHP 8.2, Laravel 12
**Storage**: MySQL (producción y local XAMPP); la suite corre en SQLite
**Frontend**: Blade + NexaDash (Bootstrap 5), jQuery, Select2, DataTables, Vite
**Testing**: PHPUnit (Feature tests) + validación manual en navegador contra MySQL
**Alcance**: aditivo sobre el módulo de Ingresos (Cobranzas de Venta) y Tesorería
**Performance**: sin requisitos particulares — el caso es de baja frecuencia (operaciones puntuales
en dólares)

## Constitution Check

| Principio | Estado | Justificación |
|---|---|---|
| **I. Docs como fuente de verdad** | ⚠️ Acción pendiente | La spec introduce entidades y reglas nuevas (tipo `vuelto`, columnas en `cobros` y `configuracion_ventas`). **Obligatorio** actualizar `docs/documentacion_principal_crm.md` y `docs/modelo_datos.md` **antes de `/speckit-tasks`**. |
| **II. Spec-driven** | ✅ | Feature de negocio con spec completa; no es un cambio trivial exento. |
| **III. Corrección fiscal (ARCA)** | ✅ **Refuerza** | La spec **elimina** el uso de Notas de Débito como parche de cuadre. Hoy esas ND son documentos comerciales que declaran una operación inexistente y, si se enviaran a ARCA, serían una declaración falsa al fisco. La feature ataca directamente ese riesgo. El vuelto **no** genera ningún comprobante fiscal. |
| **IV. Testing donde hay dinero** | ✅ | Toca saldos de tesorería y de cuenta corriente: el testing es obligatorio. Cubiertos: cálculo del neto, atomicidad de los dos movimientos, editar/anular sin saldo fantasma, y las validaciones FR-006/007/011. |
| **V. Laravel + dominio en español** | ✅ | `vuelto`, `cuenta_vuelto_id` en snake_case; FormRequests para validar; el Service existente `Cobranzas` como único punto de integración con Tesorería; migraciones versionadas. Sin `empresa_id` (single-tenant). |

**Gate**: PASA, con la acción obligatoria del principio I pendiente antes de `tasks`.

## Decisiones de diseño (detalle en [research.md](research.md))

1. **Tipo `vuelto` propio en el ENUM** de `movimientos_tesoreria.tipo` — garantiza por construcción
   que no se cuele en el informe de Gastos.
2. **Signo negativo**, como `pago` y `gasto`. Verificado contra datos: los 3.342 pagos y 9.442
   gastos son 100% negativos.
3. **Dos columnas en `cobros`** (`vuelto`, `cuenta_vuelto_id`), nullable — los 25.253 cobros
   existentes no cambian.
4. **Los dos movimientos se distinguen por `tipo`** y comparten el vínculo polimórfico. **Exige
   filtrar la relación `morphOne` existente** o anular un cobro deja saldo fantasma.
5. **La fórmula de saldo NO se toca.** `cobros.monto` guarda el neto, así que `aCobrar()`,
   `estadoCobro()` y las **5 réplicas SQL** siguen correctas sin modificarse.
6. **La validación `lte` se mantiene**, medida contra el neto. Esta spec **no habilita sobrepagos**.
7. **Default configurable** en `configuracion_ventas`, editable en el modal sin persistir.

## Riesgo principal

**`Cobro::movimientoTesoreria()` es un `morphOne` sin filtrar.** Con dos movimientos apuntando al
mismo cobro devuelve **uno cualquiera de los dos, de forma no determinística**. Si devuelve el de
vuelto, `anularCobro()` borra el vuelto y **deja el ingreso vivo en la cuenta**.

Es exactamente el modo de falla que el docblock de `anularCobro()` ya documenta como incidente
previo (25.259 cobros importados sin vínculo dejaban el ingreso vivo al anular).

**Barrera**: filtrar `movimientoTesoreria()` por `tipo='cobro'` y agregar `movimientoVuelto()`
filtrada por `tipo='vuelto'` (ver [data-model.md](data-model.md) §4). El test de anulación del
quickstart §3.7 es la verificación de que la barrera funciona.

## Riesgo secundario: el tipo nuevo queda invisible en los informes

**Precedente real documentado** (`docs/modelo_datos.md`, sección del enum `ingreso`): cuando se
agregó el valor `ingreso`, el informe de flujo de caja seguía sumando `tipo IN ('cobro')` y dejó
**$34.570.442,27 invisibles** en la sección "Cobros". El enum aceptaba el valor, nada fallaba, y el
dato simplemente no aparecía.

Agregar `vuelto` al enum **no alcanza**: hay que revisar todo lo que filtra por `tipo` con una lista
fija. Relevado como mínimo:

- `Tesoreria::flujo()` (pestaña Movimientos) y `SeccionesMovimientos`
- `CuentaTesoreriaController::LABELS` (etiqueta del ledger) y el filtro de tipo de operación
- Los exports del ledger de cuenta
- `AuditoriaController::LABELS_OPERACION`

Una consulta que no conozca `vuelto` no rompe: **oculta plata**. Es el modo de falla más difícil de
detectar de esta spec.

## Riesgo terciario: SQLite no valida ENUMs

La suite **no puede detectar** un error en la migración del ENUM. La validación contra MySQL local
es obligatoria ([quickstart.md](quickstart.md) Paso 2). Es el mismo patrón del incidente de
`ONLY_FULL_GROUP_BY` ya documentado en el proyecto: suite verde ≠ funciona en producción.

## Archivos afectados (estimación)

**Migraciones (3, todas aditivas)**
- `cobros`: + `vuelto`, `cuenta_vuelto_id`
- `movimientos_tesoreria`: ENUM `tipo` + `'vuelto'`
- `configuracion_ventas`: + `cuenta_vuelto_id`

**Modelos**
- `Cobro`: `$fillable`, **filtrar `movimientoTesoreria()`**, agregar `movimientoVuelto()`,
  accessor `recibido()`
- `ConfiguracionVentas`: `$fillable` + relación `cuentaVuelto()`

**Services**
- `Ingresos\Cobranzas`: `registrarCobro()`, `actualizarCobro()`, `anularCobro()` — los tres dentro
  de la transacción existente

**Requests**
- `StoreCobroRequest`, `UpdateCobroRequest`: campos nuevos + FR-006/007/011

**Controllers**
- `VentaController`: `cobranzaStore`, `cobranzaUpdate`, `cobranzaDestroy`, **`reciboCobranza`**
  (FR-016)
- `CuentaTesoreriaController`: etiqueta `'vuelto' => 'Vuelto'` en `LABELS` y en el filtro de tipo
- `ConfiguracionController` (Ventas): el campo nuevo

**Vistas / JS**
- Modal de cobranza en `ventas/detalle.blade.php` + `resources/js/ventas.js`
- `recibos/pdf.blade.php`: recibido / vuelto / neto
- Configuración → Ventas: select de cuenta de vuelto

**Tests**
- Feature tests nuevos de la spec + revisar los 3 asserts de 422 en `ActualizarCobroTest`
  (líneas 167, 184, 218) que fijan el tope actual

## Fuera de alcance

Saldo a favor por cobro excedente, pagos a proveedores, cuentas en moneda extranjera con
cotización, y migración de las 57 ventas sobrepagadas históricas. Ver [spec.md](spec.md).

## Artefactos generados

- [research.md](research.md) — 7 decisiones con alternativas y datos medidos
- [data-model.md](data-model.md) — esquema, semántica de `cobros.monto`, el punto crítico del morph
- [contracts/cobranzas-api.md](contracts/cobranzas-api.md) — request/response/errores de los 4
  endpoints + contrato de UI
- [quickstart.md](quickstart.md) — validación paso a paso, con el chequeo obligatorio en MySQL
