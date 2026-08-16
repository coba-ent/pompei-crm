# Revisión pre-producción — Vinculaciones y sincronización de stock/precios (ML + Tiendanube)

Fecha: 2026-08-14. Alcance: `app/Services/MercadoLibre/*`, `app/Services/Tiendanube/*`,
`app/Observers/MovimientoStockObserver.php`, `PrecioProductoObserver.php`,
`app/Console/Commands/Sincronizar*`, `bootstrap/app.php` (scheduler),
`app/Services/Stock/StockService.php`, migraciones de `ml_publicacion_producto` /
`tn_variante_producto`.

No se modificó código. Todo lo de acá es diagnóstico + propuesta.

---

## Severidad ALTA — puede romper la operación diaria

### A1. Un 404 de Tiendanube tumba **toda** la conexión

`ClienteTiendanubeRest::ejecutarConReintentos()` (líneas 125-130) trata `401` y `404`
como el mismo caso: credencial rechazada → `estado = Caida` + `ultimo_error`.

```php
if ($codigo === 401 || $codigo === 404) {
    $mensaje = 'La credencial fue rechazada por Tiendanube. Volvé a conectar.';
    $conexion->update(['estado' => EstadoConexion::Caida, ...]);
```

Pero el `404` de un `PUT products/{id}/variants/{id}` no significa "credencial mala":
significa **"esa variante/producto ya no existe en Tiendanube"**. Es el caso más común
del mundo: el cliente borra un producto en Tiendanube y en el CRM queda el vínculo.

Consecuencia en cadena:
1. Se borra/despublica una variante en Tiendanube.
2. La próxima corrida de stock hace PUT → 404.
3. La conexión entera queda marcada `Caida`.
4. `verificarCortes()` de stock y de precios corta → **se frena toda la sincronización
   de Tiendanube**, no sólo la de esa variante.
5. Reconectar Tiendanube no es un botón: requiere correr el bootstrap OAuth local y
   redeployar (el `/authorize` sólo acepta `redirect_uri` de loopback).

Es decir: **un borrado de producto en la tienda deja el CRM sin sync de stock hasta que
intervenga soporte técnico.** Con uso diario esto va a pasar.

**Fix propuesto:** separar el 404 del 401.
- `401` → credencial → `Caida`.
- `404` en una escritura sobre un recurso puntual → error del vínculo, nunca de la
  conexión. Marcar `stock_error = 'La variante ya no existe en Tiendanube'` y —mejor
  aún— marcar el vínculo como huérfano para que deje de reintentarse y aparezca en un
  listado de "vinculaciones rotas".

---

### A2. Ventana de carrera: se pisa el `stock_pendiente` y ML/TN queda con stock viejo

En ambos `SincronizadorStock::procesarVinculos()` la secuencia es:

```php
$cantidad = $this->stock->disponibilidad(...);   // (1) lee stock = 10
$respuesta = $this->cliente->enviar(... $cantidad);   // (2) HTTP, ~300-800 ms
$vinculo->update(['stock_pendiente' => false, ...]);  // (3) limpia el flag
```

Si entre (1) y (3) entra una venta de 2 unidades, el observer marca
`stock_pendiente = true` y el stock real pasa a 8. Pero (3) lo pisa a `false`.
Resultado: **Mercado Libre / Tiendanube quedan publicando 10 cuando hay 8, y nadie lo
vuelve a marcar pendiente hasta el próximo movimiento de ese producto.** Eso es
sobreventa silenciosa.

La colección se carga entera con `->get()` al principio, así que la ventana no es sólo
la del PUT: es desde el `get()` hasta que le toca el turno a ese vínculo. En una corrida
de 300 vínculos son minutos.

**Fix propuesto (cualquiera de los dos):**
- **Compare-and-clear**: limpiar el flag condicionalmente contra el valor publicado:
  `->where('stock_pendiente', true)->where('updated_at', $marcaLeida)`, o
- **Token de versión**: guardar `stock_marcado_en` al marcar pendiente; al limpiar,
  `->where('stock_marcado_en', $valorLeidoAntesDelPUT)`. Si cambió, no se limpia y
  se reenvía en la próxima corrida.

La segunda es la más robusta y no cuesta casi nada: una columna datetime y un `where`.

---

### A3. Los precios no tienen corrida programada: un fallo queda pendiente para siempre

Stock tiene cron (`mercadolibre:sincronizar-stock`, `tiendanube:sincronizar-stock`, cada
minuto). **Precios no tiene ninguno.** Los únicos disparadores son:

- `PrecioProductoObserver` (al guardar un precio), y
- los botones "Sincronizar precios ahora" / cambio de Lista de Precios.

Entonces, si un envío de precio falla —ML devolvió 500, hubo un 429, la conexión estaba
caída, el modo sólo lectura estaba prendido, el cron de ML estaba desactivado— el vínculo
queda con `precio_pendiente = true` **y nadie lo reintenta nunca**, hasta que un humano
se acuerde de apretar el botón. El precio publicado queda desactualizado en silencio.

Peor: es exactamente el escenario que el propio código anticipa en `enviarUno()`
("conservado el pendiente para el próximo intento válido")… pero no existe un
"próximo intento válido" automático.

**Fix propuesto:** un comando `*:sincronizar-precios` en el scheduler, con el mismo
patrón de frecuencia que el de stock (guardando `precio_ultima_sync_en` en la
configuración). Es la pieza que falta para que el circuito cierre solo.

---

### A4. Actualización masiva de precios = decenas/cientos de PUT síncronos dentro del request

`QUEUE_CONNECTION=sync`. `PrecioProductoObserver::saved()` llama a `enviarUno()` vía
`DB::afterCommit`, que con `sync` corre **en el mismo proceso HTTP**. Y
`ClienteMercadoLibre`/`ClienteTiendanubeRest` hacen `sleep(1/2/4)` ante 429 o 5xx.

Escenarios reales que van a pasar:
- Importación Excel de lista de precios (100-500 productos) → 100-500 PUT en un request.
- Actualización masiva de precios por porcentaje.
- Cambiar la Lista de Precios configurada → `sincronizarListaCompleta()` recorre
  **todos** los vínculos, síncrono, en el request de guardar la configuración.
- "Vincular automáticamente" recorre el catálogo entero de ML/TN, síncrono, en el request.

Con 177 publicaciones a ~400 ms cada una son ~70 segundos sin ningún 429. Con rate
limiting de ML de por medio, se va a varios minutos → `max_execution_time` / timeout de
nginx → **el usuario ve un error, la operación queda a medias, y no sabe cuáles se
enviaron y cuáles no.**

**Fix propuesto:** pasar los envíos a cola (`QUEUE_CONNECTION=database` + un worker, o
`redis`), o —mínimo viable sin infraestructura nueva— que el observer **sólo marque
`precio_pendiente = true`** y que el envío real lo haga el cron de A3. Esa segunda
opción resuelve A3 y A4 de una sola vez y es la que menos infraestructura pide.

---

## Severidad MEDIA — degradación progresiva

### M1. Tiendanube no tiene corta-circuitos: reintenta un error permanente para siempre

ML tiene `stock_intentos_fallidos` / `stock_requiere_intervencion` (5 intentos con el
mismo error → deja de reintentar, spec 063). **Tiendanube no tiene nada de eso**:
`scopePendientes()` es sólo `where('stock_pendiente', true)`.

Un vínculo que falla de forma permanente (variante borrada, producto despublicado, dato
rechazado) se reintenta en **cada** corrida, para siempre. Es exactamente el problema que
la spec 063 resolvió del lado de ML (las ~305 llamadas fallidas cada 6 h) y que en TN
sigue abierto. Además ese vínculo nunca limpia `stock_pendiente`, así que la corrida
"de pendientes" nunca se vacía.

**Fix propuesto:** portar el circuito de ML a `tn_variante_producto`
(`stock_intentos_fallidos`, `stock_error_desde`, `stock_requiere_intervencion` + scope).
Se combina naturalmente con A1.

### M2. Precios tampoco tienen corta-circuitos (ni en ML ni en TN)

`pendientesPrecio()` no excluye nada. Un precio que ML rechaza siempre (por ejemplo, por
debajo del mínimo permitido, o publicación pausada/cerrada) se reintenta indefinidamente
cada vez que alguien toca el botón o guarda el producto.

### M3. Un pendiente sin precio en la lista nunca se limpia

`SincronizadorPrecios::enviarPendientes()` (ML líneas 190-194, TN 185-189):

```php
$precio = $vinculo->producto->precios()->where('lista_precio_id', $listaPrecioId)->value('precio');
if ($precio === null) { continue; }   // ← no limpia precio_pendiente
```

Si el producto no tiene precio cargado en la lista configurada, el vínculo queda
`precio_pendiente = true` a perpetuidad y se re-consulta en cada corrida. La cola de
pendientes crece monótonamente con basura que nunca se va a enviar. (Sí se limpia el caso
`! $vinculo->producto`, pero no éste.)

**Fix:** limpiar el pendiente y registrar el motivo ("sin precio en la lista configurada"),
o dejar el pendiente pero excluirlo del scope.

### M4. Los logs de operaciones crecen sin límite, y crecen aunque no pase nada

No existe ninguna purga de `ml_operacion_log` ni de `tn_rest_operacion_log`.

Y hay un efecto multiplicador: cuando la sincronización está **bloqueada** (función
apagada, modo sólo lectura, conexión caída), `stock_ultima_sync_en` **no se actualiza**
—sólo se actualiza en el camino exitoso—, así que `correspondeEjecutar()` da `true`
siempre y el comando corre **cada minuto**, y cada corrida escribe una fila `bloqueada`.

Eso son 1.440 filas/día por integración, indefinidamente, justo en el escenario en que
el sistema no está haciendo nada útil. Sumado a los logs normales de operación, la tabla
va a ser el objeto más grande de la base en pocos meses.

**Fix propuesto:** (a) un `model:prune` / comando de retención (ej. 90 días), programado
a diario; y (b) que un corte por bloqueo también respete la frecuencia configurada —o
que se registre a lo sumo una fila por cambio de estado, no una por tick.

### M5. Se publica stock aunque no haya cambiado

`ultimo_stock_publicado` existe en ML pero **no se usa para saltear el envío**. En TN ni
siquiera existe la columna. Consecuencia: la "Sincronización forzada" y cualquier
movimiento que no cambie el neto disparan PUT redundantes, consumiendo cuota de rate
limit sin necesidad. Con el catálogo real esto es la diferencia entre 300 llamadas y 20.

**Fix:** saltear el PUT si `cantidad === ultimo_stock_publicado` (limpiando igual el
pendiente). Barato y de alto impacto en cuota. Ojo: hay que dejar una vía de "forzar de
verdad" para el caso en que el valor haya divergido del lado de ML.

### M6. Borrar un producto en el CRM deja la publicación vendiendo stock fantasma

`producto_id` tiene `cascadeOnDelete()` y `Producto` **no usa SoftDeletes**. Al borrar un
producto, el vínculo se borra de la base sin más. La publicación en ML/TN queda con el
último stock publicado, **activa y vendible**, y ya no hay vínculo que la vuelva a tocar.

**Fix propuesto:** antes de borrar un producto, empujar `stock = 0` a sus vínculos (o
pausar la publicación), o bloquear el borrado de productos con vínculos activos y exigir
desvincular primero.

---

## Severidad BAJA — vale tenerlo anotado

### B1. Vencimiento del lock vs. duración de la corrida
`Cache::lock(..., 300)` con `CACHE_STORE` sin definir → driver `file`. Una corrida de
`sincronizarTodos()` sobre el catálogo completo (con reintentos y `sleep`) puede pasar
los 300 s: el lock expira, entra otra corrida en paralelo, y el `release()` final de la
primera libera un lock que ya no le pertenece. `withoutOverlapping()` del scheduler
tapa el caso del cron, pero no el del botón "Sincronización forzada" disparado desde la
web mientras el cron corre. Subir el TTL del lock o usar `->block()`.

### B2. Publicación nueva sin clasificar recibe stock indebidamente
`esFull()` devuelve `false` cuando `logistic_type` es `null`. El vinculador automático ya
guarda el `logistic_type` del multiget, así que el hueco es chico, pero una publicación
que pase a Full **después** de vinculada queda mal clasificada hasta la corrida de
`sincronizar-tipos-publicacion` (cada 24 h), y durante esa ventana se le empuja stock que
ML no puede aceptar.

### B3. `MovimientoStockObserver` sólo escucha `created`
Es correcto hoy porque `StockService` es append-only (las reversiones generan movimientos
nuevos). Queda como invariante a preservar: si alguna vez algo borra o edita un
`MovimientoStock` directamente, la sincronización se pierde ese cambio en silencio.
Vale un comentario explícito en `StockService`.

### B4. `PrecioProductoObserver::saved` dispara aunque el precio no haya cambiado
Guardar el modal de un producto sin tocar el precio igual genera un PUT. Un
`if (! $precio->wasChanged('precio')) return;` elimina un buen porcentaje del tráfico.

### B5. No hay reconciliación periódica CRM ↔ marketplace
`sincronizarTodos()` sólo corre a mano. No existe nada que compare periódicamente lo que
el CRM cree que publicó contra lo que ML/TN realmente tienen. Cualquier divergencia
(edición manual desde el panel de ML, un PUT que devolvió 200 pero no aplicó) es
invisible hasta que alguien la nota vendiendo de más.

**Propuesta:** una corrida nocturna en modo "informe" (lee `GET /items` y compara contra
`ultimo_stock_publicado` / el stock del CRM) que no escriba nada, sólo liste las
diferencias en pantalla. Es barata y es la red de seguridad de todo lo anterior.

### B6. No hay aviso proactivo cuando esto falla
Ya está anotado como pendiente (módulo Notificaciones, `documentacion_principal_crm.md`
§7), pero conviene remarcar que **A1, A3 y M1 son todos fallos silenciosos**: la
sincronización se detiene y nadie se entera hasta que aparece una sobreventa. Con lo que
hay hoy, la única forma de detectarlo es entrar a mirar la pantalla de la integración.

---

## Orden sugerido de trabajo

| # | Qué | Por qué primero |
|---|-----|-----------------|
| 1 | A1 — separar 404 de 401 en Tiendanube | Es el que deja el sistema muerto y requiere soporte técnico para revivirlo |
| 2 | A2 — compare-and-clear del `stock_pendiente` | Es el que produce sobreventa sin dejar rastro |
| 3 | A3 + A4 — observer sólo marca, cron de precios envía | Un solo cambio cierra los dos; elimina los timeouts de importación masiva |
| 4 | M1 + M2 — corta-circuitos en TN y en precios | Frena el desgaste de cuota y la cola que no se vacía |
| 5 | M4 — retención de logs + no loguear por tick bloqueado | Crecimiento de base |
| 6 | M3, M5, M6, B4 | Higiene y ahorro de llamadas |
| 7 | B5 — reconciliación nocturna en modo informe | Red de seguridad sobre todo lo anterior |
| 8 | B6 — avisos | Depende del módulo Notificaciones |

Los puntos 1 a 4 dan para un solo spec ("robustez de sincronización de stock y precios").
Los puntos 5 a 7 pueden ir en un segundo spec de mantenimiento/observabilidad.
