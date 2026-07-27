# Quickstart — Validación de Productos & Servicios

Guía para validar la feature de punta a punta. No incluye código de implementación (eso va en
`tasks.md` / implementación); son los pasos y resultados esperados que prueban que el módulo funciona.

## Prerrequisitos

- Módulo Clientes (`001-clientes`) ya implementado: aporta `listas_precio` (sembrado) y `yajra`,
  DataTables/Toastr en el front.
- XAMPP con MySQL corriendo, DB `contagram`. `.env` apuntando a esa base.
- Dependencias PHP/JS instaladas (`composer install`, `npm install`).

## Setup

```bash
php artisan migrate            # crea depositos, productos, producto_variantes, precios_producto, stocks, movimientos_stock
php artisan db:seed --class=DepositoSeeder   # depósito "Principal" por defecto
npm run dev                    # (o npm run build) compila productos.js vía Vite
php artisan serve              # levanta la app en http://127.0.0.1:8000
```

Para la prueba de performance (SC-005):

```bash
php artisan db:seed --class=ProductosDemoSeeder   # ~1.000 productos de demo
```

## Escenarios de validación

Navegar a `/productos`. Verificar que la página carga con la DataTable (server-side) y el botón
"Nuevo Producto", sin errores en consola.

### US1 — Alta de producto/servicio básico
1. "Nuevo Producto" → completar sólo **nombre** + **precio de venta** → Guardar.
   → Toast de éxito, modal se cierra, el producto aparece en la tabla (sin recargar la página).
2. Intentar guardar sin nombre → error de validación en el modal (no se guarda).
3. Crear un ítem con **tipo Servicio** → se guarda; en su ficha/stock no se ofrece control de stock.

### US2 — Precios y datos de compra/venta
4. Editar un producto: cargar IVA venta, costo, IVA compra; desmarcar "mostrar en ventas" → Guardar →
   reabrir → los valores persisten.
5. Intentar guardar un precio o IVA negativo → rechazado con mensaje.

### US3 — Código/SKU único
6. Crear producto con código `ABC-1` → OK. Crear otro con `ABC-1` → rechazado ("el código ya existe").
7. Crear varios productos **sin** código → permitido.

### US4 — Variantes con SKU propio
8. Editar un producto → agregar variantes "Talle S / REM-S" y "Talle M / REM-M" → Guardar → reabrir →
   ambas persisten.
9. Intentar asignar `REM-S` a otra variante o producto → rechazado (unicidad global de SKU).
10. Quitar una variante (sin stock) y guardar → deja de estar asociada.

### US5 — Precios por lista
11. En un producto, cargar precio para "Mayorista" y otro para "Minorista" → Guardar → reabrir →
    ambos precios persisten asociados a su lista. Editar uno → se actualiza sin duplicar.

### US6 — Depósitos, stock y ajustes manuales
12. Abrir el modal de **stock** de un producto → ajuste **aumento** de 10 en depósito "Principal" con
    descripción → stock actual = 10, aparece el movimiento en el histórico.
13. Ajuste **disminución** de 3 → stock actual = 7, nuevo movimiento registrado.
14. Verificar coherencia: el stock mostrado (foto) coincide con la suma de los movimientos (SC-003).
15. Sobre un ítem tipo **servicio**: el ajuste de stock no está disponible / es rechazado (SC-007).

### US7 — Listar, buscar y filtrar
16. Buscar por parte del nombre → filtra. Buscar por SKU → muestra el ítem. Filtrar por "activos" →
    excluye inactivos. Filtrar por tipo "servicio" → sólo servicios.
17. (Performance) Con el seeder de ~1.000 productos, buscar por nombre/SKU responde en <5 s (SC-005).

### US8 — Baja lógica y eliminación
18. Inactivar un producto → sale de los buscadores de ventas/compras, sigue en el filtro "inactivos";
    reactivarlo → vuelve a estar disponible.
19. Eliminar un producto **sin** operaciones → se borra.
20. Intentar eliminar un producto **con** movimientos de stock → rechazado (HTTP 409 / toast), sólo se
    puede inactivar.

## Tests automatizados

```bash
php artisan test --filter=Producto     # ProductoAlta, ProductoSku, ProductoListado, ProductoBaja
php artisan test --filter=Stock        # StockAjuste (foto vs histórico, servicio sin stock)
php artisan test --filter=SkuUnico     # regla de validación
```

Todos deben quedar en verde antes de dar la feature por terminada (Principio IV: lógica de stock e
importes con test).

## Criterios de aceptación cubiertos

SC-001 (alta <1 min), SC-002 (SKU único), SC-003 (foto=histórico), SC-004 (no eliminar con
operaciones), SC-005 (búsqueda <5 s con 1.000 productos), SC-006 (sin importes negativos), SC-007
(servicio sin stock).
