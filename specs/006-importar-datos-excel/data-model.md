# Data Model — Importar Datos por Excel (Fase 1)

Sin tablas nuevas. Esta feature crea filas en `clientes`, `proveedores` y `productos` (entidades ya
existentes) a partir de un archivo subido; el archivo y el mapeo de columnas son estado transitorio
(disco temporal + sesión, research.md §2), no una entidad persistida.

## Diccionario de campos importables por entidad (`DefinicionCamposImportables`)

No es una tabla — es la lista de "campos destino" que el paso 2 (mapeo) ofrece para cada solapa,
más la regla de validación reutilizada de cada uno.

### Clientes

| Campo destino | Columna del modelo | Obligatorio | Validación reutilizada |
|---|---|---|---|
| Cliente (Nombre) | `nombre` | Sí | `ReglasCliente` |
| Nombre | `nombre_pila` | No | |
| Apellido | `apellido` | No | |
| Teléfono | `telefono` | No | |
| Teléfono Celular | `telefono_celular` | No | |
| Email | `email` | No | `email` |
| Domicilio | `domicilio` | No | |
| Localidad | `localidad` | No | |
| Provincia | `provincia` | No | |
| CUIT | `cuit` | No | `CuitValido` (si `tipo_documento` es CUIT/CUIL) |
| Condición de IVA | `condicion_iva_id` | No | lookup por nombre (research.md §3) |
| Categoría Ventas | `categoria_id` | No | lookup por nombre |
| Nota | `nota` | No | |
| *(campo personalizado)* | `campos_personalizados[]` | No | nombre definido por el usuario en el mapeo |

### Proveedores

Mismo diccionario que Clientes, con las diferencias ya vigentes desde `003-proveedores-informe-stock`:
sin "Apodo ML"; "Categoría Compras" en vez de "Categoría Ventas"; "Nota Interna" en vez de "Nota
para el Cliente".

### Productos & Servicios

| Campo destino | Columna del modelo | Obligatorio | Validación reutilizada |
|---|---|---|---|
| Nombre | `nombre` | Sí | `ReglasProducto` |
| Código/SKU | `codigo` | No | `SkuUnico` |
| Tipo | `tipo` (Producto/Servicio, default Producto) | No | `in:producto,servicio` |
| Tipo de Producto | `tipo_producto_id` | No | lookup por nombre |
| Proveedor | `proveedor_id` | No | lookup por nombre (FR-009) |
| Precio de Venta | `precio_venta` | No | `numeric, min:0` |
| Costo | `costo` | No | `numeric, min:0` |
| IVA Ventas | `iva_venta_pct` | No | `Rule::in(OPCIONES_IVA)` |
| IVA Compras | `iva_compra_pct` | No | `Rule::in(OPCIONES_IVA)` |
| Descripción | `descripcion` | No | |

## Estado transitorio del asistente (no persistido)

- **Archivo subido**: `storage/app/private/imports/{uuid}.{ext}`, referenciado por sesión durante el
  paso 2, borrado al confirmar/cancelar.
- **Mapeo de columnas**: `{ columna_origen: campo_destino }` por columna detectada, incluyendo el
  caso especial `campo_destino = 'personalizado:{nombre}'` (research.md §5). Viaja como body del
  POST de confirmación — no se guarda en sesión ni en disco aparte.
- **Resultado de la importación**: `{ importados: int, fallidos: [{ fila, motivo }], advertencias: [{ fila, motivo }] }`
  — se muestra una sola vez en la página de resumen (paso 3), no se persiste.

## Reglas de validación (desde Requirements)

| Regla | Origen | Dónde se aplica |
|---|---|---|
| Campo obligatorio de la entidad debe tener columna mapeada | FR-005 | Validación del mapeo antes de confirmar (paso 2 → 3) |
| No dos columnas mapeadas al mismo campo destino | FR-005 | Idem |
| Cada fila se valida con las mismas reglas que el alta manual | FR-006 | `ImportadorFilas`, reutilizando `ReglasCliente`/`ReglasProveedor`/`ReglasProducto` |
| Fila inválida se omite, no aborta el archivo | FR-006, research.md §4 | `ImportadorFilas`, transacción corta por fila |
| Proveedor/Categoría/Condición de IVA/Tipo de Producto se resuelven por nombre, null si no matchea | FR-009, research.md §3 | `ImportadorFilas` |
| Cancelar no deja registros creados | FR-007 | No se persiste nada hasta el paso de confirmación; cancelar sólo borra el archivo temporal |
