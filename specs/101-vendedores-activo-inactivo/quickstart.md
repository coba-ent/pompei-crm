# Quickstart: Vendedores — activar/desactivar

## Prerrequisitos

- Migración `add_activo_to_vendedores_table` corrida (`php artisan migrate`).
- Al menos 2 vendedores existentes en la base (uno para desactivar, uno para dejar activo de control).
- Usuario logueado con rol Admin.

## Escenario 1 — Desactivar desde el tab nuevo (User Story 1 y 2)

1. Ir a Configuración & Ajustes → tab **Vendedores**.
2. Verificar que la tabla lista todos los vendedores existentes, todos con estado "Activo".
3. Sobre un vendedor, usar el control de estado para desactivarlo.
4. **Esperado**: toast de confirmación, la fila pasa a "Inactivo" sin recargar la página.
5. Ir a Ventas → Nueva Venta, abrir el select de Vendedor.
6. **Esperado**: el vendedor desactivado NO aparece en la lista.
7. Abrir una Venta anterior que ya tenía asignado ese vendedor.
8. **Esperado**: sigue mostrando el nombre del vendedor sin error ni campo vacío.

## Escenario 2 — Alta y reactivación (User Story 2)

1. En el tab Vendedores, dar de alta un vendedor nuevo.
2. **Esperado**: aparece en la tabla como "Activo", sin recargar.
3. Desactivarlo y volver a activarlo.
4. **Esperado**: ambos cambios se reflejan al instante con toast, y el vendedor vuelve a aparecer
   en el select de Nueva Venta tras reactivarlo.

## Escenario 3 — Vendedor por defecto inactivo (User Story 3)

1. En Configuración & Ajustes → tab Ventas, configurar un "Vendedor por defecto".
2. Ir al tab Vendedores y desactivar ese mismo vendedor.
3. Volver al tab Ventas.
4. **Esperado**: aviso visible indicando que el vendedor por defecto configurado está inactivo.
5. Ir a Nueva Venta.
6. **Esperado**: el campo Vendedor no viene precargado con ese vendedor.

## Validación de regresión

- El buscador inline Select2 de Vendedor en Nueva Venta/Presupuesto sigue permitiendo crear un
  vendedor nuevo sin salir del formulario (comportamiento spec 020, sin cambios).
- Intentar crear un vendedor con el mismo nombre de uno inactivo existente debe seguir fallando por
  unicidad (mensaje de validación existente).
