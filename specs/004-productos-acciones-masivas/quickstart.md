# Quickstart — Validación de Selección Múltiple y Acciones Masivas en Productos

Guía para validar la feature de punta a punta. No incluye código de implementación (eso va en
`tasks.md`/implementación); son los pasos y resultados esperados que prueban que el módulo funciona.

## Prerrequisitos

- Módulo Productos (`002-productos`) ya implementado, con al menos 5 productos cargados (mezcla de
  activos/inactivos, con y sin proveedor/tipo de producto asignado).
- Al menos un producto con movimientos de stock cargados (para probar la protección de "Eliminar
  Masivamente").
- XAMPP con MySQL corriendo, DB `contagram`. Dependencias PHP/JS instaladas.

## Setup

```bash
npm run build                   # (o npm run dev) compila productos.js con los cambios
php artisan serve               # levanta la app en http://127.0.0.1:8000
```

## Escenarios de validación

### US1 — Seleccionar productos del listado

1. Navegar a `/productos`. Marcar el checkbox de una fila → aparece la barra "1 productos
   seleccionados. Haga click aquí para realizar acciones. Seleccionar los N productos." (N = total
   que matchea el filtro vigente, no sólo la página).
2. Marcar el checkbox "seleccionar todo" del header → se marcan todas las filas de la página
   visible; el contador de la barra sube a esa cantidad.
3. Hacer click en "Seleccionar los N productos" → el contador pasa a mostrar N (el total, no sólo
   la página).
4. Con productos seleccionados, cambiar de página o aplicar un filtro → la selección se limpia y la
   barra desaparece.

### US2 — Acciones Masivas

5. Seleccionar 2-3 productos → click en "Haga click aquí para realizar acciones" → se abre el modal
   "Acciones Masivas" con el select "Elegí una Acción" listando las 11 opciones en el orden relevado
   (ver `contracts/acciones-masivas-rutas.md`).
6. Elegir "Modificar Precio de Venta" → aparece un input numérico → cargar un valor → Confirmar →
   toast de éxito, la tabla se refresca y los productos seleccionados muestran el nuevo precio, sin
   recargar la página.
7. Elegir "Mostrar en Ventas" (sin valor adicional) → Confirmar → se aplica directo, sin pedir datos
   extra.
8. Elegir "Modificar IVA por defecto", cargar una opción (ej. "10,5") → Confirmar → verificar que
   tanto la columna IVA Ventas como IVA Compras de los productos seleccionados cambiaron.
9. Seleccionar un lote que incluya el producto con movimientos de stock (paso de prerrequisitos) +
   al menos un producto sin operaciones → elegir "Eliminar Masivamente" → Confirmar → verificar que
   el producto sin operaciones se eliminó y el que tiene movimientos NO, con el motivo explícito en
   la respuesta/mensaje.
10. Repetir el paso 9 pero seleccionando **sólo** el producto con movimientos → verificar que la
    respuesta indica 0 eliminados y 1 no-eliminado con motivo, sin mostrar un error genérico.
11. Elegir "Modificar Precio de Venta" y cargar un valor negativo → Confirmar → verificar que ningún
    producto del lote se modifica (422, validación previa a tocar la base).
12. Abrir el modal sin elegir ninguna acción y tratar de confirmar → el sistema no ejecuta nada y
    pide elegir una acción.

## Tests automatizados

```bash
php artisan test --filter=ProductoAccionesMasivas
```

Debe quedar en verde antes de dar la feature por terminada (Principio IV de la constitución: hay
impacto económico directo en las acciones de precio/costo).

## Consistencia de documentación (antes de cerrar la feature)

- Documentar Selección Múltiple + Acciones Masivas como sección activa en
  `docs/documentacion_principal_crm.md` §2.2 (Productos), reemplazando la mención de brecha conocida
  en §4.1.

## Criterios de aceptación cubiertos

SC-001 (acción en lote <15s sin recargar), SC-002 (protección de eliminar con operaciones + resumen
claro), SC-003 (selección de "todos los N" exacta), SC-004 (estructura del modal fiel a Contagram
real).
