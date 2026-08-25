# Quickstart: Validar Deshacer Import de Productos

## Prerrequisitos

- Migraciones corridas (`importacion_corridas`, `importacion_filas_snapshot`).
- Al menos 3 productos existentes en la base local con precio, costo y stock en el depósito Local
  conocidos de antemano.
- Un archivo `.xlsx` de prueba mapeable a la solapa Productos & Servicios con columna "Id" (upsert)
  apuntando a esos 3 productos, con precio/costo/stock distintos a los actuales, más 1 fila de alta
  nueva (sin Id).

## Escenario 1 — Undo completo (US1)

1. Anotar precio, costo y stock actual de los 3 productos existentes.
2. Importar el archivo de prueba vía `/importar-datos/productos` (Paso 1 → 2 → 3, confirmar).
3. Verificar en el listado de Productos que los 3 productos cambiaron y que el 4º (alta) existe.
4. Ir al resumen post-import (o al historial) y accionar "Deshacer este import".
5. **Esperado**: los 3 productos vuelven a los valores anotados en el paso 1; el producto dado de
   alta queda inactivo/fuera del listado; el toast confirma "4 de 4 filas revertidas".
6. Verificar en Auditoría que los cambios de precio del undo quedan registrados con origen
   "Deshacer import".

## Escenario 2 — Undo parcial por operación posterior (US2)

1. Repetir pasos 1-3 del Escenario 1.
2. Antes de deshacer, generar una venta que incluya uno de los 3 productos actualizados (o un
   ajuste manual de stock sobre él).
3. Accionar "Deshacer este import".
4. **Esperado**: los 2 productos sin actividad posterior vuelven a su estado anterior; el producto
   con venta/ajuste posterior queda sin modificar; el toast/resumen lista ese producto con el
   motivo ("tiene una venta posterior al import" o equivalente); el resto del undo no se aborta.

## Escenario 3 — Historial y vencimiento (US3)

1. Hacer 2 imports de prueba en momentos distintos.
2. Abrir `/importar-datos/productos/historial`.
3. **Esperado**: ambas corridas listadas con fecha, usuario, archivo, conteos y estado `vigente`.
4. (Validación de vencimiento, sin esperar 48h reales): con acceso a Tinker/base local, adelantar
   `deshacer_disponible_hasta` de una corrida a una fecha pasada.
5. Refrescar el historial. **Esperado**: esa corrida pasa a estado `vencido`, sin botón "Deshacer"
   disponible; intentar el endpoint de undo directamente sobre esa corrida devuelve 422.

## Escenario 4 — Concurrencia de stock durante el undo

Cubre FR-007/FR-008 (research.md R4): mismo patrón de test que spec 074 usó para
`StockService::fijar()`, adaptado al undo — una venta concurrente entre el import y el undo no
debe perderse silenciosamente; la fila de stock afectada debe quedar como no revertida en vez de
pisar la venta.

## Escenario 5 — No se puede deshacer dos veces / orden entre corridas superpuestas

1. Deshacer una corrida ya deshecha → el endpoint responde 422, sin efecto.
2. Con dos corridas vigentes que tocaron el mismo producto, deshacer primero la más antigua →
   la fila de ese producto queda `no_revertida` con motivo "modificado por una corrida más
   reciente"; deshacer luego la más reciente sí revierte esa fila.

Ver [data-model.md](./data-model.md) para el detalle de columnas y
[contracts/importacion-undo-api.md](./contracts/importacion-undo-api.md) para las respuestas
esperadas de cada endpoint.
