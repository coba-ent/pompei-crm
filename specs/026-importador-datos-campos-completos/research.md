# Research — Importador de Datos: Campos Completos

Todas las incógnitas de Technical Context ya se resolvieron en la especificación (fase `/speckit-clarify`)
y contra el código existente de spec 006 — no quedan `NEEDS CLARIFICATION`. Este documento consolida las
decisiones técnicas de cómo implementarlas.

## 1. Parseo de fecha por fila (campo nuevo: `saldo_inicial_fecha`)

- **Decisión**: agregar un helper `normalizarFecha(mixed $valor): ?string` en `ImportadorFilas`, invocado
  cuando el campo destino tiene `'fecha' => true` en su definición (nueva marca en
  `DefinicionCamposImportables`, mismo patrón que `'numerico' => true`). Devuelve `Y-m-d` (formato que
  acepta el cast `date:Y-m-d` de `Cliente`/`Proveedor`) o `null` si no matchea ningún formato aceptado, en
  cuyo caso la fila se marca inválida vía la regla `date` de Laravel sobre el valor crudo (no sobre el ya
  normalizado) para que el mensaje de error sea claro.
- **Formatos aceptados** (clarify 2026-07-31): fecha nativa de Excel (PhpSpreadsheet ya la entrega como
  objeto `DateTime` o como número de serie según el driver — `maatwebsite/excel` con `Excel::toArray` la
  devuelve formateada como string `Y-m-d H:i:s` o como `Carbon`/`DateTime` dependiendo de la celda; se
  normaliza con `Carbon::parse()` cuando ya es un objeto fecha), texto `DD/MM/YYYY`
  (`Carbon::createFromFormat('d/m/Y', $valor)`), texto `YYYY-MM-DD` (`Carbon::createFromFormat('Y-m-d',
  $valor)`).
- **Rationale**: reutiliza `Carbon` (ya disponible vía Laravel), sin agregar dependencias. El intento de
  parseo prueba los 3 formatos en orden y usa el primero que no lance excepción; si ninguno matchea, la
  fila falla con motivo explícito ("Fecha de Saldo Inicial: valor no reconocido").
- **Alternativas consideradas**: aceptar cualquier formato vía `strtotime()` — descartado por ser
  demasiado permisivo/ambiguo (p.ej. confunde `01/02/2026` como enero o febrero según locale), lo que
  viola el criterio de "fallar claro" ya establecido en el edge case de la spec.

## 2. Parseo de booleano por fila (campos nuevos: `activo`, `mostrar_en_ventas`, `mostrar_en_compras`)

- **Decisión**: agregar un helper `normalizarBooleano(string $valor): ?bool` en `ImportadorFilas`,
  invocado cuando el campo destino tiene `'booleano' => true`. Normaliza el valor (minúsculas, sin
  acentos, trim) y matchea contra un mapa fijo: `si|1|true → true`, `no|0|false → false`; cualquier otro
  valor no vacío devuelve `null` y la fila se marca fallida.
- **Valores aceptados** (clarify 2026-07-31): `Si/No`, `1/0`, `true/false`, sin distinguir
  mayúsculas/acentos.
- **Default en celda vacía**: si la celda viene vacía, el campo no se agrega a `$datos` y
  `crearProducto()`/`Producto::create()` aplica el default de columna ya vigente en la migración/`
  $fillable` (Producto se crea con `activo`/`mostrar_en_ventas`/`mostrar_en_compras` en `true` por
  defecto igual que el alta manual) — mismo mecanismo que el default `tipo = 'producto'` ya implementado
  para FR-010 de spec 006.
- **Rationale**: patrón mínimo, sin librería externa; consistente con `normalizarNumero()` ya existente
  en la misma clase.

## 3. Campo `lista_precio_id` en Clientes — lookup por nombre

- **Decisión**: reutilizar tal cual el patrón `fk` ya existente en `DefinicionCamposImportables`
  (`precargarCatalogosFk` + resolución en `mapearFila` de `ImportadorFilas`), agregando
  `'lista_precio_id' => ['etiqueta' => 'Lista de Precios', 'obligatorio' => false, 'fk' => ['modelo' =>
  \App\Models\ListaPrecio::class]]` a `DefinicionCamposImportables::clientes()`. Sin scope (todas las
  listas activas, mismo criterio que Categoría).
- **Rationale**: cero código nuevo de resolución — es el mismo mecanismo que ya usan
  `condicion_iva_id`/`categoria_id`/`proveedor_id`/`tipo_producto_id`. La advertencia "no encontrado" sin
  bloquear la fila ya es el comportamiento por defecto de ese mecanismo (no hace falta condicional
  especial).

## 4. Campo `tipo_documento` — texto libre, sin catálogo

- **Decisión** (clarify 2026-07-31): se mapea como campo de texto simple (sin `fk`, sin `numerico`, sin
  `fecha`, sin `booleano`) — el valor de la celda se guarda tal cual, igual que `razon_social` o
  `domicilio_fiscal`. Si la columna no se mapea, no se agrega a `$datos` y Eloquent aplica el default de
  columna `'CUIT'` ya definido en la migración.
- **Rationale**: consistencia con el alta manual actual, que tampoco valida `tipo_documento` contra un
  catálogo fijo — introducir esa validación sólo en el importador crearía una divergencia de reglas entre
  las dos vías de alta, contra el principio de reutilizar las mismas reglas del alta manual ya establecido
  en spec 006 (FR-006).

## 5. Resto de campos nuevos (texto/numérico simple)

`razon_social`, `domicilio_fiscal`, `localidad_fiscal`, `provincia_fiscal`, `cp_fiscal`,
`telefono_fiscal`, `telefono_celular_fiscal`, `cp`, `nota_cliente`, `apodo_ml`, `pagina_web` no requieren
ninguna capacidad de parseo nueva — son texto simple, mismo tratamiento que `domicilio`/`localidad`/`nota`
ya existentes. `descuento_general_pct` y `saldo_inicial` son numéricos simples — mismo tratamiento que
`precio_venta`/`costo` ya existentes (acepta formato argentino vía `normalizarNumero()` ya implementado).

**Decisión**: agregar las entradas correspondientes a `DefinicionCamposImportables::clientes()` /
`::proveedores()` con la misma forma que los campos ya existentes; sin lógica nueva en `ImportadorFilas`
para estos.

## 6. Reglas de validación reutilizadas (`Reglas*Importacion`)

- **Decisión**: no se agregan reglas nuevas a `ReglasClienteImportacion`/`ReglasProveedorImportacion`/
  `ReglasProductoImportacion` — estas clases ya derivan sus reglas de las mismas usadas en el alta manual
  (`ReglasCliente`/`ReglasProveedor`/`ReglasProducto`), que ya cubren `razon_social`, `domicilio_fiscal`,
  etc. como campos opcionales de texto. Sólo hace falta que `ImportadorFilas::construirReglas()` siga
  agregando `nullable|date` para los campos marcados `'fecha' => true` y `nullable|boolean` para los
  marcados `'booleano' => true` que no tengan ya una regla explícita — mismo patrón que ya usa para
  `lista_precio_id`/`deposito_id` dinámicos (`$esNumericoDinamico`).
- **Rationale**: cero duplicación de reglas de validación; el importador sigue siendo un espejo del alta
  manual (principio ya establecido en spec 006 FR-006).
