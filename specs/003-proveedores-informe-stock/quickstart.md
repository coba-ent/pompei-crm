# Quickstart — Validación de Proveedores + Informe de Stock

Guía para validar la feature de punta a punta. No incluye código de implementación (eso va en
`tasks.md`/implementación); son los pasos y resultados esperados que prueban que el módulo funciona.

## Prerrequisitos

- Módulos Clientes (`001-clientes`) y Productos (`002-productos`) ya implementados.
- XAMPP con MySQL corriendo, DB `contagram`. `.env` apuntando a esa base.
- Dependencias PHP/JS instaladas (`composer install`, `npm install`).
- Al menos un producto activo con algún movimiento de stock (ajuste manual) ya cargado, para probar
  el Informe de Stock sin depender de datos nuevos.

## Setup

```bash
php artisan migrate             # crea proveedores, proveedor_contactos, agrega FK a productos.proveedor_id
npm run build                   # (o npm run dev) compila proveedores.js e informe-stock.js vía Vite
php artisan serve               # levanta la app en http://127.0.0.1:8000
```

Opcional, para probar el filtro Proveedor del informe con datos de ejemplo:

```bash
php artisan db:seed --class=ProveedoresDemoSeeder
```

## Escenarios de validación

### US1 — Alta y gestión de Proveedores

1. Navegar a `/proveedores`. Verificar que carga con la DataTable (server-side) y el botón "Nuevo
   Proveedor", sin errores en consola.
2. "Nuevo Proveedor" → completar sólo **Proveedor** (nombre) → Guardar. → Toast de éxito, modal se
   cierra, el proveedor aparece resaltado en la tabla sin recargar la página.
3. Editar ese proveedor → comparar el formulario contra el de Cliente: mismos bloques salvo sin
   "Apodo ML", bloque "Compras" (no "Ventas") con "Categoría Compras", y "Nota Interna" (no "Nota
   para el Cliente").
4. Cargar un CUIT matemáticamente inválido y guardar → rechazado con mensaje de error. Dejar el CUIT
   vacío y guardar → permitido (mismo comportamiento que Cliente).
5. Inactivar el proveedor → sale del selector de "Elija Proveedor" en Productos para altas nuevas,
   pero sigue visible en el listado con el filtro "inactivos".
6. Intentar eliminar físicamente un proveedor que **sí** tiene un producto asociado (ver US2) →
   rechazado (HTTP 409 / toast), sólo permite inactivar.

### US2 — Asociar un Proveedor a un Producto

7. Con al menos un proveedor activo cargado, abrir "Nuevo Producto" en `/productos` → verificar que
   aparece el selector "Proveedor" (Select2 con buscador), opcional.
8. Crear o editar un producto asignándole ese proveedor → Guardar → reabrir el producto → el
   proveedor viene precargado.
9. En el listado de Productos, aplicar el filtro "Proveedor" → sólo aparecen los productos de ese
   proveedor; la columna "Proveedor" del listado muestra su nombre.

### US3 — Informe de Stock

10. Desde el menú de fila de un producto con movimientos de stock, elegir "Movimientos" → verificar
    que navega a `/informes/stock?producto_id={id}` (no abre un modal) y que el filtro "Productos"
    viene pre-cargado con ese producto.
11. Verificar que la tabla muestra, por cada movimiento: Fecha, Operación, Detalle, Producto,
    Cantidad y **Stock Saldo** — y que el "Stock Saldo" de la última fila (más reciente) coincide con
    el stock actual de ese producto/depósito (visible en el listado de Productos).
12. Quitar el filtro "Productos" (ver todos) y aplicar sólo un rango de fechas que excluya el
    "Registro inicial" del producto de la prueba → verificar que el "Stock Saldo" de las filas
    visibles **no cambia** respecto del paso 11 (el saldo se calcula sobre el histórico completo, no
    sobre las filas filtradas — research.md §2).
13. Aplicar el filtro "Proveedor" (con el producto de US2 asociado a un proveedor) → la tabla se
    acota a movimientos de productos de ese proveedor, y los 3 KPIs (Unidades en Stock, Costo Total,
    Valor Venta Total) se recalculan sólo sobre esos productos.
14. Abrir el filtro "Operación" → verificar que sólo lista los tipos que el sistema genera hoy
    (Ajuste, Transferencia) — no debe aparecer "Compra" ni "Venta" (FR-013, esos módulos no existen
    todavía).
15. Verificar que el ajuste manual de stock (Aumentar/Disminuir desde el menú de fila de Productos)
    sigue funcionando exactamente igual que antes de esta feature (FR-014 — no se reemplaza el flujo
    de carga, sólo la vista de histórico).

## Tests automatizados

```bash
php artisan test --filter=Proveedor       # ProveedorAlta (incluye CUIT), ProveedorBaja, ProveedorListado
php artisan test --filter=ProductoProveedor  # asociación producto-proveedor
php artisan test --filter=InformeStock     # filtros (incluye límite de "Operación"), KPIs, cálculo de Stock Saldo con/sin filtros
php artisan test --filter=StockAjuste      # regresión: el ajuste manual (002-productos) sigue funcionando tras quitar la tabla de histórico del modal
```

Todos deben quedar en verde antes de dar la feature por terminada (Principio IV de la constitución:
lógica con impacto en valorización de stock lleva test).

## Consistencia de documentación (antes de cerrar la feature)

- Mover `proveedores`/`proveedor_contactos` de "Tablas descartadas" a la sección activa de
  `docs/modelo_datos.md`, y documentar la columna `stock_saldo` calculada del Informe de Stock.
- Mover "Base de Datos → Proveedores" y el Informe de Stock de la lista de "Módulos pendientes de
  re-relevamiento" (`docs/documentacion_principal_crm.md` §5) a sus propias secciones activas,
  siguiendo el mismo formato usado para Clientes/Productos.
- Actualizar el sidebar (`resources/views/elements/sidebar.blade.php`) agregando "Proveedores" de
  vuelta bajo "Base de Datos", y agregar un ítem "Informes → Stock" (o similar) si corresponde según
  cómo quede planteada la navegación en `tasks.md`.

## Criterios de aceptación cubiertos

SC-001 (alta de proveedor <15 s), SC-002 (asociación producto-proveedor sin refresh), SC-003 (saldo
corrido correcto), SC-004 (KPIs consistentes con Productos), SC-005 (estructura de pantalla fiel al
informe con capturas).
