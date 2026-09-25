# Implementation Plan — Caja editable + modales de medio de pago (spec 111)

**Branch**: `111-tesoreria-caja-editable` | **Fecha**: 2026-09-25 | **Spec**: [spec.md](spec.md)

## Summary

Tres cambios de Tesorería que se prueban en las mismas pantallas y se deployan juntos:

1. **Caja editable** en Editar Movimiento, con un selector para los movimientos sueltos y dos
   (origen/destino) para las transferencias.
2. **Orden alfabético** de las cajas en los modales de medio de pago de Venta y Compra.
3. **Botones rellenos** en esos modales, con el elegido más oscuro y con tilde.

## Technical Context

**Stack**: PHP 8.2 / Laravel 12, MySQL (prod y local), suite en SQLite
**Frontend**: Blade + NexaDash, jQuery, DataTables, Select2
**Alcance**: aditivo sobre Tesorería (Ingresos/Egresos sólo en el render de los botones)
**Migraciones**: **ninguna** — no hay columnas nuevas
**Performance**: sin requisitos particulares; son ediciones esporádicas

## Constitution Check

| Principio | Estado | Justificación |
|---|---|---|
| **I. Docs fuente de verdad** | ⚠️ Acción | No hay entidades ni campos nuevos, pero sí una **regla de negocio nueva** (la caja de un movimiento nativo es editable, y en transferencias se editan las dos patas). Actualizar `docs/documentacion_principal_crm.md` antes de `tasks`. `modelo_datos.md` no cambia: no hay columnas nuevas. |
| **II. Spec-driven** | ✅ | Feature de negocio con spec previa. |
| **III. Corrección fiscal** | ✅ N/A | No toca comprobantes, CAE ni documentos fiscales. Los movimientos nativos no tienen origen documental. |
| **IV. Testing donde hay dinero** | ✅ | Mueve saldos entre cajas: testing obligatorio. Cubierto: reimputación de un movimiento suelto, de las dos patas de una transferencia, rechazo de origen=destino, atomicidad, y que la suma total de tesorería no cambie. |
| **V. Laravel + español** | ✅ | `cuenta_tesoreria_id` ya existe; validación por FormRequest/`validate()`; sin tablas nuevas. |

**Gate**: PASA, con la actualización de docs pendiente antes de `tasks`.

## Decisiones de diseño

### D1 — El endpoint recibe las cajas por separado, no un array

`updateMovimiento()` hoy valida `fecha`, `monto`, `observacion`. Se suman:

- `cuenta_tesoreria_id` — la caja del movimiento que se está editando
- `cuenta_contraparte_id` — sólo cuando el movimiento tiene `transferencia_id`

**Por qué no un array de patas**: el modal siempre se abre sobre **un** movimiento concreto, y la
contraparte se resuelve por `transferencia_id` en el backend, como ya lo hacen `updateMovimiento()`
(para monto y fecha) y `destroyMovimiento()`. Mantener esa forma evita introducir un contrato nuevo
para el mismo caso.

### D2 — "Sale de" / "Entra a" se derivan del signo, no de un campo

El modelo no marca cuál pata es origen y cuál destino: se deduce del **signo del monto** (negativo
= sale, positivo = entra), que es como ya se calculan los accessors `ingreso`/`egreso`.

El modal rotula los selectores según el signo del movimiento abierto:

- Si el abierto es el **negativo** → "Sale de" es su caja, "Entra a" la de la contraparte
- Si es el **positivo** → al revés

**Riesgo**: si alguna transferencia tuviera las dos patas del mismo signo (dato corrupto), la
etiqueta sería confusa. Se verifica contra producción antes de implementar (tarea T002).

### D3 — El orden alfabético se resuelve en el origen de datos, no en el JS

Los modales de Venta y Compra usan `CuentaTesoreria::visibles()->paraCobrar()->ordenadas()`.
`ordenadas()` ordena por la columna `orden` y usa el nombre sólo como desempate — ese orden es el
de **las cards de Tesorería**, configurado a propósito por el cliente.

**Se reemplaza `ordenadas()` por `orderBy('nombre')` únicamente en las consultas que alimentan
esos modales**, dejando `ordenadas()` intacto para las cards (FR-011).

Puntos a tocar (relevados):
- `VentaController` líneas 68, 624, 731 (`paraCobrar()`)
- `CompraController` líneas 496, 523

**Ya están bien** y no se tocan: `GastoController:27` y `OtroIngresoController:29` usan
`orderBy('nombre')`; `CompraController:48` también.

**Alternativa descartada**: ordenar en el JS. Dejaría el criterio duplicado en dos lugares y el
orden dependería de que cada vista se acuerde de aplicarlo.

### D4 — Los botones rellenos se resuelven con las clases de Bootstrap del template

`ventas.js:1360` y `compras.js:1047` pintan `btn-outline-primary` y cambian a `btn-primary` al
seleccionar. Pasa a:

- **No seleccionado**: `btn-primary` (relleno)
- **Seleccionado**: `btn-primary active` + ícono de tilde

Se usa la clase `active` de Bootstrap, que ya oscurece el botón, en vez de inventar un color. El
tilde va como `<i class="fas fa-check">`, la librería de íconos que el template ya carga.

### D5 — La suma total de tesorería es la invariante de control

Cambiar la caja de un movimiento es una **reimputación**: lo que baja de una caja sube en otra. La
suma total no debe cambiar (FR-014, SC-003). Es la verificación que delata cualquier error de
implementación, y va como test y como paso de validación manual.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Editar una pata deja la transferencia descuadrada (una caja movida y la otra no) | Todo dentro de una `DB::transaction`, igual que el manejo actual de monto/fecha. Test de atomicidad. |
| Origen y destino terminan siendo la misma caja | Validación explícita en el backend (FR-004), no sólo en el JS. |
| El cambio de orden se filtra a las cards de Tesorería | Sólo se tocan las consultas de los modales; `ordenadas()` queda intacto. Test que fija el orden de las cards. |
| `saldo_inicial` ya desincronizado con la columna de la cuenta | **Fuera de alcance, documentado.** Esta feature no toca esa columna. Se verifica que tampoco la altere. |
| El `active` de Bootstrap no contrasta lo suficiente | Por eso además va el tilde (FR-013): la distinción no depende sólo del tono. |

## Archivos afectados (estimación)

**Backend**
- `CuentaTesoreriaController::updateMovimiento()` — validación + reimputación de una o dos patas
- `VentaController` (3 consultas) y `CompraController` (2) — `orderBy('nombre')` en los modales

**Frontend**
- `resources/views/tesoreria/_modal_movimiento_editar.blade.php` — uno o dos selectores
- `resources/js/tesoreria.js` — llenar los selectores, alternar suelto/transferencia, enviar
- `resources/js/ventas.js` y `resources/js/compras.js` — botones rellenos + tilde

**Tests**
- Feature tests nuevos de reimputación (suelto, transferencia, rechazos, atomicidad, invariante de
  suma total) y del orden de los modales vs. las cards

## Artefactos

- [spec.md](spec.md) — 14 FR, 3 historias, edge cases
- [tasks.md](tasks.md) — desglose accionable
