# Research: Vinculación múltiple Producto ↔ Publicaciones (ML y Tiendanube)

Sin unknowns marcados como `NEEDS CLARIFICATION` en el Technical Context del plan — el feature es un
cambio de cardinalidad sobre infraestructura ya existente y bien entendida (specs 012/013/016/017/018/
021/023/024). Este documento consolida las decisiones de research derivadas de leer el código actual.

## R1 — Cómo quitar la restricción 1:1 sin romper la unicidad por publicación/variante

**Decisión**: migración que hace `dropUnique` sobre el índice único sacado por `->unique()` en la
columna `producto_id` de `ml_publicacion_producto` y `tn_variante_producto`, dejando intacto el
`->unique()` sobre `ml_item_id` / `variant_id` (columna distinta, índice distinto).

**Rationale**: Laravel genera un nombre de índice predecible (`{tabla}_{columna}_unique`) cuando se
usa `$table->foreignId('producto_id')->unique()->constrained(...)` en la migración original — se puede
apuntar a ese nombre exacto (`ml_publicacion_producto_producto_id_unique`,
`tn_variante_producto_producto_id_unique`) sin tocar la FK en sí (sólo se cae el índice único, la
`foreignId`/constraint de integridad referencial permanece).

**Alternatives considered**: recrear la tabla completa (descartado, innecesariamente disruptivo para
una migración que sólo cambia un índice); usar una tabla pivote nueva N:N en vez de 1:N (descartado —
la spec no requiere N:N, cada publicación/variante sigue perteneciendo a un único producto; 1:N con
FK simple alcanza y es coherente con cómo ya está modelado).

## R2 — Dónde cambiar el rechazo "ya_vinculado" en los vinculadores automáticos

**Decisión**: en `MercadoLibre\VinculadorAutomatico::procesar()` (líneas 174-176 actuales) y en
`Tiendanube\VinculadorAutomatico::procesar()` (líneas 140-142 actuales), eliminar el chequeo
`if (isset($productosVinculados[$producto->id])) { return [...'ya_vinculado'...]; }` — el índice
`$productosVinculados` deja de usarse como bloqueo y pasa a ser innecesario para ese propósito (se
puede eliminar esa estructura si no se usa para otra cosa dentro del método).

**Rationale**: es el único punto de rechazo por producto duplicado en ambos servicios; su eliminación
es suficiente y no requiere tocar `recorrerCatalogo()`/`detalleDePublicaciones()` (que ya excluyen
correctamente `closed`/variantes de ML antes de llegar a `procesar()`).

**Alternatives considered**: mantener un límite configurable de "máximo N vínculos por producto"
(descartado — la spec no lo pide, agrega complejidad sin requisito que la sostenga, viola YAGNI).

## R3 — Cómo hacer que los observers marquen todos los vínculos, no sólo el primero

**Decisión**: en `MovimientoStockObserver::ramaMercadoLibre()`/`ramaTiendanube()` y en
`PrecioProductoObserver::ramaMercadoLibre()`/`ramaTiendanube()`, cambiar
`::where('producto_id', $x)->first()` por `::where('producto_id', $x)->get()` y envolver la lógica
existente (chequeo de null/vacío + `update`/`enviarUno`) en un `foreach`.

**Rationale**: cambio mínimo y localizado — la lógica de "qué hacer con un vínculo" no cambia, sólo
pasa de aplicarse a 0-o-1 vínculo a aplicarse a 0-o-N. `PrecioProductoObserver` ya despacha
`enviarUno($vinculo, $precio)` por vínculo individual (nombre del método ya sugiere 1 vínculo a la
vez) — se llama una vez por cada vínculo en el `foreach`, sin cambiar su firma.

**Alternatives considered**: agregar un método `enviarVarios()`/`marcarTodosPendientes()` a nivel de
colección (descartado por ahora — no hay otro caller que lo necesite; el `foreach` en el observer es
suficientemente simple y no justifica una nueva abstracción, YAGNI otra vez).

## R4 — `SincronizadorStock`/sincronizador de precios: ¿requieren cambios?

**Decisión**: No. `SincronizadorStock::procesarVinculos()` (ML) ya itera sobre una colección de
`MercadoLibrePublicacionProducto` obtenida vía `::pendientes()->with('producto')->get()` — no asume
0-o-1 vínculo por producto en ningún punto, procesa fila por fila. **Confirmado también para
Tiendanube** (`app/Services/Tiendanube/SincronizadorStock.php`): `procesarVinculos()` usa el mismo
patrón exacto, `TiendanubeVarianteProducto::pendientes()->with('producto')->get()` seguido de un
`foreach`, con `enviarUno()` por vínculo individual — no requiere ningún cambio.

**Rationale**: Confirmado por lectura directa de ambos archivos (`SincronizadorStock.php` de ML, spec
013/035, y de Tiendanube, spec 018/024/035) — el único cambio real de comportamiento ocurre "aguas
arriba", en qué vínculos quedan marcados como pendientes (R3), no en cómo se recorren.

**Alternatives considered**: N/A, no hay alternativa a evaluar — se confirma que no hace falta tocar
estos archivos.

## R5 — Impacto en pantallas existentes que listan vínculos

**Decisión**: se evalúa en Phase 1 (data-model.md) revisando las vistas que consultan
`MercadoLibrePublicacionProducto`/`TiendanubeVarianteProducto` por `producto_id`; si alguna asume
"a lo sumo un vínculo" (ej. `firstWhere`/relación `hasOne` en vez de `hasMany`), se ajusta a listar
todos. No se rediseña la pantalla, sólo se corrige el supuesto de cardinalidad si existe.

**Rationale**: FR-010 de la spec lo exige explícitamente. Se detalla en data-model.md tras confirmar
el tipo de relación declarada en los modelos Eloquent.
