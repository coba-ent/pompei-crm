# Quickstart: validar fidelidad de la tabla NC/ND

## Prerrequisitos

- Migración `add_nota_interna_to_notas_credito_debito_table` corrida (`php artisan migrate`).
- Al menos:
  - Una Venta o Compra con un comprobante fiscal **aprobado** (CAE real) y una NC/ND creada sobre
    ella, también con comprobante fiscal aprobado.
  - Una NC/ND sobre una Venta/Compra **sin** comprobante fiscal emitido (para validar el caso "-").
  - Una NC/ND que ajusta a **otra** NC/ND (`nota_ajustada_id` seteado) para validar el
    encadenamiento de "Documento que Ajusta".

## Pasos

1. Abrir el detalle de la Venta/Compra con NC/ND aprobada.
   - **Esperado**: la fila muestra Estado = estado real del comprobante fiscal de la nota (no un
     menú), Comprobante y N° Comprobante con el tipo/número real, Documento que Ajusta con el
     comprobante original, Total y (si se cargó) Nota Interna.
2. Abrir el detalle de la Venta/Compra con NC/ND sin comprobante fiscal.
   - **Esperado**: Estado = "Sin emitir" (o equivalente), Comprobante/N° Comprobante/Documento que
     Ajusta en "-", sin error de render.
3. Abrir el detalle donde hay una NC/ND que ajusta a otra nota.
   - **Esperado**: Documento que Ajusta muestra el comprobante de la nota ajustada, no el de la
     Venta/Compra original.
4. Abrir el modal de alta de NC/ND (Venta y Compra), completar Nota Interna, guardar.
   - **Esperado**: al recargar el detalle, la columna Nota Interna muestra el texto cargado.
5. Editar esa misma nota y modificar Nota Interna.
   - **Esperado**: el cambio se refleja en la tabla tras guardar.
6. Desde la fila, usar el control de acciones (ahora separado del Estado) para Ver Detalle / Editar /
   Eliminar.
   - **Esperado**: mismo comportamiento que antes del cambio, sin regresiones.

## Comandos útiles

```bash
php artisan migrate
php artisan test --filter=NotaCreditoDebitoTablaDetalleTest
```
