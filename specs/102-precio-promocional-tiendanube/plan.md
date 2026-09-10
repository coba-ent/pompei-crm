# Plan técnico — spec 102

## Enfoque

Se calca el patrón que Mercado Libre ya usa para su segunda lista (`lista_precio_id_premium`), con
una diferencia que atraviesa todo el diseño: en ML las dos listas son **excluyentes** y hay que
elegir una; en Tiendanube son **complementarias** y viajan juntas en el mismo PUT.

Por eso Tiendanube **no necesita** un `resolverListaPrecio()`: necesita armar un payload con uno o
dos campos.

## 1. La configuración

Migración: `lista_precio_promocional_id` en `tn_conexion_rest`, nullable, con FK a `listas_precio` —
igual que la columna que ya existe.

Nullable **a propósito**: sin ella configurada la feature queda inerte (FR-000a) y el comportamiento
es idéntico al de hoy. Eso hace que el deploy sea seguro por sí mismo, antes de que nadie configure
nada.

En la pantalla de configuración, un Select2 al lado del actual, con la validación de FR-000b (no
puede ser la misma lista que la normal).

## 2. La firma de `enviarUno()` — el punto que el análisis destapó

Hoy la firma es:

```php
public function enviarUno(TiendanubeVarianteProducto $vinculo, float $precio): bool
```

El precio **entra por parámetro**, y el observer se lo pasa desde el registro que se acaba de
editar (`(float) $precio->precio`). Eso funciona con un solo precio, pero con dos se rompe: cuando
se edita la lista promocional, el observer tiene en la mano el promocional y **no** el de lista.
Pasarlo como segundo parámetro publicaría el promocional como `price`.

Por eso `enviarUno()` deja de recibir el importe y pasa a **resolverlo él** desde el vínculo, con el
punto único de §5. El observer queda reducido a decir *qué vínculo* cambió, no *con qué número*.

Es un cambio de firma, no un agregado: los tres llamadores (`ramaTiendanube()`, `ejecutar()`,
`sincronizarListaCompleta()`) se ajustan.

## 2.bis El payload del PUT

Resuelto el importe, el cuerpo se arma según lo que haya:

```php
$cuerpo = ['price' => $precio];

if ($promocional !== null) {
    $cuerpo['promotional_price'] = $promocional;
}
```

**La clave está en el `if`.** Verificado contra la cuenta real: omitir el campo deja intacta la
promoción que Tiendanube tenga. Eso es lo que hace que el CRM **nunca borre** allá (FR-003) — no por
cuidado de quien programa, sino porque el campo simplemente no viaja.

Enviar `null` o `""` **sí borraría**, así que la diferencia entre "omitir" y "mandar null" no es
cosmética: es el requisito.

## 3. La validación de FR-004

Antes de agregar el campo:

```php
if ($promocional >= $precio) {
    // se registra el error y se envía SÓLO el precio de lista
}
```

Tiendanube acepta un promocional más caro sin chistar —verificado: HTTP 200 y lo guarda—, así que
esta comparación es la única red que hay.

**El precio de lista se envía igual** (FR-005): un promocional mal cargado no puede bloquear la
actualización del precio normal, que es el que cobra la tienda si no hay oferta.

## 4. El observer

`PrecioProductoObserver::ramaTiendanube()` hoy hace:

```php
if (! $listaConfigurada || (int) $precio->lista_precio_id !== (int) $listaConfigurada) {
    return;
}

// ...
app(SincronizadorPreciosTiendanube::class)->enviarUno($vinculo, (float) $precio->precio);
```

Pasa a aceptar **las dos** listas, y a llamar `enviarUno($vinculo)` **sin el importe** (§2). En los
dos casos se envía el vínculo completo (precio + promo), porque van en el mismo PUT: no hay forma de
mandar uno sin el otro.

`DB::afterCommit()` se conserva: el precio recién guardado tiene que estar visible para la consulta
que hace el punto único, y dentro de la transacción todavía no lo está de forma confiable.

## 5. De dónde sale cada precio

Un único punto que, dado un vínculo, devuelve los dos importes leyendo las dos listas configuradas.
Lo usan el observer, `ejecutar()` y `sincronizarListaCompleta()`, para que no haya tres lugares
resolviendo lo mismo — que es exactamente el origen del bug de la spec 099 en NC/ND.

## Qué NO se toca

- El envío del precio de lista: se **agrega** un campo al payload. El importe pasa a resolverse
  adentro en vez de venir por parámetro, pero **el valor publicado es el mismo** — sale de la misma
  lista configurada.
- Los estados del vínculo (`precio_pendiente`, `precio_error`, `precio_sincronizado_en`): siguen
  siendo uno por vínculo, cubren el PUT completo.
- El sincronizador de stock.
- Mercado Libre.

## Orden de trabajo

1. Migración + configuración + su validación.
2. Test que reproduce el caso: producto con precio en las dos listas → el PUT lleva los dos campos.
3. Test de FR-003: sin precio promocional, el campo **no viaja**. Es el que protege de borrar en
   Tiendanube.
4. Test de FR-004: promo ≥ precio → se rechaza el promocional, el precio de lista sale igual.
5. El payload y la validación.
6. El observer con las dos listas.
7. Verificación contra la cuenta real, con una variante no vinculada.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Borrar una promoción cargada a mano en Tiendanube** | El campo se omite, no se manda null. Test propio (FR-003) |
| Publicar un promocional más caro que el precio | FR-004; Tiendanube no valida nada |
| Romper el envío del precio de lista | FR-005 + SC-005: el precio normal sale igual aunque el promo se rechace |
| Que la feature se active sola al deployar | La columna nace `null` y sin ella no se envía nada (FR-000a) |

## Verificación

La cuenta real tiene **1 variante con promoción** (`Espejo Pegar Ventosas`, $31.621,34 con promo de
$24.999) y **100 sin el campo**. Las 85 vinculadas tienen precio en la lista 5, y **las 85 lo tienen
menor** que el normal.

Las pruebas contra la API se hacen sobre una variante **no vinculada**, y se restaura su estado al
terminar.
