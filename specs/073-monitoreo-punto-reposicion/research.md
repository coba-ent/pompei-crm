# Research: Monitoreo, Punto de Reposición y Notificaciones

**Feature**: 073-monitoreo-punto-reposicion | **Fecha**: 2026-08-21

Decisiones técnicas tomadas antes de planificar la implementación. Cada una responde a una tensión
real entre lo que pide la spec y lo que ya existe en el proyecto.

---

## Decisión 1 — El panel deja de ser autocontenido y pasa a `layouts.default`

**Decisión**: la vista `resources/views/monitoreo/index.blade.php` se reescribe extendiendo
`layouts.default`, con Yajra DataTables server-side, modales de Bootstrap, Toastr y el pagelevel
`monitoreo` registrado en `config/dz.php`. Se descarta el HTML autocontenido con CSS propio y tema
oscuro que tiene hoy.

**Rationale**: el archivo actual abre con un comentario que explica por qué **no** extiende el
layout: "así no depende de nada del CRM y nada del CRM depende de esto". Ese razonamiento era
correcto para una pantalla de diagnóstico escondida que se miraba desde el teléfono a las 2 de la
mañana. Deja de serlo en cuanto la pantalla pasa a ser parte del producto, con permiso, link en la
barra superior y usuarios reales: a partir de ahí, "se ve distinto a todo el resto del sistema" no
es aislamiento, es una pantalla rota. Las reglas de diseño obligatorias de `CLAUDE.md` (§1 a §6) no
admiten excepción, y la excepción documentada que existe en el proyecto (spec 071, buscador de
productos) se justificó por una limitación técnica real del componente, no por preferencia.

**Qué se conserva del principio de aislamiento** (la parte que sí sigue valiendo):

- El controlador sigue resolviendo todo con **consultas directas** (`DB::table`), sin depender de
  servicios, observers ni scopes del resto de la app. Si mañana cambia `StockService` o un observer
  de Venta, el panel no se entera.
- Cada bloque del panel carga por su **propio endpoint**. La falla de uno no derriba la pantalla
  (FR-024) — cosa que hoy *no* se cumple, porque `datos()` arma todo en una sola respuesta y una
  excepción en cualquier bloque deja la pantalla en blanco. El rediseño **mejora** el aislamiento
  real, no lo empeora.

**Alternativas consideradas**:

- *Mantener la vista autocontenida y sólo agregarle el link*: rechazada. Incumple 5 de las 6 reglas
  de diseño obligatorias y entrega al negocio una pantalla que se ve como una herramienta de
  diagnóstico.
- *Dos pantallas, una nueva para el negocio y la vieja intacta para diagnóstico*: rechazada. Dos
  fuentes de verdad sobre el mismo estado, y la vieja se pudre sin que nadie la mantenga.

---

## Decisión 2 — El punto de reposición se guarda en `productos.punto_reposicion` y la lista de precios 14 se elimina

**Decisión**: columna nueva `productos.punto_reposicion` (`unsignedInteger`, nullable, default
`null`). Migración de datos en **tres pasos verificables**, cada uno reversible por separado:

1. Agregar la columna (migración de schema, sin tocar datos).
2. Comando de migración de datos `migracion:punto-reposicion` que copia `precios_producto.precio`
   de la lista "Punto Reposición" a la columna nueva, con `--dry-run` por defecto.
3. Migración que elimina las filas de `precios_producto` de esa lista y la fila de `listas_precio`,
   **previa verificación de que nada la referencia**.

**Rationale**: el dato es real y es del negocio — se importó del archivo de Productos. Perderlo o
duplicarlo silenciosamente es el peor resultado posible, y este proyecto ya tiene la cicatriz: la
bitácora de importación registra que "Punto Reposición" quedó como lista de precios *por decisión
de no tocar schema*, con la nota de que "conceptualmente es raro (no es un precio)". Esta spec
existe justamente para pagar esa deuda.

El `--dry-run` por defecto y el resumen verificable (FR-008) no son ceremonia: el usuario tiene una
memoria explícita del proyecto sobre haber ejecutado un comando destructivo sobre datos reales sin
verificar antes.

**Verificación previa al borrado (FR-007)** — hay que chequear que la lista 14 no esté referenciada
por ninguna de estas columnas antes de borrarla:

| Tabla | Columna | Qué pasaría si estuviera referenciada |
|---|---|---|
| `clientes` | `lista_precio_id` | El cliente quedaría sin lista y facturaría al precio base |
| `ml_configuracion` | `lista_precio_id`, `lista_precio_id_premium` | Mercado Libre publicaría precios equivocados |
| `tiendanube_configuracion` | `lista_precio_id` | Ídem Tiendanube |
| `empresa` / configuración de ventas | `lista_precio_id` | Toda venta nueva saldría con la lista equivocada |
| `ventas`, `presupuestos` | `lista_precio_id` | Se rompería el histórico de comprobantes |

Si **cualquiera** de estas devuelve filas, el comando aborta e informa. No hay borrado "de todas
formas": lo que se rompe del otro lado son precios de venta reales.

**Tipo `unsignedInteger` y no decimal**: el punto de reposición es una cantidad de unidades. Viene
de un campo `decimal(14,2)` porque vivía disfrazado de precio; al migrar se redondea al entero más
cercano y el comando informa cuántos valores tenían decimales, para que el usuario pueda revisarlos.

**Alternativas consideradas**:

- *Dejar la lista y sincronizar ambas*: rechazada por el usuario y con razón — dos fuentes de verdad
  para el mismo número es garantía de que se van a desincronizar.
- *Tabla aparte `producto_puntos_reposicion` (uno por depósito)*: rechazada. La spec define **un**
  punto por producto aplicado a dos depósitos; una tabla por depósito es complejidad que nadie pidió
  y que además obliga a decidir qué pasa con los depósitos sin valor.

---

## Decisión 3 — Notificaciones: estado vigente + tabla mínima de lecturas con clave de episodio

**Decisión**: no hay tabla de notificaciones. Hay una tabla `notificaciones_leidas` con
`(user_id, clave, leida_en)`, única por `(user_id, clave)`. La clave identifica el **problema**
(`reposicion:{producto_id}`, `ml_stock:{ml_item_id}`), y el **episodio es implícito**: la marca de
lectura se **borra** en cuanto la condición deja de cumplirse.

**Rationale**: eso es lo que hace posible FR-035 y el escenario 6 de la historia 5 ("el producto se
repone, semanas después vuelve a caer, y la notificación reaparece **como no leída**"). Al reponerse
el stock, la marca desaparece; cuando el producto vuelve a caer no hay nada que la silencie. Si la
marca fuera permanente, marcar leída una vez silenciaría ese producto para siempre — un bug que se
descubre tres meses después, cuando alguien se queda sin mercadería y jura que nunca le avisaron.

**Alternativa descartada — clave con timestamp de episodio** (era la decisión original de este
documento, corregida en `/speckit-analyze`): armar la clave como
`reposicion:{producto_id}:{MAX(movimientos_stock.created_at)}`. Parecía más robusta porque no
dependía de la limpieza, pero tenía un defecto peor: **cada venta del producto cambia ese
timestamp**. Un producto que se mantiene por debajo de su punto de reposición volvería a alertar
como no leído en cada venta, y serían los productos que más rotan los que más molestarían — el
camino directo a que el usuario deje de mirar la campanita. Se prefiere el riesgo residual del
borrado (ver abajo) antes que una fuente garantizada de ruido.

**Limpieza**: las filas de `notificaciones_leidas` cuya clave ya no corresponde a ninguna alerta
vigente se borran de forma oportunista en cada consulta del resumen (un `DELETE` acotado a las
claves del usuario que ya no están en el conjunto vigente). No hace falta un cron.

**Riesgo residual asumido**: si el problema se resuelve y reaparece **entre dos consultas del mismo
usuario**, la limpieza no llegó a correr y la alerta le figura como ya leída. La ventana es de 5
minutos con la pestaña abierta, y el costo de errarle es que un aviso no se resalte.

**Alternativas consideradas**:

- *Tabla de notificaciones persistida con historial*: rechazada explícitamente por el usuario.
  Requiere cron generador, política de retención y limpieza.
- *Notificaciones de Laravel (`DatabaseNotifications`)*: rechazada. Es exactamente el modelo
  persistido que se descartó, con el costo extra de arrastrar una tabla polimórfica del framework
  para algo que se calcula en dos consultas.
- *Guardar sólo un `notificaciones_vistas_hasta` (timestamp por usuario)*: tentador por lo simple,
  pero rompe el "marcar una sola como leída" (FR-034) y hace que una alerta nueva de hace 3 días
  quede silenciada si el usuario marcó todo como leído ayer.

---

## Decisión 4 — Endpoints por bloque, no un `datos()` monolítico

**Decisión**: `datos()` se reemplaza por endpoints independientes: uno por cada tabla server-side
(publicaciones fallando, a reponer, riesgo ML, sin stock, órdenes sin venta), uno de `pulso` (estado
de sincronizaciones + interruptores + conteos) y uno de `resumen` para la barra superior.

**Rationale**: tres razones que empujan en la misma dirección. (1) FR-024 exige que la falla de un
bloque no tumbe la pantalla, imposible con una sola respuesta. (2) DataTables server-side necesita
un endpoint por tabla, con su paginación y su búsqueda. (3) El `datos()` actual trae hasta 120
productos de stock bajo, 50 órdenes y todas las publicaciones fallando en una sola respuesta que se
recalcula entera cada vez que la pantalla se refresca — con 8.400 productos eso no escala (SC-005).

**El endpoint de la barra superior (`resumen`) se llama desde TODAS las pantallas** del sistema, no
sólo desde el panel. Por lo tanto: sólo conteos y una muestra de 5 elementos por bloque, nada de
recorrer el catálogo. Los tres conteos salen de tres `COUNT` con índice.

---

## Decisión 5 — Índices necesarios

**Decisión**: agregar índice compuesto `(deposito_id, cantidad)` en `stocks` si no existe, e índice
en `productos.punto_reposicion` no.

**Rationale**: la consulta caliente es "productos activos, tipo producto, con punto de reposición
definido, cuyo stock en el depósito X es ≤ su punto". El filtro selectivo es el depósito y la
comparación es entre dos columnas de tablas distintas, así que un índice sobre `punto_reposicion`
solo no ayuda (no puede resolver la comparación). Lo que sí ayuda es entrar por `stocks` filtrando
depósito. `productos` se joinea por PK.

**Dato de escala real** (medido en `/speckit-analyze`): hay **9.181** productos con valor cargado en
la lista "Punto Reposición", sobre un catálogo de ~8.400 productos activos. Es decir: **casi todo el
catálogo va a quedar con punto de reposición definido**, así que la cláusula
`punto_reposicion IS NOT NULL` no filtra prácticamente nada y no se puede contar con ella para
achicar el conjunto. El filtro que realmente reduce es la comparación contra el stock. Esto refuerza
medir el `EXPLAIN` antes de decidir, y hace más probable que el índice sí haga falta.

Se mide antes de agregar: si el `EXPLAIN` sobre la base con datos reales (8.400 productos) ya
resuelve en tiempo aceptable con los índices existentes, no se agrega nada. Índice que no se
justifica con un plan de ejecución es peso muerto en cada escritura.

---

## Decisión 6 — Dos permisos nuevos, `monitoreo.ver` y `monitoreo.gestionar`

**Decisión**: se agregan al catálogo de `PermisoSeeder` bajo el módulo `monitoreo`, y se asignan
sólo al rol Admin (que ya recibe todos por `Permiso::pluck('id')` en `RolSeeder`, así que no hay que
tocar nada ahí). Las rutas usan el middleware `permiso:` existente; las vistas usan `@can`.

**Rationale**: el proyecto ya tiene el patrón `modulo.accion` resuelto y el par ver/gestionar tiene
precedente exacto en `integraciones.ver` / `integraciones.gestionar`, que cubre justamente
"disparar sincronizaciones". Inventar otro esquema para esta pantalla sería inconsistencia gratuita.

**Nota sobre el rol Admin**: `RolSeeder` sincroniza Admin con **todos** los permisos existentes, así
que al correr el seeder los dos permisos nuevos entran solos. Los roles Vendedor y Contable listan
sus permisos explícitamente y no los reciben — que es lo que la spec pide (FR-013a).

---

## Decisión 7 — El widget de la barra superior son dos cosas separadas

**Decisión**: en la barra superior conviven (a) el **indicador de Monitoreo**, con su desplegable de
tres bloques y link a la pantalla, y (b) la **campanita de notificaciones**, que se des-oculta y se
llena con datos reales. Ambos se alimentan del **mismo endpoint** `monitoreo/resumen`, en una sola
llamada.

**Rationale**: el usuario los pidió como dos elementos distintos y lo son conceptualmente — el
indicador responde "¿cómo está el sistema ahora?" y la campanita "¿qué pasó que yo no vi?". Pero
pedir los mismos datos dos veces por cada carga de página, en todas las pantallas del sistema, sería
absurdo. Un endpoint, dos consumidores.

**Implementación**: un único archivo `resources/js/monitoreo-topbar.js` cargado desde
`layouts/default.blade.php` (como ya se hace con `fecha-ar.js`), que hace el fetch, pinta ambos
widgets y programa el refresco cada 5 minutos (FR-037a). Si el usuario no tiene `monitoreo.ver`, el
Blade no renderiza ninguno de los dos y el JS no se carga — cero llamadas.
