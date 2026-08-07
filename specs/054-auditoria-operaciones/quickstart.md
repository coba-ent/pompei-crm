# Quickstart: Validar el Módulo de Auditoría

## Prerrequisitos

- Migraciones corridas (incluye `logs_auditoria` y el nuevo permiso `auditoria.ver`).
- Usuario de prueba con rol que incluya `auditoria.ver` (ver `CREDENCIALES_ACCESO.txt`).
- Al menos una Venta, un Gasto y un Cobro existentes o creables desde la UI.

## Escenario 1 — Captura automática (User Story 1)

1. Crear una Venta desde `/ingresos/ventas` (o el módulo correspondiente).
2. Ir a `/auditoria`.
3. **Esperado**: aparece una fila nueva con `Tipo = Creó`, `Operación = Venta`, `Usuario` = el
   usuario logueado, `Detalle` con referencia a la venta creada, `Total` = el importe de la venta.
4. Repetir con un Gasto y un Cobro — verificar que cada uno aparece con su `Operación` correcta.

## Escenario 2 — Origen de integración

1. Disparar (o simular en tinker) la creación de una Venta de Mercado Libre vía el flujo de
   sincronización existente.
2. Ir a `/auditoria`.
3. **Esperado**: la fila muestra `Usuario = Ventas Online` (o el label equivalente), sin usuario_id
   real, y sin errores en el listado.

## Escenario 3 — Filtros combinados (User Story 2)

1. Con datos de al menos dos usuarios y dos tipos de operación distintos cargados.
2. En `/auditoria`, filtrar por `Operación = Gasto` y `Usuario = <usuario A>` simultáneamente.
3. **Esperado**: sólo aparecen filas de Gastos creados/modificados por ese usuario específico.
4. Cambiar el selector de fecha a un rango que no incluya el día actual.
5. **Esperado**: el listado se actualiza mostrando sólo operaciones dentro de ese rango.

## Escenario 4 — Exportar (User Story 3)

1. Aplicar un filtro que acote el listado a un subconjunto conocido (ej. 3-5 filas).
2. Hacer clic en "Exportar".
3. **Esperado**: el archivo descargado contiene exactamente esas filas, ni más ni menos.
4. Aplicar un filtro que no devuelva resultados y volver a exportar.
5. **Esperado**: se muestra un toast de error ("no hay datos para exportar"), no se descarga un
   archivo vacío.

## Escenario 5 — Usuario dado de baja (Edge Case)

1. Identificar una fila de auditoría generada por un usuario X.
2. Dar de baja (desactivar) al usuario X desde Configuración → Usuarios.
3. Volver a `/auditoria` y ubicar esa misma fila.
4. **Esperado**: el nombre del usuario X se sigue mostrando correctamente (no aparece vacío ni
   "Usuario eliminado").

## Verificación técnica (no UI)

- Confirmar por query directa que no existe ningún endpoint HTTP que permita `UPDATE`/`DELETE` sobre
  `logs_auditoria` (revisar `routes/web.php` — no debe haber rutas de edición/borrado para este
  recurso).
- Confirmar que la query usada por `/auditoria/datatable` y por `/auditoria/exportar` es la misma
  (mismo método de construcción de filtros), para evitar el bug de "exporta todo en vez de lo
  filtrado".
