# El precio de promoción de Tiendanube se publica desde el CRM

**Spec**: 102 | **Fecha**: 2026-09-10 | **Estado**: listo para planificar

## El problema

Tiendanube muestra **dos precios** por variante: el de lista y el de oferta, que aparece con el
primero tachado al lado. El CRM sólo publica el primero.

La lista **"Lista de Precios Promoción Tiendanube"** (id 5) existe en el CRM desde la migración y
**las 85 variantes vinculadas tienen precio cargado ahí** — pero nunca llegó ninguno a Tiendanube,
porque el sincronizador no conoce ese campo.

Hoy en la tienda hay **una sola variante con promoción**, cargada a mano.

## Qué se quiere

Que el precio de una lista **configurable** del CRM se publique en Tiendanube como precio
promocional, por el mismo camino que ya publica el precio normal. En esta instalación esa lista
sería la 5, pero eso lo elige el usuario en la configuración — no lo asume el código.

## Lo relevado (documentación oficial + verificación contra la cuenta real)

El campo es **`promotional_price`** y se escribe por el **mismo `PUT`** que ya usa el CRM:

```
PUT /products/{product_id}/variants/{variant_id}
{ "promotional_price": "12000.00" }
```

Viaja como **string**, igual que `price`.

### Lo que la documentación NO decía, verificado contra la cuenta real

| Caso | Qué hace Tiendanube |
|---|---|
| Promo **menor** que el precio | 200, se guarda. Caso normal. |
| Promo **mayor o igual** que el precio | **200, y lo guarda igual** |
| `promotional_price: null` | 200, borra la promoción |
| `promotional_price: ""` | 200, borra la promoción |
| **Omitir el campo** en el PUT | **200, y NO toca la promoción existente** |

Dos hallazgos que definen esta spec:

1. **Tiendanube no valida nada.** Acepta un promocional más caro que el precio de lista y lo
   publica: la tienda mostraría el precio tachado y uno **mayor** al lado. La red la tiene que
   poner el CRM.
2. **Omitir el campo preserva lo que haya.** Verificado sobre la variante real que tiene promoción:
   un PUT con sólo `price` la dejó intacta. Eso permite no borrar nunca desde el CRM.

### Cómo vienen las variantes sin promoción

De las 101 variantes de la tienda, **100 no traen el campo** — no es `null`, directamente **está
ausente** en la respuesta. Sólo 1 lo trae con valor.

## La lista se configura, no se hardcodea

Mercado Libre ya resuelve esto con **dos columnas** en su configuración: `lista_precio_id` y
`lista_precio_id_premium`. Tiendanube tiene hoy sólo `lista_precio_id`, y le falta la segunda.

**Se calca ese patrón**, no se inventa uno nuevo: una columna nueva
`lista_precio_promocional_id` en `tn_conexion_rest`, y su selector en la pantalla de configuración
de Tiendanube, al lado del que ya existe.

Hay una diferencia conceptual con ML que el diseño tiene que respetar: en Mercado Libre las dos
listas son **excluyentes** (una publicación es Clásica **o** Premium, y `resolverListaPrecio()`
elige una). En Tiendanube son **complementarias**: las dos aplican a la misma variante y viajan
juntas en el mismo PUT.

## Requisitos funcionales

### La configuración

- **FR-000** La lista de precios promocional se elige en **Configuración → Tiendanube**, con el
  mismo selector que ya tiene la lista normal. No se asume la lista 5 ni ninguna otra.
- **FR-000a** Si **no hay** lista promocional configurada, el CRM **no envía nunca**
  `promotional_price`. La feature queda inerte hasta que alguien la configure, y el comportamiento
  es exactamente el de hoy.
- **FR-000b** La lista promocional **no puede ser la misma** que la normal: sería publicar el mismo
  importe como precio y como oferta, y la tienda mostraría un precio tachado idéntico al vigente.

### El envío

- **FR-001** Cuando el producto tiene precio en la **lista promocional configurada** y ese precio es
  **válido** (ver FR-004), se envía como `promotional_price` en el mismo PUT que ya manda `price`.
- **FR-002** Cuando el producto **no tiene** precio en esa lista, o está en cero, **el campo no se
  envía**. No se manda `null` ni `""`.
- **FR-003** El CRM **nunca borra** una promoción en Tiendanube. Si alguien la cargó a mano allá y
  el CRM no tiene precio para ese producto, la promoción **queda como está** (decisión del usuario:
  *"borrar cosas desde acá a Tiendanube sería medio peligroso"*). FR-002 lo garantiza por
  construcción, no por cuidado: el campo omitido no viaja.

### La validación que Tiendanube no hace

- **FR-004** Un precio promocional **mayor o igual** al precio de lista **se rechaza y no se envía**.
  Se registra el error en el vínculo, como cualquier otro fallo de sincronización.
- **FR-005** El rechazo es **sólo del promocional**: el precio de lista del mismo PUT se envía
  igual. Un promocional mal cargado no puede impedir que se actualice el precio normal.
- **FR-006** El mensaje del error dice los dos importes, para que se entienda qué corregir.

### Disparadores

- **FR-007** Editar un precio de la **lista promocional** dispara el envío del vínculo, igual que
  hoy lo hace la normal. Hoy `PrecioProductoObserver::ramaTiendanube()` compara contra
  `lista_precio_id` y descarta cualquier otra lista, así que la promocional se ignora por completo.
- **FR-008** La **sincronización forzada** envía los dos precios de todas las variantes vinculadas.
- **FR-009** Editar la **lista normal** sigue enviando el precio de lista, y arrastra el promocional
  vigente si lo hay — porque van en el mismo PUT.
- **FR-009a** Cambiar **cuál** es la lista promocional configurada empuja de inmediato los precios
  de la lista nueva, igual que ya hace hoy el cambio de la lista normal
  (`sincronizarListaCompleta()`).

### Cuál precio es cuál

- **FR-009b** El importe que se publica como `price` sale **siempre** de la lista normal
  configurada, y el que se publica como `promotional_price` **siempre** de la promocional — sin
  importar cuál de las dos listas se editó para disparar el envío. Hoy el importe viaja como
  parámetro desde el precio que se acaba de tocar, así que editar la lista promocional publicaría
  ese número como precio de lista: el producto pasaría a venderse al precio de oferta.

### Lo que no cambia

- **FR-010** El precio de lista se sigue enviando exactamente como hoy. Esta spec **agrega** un
  campo, no modifica el existente.
- **FR-011** Los estados `precio_pendiente`, `precio_error` y `precio_sincronizado_en` siguen
  siendo uno por vínculo: cubren el PUT completo, no un precio de cada uno.

## Criterios de éxito

- **SC-000** Sin lista promocional configurada, el CRM se comporta **exactamente** como antes de
  esta spec.
- **SC-001** Configurada la lista 5 como promocional y tras una sincronización forzada, las 85
  variantes tienen su precio promocional publicado en Tiendanube.
- **SC-002** La variante que hoy tiene promoción cargada a mano (`Espejo Pegar Ventosas`) **no se
  rompe**: o recibe el precio del CRM, o queda como está.
- **SC-003** Un producto sin precio en la lista promocional **no pierde** la promoción que tenga en
  Tiendanube.
- **SC-004** Un promocional cargado por encima del precio de lista **no se publica**, y el vínculo
  queda con el error explicado.
- **SC-005** El precio de lista se sigue publicando igual que antes de esta spec.

## Casos de borde

| Caso | Tratamiento |
|---|---|
| **Sin lista promocional configurada** | FR-000a: no se envía nunca el campo |
| Sin precio en la lista promocional | FR-002: no se envía el campo |
| Precio en la lista promocional = 0 | Igual que sin precio: no se envía |
| Promo ≥ precio de lista | FR-004: se rechaza, el precio de lista sí se envía |
| Promo cargada a mano en Tiendanube, sin dato en el CRM | Queda intacta (FR-003) |
| Sin precio en la lista normal pero con precio en la promocional | Se envía sólo el promocional |
| Variante sin `tn_product_id` | Sin cambios: ya se rechaza hoy |

## Riesgo

Publicar un precio promocional es publicar **el precio al que se vende**. Tiendanube no valida nada,
así que la única red es FR-004 — y esta integración **no tiene el corte de bajadas** que sí tiene
Mercado Libre.

Las 85 variantes del CRM ya se compararon: **las 85 tienen el promocional menor que el normal**, así
que hoy ninguna dispararía el rechazo. Pero eso es el estado de hoy, no una garantía.
