# Data Model — Importador de Datos: Campos Completos

Sin tablas ni columnas nuevas. Todos los campos de esta feature ya existen en `clientes`, `proveedores` y
`productos` (ver `app/Models/Cliente.php`, `Proveedor.php`, `Producto.php`) — esta feature sólo amplía el
diccionario `DefinicionCamposImportables` (paso 2 del asistente, spec 006) y agrega dos capacidades de
parseo por fila en `ImportadorFilas` (research.md §1-2).

## Diccionario de campos importables — ampliación por entidad

### Clientes (`DefinicionCamposImportables::clientes()`)

Campos ya existentes (spec 006): sin cambios. Se agregan:

| Campo destino (etiqueta) | Columna del modelo | Tipo de parseo | Obligatorio | Resolución |
|---|---|---|---|---|
| Razón Social | `razon_social` | texto | No | — |
| Tipo de Documento | `tipo_documento` | texto | No | valor literal, sin catálogo (research.md §4) |
| Domicilio Fiscal | `domicilio_fiscal` | texto | No | — |
| Localidad Fiscal | `localidad_fiscal` | texto | No | — |
| Provincia Fiscal | `provincia_fiscal` | texto | No | — |
| Código Postal Fiscal | `cp_fiscal` | texto | No | — |
| Teléfono Fiscal | `telefono_fiscal` | texto | No | — |
| Teléfono Celular Fiscal | `telefono_celular_fiscal` | texto | No | — |
| Código Postal | `cp` | texto | No | — |
| Saldo Inicial | `saldo_inicial` | numérico | No | `normalizarNumero()` ya existente |
| Fecha de Saldo Inicial | `saldo_inicial_fecha` | fecha | No | `normalizarFecha()` nueva (research.md §1) |
| Nota para Ventas | `nota_cliente` | texto | No | — |
| Descuento General | `descuento_general_pct` | numérico | No | `normalizarNumero()` ya existente |
| Lista de Precios | `lista_precio_id` | fk-por-nombre | No | lookup contra `ListaPrecio` (research.md §3) |
| Usuario de Mercado Libre | `apodo_ml` | texto | No | — |
| Página Web | `pagina_web` | texto | No | — |

### Proveedores (`DefinicionCamposImportables::proveedores()`)

Mismo bloque agregado que Clientes, **excepto** (campos que no existen en `Proveedor`): Usuario de
Mercado Libre (`apodo_ml`), Nota para Ventas (`nota_cliente` — Proveedor sólo tiene `nota`/`nota_interna`,
ya expuestos desde spec 006), Descuento General (`descuento_general_pct`), Lista de Precios
(`lista_precio_id`).

### Productos & Servicios (`DefinicionCamposImportables::productos()`)

Campos ya existentes (spec 006): sin cambios. Se agregan:

| Campo destino (etiqueta) | Columna del modelo | Tipo de parseo | Obligatorio | Default si celda vacía |
|---|---|---|---|---|
| Activo | `activo` | booleano | No | default de columna (`true`) |
| Mostrar en Ventas | `mostrar_en_ventas` | booleano | No | default de columna (`true`) |
| Mostrar en Compras | `mostrar_en_compras` | booleano | No | default de columna (`true`) |

## Marcas nuevas en la definición de campo (`DefinicionCamposImportables`)

Se agregan dos claves opcionales al array de definición de cada campo, análogas a la ya existente
`'numerico' => true`:

- `'fecha' => true`: el valor de la celda se procesa con `normalizarFecha()` antes de guardarse; si no
  matchea ningún formato aceptado, la fila se marca fallida.
- `'booleano' => true`: el valor de la celda se procesa con `normalizarBooleano()`; si no matchea ningún
  valor reconocido, la fila se marca fallida; si la celda está vacía, el campo no se incluye en el payload
  de creación (aplica el default de columna).

## Reglas de validación (`ImportadorFilas::construirReglas()`)

| Regla | Origen | Dónde se aplica |
|---|---|---|
| Campo marcado `'fecha' => true` sin regla explícita → `nullable\|date` | FR-005 | `construirReglas()`, mismo patrón que `$esNumericoDinamico` |
| Campo marcado `'booleano' => true` sin regla explícita → `nullable\|boolean` (sobre el valor ya normalizado a `true`/`false`/`null`) | FR-008 | `construirReglas()` + `mapearFila()` |
| `lista_precio_id` se resuelve por nombre, advertencia (no fila fallida) si no matchea | FR-004 | `mapearFila()`, mismo mecanismo `fk` ya existente |
| `tipo_documento` sin validación de catálogo, valor literal | Clarify 2026-07-31 | `mapearFila()`, tratado como campo de texto simple |

## Estado transitorio del asistente

Sin cambios respecto a spec 006 (`specs/006-importar-datos-excel/data-model.md` §"Estado transitorio del
asistente") — el archivo subido, el mapeo de columnas y el resultado de la importación siguen siendo
estado transitorio no persistido.
