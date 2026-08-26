# Quickstart: verificación manual de la prevalidación

**Spec**: [spec.md](./spec.md) | **Fecha**: 2026-08-26

> **En LOCAL, nunca en producción.** El VPS está en uso real (regla vigente del proyecto).

## Archivos de prueba

| Archivo | Para qué |
|---|---|
| `Ferrum nuevos (2).xlsx` (148 filas) | El del incidente: fórmulas sin calcular en Código/SKU (148 celdas) y en AHORA 3 (24 celdas). |
| Export de Productos del catálogo (9.632 filas) | Volumen y round-trip. Se obtiene desde Productos → Exportar. |

## 1. Preparar

```bash
php artisan serve --port=8011
# admin@contagram.local / password  (ver CREDENCIALES_ACCESO.txt)
```

Anotar el estado previo, para poder restaurarlo:

```sql
SELECT COUNT(*), MAX(id) FROM productos;
SELECT AUTO_INCREMENT FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos';
```

## 2. Fórmulas calculadas (FR-011 a FR-013)

1. Subir `Ferrum nuevos (2).xlsx` en Productos & Servicios.
2. En el Paso 2, mirar la **vista previa**: la columna Código/SKU tiene que mostrar
   `DEPOSITO ANDINA ... 44927`, **no** `=+B2&" "&A2`.
3. ✅ si ninguna celda de la vista previa empieza con `=`.

## 3. Modal de confirmación (FR-001 a FR-006)

1. Dejar el automapeo como viene, pero poner **Id** en "No importar".
2. Apretar "Confirmar importación": tiene que abrirse el **modal de confirmación**, y **no** empezar
   a importar.
3. Verificar que informa **148 altas, 0 actualizaciones, 0 errores** (con las fórmulas ya calculadas,
   las 24 filas que antes fallaban ahora son válidas).
4. **Sin confirmar todavía**, comprobar en la base que no se creó nada:
   `SELECT COUNT(*) FROM productos;` tiene que dar el mismo número del punto 1.
5. Cerrar el modal con "Cancelar": tiene que volver al mapeo **con la selección intacta** (FR-005c).
6. ✅ si los conteos son correctos, la base no cambió y el mapeo sobrevivió al cancelar.

## 3b. Campos que se van a modificar (FR-005b)

1. Subir el **mismo archivo pero con la columna Id mapeada** (así las filas caen como actualizaciones).
2. Abrir el modal.
3. Verificar que, además de "N actualizaciones", lista **qué campos se van a pisar** con su etiqueta
   visible y **a cuántos registros afecta cada uno**.
4. ✅ si los campos listados son exactamente los mapeados con valor, y los conteos por campo cuadran.

## 4. Bloqueo ante errores (FR-005)

1. Editar el Excel y romper una fila a propósito (poner `abc` en la columna Costo).
2. Subir, mapear y llegar a revisión.
3. Verificar que el modal informa **1 error**, con el **número de fila** y el motivo **en español**
   nombrando **"Costo"** (FR-018, FR-019).
4. ✅ si el botón de confirmar está **deshabilitado** y no hay forma de importar.

## 5. Importación real y resumen (FR-021 a FR-024)

1. Volver al archivo sano, revisar y confirmar.
2. En el resumen, verificar: el número coincide con lo prevalidado, y aparecen el **nombre del
   archivo** y la **fecha y hora**.
3. En la base: los ids creados tienen que ser **consecutivos** desde el AUTO_INCREMENT previo.
4. ✅ si los tres puntos dan bien.

## 6. El caso del resumen contaminado (FR-022)

1. Empezar una importación y **abandonarla** a mitad (cerrar la pestaña durante el proceso).
2. Empezar una importación nueva, chica (2 o 3 filas), y completarla.
3. ✅ si el resumen informa **2 o 3**, y no un número inflado con lo de la anterior.

## 7. Round-trip (FR-014, FR-017)

1. Productos → Exportar. Guardar el archivo sin abrirlo ni tocarlo.
2. Subirlo al asistente.
3. ✅ si **todas** las columnas quedan automapeadas — mirar especialmente **"Precio venta"**.
4. Revisar: tiene que informar **0 altas, N actualizaciones, 0 errores**.
5. Confirmar y verificar que **ningún** producto cambió de precio, costo ni stock.

## 8. Volumen (FR-007, FR-008, SC-008)

1. Subir el export completo (9.632 filas).
2. ✅ si el modal muestra **progreso** mientras prevalida y termina sin cortarse.
3. Anotar cuánto tardó.

## 9. Restaurar la base

El **"Deshacer"** del historial **no borra**: deja los productos inactivos, no libera los ids y no
borra los precios (verificado el 26/08/2026). Para dejar la base como estaba hay que limpiar a mano:

```sql
-- Ajustar el rango al de la prueba y verificar SIEMPRE antes de borrar.
SELECT (SELECT COUNT(*) FROM venta_items  WHERE producto_id BETWEEN :desde AND :hasta) AS en_ventas,
       (SELECT COUNT(*) FROM compra_items WHERE producto_id BETWEEN :desde AND :hasta) AS en_compras;
-- Sólo si ambos dan 0:
DELETE FROM importacion_filas_snapshot WHERE importacion_corrida_id = :corrida;
DELETE FROM importacion_corridas       WHERE id = :corrida;
DELETE FROM precios_producto           WHERE producto_id BETWEEN :desde AND :hasta;
DELETE FROM stocks                     WHERE producto_id BETWEEN :desde AND :hasta;
DELETE FROM productos                  WHERE id BETWEEN :desde AND :hasta;
ALTER TABLE productos AUTO_INCREMENT = :auto_increment_previo;
```

## Despliegue

No hay migraciones. El despliegue es el estándar del proyecto (`git pull` + limpiar y recachear).
Ver la memoria `deploy-al-vps-como-se-hace`.
