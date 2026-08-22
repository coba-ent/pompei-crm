# Research: Robustez del importador de Productos

**Feature**: 074-robustez-importacion-productos | **Fecha**: 2026-08-22

Relevamiento del código vigente y decisiones técnicas previas al diseño. Todas las
`NEEDS CLARIFICATION` del Technical Context quedan resueltas acá.

---

## Estado actual relevado

### Camino de stock por importación

`ImportadorFilas::actualizarProducto()` (`app/Services/Import/ImportadorFilas.php:778-795`):

```php
$actual = $this->stockService->disponibilidad($producto, null, $deposito);  // SELECT sin lock
$diferencia = $cantidadDeseada - $actual;
if ($diferencia === 0.0) { continue; }
$this->stockService->ajustar($producto, null, $deposito, $diferencia, 'Ajuste (importación)', $usuario);
```

`StockService::ajustar()` (`app/Services/Stock/StockService.php:27-62`) **sí** es atómico: abre
`DB::transaction()` y hace `Stock::lockForUpdate()->firstOrNew(...)`. El problema no está ahí: está en
que `disponibilidad()` (línea 193) es un `SELECT` suelto, **fuera** de esa transacción. Entre la lectura
y la escritura hay una ventana en la que cualquier otra operación (`Venta`, `Compra`, otro ajuste) puede
mover el mismo `(producto_id, variante_id, deposito_id)`, y el delta calculado queda obsoleto → lost
update.

La ventana es material: `ImportacionController::FILAS_POR_LOTE = 1000` y el archivo se procesa en varias
requests sucesivas; una planilla grande tarda minutos con el negocio operando.

### Caminos de escritura sobre `precios_producto`

| # | Origen | Ubicación | Método | ¿Dispara eventos de modelo? |
|---|---|---|---|---|
| 1 | Importación — alta | `ImportadorFilas.php:731` | `precios()->create()` | ✅ `created` |
| 2 | Importación — actualización | `ImportadorFilas.php:772` | `precios()->updateOrCreate()` | ✅ `created`/`updated` |
| 3 | Alta/edición manual (modal) | `ProductoController.php:773` | `precios()->updateOrCreate()` | ✅ `created`/`updated` |
| 4 | Alta/edición manual — quitar precio | `ProductoController.php:780` | `precios()->whereNotIn(...)->delete()` | ❌ **mass delete, NO dispara** |
| 5 | **Edición masiva de precios/costos** | `ProductoController.php:569` (`accionAjustarPrecios`) | `precios()->updateOrCreate()` | ✅ `created`/`updated` |
| 6 | Copia de producto | `ProductoController.php:429` | `precios()->create()` | ✅ `created` |
| 7 | Comando de migración | `MigrarPuntoReposicion.php:104` | `DB::table(...)->delete()` | ❌ **query builder crudo, NO dispara** |

Ya existe `PrecioProductoObserver` (`app/Observers/PrecioProductoObserver.php`), registrado en
`AppServiceProvider:64`, documentado como *"único punto por el que pasa cualquier escritura sobre
`precios_producto` … sin importar el camino que la originó (modal de Producto, importación masiva)"*.
Hoy lo usa el push de precios hacia Mercado Libre y Tiendanube.

El relevamiento **corrige parcialmente esa afirmación**: es único para las escrituras que pasan por el
modelo (filas 1, 2, 3, 5, 6), pero **no** captura los dos borrados que van por query builder (4 y 7).

### Mecanismo de auditoría existente (spec 054)

- `LogAuditoria` (`logs_auditoria`): registro inmutable, sin `updated_at`, sólo INSERT.
- `AuditoriaService::registrarEvento()`: punto único de escritura, envuelto en `try/catch` — nunca lanza
  excepción, loguea en `storage/logs` y sigue.
- `tipo_operacion` es un **enum cerrado** en la migración
  `2026_08_07_155244_create_logs_auditoria_table.php`: `venta`, `presupuesto`, `cobro`, `gasto`,
  `compra`, `movimiento_tesoreria`, `movimiento_stock`. **No hay valor para cambios de precio.**
- `tipo_accion`: `creo`, `modifico`, `elimino`, `anulo`.
- `AuditoriaController::LABELS_OPERACION` alimenta a la vez el `<select>` del filtro
  (`resources/views/auditoria/index.blade.php:36-38`) y la columna "Operación" del DataTable.
- `AuditoriaController::detalle()` resuelve el modal con un `match` sobre `entidad_tipo` con
  `default => null`: un tipo nuevo no rompe nada, simplemente muestra el detalle de texto plano.

---

## Decisiones

### D1 — `StockService::fijar()`: nueva operación de "stock a valor absoluto"

**Decisión**: agregar a `StockService` un método que reciba la **cantidad deseada** (no un delta) y haga
lectura, cálculo y escritura dentro de una única `DB::transaction()` con `lockForUpdate()`. El importador
lo usa en lugar de `disponibilidad()` + `ajustar()`.

Firma propuesta (contrato completo en `contracts/stock-service-fijar.md`):

```php
public function fijar(
    Producto $producto,
    ?ProductoVariante $variante,
    Deposito $deposito,
    float $cantidadDeseada,
    ?string $descripcion = null,
    ?User $usuario = null,
): float
```

Devuelve la cantidad resultante. Si la cantidad deseada ya coincide con la actual, **no** crea
`MovimientoStock` (FR-004) y devuelve la cantidad sin tocar nada.

**Rationale**: mueve la decisión "cuánto ajustar" adentro del mismo lock que ya protege la escritura, que
es exactamente el invariante que hoy falta. Es la corrección mínima y deja la capacidad reutilizable para
cualquier futuro importador o corrección masiva de stock, en lugar de parchear el importador.

**Alternativas descartadas**:
- *Envolver el bloque del importador en su propia transacción con un `SELECT ... FOR UPDATE` a mano*:
  duplica lógica de bloqueo fuera del servicio de stock y deja el mismo error latente para el próximo
  llamador que haga read-then-write.
- *Bloquear la fila de `productos` en vez de la de `stocks`*: bloquea de más (todos los depósitos) y no
  es el recurso que realmente se está modificando.
- *Reintento optimista con comparación de versión*: más complejo y con peor comportamiento bajo la
  contención real de una importación masiva que un lock pesimista corto.

**Nota heredada (no se corrige acá)**: `ajustar()` usa `Stock::lockForUpdate()->firstOrNew(...)`; cuando
la fila **todavía no existe** no hay nada que bloquear. La unicidad de
`(producto_id, variante_id, deposito_id)` en `stocks` protege contra la doble inserción. `fijar()` hereda
ese mismo comportamiento; no se cambia en esta spec.

### D2 — La auditoría de precios va en el Observer, no en cada call site

**Decisión**: implementar el registro de auditoría dentro de `PrecioProductoObserver`, agregando los
hooks `saved()` (ya existe, se extiende) y `deleted()`.

**Rationale**: cubre de una sola vez los 5 caminos que pasan por el modelo — incluida la **edición
masiva** (`accionAjustarPrecios`), que es el origen de mayor riesgo del sistema y el que el pedido
original no contemplaba. Auditar en cada call site significaría 5 implementaciones y la garantía de que
el sexto camino que alguien agregue en el futuro nazca sin auditar.

**Alternativas descartadas**:
- *Auditar en cada punto de escritura*: duplicación y fragilidad ante caminos nuevos.
- *Trigger de base de datos*: fuera de las convenciones del proyecto (Principio V), invisible desde el
  código, y no puede resolver el usuario autenticado ni el origen.

### D3 — Precio anterior y detección de "cambio real"

**Decisión**: dentro de `saved()` usar el estado del modelo Eloquent:

| Situación | Cómo se detecta | Acción auditada | Precio anterior |
|---|---|---|---|
| Precio nuevo | `wasRecentlyCreated === true` | `creo` | ninguno |
| Precio modificado | `wasRecentlyCreated === false` y `wasChanged('precio')` | `modifico` | `getOriginal('precio')` |
| Guardado sin cambio | `wasChanged('precio') === false` | — | no se registra nada (FR-010) |
| Precio eliminado | hook `deleted()` | `elimino` | `precio` del modelo borrado |

**Rationale**: `wasChanged()` compara contra el valor previo al `save()` y es exactamente la semántica de
FR-010 (no ensuciar la auditoría cuando se reimporta una planilla sin cambios). Evita una consulta extra
para leer el valor anterior.

**Cuidado con el cast**: `precios_producto.precio` es `decimal(14,2)`. La comparación debe hacerse sobre
el valor normalizado a 2 decimales para que `100` vs `100.00` no se registre como cambio.

### D4 — Cómo sabe el Observer de dónde vino el cambio

**Decisión**: un contexto explícito de origen, `App\Support\OrigenCambioPrecio`, con un valor por request
que cada punto de entrada declara antes de escribir precios, y que el Observer lee:

```php
OrigenCambioPrecio::durante(OrigenCambioPrecio::IMPORTACION, function () { /* ... */ });
```

Orígenes: `importacion`, `manual`, `edicion_masiva`, `copia`, y `desconocido` como **valor por defecto**.

**Rationale**: el Observer, por definición, no sabe quién lo llamó. Un contexto explícito mantiene el
punto único de auditoría (D2) sin que el Observer tenga que inspeccionar la request ni el stack. El
default `desconocido` es deliberado: si mañana aparece un camino de escritura nuevo que no declara su
origen, **igual queda auditado** (con origen desconocido) en lugar de pasar inadvertido — falla hacia el
lado seguro.

**Alternativas descartadas**:
- *Inferir el origen de la ruta/request actual*: frágil, y no distingue edición manual de edición masiva
  (ambas viven en `ProductoController`); además no funciona en comandos de consola.
- *Pasar el origen como parámetro hasta el Observer*: los eventos de Eloquent no aceptan parámetros
  adicionales; obligaría a abandonar el Observer.
- *Usar la fachada `Context` de Laravel*: sirve, pero es de propósito general y propaga a colas; una
  clase chica de dominio en español es más explícita y alineada con el Principio V.

### D5 — El borrado de precios debe pasar a borrado por modelo

**Decisión**: cambiar `ProductoController::sincronizarPrecios()` (línea 780) de mass delete a borrado por
modelo, para que el hook `deleted()` se dispare por cada precio quitado:

```php
// antes: $producto->precios()->whereNotIn('lista_precio_id', $listasRecibidas)->delete();
// después: recuperar los modelos y borrarlos uno por uno
```

**Rationale**: `$relacion->whereNotIn(...)->delete()` es un `DELETE` de query builder: **no instancia
modelos y no dispara eventos**. Sin este cambio, US1 escenario 4 (quitar un precio desde la ficha) queda
sin auditar y el gap sigue abierto justo en el caso más destructivo (pérdida total del precio, no un
cambio de valor).

**Costo**: N consultas en vez de una, donde N = listas de precio que se quitan en ese guardado — como
mucho la cantidad de listas activas del negocio (unidades). Irrelevante frente al beneficio.

**Nota**: este cambio también hace que esos borrados pasen por el push a Mercado Libre/Tiendanube que ya
vive en el Observer, algo que hoy **no** ocurre. Es un efecto secundario correcto pero real: debe
verificarse que la rama de integraciones tolere un precio borrado (ver tarea de verificación en
`tasks.md`); FR-017 exige que la sincronización existente no se rompa.

**No se corrige** el caso 7 (`MigrarPuntoReposicion`, `DB::table()->delete()`): es un comando de
migración de única vez, fuera de alcance por FR-009a — se documenta como excepción.

### D6 — Nuevo valor de enum `precio_producto`

**Decisión**: migración que agrega `precio_producto` al enum `logs_auditoria.tipo_operacion`, más la
entrada correspondiente en `AuditoriaController::LABELS_OPERACION`.

**Rationale**: es el único cambio de esquema de la feature. Agregar la etiqueta al `const` existente
resuelve simultáneamente el `<select>` del filtro y la columna "Operación" del DataTable (FR-011), sin
tocar la vista.

**Antecedente en el proyecto**: la spec 055 ya agregó el valor `ingreso` al enum
`movimientos_tesoreria.tipo` — mismo patrón de `ALTER TABLE ... MODIFY`, con el enum completo repetido en
`up()` y en `down()`.

**Obligación de Principio I**: este cambio de modelo de datos exige actualizar `docs/modelo_datos.md`
(sección `logs_auditoria`) y `docs/documentacion_principal_crm.md` **antes de `/speckit-tasks`**.

### D7 — Volumen de auditoría durante la importación

**Decisión**: `AuditoriaService` gana un modo de buffer opcional (`iniciarBuffer()` / `vaciarBuffer()`)
que acumula eventos en memoria y los escribe con un `INSERT` múltiple. El importador lo activa alrededor
de cada tanda y lo vacía al terminarla; todo el resto de la aplicación sigue escribiendo evento por
evento, sin cambios.

**Cálculo del peor caso**: 1.000 filas por tanda × listas de precio activas (unidades) ⇒ del orden de
3.000-5.000 eventos por tanda. Con `INSERT` fila por fila eso son miles de round-trips dentro de una
request que ya opera contra un corte de ~60 s del proxy. Agrupados quedan en unas pocas consultas.

**Rationale**: preserva el punto único de escritura de auditoría y el contrato de "nunca lanza excepción",
resolviendo el único riesgo de performance que introduce la feature (SC-005).

**Ventana de pérdida aceptada**: si el proceso muere en medio de una tanda, los eventos todavía
buffereados de esa tanda se pierden aunque los precios sí se hayan guardado. Es aceptable y coherente con
el principio ya vigente de spec 054 ("la auditoría documenta, no gatea"). Se acota vaciando el buffer
también cada 200 eventos, no sólo al final de la tanda.

**Alternativas descartadas**:
- *Escribir evento por evento siempre*: más simple, pero es el escenario que SC-005 pide evitar.
- *Mandar la auditoría a una cola*: agrega infraestructura y una dependencia operativa (worker vivo) para
  un registro que debe existir sí o sí; además complica el orden de los eventos.

### D8 — `entidad_tipo` / `entidad_id` apuntan al Producto

**Decisión**: `entidad_tipo = App\Models\Producto::class`, `entidad_id = producto_id` (no el id de la
fila de `precios_producto`).

**Rationale**: `logs_auditoria` tiene índice por `(entidad_tipo, entidad_id)`. Apuntando al producto,
"todo el historial de precios del producto X" es una consulta indexada directa — que es la pregunta real
que el usuario quiere responder. El id de la fila de precio no le sirve a nadie y además desaparece si el
precio se borra y se vuelve a crear.

### D9 — Contenido del evento

**Decisión**:

- `tipo_operacion`: `precio_producto`
- `tipo_accion`: `creo` | `modifico` | `elimino`
- `total`: el **precio nuevo** (en un borrado, `null`)
- `detalle`: texto legible, recortado al límite de 255 caracteres de la columna, con la forma:
  `"{Producto} — {Lista}: {anterior} → {nuevo} ({origen})"`, p. ej.
  `"Caño PVC 110mm — Mayorista: $ 7.137,04 → $ 8.564,45 (importación)"`.
  En un alta: `"… — Mayorista: sin precio → $ 8.564,45 (edición masiva)"`.

**Rationale**: `total` es la columna que el DataTable de Auditoría ya muestra como monto; el precio nuevo
es el valor "resultante" de la operación, coherente con cómo lo usan los demás tipos. El precio anterior
no tiene columna propia — va en `detalle`, igual que el resto del contexto de los otros eventos
auditados (FR-007 pide que sea recuperable, no que tenga columna dedicada). El rótulo de origen entre
paréntesis satisface FR-008 sin tocar el esquema.

**Nombre del producto**: se toma en el momento del evento y queda congelado en el texto, igual que
`usuario_nombre` — el registro sobrevive a un renombre posterior del producto.

---

## Technical Context resuelto

| Ítem | Valor |
|---|---|
| Lenguaje | PHP 8.2 / Laravel 12 |
| Dependencias | Eloquent (Observers, transacciones), Maatwebsite Excel (ya en uso, sin cambios) |
| Almacenamiento | MySQL — `stocks`, `movimientos_stock`, `precios_producto`, `logs_auditoria` |
| Testing | PHPUnit (`tests/Feature`, `tests/Unit`) |
| Plataforma | Aplicación web Laravel single-tenant |
| Objetivo de performance | Tanda de 1.000 filas dentro del margen actual del asistente (corte de proxy ~60 s) |
| Restricciones | La auditoría nunca aborta la operación; sin cambios en la UI del asistente |
| Escala | Planillas de miles de productos; unidades de listas de precio y depósitos activos |
