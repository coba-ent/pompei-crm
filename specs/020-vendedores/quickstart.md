# Quickstart: Vendedores como catálogo propio

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Data model**: [data-model.md](./data-model.md)

Guía de validación end-to-end manual, una vez implementada la feature.

## Prerrequisitos

- Migraciones corridas (`php artisan migrate`), incluida la que crea `vendedores` y migra los datos
  existentes.
- Al menos una Venta o Presupuesto pre-existente con `vendedor_id` apuntando a un usuario, para
  verificar la migración (si no hay datos previos, este paso se valida sólo en un entorno con datos
  de prueba cargados antes de migrar).

## 1. Verificar la migración de datos (SC-002)

1. Antes de migrar, anotar: para una Venta/Presupuesto existente, qué usuario aparecía como
   "Vendedor" en su detalle.
2. Correr las migraciones.
3. Abrir esa misma Venta/Presupuesto: el detalle debe seguir mostrando el mismo nombre como
   Vendedor (ahora resuelto contra la tabla `vendedores`, no `users`).
4. Confirmar que existe un registro en `vendedores` con ese nombre.

## 2. Elegir vendedor al cargar una Venta (User Story 1)

1. Ir a Ingresos → Ventas → Nueva Venta.
2. Verificar que el formulario tiene un campo "Vendedor" (select buscable) — hoy no existe, debe
   aparecer con esta feature.
3. Elegir un vendedor de la lista, completar el resto del formulario y guardar.
4. En el listado de Ventas, confirmar que la columna "Vendedor" muestra el elegido.
5. Abrir el detalle y el PDF de esa Venta: ambos deben mostrar el mismo vendedor.
6. Repetir sin elegir ningún vendedor: la Venta debe guardarse igual, con Vendedor vacío.
7. Repetir los 6 pasos anteriores en Presupuestos → Nuevo Presupuesto.

## 3. ABM inline desde el select (User Story 2)

1. En el mismo formulario, en el select de Vendedor, elegir "＋ Crear Vendedor", escribir un nombre
   nuevo y confirmar: debe quedar creado y seleccionado sin recargar la página (Toastr de éxito).
2. Con ese vendedor recién creado, usar el botón de renombrar: cambiarle el nombre y confirmar que
   el select lo refleja de inmediato.
3. Sin haber guardado ninguna Venta/Presupuesto con ese vendedor todavía, eliminarlo desde el
   select: debe desaparecer de la lista (Toastr de éxito).
4. Crear un vendedor nuevo, usarlo en una Venta (guardar la Venta), y luego intentar eliminarlo
   desde el select: debe rechazarse con un mensaje "No se puede eliminar: está en uso." (Toastr de
   error), y el vendedor debe seguir apareciendo en la lista.

## 4. Vendedor por defecto en integraciones (User Story 3)

1. Ir a Configuración & Ajustes → Tiendanube (requiere la función avanzada activa y la conexión
   configurada, spec 019).
2. En la sección de configuración de ventas, elegir un "Vendedor por defecto" y guardar.
3. Disparar una sincronización que genere al menos una Venta automática (o, en un entorno de
   pruebas, invocar directamente `ConversorOrdenAVenta` sobre una orden de prueba).
4. Verificar que la Venta creada automáticamente tiene asignado ese vendedor por defecto.
5. Repetir 1-4 en Configuración & Ajustes → MercadoLibre, confirmando que ambos defaults son
   independientes entre sí (cambiar uno no afecta al otro).
6. Sin ningún default configurado, generar una Venta automática: debe crearse igual, con Vendedor
   vacío.

## 5. Verificar que no hay pantalla de administración separada (FR-012)

Confirmar que no existe ninguna entrada de menú ni ruta pública para "listar/administrar
Vendedores" fuera de los cuatro selects (Venta, Presupuesto, config. Tiendanube, config.
MercadoLibre) — es una decisión de alcance explícita, no un olvido.
