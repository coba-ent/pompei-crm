# Quickstart — spec 064: cómo validar que funciona

Guía de verificación manual, ordenada por user story. Cada bloque se puede probar por separado.

## Antes de empezar

```bash
php artisan migrate
npm run build
```

Verificar que la corrección de nulabilidad se aplicó (sin esto, US4 no funciona):

```sql
DESCRIBE remitos;
-- venta_id debe figurar como NULL = YES
```

## US1 — Emitir el remito (MVP)

1. Abrir una Venta **con productos** → botón **"Crear Remito"**.
2. Verificar que el formulario abre en **página completa** (no modal) y viene precargado con:
   - cliente (no editable)
   - domicilio de entrega del cliente (editable)
   - fecha de hoy
   - **todas las líneas de producto de la Venta con sus cantidades originales**
3. Abrir el selector de **Transportista** → **"Crear Transportista"** → escribir un nombre → Crear.
   Debe quedar seleccionado sin recargar la página.
4. Escribir una observación en una línea y una nota para el cliente.
5. Cambiar una cantidad → **verificar que Total Bultos se recalcula solo**.
6. Tildar **Monto Asegurado** → el importe se habilita, precargado con el total de la Venta.
7. **Guardar** → toast de éxito y vuelta al detalle de la Venta.
8. En el detalle, verificar la sección **Remitos** con columnas: Id, Fecha, Transportista, Nota,
   Total Bultos, Comprobante.
9. Click en **"Ver Remito"** → se abre el **modal PDF compartido** (no una pestaña nueva) con:
   - encabezado REMITO con la letra en recuadro
   - Nro. Remito y Fecha de Emisión
   - Transportista
   - datos del cliente y Domicilio de Entrega
   - tabla Código / Productos / Observaciones / Cantidad
   - **sin precios, sin IVA, sin totales de dinero, y sin el Monto Asegurado**

**Verificación crítica (FR-010)** — antes y después de todo lo anterior:

```sql
SELECT cantidad FROM stocks WHERE producto_id = <id del producto remitido>;
SELECT COUNT(*) FROM movimientos_stock WHERE producto_id = <id>;
SELECT total FROM ventas WHERE id = <venta>;
```

Los tres valores deben ser **idénticos** antes y después. El remito no mueve nada.

## US2 — Editar y eliminar

1. En la sección Remitos, click en el **ícono de lápiz**.
2. Verificar que **ningún campo está bloqueado** (a diferencia de NC/ND): transportista, domicilio,
   fecha, nota, observaciones, cantidades y monto asegurado son todos editables.
3. Cambiar el transportista y una cantidad → Guardar → verificar que el PDF refleja los cambios.
4. Volver a editar → **Eliminar** → confirmar → el remito desaparece de la sección.
5. Repetir la verificación de stock del bloque anterior: **nada cambió**.

## US3 — Envíos parciales

1. Sobre una Venta que **ya tiene** un remito, tocar **"Crear Remito"** otra vez (el botón sigue
   disponible).
2. Verificar que las cantidades vienen con **los totales originales de la Venta**, sin descontar lo ya
   remitido — es el comportamiento de Contagram, no un bug.
3. Ajustar cantidades a mano y guardar.
4. Verificar que **ambos remitos** conviven en la sección, cada uno con su número, fecha y bultos.

## US4 — Compras

1. Abrir una Compra con productos → **"Crear Remito"**.
2. Verificar que **no falla** (antes de esta spec fallaba: `venta_id` NOT NULL, ver research §R2).
3. Verificar que el domicilio de entrega precarga el **depósito que recibe**, no el del proveedor.
4. Guardar, ver la sección Remitos en el detalle de la Compra, abrir el PDF con los datos del
   proveedor.
5. Verificar stock sin cambios.

## Regresiones a vigilar

- **Eliminar una Venta con remitos** → los remitos se eliminan con ella (FR-018), y la reversión de
  cobros y stock que ya existía **sigue funcionando igual** (no se tocó esa lógica).
- **Los 2 remitos históricos** (N° 1 y N° 2, sin ítems ni transportista) siguen apareciendo en la
  sección y su PDF abre sin error, con la tabla vacía (FR-026).
- **Filtros del listado de Ventas** "Con Remito" / "Sin Remito" / "Tipo y N° de Remito" siguen
  funcionando.
- **El botón "Crear Remito" muestra su ícono** de camión (FR-024) y el acceso desde el menú de fila
  lleva al formulario, sin URLs con `#` (FR-025).

## Limpieza de datos previa (una sola vez, en producción)

Decisión del usuario: eliminar el remito N° 3 (creado por accidente el 12/08/2026 sobre la Venta
24038) y conservar el N° 1 y el N° 2.

```sql
DELETE FROM remitos WHERE id = 3;
```
