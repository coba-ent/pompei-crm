# Data Model: Monitoreo, Punto de Reposición y Notificaciones

**Feature**: 073-monitoreo-punto-reposicion | **Fecha**: 2026-08-21

Sólo se describe lo que cambia. Todo lo demás que el panel consulta
(`ml_publicacion_producto`, `ml_configuracion`, `ml_ordenes`, `stocks`, `movimientos_stock`,
`ventas`, `depositos`) se lee tal cual está y **no se modifica**.

---

## 1. `productos` — columna nueva

| Campo | Tipo | Notas |
|---|---|---|
| `punto_reposicion` | `unsignedInteger`, nullable, default `null` | Cantidad mínima deseada del producto. `null` o `0` → el producto **no se controla** y nunca genera alerta ni notificación (FR-011a). Sólo aplica a `tipo = 'producto'` y `activo = true` |

- Se agrega a `$fillable` y a `$casts` (`'punto_reposicion' => 'integer'`) en `App\Models\Producto`.
- Validación: `nullable|integer|min:0` (FR-004). Un valor negativo o no numérico se rechaza con
  error de validación en JSON, que el modal muestra sin recargar.
- **No** se agrega índice sobre esta columna — ver `research.md` Decisión 5.

### Estado derivado (no se almacena)

```
enPuntoDeReposicion(producto, deposito) :=
       producto.tipo = 'producto'
   AND producto.activo = true
   AND producto.punto_reposicion IS NOT NULL
   AND producto.punto_reposicion > 0
   AND COALESCE(stock(producto, deposito), 0) <= producto.punto_reposicion
```

Se evalúa con **dos depósitos distintos**, y son dos listas distintas del panel:

| Bloque del panel | Depósito | Pregunta que responde |
|---|---|---|
| **A reponer** (FR-018) | **Local** (id 5). Todo el catálogo, publicado o no | ¿Le compro al proveedor / traigo de Full? |
| **Riesgo de stock publicable** (FR-019) | **Local + Full** (5 + 6), sólo productos publicados en ML | ¿Se me cae la publicación? |

> **Ojo** (verificado contra la base en `/speckit-analyze`): `ml_configuracion.deposito_id = 5`, que
> **es** el depósito Local. Definir el segundo control "contra el depósito de Mercado Libre" habría
> producido la misma lista que el primero, apenas filtrada por "publicado en ML". Lo que distingue
> de verdad a los dos bloques es **Full**: un producto con 1 en Local y 50 en Full hay que reponerlo,
> pero su publicación no corre ningún riesgo.

El bloque de riesgo ML suma dos columnas derivadas que ya se calculan hoy en el panel y se
conservan: `porDia` (unidades vendidas por día en los últimos 14 días, desde `movimientos_stock`
con `origen_type LIKE '%Venta'`) y `dias` (stock ÷ `porDia`), usada para ordenar por urgencia real.

---

## 2. `notificaciones_leidas` — tabla nueva

Única parte persistida de las notificaciones. No guarda el contenido del aviso: sólo que un usuario
ya vio un episodio determinado.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | `bigint` PK | |
| `user_id` | FK → `users`, `cascadeOnDelete` | de quién es la lectura |
| `clave` | `string(190)` | identificador del **episodio** (ver abajo) |
| `leida_en` | `timestamp` | cuándo la marcó |

- Único por `(user_id, clave)`. Índice adicional por `user_id` para el conteo.
- `string(190)` y no 255: el índice único compuesto sobre `utf8mb4` no entra en 255 en MySQL con
  índices de 3072 bytes si se combina con el `user_id`; 190 es el largo seguro de siempre.

### Formato de `clave`

| Alerta | Clave |
|---|---|
| Producto en punto de reposición | `reposicion:{producto_id}` |
| Publicación ML que no actualiza stock | `ml_stock:{ml_item_id}` |

**El episodio es implícito, no va en la clave.** Lo que hace que una alerta resuelta y vuelta a
aparecer cuente de nuevo como no leída (FR-035, historia 5 escenario 6) es el **borrado de la marca
de lectura** cuando la condición deja de cumplirse: al reponerse el stock la fila se borra, así que
cuando el producto vuelve a caer no hay marca y la notificación nace no leída.

> **Por qué no lleva timestamp** (corregido en `/speckit-analyze`): la versión anterior armaba la
> clave como `reposicion:{producto_id}:{MAX(movimientos_stock.created_at)}`. Eso tenía un defecto
> grave: **cada venta del producto cambia ese timestamp**, así que un producto que se mantiene por
> debajo de su punto de reposición volvería a alertar como no leído en cada venta — justo los
> productos que más rotan serían los más molestos, y el usuario terminaría ignorando la campanita.
> Sin timestamp, la marca sobrevive mientras el problema sea el mismo y muere cuando se resuelve,
> que es exactamente la semántica que la spec pide.
>
> **Riesgo residual asumido**: si el producto sube por encima de su punto y vuelve a caer **entre
> dos consultas del mismo usuario**, la limpieza no llegó a correr y la alerta le sigue figurando
> como leída. Es una ventana corta (5 minutos con la pestaña abierta) y el costo de errarle es que
> un aviso no se resalte; el costo del diseño anterior era re-alertar en cada venta.

### Ciclo de vida

1. **Nace** cuando un usuario marca una notificación como leída.
2. **Vive** mientras su clave siga apareciendo en el conjunto de alertas vigentes.
3. **Muere** de forma oportunista: en cada cálculo del resumen se borran las filas del usuario cuya
   clave no está en el conjunto vigente. Sin cron, sin política de retención.

---

## 3. `listas_precio` / `precios_producto` — eliminación de "Punto Reposición"

**No es un cambio de schema**: es una migración de datos que borra una fila de `listas_precio` (la
que hoy se llama "Punto Reposición") y sus filas en `precios_producto`.

**Precondición de borrado (FR-007)** — todas estas consultas deben devolver 0 filas:

| Tabla | Columna |
|---|---|
| `clientes` | `lista_precio_id` |
| `ventas` | `lista_precio_id` |
| `presupuestos` | `lista_precio_id` |
| `ml_configuracion` | `lista_precio_id`, `lista_precio_id_premium` |
| `tiendanube_configuracion` | `lista_precio_id` |
| `empresa` (configuración de ventas) | `lista_precio_id` |

Si alguna devuelve filas, el proceso **aborta e informa** cuáles. No hay modo forzado: lo que se
rompería del otro lado son precios de venta reales.

**Efecto colateral esperado y deseado**: el listado de Productos genera **una columna por cada lista
de precio activa** (subselect dinámico contra `precios_producto`). Al desaparecer la lista, esa
columna desaparece sola del listado y del export, sin tocar código — que es exactamente FR-006.

**Conversión de valores**: `precios_producto.precio` es `decimal(14,2)`. Al migrar:

| Caso | Resultado |
|---|---|
| Valor entero ≥ 0 | Se copia tal cual |
| Valor con decimales | Se redondea al entero más cercano y se cuenta en el resumen |
| Valor negativo o nulo | El producto queda sin punto de reposición y se cuenta como "no interpretable" |
| Producto sin fila en la lista | Queda `null` — no se inventa un default |

---

## 4. `permisos` — dos filas nuevas

| `codigo` | `modulo` | `descripcion` |
|---|---|---|
| `monitoreo.ver` | `monitoreo` | Ver el panel de Monitoreo, su indicador en la barra superior y sus notificaciones |
| `monitoreo.gestionar` | `monitoreo` | Destrabar y reactivar publicaciones, forzar sincronizaciones y editar el punto de reposición desde el panel |

Se agregan al catálogo de `PermisoSeeder`. `RolSeeder` sincroniza Admin con todos los permisos
existentes, así que Admin los recibe sin tocar ese archivo; Vendedor y Contable listan los suyos de
forma explícita y no los reciben (FR-013a).

---

## 5. Lo que se elimina del código

| Qué | Dónde | Por qué |
|---|---|---|
| `MonitoreoController::UMBRAL_STOCK_BAJO = 3` | controlador de monitoreo | Reemplazado por `productos.punto_reposicion` (FR-011) |
| `MonitoreoController::datos()` (respuesta monolítica) | controlador de monitoreo | Reemplazado por endpoints por bloque (research Decisión 4) |
| Contenido de demostración de la campanita | `resources/views/elements/header.blade.php` | Reemplazado por datos reales (FR-030) |
| Clase `d-none` de la campanita | `resources/views/elements/header.blade.php` | La campanita se activa |

`DIAS_VELOCIDAD = 14` y `MINUTOS_SIN_SYNC = 15` **se conservan**: no son umbrales inventados sobre
datos del negocio, son parámetros de presentación y de monitoreo de la integración (la spec ratifica
los 15 minutos en Assumptions).
