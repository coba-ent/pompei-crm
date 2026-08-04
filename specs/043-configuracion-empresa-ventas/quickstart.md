# Quickstart: validar la reorganización de Configuración & Ajustes

## Prerequisitos

- Migraciones corridas (incluye la nueva `configuracion_ventas`) y seeders (`RolSeeder`,
  `UsuarioAdminSeeder`, `FuncionAvanzadaSeeder`) ejecutados.
- Dos usuarios de prueba: uno con rol `Admin` (el seedeado, `admin@contagram.local`) y otro sin ese rol
  (por ejemplo un usuario con algún permiso granular viejo asignado a mano, para probar que ya no le
  alcanza).

## Escenario 1 — Empresa reemplaza a Usuarios y Permisos

1. Loguearse como Admin.
2. Ir a Configuración & Ajustes (topbar) → "Empresa" (o directo desde el dropdown de topbar).
3. Verificar que se ven, en la misma pantalla: la tarjeta de datos fiscales y, debajo, la tabla de
   usuarios con columnas Nombre/Email/Roles/Estado/Acciones.
4. Click en "Nuevo Usuario" → se abre el modal, se completa y guarda por AJAX → aparece en la tabla sin
   recargar la página.
5. Click en "Roles y Permisos" → navega a la pantalla existente de roles, sin cambios.
6. Confirmar que la URL vieja de "Usuarios y Permisos" ya no existe como pantalla propia.

## Escenario 2 — Gate de Admin y ubicación en topbar

1. Loguearse con el usuario sin rol Admin.
2. Verificar que el sidebar no muestra ningún bloque "Configuración & Ajustes".
3. Verificar que el dropdown de usuario de la topbar no muestra "Empresa" ni "Configuración & Ajustes".
4. Intentar entrar por URL directa a `configuracion.index`, `configuracion.mi-perfil.index`,
   `configuracion.depositos.index`, etc. → esperar 403.
5. Loguearse como Admin, repetir el paso 4 → esperar 200 en todos los casos.

## Escenario 3 — Pantalla única con tabs

1. Loguearse como Admin, ir a "Configuración & Ajustes" desde el dropdown de topbar.
2. Verificar que carga con el tab "Funciones Avanzadas" activo por defecto, y que cada tab visible tiene
   un ícono.
3. Con una función (ej. Mercado Libre) en estado inactiva (`activa=false`), confirmar que su tab no
   aparece (o aparece deshabilitado). Activarla desde el tab "Funciones Avanzadas" → confirmar que el tab
   "Mercado Libre" pasa a estar disponible sin recargar la página completa.
4. Click en el tab "Ventas" → confirmar que el contenido cambia in-page (sin fragmento `#` en la URL del
   navegador).

## Escenario 4 — Defaults de Ventas

1. En el tab "Ventas", configurar: Categoría = X, Vendedor = Y, Lista de Precios = Z, Tipo de
   Comprobante = A, Días de Vto. de Cobro = 15. Guardar (Toastr de éxito, sin recarga).
2. Ir a Ventas → Crear Venta. Verificar que Categoría=X, Vendedor=Y, Lista de Precios=Z y Tipo de
   Comprobante=A quedan preseleccionados, y que "Vto. del Cobro" = fecha de Emisión (hoy) + 15 días.
3. Elegir un Cliente que tenga `tipo_comprobante_defecto` propio cargado → confirmar que ese valor
   reemplaza al default global de Tipo de Comprobante (prioridad ya existente, sin cambios).
4. Borrar la Categoría X del catálogo. Volver a abrir Crear Venta → confirmar que el formulario carga sin
   romper, con Categoría sin preselección (el resto de los defaults sigue aplicando normalmente).
5. Editar una Venta ya existente (o convertir un Presupuesto) → confirmar que sus valores actuales no son
   pisados por los defaults configurados.
6. Abrir una Venta creada antes de cambiar los defaults → confirmar que sus datos guardados no cambiaron.
