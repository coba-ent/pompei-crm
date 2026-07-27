# Research: Importar Datos por Excel

## 1. Librería de lectura de Excel/CSV

**Pregunta**: ¿qué librería parsea los archivos `.xls`/`.xlsx`/`.csv` subidos?

**Decisión**: `maatwebsite/excel` ^3.1 — **ya está en `composer.json` y ya instalado en `vendor/`**
(verificado: `Maatwebsite\Excel\Facades\Excel` resuelve dentro de la app vía `php artisan tinker`).
No se agrega ninguna dependencia nueva.

**Rationale**: es el estándar de facto para Excel/CSV en Laravel (envuelve PhpSpreadsheet), y ya
está disponible en el proyecto — probablemente quedó del intento anterior de este mismo módulo
(descartado junto con otros en la reconstrucción del 24/07/2026, pero el paquete de Composer nunca
se desinstaló). Se usa `Excel::toArray()` para obtener un array crudo de filas/columnas por hoja, en
vez de una clase de importación con encabezados fijos — porque el mapeo de columnas a campos lo
define el usuario en pantalla (paso 2), no puede inferirse de antemano.

**Alternativas consideradas**:
- *`league/csv` + un parser de xlsx aparte*: rechazada — dos librerías para cubrir lo que
  `maatwebsite/excel` ya cubre con una, que además ya está instalada.

## 2. Dónde vive el estado del asistente entre pasos (subir → mapear → confirmar)

**Pregunta**: el archivo se sube en el paso 1, se previsualiza y mapea en el paso 2, y se confirma
en el paso 3 (páginas reales, no una sola request) — ¿dónde se guarda el archivo y el mapeo elegido
mientras tanto?

**Decisión**: el archivo subido se guarda en `storage/app/private/imports/{uuid}.{ext}` (disco
`local`, fuera del `public`); la sesión de Laravel guarda `{entidad, ruta_archivo, uuid}` durante el
paso 2. El mapeo de columnas elegido viaja del paso 2 al 3 como el body del POST de confirmación
(no necesita persistirse aparte). El archivo temporal se borra al confirmar, al cancelar, o por un
job de limpieza de archivos huérfanos con más de 24hs (mismo criterio que cualquier archivo temporal
de subida).

**Rationale**: es el patrón estándar de "wizard multi-paso" en Laravel sin necesidad de una tabla de
"importaciones en progreso" — nada de esto se persiste en base de datos porque no es un dato del
negocio, es un estado transitorio de UI (consistente con Key Entities del spec: "no persistido").

**Alternativas consideradas**:
- *Guardar el archivo completo en la sesión (base64)*: rechazada — infla la sesión y no escala con
  el límite de 10MB ya relevado.
- *Persistir una tabla `importaciones_en_progreso`*: rechazada — over-engineering para un estado que
  vive minutos, no días; ya existe precedente de NO perseguir este camino (el `Importacion` model
  del código descartado en la reconstrucción no se recupera).

## 3. Resolución de campos por lookup (Proveedor, Categoría, Condición de IVA, Tipo de Producto)

**Pregunta**: FR-009 especifica que la columna "Proveedor" de Productos se resuelve por nombre
contra proveedores existentes. ¿Aplica el mismo criterio a otros campos que son FK en el alta manual
(`categoria_id`, `condicion_iva_id`, `tipo_producto_id`)?

**Decisión**: sí, se generaliza el mismo criterio de FR-009 a **todo** campo destino que sea una
relación (FK) mapeable desde una columna de texto: se busca un registro existente cuyo `nombre`
coincida sin distinguir mayúsculas/acentos (`Str::of($valor)->lower()->ascii()`); si no hay
coincidencia, el campo queda `null` para esa fila (no bloquea la fila, salvo que ese campo sea el
obligatorio de la entidad — hoy sólo "Nombre" lo es).

**Rationale**: mismo comportamiento ya especificado explícitamente para Proveedor (FR-009); aplicar
el mismo criterio a Categoría/Condición de IVA/Tipo de Producto evita definir 4 reglas distintas
para el mismo tipo de problema (texto libre → FK existente).

## 4. Atomicidad: por fila, no por archivo completo

**Pregunta**: si una fila del archivo falla validación, ¿se aborta toda la importación?

**Decisión**: no — cada fila se valida y se crea de forma independiente, dentro de su propia
transacción corta. Una fila inválida se omite y se reporta en el resumen final; las demás filas
válidas del mismo archivo se importan igual (FR-006, SC-002).

**Rationale**: es el comportamiento explícitamente relevado y ya definido en el spec — a diferencia
de `004-productos-acciones-masivas` (donde el "lote" comparte un único valor y por eso es
todo-o-nada), acá cada fila trae sus propios datos independientes; no hay razón de negocio para que
el typo de una fila descarte las demás.

## 5. Reuso de patrones existentes (sin research adicional)

- **Validación de fila**: reutiliza `ReglasCliente`/`ReglasProveedor`/`ReglasProducto` (traits ya
  existentes) contra el array de datos ya mapeado de cada fila, en vez de duplicar reglas.
- **Campos personalizados**: mismo formato JSON (`nombre`/`tipo`/`opciones`/`valor`) ya usado en el
  alta manual de Cliente/Proveedor — una columna mapeada como "campo personalizado" arma una entrada
  de ese array por fila, con `tipo: 'texto'` fijo (no hay forma de inferir Fecha/Numérico/Opciones
  desde una celda de Excel sin pedirle más al usuario — fuera de alcance de esta versión).
