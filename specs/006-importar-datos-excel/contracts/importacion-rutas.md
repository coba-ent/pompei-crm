# Contrato de UI / Rutas: Importar Datos por Excel

Interfaz que esta feature expone al usuario. Rutas web Laravel, en español. **Única pantalla de la
app que navega por páginas reales entre pasos** (excepción documentada en spec.md Assumptions) —
las demás reglas de diseño (toasts, sin librerías nuevas) siguen aplicando donde corresponda.

## Rutas

| Método | Ruta | Nombre | Acción | Respuesta | Historia |
|---|---|---|---|---|---|
| GET | `/importar-datos/{entidad}` | `importacion.index` | Paso 1: solapas + "Seleccionar Archivo" + paneles informativos | HTML | US1 |
| POST | `/importar-datos/{entidad}/subir` | `importacion.subir` | Sube y valida el archivo, arma la vista previa | Redirect a `importacion.mapear` | US1 |
| GET | `/importar-datos/{entidad}/mapear` | `importacion.mapear` | Paso 2: vista previa + selects de mapeo por columna | HTML | US1 |
| POST | `/importar-datos/{entidad}/confirmar` | `importacion.confirmar` | Aplica el mapeo, crea las filas válidas | Redirect a `importacion.resumen` | US1 |
| POST | `/importar-datos/{entidad}/cancelar` | `importacion.cancelar` | Descarta el archivo temporal, vuelve al paso 1 | Redirect | US1 |
| GET | `/importar-datos/{entidad}/resumen` | `importacion.resumen` | Paso 3: resultado (importados/fallidos/advertencias) | HTML | US1 |

`{entidad}` ∈ `clientes` \| `proveedores` \| `productos` (US1, US2, US3 respectivamente — misma ruta
parametrizada, ver `DefinicionCamposImportables` en data-model.md).

## Contratos de cada paso

### GET `importacion.index`

- Muestra las 3 solapas (Clientes/Proveedores/Productos & Servicios) con la solapa `{entidad}`
  activa, el botón "Seleccionar Archivo", el texto de formatos permitidos (`.xls`, `.xlsx`, `.csv`),
  y los paneles "Acerca de la importación de {Entidad}" / "Notas Técnicas" (con la recomendación de
  importar Proveedores antes que Productos cuando `{entidad} = productos`, FR-011).

### POST `importacion.subir`

- Request: `multipart/form-data` con `archivo` (mimes:xls,xlsx,csv, max:10240 KB).
- Si el archivo no cumple formato/tamaño: `422`, vuelve al paso 1 con el error (FR-002).
- Si es válido: guarda el archivo en `storage/app/private/imports/{uuid}.{ext}` (research.md §2),
  guarda la referencia en sesión, y redirige a `importacion.mapear`.

### GET `importacion.mapear`

- Muestra las primeras filas del archivo (vista previa) y, por cada columna detectada, un select con
  los campos destino de `{entidad}` (FR-003) más las opciones "No importar" y "Campo personalizado"
  (con un input de nombre que aparece al elegir esa opción, FR-004).

### POST `importacion.confirmar`

- Request: `{ mapeo: { "<indice_columna>": "<campo_destino>" } }` (más el nombre de cada campo
  personalizado elegido, si aplica).
- Rechaza (`422`, vuelve al paso 2) si el campo obligatorio de la entidad no tiene columna mapeada,
  o si dos columnas mapean al mismo campo (FR-005).
- Si el mapeo es válido: procesa el archivo fila por fila (FR-006), crea un registro por fila válida
  con las reglas de validación de esa entidad, resuelve los campos FK por nombre (research.md §3),
  borra el archivo temporal, guarda el resultado en sesión (research.md §2) y redirige a
  `importacion.resumen`.

### POST `importacion.cancelar`

- Borra el archivo temporal y la referencia de sesión, sin crear ningún registro (FR-007). Redirige
  a `importacion.index`.

### GET `importacion.resumen`

- Muestra: cantidad de filas importadas, lista de filas fallidas con su motivo (ej. "Fila 5: CUIT
  inválido"), y lista de advertencias (ej. "Fila 12: Proveedor 'Acme' no encontrado, producto creado
  sin proveedor"). Botón para volver al listado de `{entidad}` (equivalente a "Ver mis Clientes" de
  Contagram real).

## Notas de UI

- Se agrega el botón "Importar datos" (no existe hoy) en `clientes/index.blade.php`,
  `proveedores/index.blade.php` y `productos/index.blade.php`, apuntando a
  `importacion.index` con el parámetro `{entidad}` correspondiente.
- Los paneles "Acerca de la importación"/"Notas Técnicas" reproducen el texto relevado en
  `docs/informe_contagram_base_de_datos.md` §2.6, adaptado por entidad.
- El video "Cómo importar" y el link "Tips Para Importar" quedan fuera de alcance (spec.md
  Assumptions) — no se reproducen como placeholders vacíos; simplemente no están en la pantalla.
