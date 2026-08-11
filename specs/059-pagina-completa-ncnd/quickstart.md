# Quickstart: validar la página completa de NC/ND

## Escenario 1 — Crear NC/ND (Venta) vía la página completa

1. Detalle de una Venta → "Agregar" en NC/ND → completar modal de paso 1 (Tipo, Stock=No, Mes) →
   "Siguiente".
2. **Esperado**: NO aparece un 2do paso dentro del modal — el navegador va a una página completa
   nueva con header/tabla/nota interna/descuento general/total, igual estructura que "Nueva Compra".
3. Completar Descripción/Cant./Precio/IVA de la única fila (Stock=No) y "Guardar".
4. **Esperado**: vuelve al detalle de la Venta, con la nota nueva en la tabla.

## Escenario 2 — Crear NC/ND que afecta stock

1. Repetir el paso 1 con Stock=Sí.
2. **Esperado**: la página completa muestra selector de Producto/Servicio (no un textarea de
   descripción), con las mismas columnas.

## Escenario 3 — Editar con Tipo y Stock bloqueados

1. Sobre una nota existente sin CAE, "Editar" desde el menú de fila.
2. **Esperado**: el modal de paso 1 muestra Tipo Y "¿Afecta Stock?" deshabilitados (grises),
   precargados con los valores actuales.
3. "Siguiente".
4. **Esperado**: navega a la misma página completa, precargada, con el botón "Eliminar" visible
   (a la izquierda de Cancelar/Guardar) — que no aparece en el flujo de Crear.

## Escenario 4 — Eliminar desde la página completa

1. Sobre la página de edición del Escenario 3, click "Eliminar".
2. **Esperado**: mismo comportamiento ya validado en spec 057 (confirmación, soft delete, reversión
   de stock si corresponde) y vuelve al detalle de origen.

## Escenario 5 — Acceso directo por URL

1. Copiar la URL de la página completa de creación (`.../notas/nueva`) y abrirla en una pestaña nueva
   sin pasar por el modal.
2. **Esperado**: el formulario funciona igual, con Tipo/Documento que Ajusta/Stock/Mes vacíos y
   editables ahí mismo (FR-010).

## Escenario 6 — Compras (simetría)

1. Repetir Escenarios 1-4 sobre una Compra.
2. **Esperado**: mismo comportamiento, con Proveedor en vez de Cliente.

## Validación automatizada

```bash
php artisan test --filter=NotaCreditoDebitoPagina
```

Debe cubrir, como mínimo, que las rutas nuevas responden 200, que el formulario crea/edita/elimina
con los mismos efectos que los tests de spec 057 (que siguen vigentes, ya que el backend no cambió).
