# Quickstart: Toggle %/monto fijo para el Descuento General

## Prerrequisitos

- Migraciones nuevas corridas: `php artisan migrate` (agrega columnas a `ventas`, `presupuestos`,
  `compras`, `notas_credito_debito`).
- `npm run build` (o `npm run dev`) para tomar los cambios de `resources/js/{ventas,presupuestos,
  compras,notas-credito-debito}.js`.
- Sesión iniciada con un usuario con permisos de alta/edición en los 4 módulos.

## Escenario 1 — Venta nueva con descuento general en monto fijo (US1)

1. Ir a Ventas → Nueva Venta.
2. Cargar al menos un ítem con precio unitario (ej. cantidad 1, precio $10.000, IVA 21%).
3. En "Descuento General", hacer clic en el botón inline (arranca en `%`) → pasa a `$`.
4. Cargar `500` como monto fijo.
5. **Verificar**: el total mostrado baja exactamente $500 respecto del que se vería sin descuento
   (con el prorrateo proporcional a IVA de spec 044 aplicado sobre ese monto).
6. Guardar.
7. **Verificar** (vía `php artisan tinker` o inspección de la respuesta JSON del `store`): la Venta
   creada tiene `descuento_general_tipo = 'monto'`, `descuento_general_monto = 500.00`,
   `descuento_general_pct = null`.

## Escenario 2 — Reabrir para editar conserva modo y valor (US2)

1. Abrir para editar la Venta creada en el Escenario 1.
2. **Verificar**: el botón muestra `$` (no `%`) y el campo muestra `500` (no un porcentaje
   recalculado).
3. Cambiar sólo otro campo (ej. la nota interna) y guardar.
4. **Verificar**: `descuento_general_tipo`/`descuento_general_monto` no cambiaron.

## Escenario 3 — Validación de monto mayor al subtotal (FR-007)

1. En el mismo formulario de alta, con un ítem de subtotal $10.000, cargar el descuento general en
   modo `$` con un valor de `15000`.
2. Intentar guardar.
3. **Verificar**: la request devuelve 422 con el mensaje de error en `descuento_general_monto`, y el
   formulario lo muestra (toast/mensaje inline, según el patrón ya usado por el módulo) sin guardar
   nada.

## Escenario 4 — Paridad entre los 4 módulos (US3)

Repetir el Escenario 1 en Presupuestos, Compras y en una Nota de Crédito (desde el detalle de una
Venta, "Agregar" NC/ND → página completa spec 059).

**Verificar en los 4**: mismo comportamiento visual del botón, mismo comportamiento de limpieza de
campo al alternar, mismo resultado de cálculo/persistencia.

Para Compras y Presupuestos, verificar además que convertir un Presupuesto con descuento en monto fijo
a Venta (botón "Convertir a Venta") traslada el mismo `descuento_general_tipo`/`descuento_general_monto`
sin reconvertir a porcentaje (Edge Case de spec.md).

## Escenario 5 — No-regresión en modo porcentaje (FR-008, SC-003)

1. Tomar una Venta ya existente en la base (cargada antes de este cambio, con
   `descuento_general_pct` seteado y `descuento_general_tipo` en su default `porcentaje` post-
   migración).
2. Abrir para editar sin tocar el descuento general, guardar.
3. **Verificar**: `total`/`subtotal_con_descuento` no cambiaron respecto a antes del deploy de esta
   feature — correr el test unitario existente `tests/Unit/TotalesVentaTest.php` (u equivalente) para
   confirmar 0 regresiones automatizado, además de la verificación manual.

## Comandos de referencia

```bash
php artisan migrate
npm run build
php artisan test --filter=CalculoComprobanteTest
php artisan test --filter=TotalesVentaTest
php artisan test --filter=TotalesPresupuestoTest
php artisan test --filter=CompraCalculoTest
```
