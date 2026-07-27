# Quickstart — Validación de Importar Datos por Excel

Guía para validar la feature de punta a punta. No incluye código de implementación (eso va en
`tasks.md`/implementación); son los pasos y resultados esperados que prueban que el módulo funciona.

## Prerrequisitos

- Módulos Clientes, Proveedores y Productos (`001`-`003`) ya implementados.
- `maatwebsite/excel` ya instalado (research.md §1 — verificar con
  `php artisan tinker --execute="echo class_exists('Maatwebsite\Excel\Facades\Excel');"`).
- Un archivo `.xlsx` de prueba con un puñado de clientes (columnas: Nombre, Apellido, Teléfono,
  Email, una columna extra sin campo fijo — para probar el mapeo a campo personalizado).
- Un archivo `.xlsx` de prueba con un puñado de proveedores.
- Un archivo `.xlsx` de prueba con un puñado de productos, incluyendo una columna "Proveedor" con
  algunos nombres que coincidan con proveedores ya cargados y al menos uno que no coincida con nada.

## Setup

```bash
php artisan serve               # levanta la app en http://127.0.0.1:8000
```

## Escenarios de validación

### US1 — Importar Clientes

1. En `/clientes`, click en "Importar datos" → navega a la pantalla "Importar Datos" con la solapa
   Clientes activa.
2. Subir el archivo de prueba de clientes → navega al paso de vista previa + mapeo, mostrando las
   columnas detectadas.
3. Mapear Nombre/Apellido/Teléfono/Email a sus campos, y la columna extra como "Campo personalizado"
   con un nombre elegido → confirmar.
4. Verificar la página de resumen: N clientes importados, sin fallidos. Ir a `/clientes` y verificar
   que aparecen con los datos correctos, incluyendo el campo personalizado.
5. Repetir el flujo con un archivo que incluya una fila con CUIT matemáticamente inválido (mapeando
   la columna CUIT) → el resumen muestra esa fila como fallida con el motivo, y el resto de las
   filas válidas igual se importaron.
6. Repetir el flujo y cancelar antes de confirmar (en el paso de mapeo) → volver a `/clientes` y
   verificar que no se creó ningún cliente nuevo de esa sesión.
7. Intentar subir un archivo de más de 10MB o con extensión no soportada (ej. `.pdf`) → rechazado
   antes de llegar a la vista previa, con mensaje claro.

### US2 — Importar Proveedores

8. Repetir los pasos 1-4 desde `/proveedores`, con la solapa Proveedores — mismo comportamiento.

### US3 — Importar Productos & Servicios, asociados a Proveedor

9. Con los proveedores del paso 8 ya cargados, ir a `/productos` → "Importar datos" → solapa
   Productos & Servicios. Verificar que el panel de notas técnicas recomienda importar primero
   Proveedores (FR-011).
10. Subir el archivo de productos, mapear la columna "Proveedor" al campo Proveedor → confirmar →
    verificar en el resumen que los productos con nombre de proveedor coincidente quedaron
    asociados, y que la fila con un proveedor inexistente se creó igual pero reportada como
    advertencia (no como fallo).
11. Mapear (o dejar sin mapear) la columna "Tipo" → confirmar → verificar que las filas sin esa
    columna mapeada, o con la celda vacía, se crearon con Tipo = Producto.

## Tests automatizados

```bash
php artisan test --filter=ImportacionDatos
```

Debe quedar en verde antes de dar la feature por terminada (Principio IV de la constitución: hay
validación de CUIT y de campos económicos de Producto en el camino de importación).

## Consistencia de documentación (antes de cerrar la feature)

- Actualizar `docs/documentacion_principal_crm.md`: agregar la sección activa "Importar Datos" y
  quitar la mención de brecha pendiente en Clientes/Proveedores/Productos.
- Confirmar que `docs/modelo_datos.md` no requiere cambios (sin tablas nuevas).

## Criterios de aceptación cubiertos

SC-001 (importar 50 clientes en <3 min), SC-002 (filas inválidas no bloquean el resto), SC-003
(productos asociados a proveedor por nombre sin intervención manual), SC-004 (estructura de pantalla
fiel a Contagram real salvo la unificación documentada de Productos/Servicios).
