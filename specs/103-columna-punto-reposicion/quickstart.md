# Quickstart: Columna Punto de Reposición en el listado de Productos

## Prerrequisitos

- Servidor local levantado (`php artisan serve` o XAMPP), sesión iniciada con un usuario con permiso
  de Productos.
- Al menos dos productos de prueba: uno con `punto_reposicion` > 0, otro con `0` (o sin configurar), y
  un producto de Tipo = Servicio.

## Escenario 1 — El valor se ve en el listado (User Story 1 / SC-001, SC-002)

1. Ir a **Base de Datos → Productos**.
2. Confirmar que la tabla muestra una columna **"Punto de Reposición"** ubicada después de las
   columnas de Stock (Stock total + una por depósito) y antes de Costo.
3. Verificar la fila del producto con `punto_reposicion = 5`: la celda muestra `5`.
4. Verificar la fila del producto con `punto_reposicion = 0` (o nunca configurado): la celda muestra el
   indicador de "sin control" (no un `0` desnudo).
5. Verificar la fila del producto de Tipo = Servicio: misma celda, mismo indicador de "sin control".

**Resultado esperado**: se puede leer el Punto de Reposición de cualquier producto sin abrir el modal
de edición.

## Escenario 2 — Ordenar por la columna (User Story 2)

1. En la misma pantalla, hacer clic en el encabezado "Punto de Reposición".
2. Confirmar que la tabla se reordena ascendente por ese valor (los "sin control" quedan primero,
   tratados como `0`).
3. Volver a hacer clic: confirmar que se invierte a descendente.

**Resultado esperado**: el orden se resuelve del lado del servidor (la URL/petición AJAX de la
DataTable lleva el parámetro de orden de Yajra), sin degradar el tiempo de respuesta observado hoy
para el resto de columnas ordenables.

## Escenario 3 — Mostrar/ocultar la columna (User Story 3)

1. Abrir el selector de columnas (ícono de columnas en la toolbar del listado).
2. Confirmar que "Punto de Reposición" aparece en la lista de columnas disponibles.
3. Desmarcarla: la columna desaparece de la tabla sin recargar la página.
4. Volver a marcarla: reaparece con los mismos valores.

## Verificación de no regresión

- Confirmar que las columnas dinámicas de Stock por depósito y de Listas de Precio siguen apareciendo
  en el mismo orden relativo entre sí (esta spec no debe correr su posición, sólo insertar una columna
  nueva justo después de Stock).
- Confirmar que el export CSV existente del listado no se rompe (esta spec no lo modifica — verificar
  que sigue funcionando igual que antes del cambio).
