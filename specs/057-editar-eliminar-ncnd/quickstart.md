# Quickstart: validar Edición/Eliminación/PDF de NC/ND

## Prerrequisitos

- Servidor local levantado (`php artisan serve`), DB `contagram` con al menos una Venta y una
  Compra con ítems.
- Migración de esta feature aplicada: `php artisan migrate`.
- Usuario con permiso `ventas.ver`/`compras.ver` logueado.

## Escenario 1 — Editar una NC/ND sin CAE y sin stock

1. Crear una NC/ND (tipo Crédito, sin afectar stock, monto $1000, descripción "prueba") sobre una
   Venta cualquiera.
2. Desde el menú de fila de la nota (columna Estado), elegir **Editar**.
3. Confirmar que el wizard precarga los valores actuales.
4. Cambiar el monto a $1500, guardar.
5. **Esperado**: la tabla se actualiza sin recargar, el monto nuevo aparece, la barra de ecuación
   (A Cobrar) de la Venta refleja el cambio.

## Escenario 2 — Editar una NC/ND que afecta stock (reversión)

1. Anotar el stock actual de un producto en un depósito (`stocks` o pantalla de Stock).
2. Crear una ND que afecta stock, cantidad 2, sobre ese producto/depósito.
3. Confirmar que el stock subió en 2 (ND en Compra suma; en Venta resta — según el módulo).
4. Editar la nota, cambiar la cantidad a 5.
5. **Esperado**: el stock refleja exactamente +5 (no +7) respecto al valor original — confirma que
   se revirtió el ajuste anterior antes de aplicar el nuevo (research.md §1).

## Escenario 3 — Eliminar una NC/ND (reversión completa)

1. Repetir el paso 1-3 del Escenario 2.
2. Eliminar la nota desde el menú de fila.
3. **Esperado**: el stock vuelve exactamente al valor anotado en el paso 1; la nota desaparece de
   la tabla (soft delete — sigue en la DB con `deleted_at` seteado); confirma también que se puede
   eliminar desde dentro del formulario de edición con el mismo resultado.

## Escenario 4 — Bloqueo por CAE aprobado

1. Ubicar (o generar, si el entorno lo permite) una NC/ND con `comprobanteFiscal.aprobado() === true`.
2. Intentar editarla y eliminarla.
3. **Esperado**: ambas acciones responden 409 con el mensaje de bloqueo por CAE; ningún dato
   cambia.

## Escenario 5 — Encadenamiento a 1 nivel

1. Crear NC/ND #A sobre una Compra (ajusta al comprobante original).
2. Crear NC/ND #B sobre la misma Compra, eligiendo #A como "Documento que Ajusta".
3. Intentar crear/editar una tercera nota #C y elegir #B como "Documento que Ajusta".
4. **Esperado**: el selector "Documento que Ajusta" NO ofrece a #B como opción (sólo el
   comprobante original y #A) — confirma el límite de 1 nivel (FR-013).
5. Intentar eliminar #A mientras #B existe.
6. **Esperado**: 409, mensaje indicando que #B la ajusta y hay que eliminarla primero.

## Escenario 6 — PDF de NC/ND en Compras (nuevo)

1. Sobre cualquier NC/ND cargada en una Compra, elegir **Ver Detalle**.
2. **Esperado**: se abre el modal de PDF compartido (`window.AppPdf.abrir`) con los datos del
   proveedor, el comprobante que ajusta, y la tabla de conceptos con IVA — sin error ni datos
   vacíos.
3. Repetir sobre una NC/ND de Ventas — **Esperado**: sigue funcionando igual que antes (no
   regresión).

## Validación automatizada

Correr la suite de tests de esta feature:

```bash
php artisan test --filter=NotaCreditoDebitoEdicion
```

Debe cubrir, como mínimo, los 6 escenarios de arriba (ver Principio IV de la constitución:
testing obligatorio donde hay dinero/stock/impacto fiscal).
