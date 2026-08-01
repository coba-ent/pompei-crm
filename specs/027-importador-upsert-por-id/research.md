# Research — Importador de Datos: Actualizar por Id (Upsert)

Todas las incógnitas de Technical Context ya se resolvieron en la especificación (`/speckit-clarify`) y contra el
código existente de spec 006/026 — no quedan `NEEDS CLARIFICATION`. Este documento consolida las decisiones
técnicas de cómo implementarlas.

## 1. Campo destino "Id" — marca nueva en `DefinicionCamposImportables`

- **Decisión**: agregar `'id' => ['etiqueta' => 'Id', 'obligatorio' => false, 'id' => true]` a `clientes()`,
  `proveedores()` y `productos()` — mismo patrón que las marcas ya existentes (`'numerico'`, `'fecha'`,
  `'booleano'`, `'fk'`). No es un campo `fillable` de ningún modelo: su tratamiento en `mapearFila()` es
  especial (resuelve el registro a actualizar), no un valor a persistir directamente.
- **Rationale**: consistente con el patrón de marcas ya usado por spec 026 — cero cambios en la vista de mapeo
  (itera `$definicion` dinámicamente).

## 2. Resolución de modo alta vs actualización en `mapearFila()`/`importar()`

- **Decisión**: en `mapearFila()`, cuando `$def['id'] === true`, guardar el valor crudo (trim) en
  `$datos['id']` sin castear todavía — no se procesa distinto del resto de los campos ahí. La decisión de
  alta/actualización se resuelve en `importar()`, después de `mapearFila()`, con esta lógica por fila:
  - `$datos['id']` ausente o `''` → **alta nueva** (comportamiento actual sin cambios, FR-005).
  - `$datos['id']` no vacío pero no `is_numeric` → **fila fallida**, motivo "Id \"{valor}\" no es un id válido"
    (FR-008) — corte temprano, sin llegar a `validarFila()`.
  - `$datos['id']` numérico → buscar el registro (`Cliente::find($id)`/`Proveedor::find($id)`/
    `Producto::find($id)`). Si no existe → **fila fallida**, motivo "Id {valor} no encontrado" (FR-004). Si
    existe → **actualización** (ver §3).
- **Rationale**: mantiene `mapearFila()` simple (sigue sólo "mapeando", no decide flujo de negocio) y concentra
  la lógica de alta/actualización en `importar()`, que ya es el método que orquesta el loop por fila y decide
  qué hacer con el resultado de `validarFila()`.
- **Alternativas consideradas**: usar la regla `exists:tabla,id` de Laravel para que `validarFila()` reporte el
  "no encontrado" igual que cualquier otro campo inválido — descartado porque el resultado de la búsqueda
  (el propio registro) hace falta de todos modos para el paso siguiente (actualización parcial + `ignore($id)`
  en las reglas de unicidad), así que buscarlo una sola vez en `importar()` evita una segunda consulta redundante
  dentro de `validarFila()`.

## 3. Actualización parcial + reglas relajadas para filas de actualización

- **Decisión**: al confirmar que una fila es de actualización (registro encontrado), antes de validar:
  1. Quitar `id` de `$datos` (no es un campo a persistir).
  2. Construir un set de reglas de **actualización** distinto del de alta: se agrega un método nuevo
     `reglasActualizacion(?int $id): array` a cada adaptador `Reglas*Importacion` (`app/Http/Requests/Import/`),
     que llama al mismo `reglasCliente($id)`/`reglasProveedor($id)`/`reglasProducto($id)` de siempre (ya soportan
     un id opcional para `Rule::unique(...)->ignore($id)`/`SkuUnico($id, $id)` — FR-011) y luego reemplaza
     `'required'` por `'nullable'` en los campos que lo tuvieran (`nombre`, `tipo` en Producto) — FR-006. Las
     reglas compartidas (`ReglasCliente`/`ReglasProveedor`/`ReglasProducto`, usadas también por el alta/edición
     manual) **no se tocan** — el ajuste vive sólo en el adaptador de importación.
  3. Validar `$datos` (ya sin `id`, sin los campos no mapeados de esa fila) contra ese set de reglas. Como
     `$datos` sólo contiene las claves efectivamente mapeadas con valor no vacío en esa fila (comportamiento ya
     vigente de `mapearFila()`: una celda vacía nunca agrega la clave a `$datos`, salvo default), la validación
     ya es parcial "gratis" — no hace falta lógica extra para FR-003.
  4. Si válida: `$registro->update($datos)` (actualización parcial real — Eloquent sólo persiste las columnas
     presentes en `$datos`; el resto del registro queda intacto, FR-003).
- **Rationale**: reutiliza al máximo el mecanismo ya existente (`Reglas*Importacion` como adaptador,
  `validarFila()`/`construirReglas()` ya presentes) — el único código genuinamente nuevo es la relajación de
  `required→nullable` y el `update()` en vez de `create()`.
- **Alternativas consideradas**: relajar `required` directamente en `ReglasCliente`/`ReglasProveedor`/
  `ReglasProducto` compartidas (ej. con un parámetro `$esActualizacionParcial`) — descartado por el plan.md
  Constraint de no tocar esas reglas compartidas con el alta/edición manual real (que sí deben seguir exigiendo
  Nombre siempre), evitando el riesgo de que un cambio ahí afecte sin querer el formulario manual.

## 4. Costo de precomputar reglas dos veces (alta vs actualización) por importación

- **Decisión**: `construirReglas()` (ya existente) se sigue usando tal cual para las filas de alta (una sola vez
  por importación, sin cambios). Para las filas de actualización, se construye el set de reglas de actualización
  **una vez por fila de actualización** (no una vez por importación), porque `ignore($id)` varía por fila —
  ver §3. El resto de las filas (alta, mayoría típica de un archivo) no paga ese costo extra.
- **Rationale**: el volumen esperado de filas de actualización por corrida es bajo (corrección puntual post
  importación masiva, no una recarga completa) — el costo de reconstruir un array de reglas en PHP por fila es
  despreciable comparado con el `SELECT`/`UPDATE` que ya implica cada actualización.

## 5. Resto del mecanismo (FK-por-nombre, defaults, advertencias) — sin cambios

`precargarCatalogosFk()`, la resolución de campos FK-por-nombre dentro de `mapearFila()`, los defaults de columna
(`tipo = 'producto'`) y el mecanismo de advertencias (Proveedor/Categoría/Lista de Precios no encontrado) no
cambian: aplican igual en filas de alta y de actualización, con la salvedad ya cubierta por FR-003 (si el campo FK
no está mapeado o la celda está vacía en una fila de actualización, no se agrega a `$datos`, así que el valor FK
existente del registro no se toca — comportamiento ya garantizado por cómo `mapearFila()` arma `$datos` hoy, sin
código nuevo).
