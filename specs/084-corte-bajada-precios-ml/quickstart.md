# Cómo verificarlo a mano — spec 084

La prueba de que esto funciona no es que los tests pasen: es que **los dos incidentes reales queden
retenidos**. Todo lo de acá va en local, nunca en producción (memoria del proyecto: nunca probar en
producción).

---

## Preparación

```bash
php artisan migrate
php artisan tinker
```

Necesitás un producto vinculado a dos publicaciones —una Clásica y una Premium— con precio en las dos
listas, y `precio_publicado` poblado. Si `precio_publicado` está en `NULL` **todo se retiene**, que
es correcto pero no es lo que querés probar acá.

---

## Caso 1 — El incidente del 25/08: la Premium a precio Clásico

Es el que costó 30 horas de publicaciones baratas.

1. Producto con `ML = $218.607,42` y `ML Premium = $317.743,34`.
2. La publicación Premium tiene `precio_publicado = 317.743,34`.
3. Cambiá el precio de la lista **general** desde el modal de Producto.

**Qué tiene que pasar**

- La publicación **Clásica** recibe su precio nuevo, normal.
- La **Premium** no recibe nada — ya lo impide el arreglo del observer (commit `6e2916a5`).
- Si forzás el envío del precio general a la Premium (llamando `enviarUno()` a mano), **el corte lo
  retiene**: −31,2% contra un umbral del 20%.

**Verificación**: en Mercado Libre la Premium sigue en $317.743,34, y en Vinculaciones aparece
retenida con los dos importes y "31,20%".

Este caso tiene **dos** defensas encima. Es a propósito: la primera fue la que falló.

---

## Caso 2 — El incidente del 06/08: el precio dividido por 1000

1. Producto con `ML = $262.252,00`, `precio_publicado = 262.252,00`.
2. Poné el precio en `$262,26` (lo que dejó la migración).

**Qué tiene que pasar**: retenido, motivo `supera_umbral`, caída **99,90%**. Nada sale hacia Mercado
Libre.

**Lo importante**: antes, esto lo frenaba la validación de Mercado Libre, no el CRM. Ahora ni
siquiera llega a la API.

---

## Caso 3 — Que no moleste en la operación normal

Con umbral 20% y `precio_publicado = 100.000`:

| Precio nuevo | Variación | Qué tiene que pasar |
|--------------|-----------|---------------------|
| `$130.000` | +30% | **Publica.** Las subidas no se retienen nunca |
| `$85.000` | −15% | **Publica.** Está dentro del umbral |
| `$80.000` | −20% exacto | **Publica.** El borde pasa: se retiene lo *mayor* al umbral |
| `$79.900` | −20,1% | **Retiene** |
| `$0` | — | **Retiene**, motivo `precio_invalido` |

La fila de −20% exacto es la que se olvida y la que hay que probar: define el borde.

---

## Caso 4 — Sin referencia no se publica

1. Vínculo nuevo, `precio_publicado = NULL`.
2. Cambiá el precio del producto.

**Retiene**, motivo `sin_referencia`. Aunque el precio nuevo sea más alto.

Es contraintuitivo y es deliberado (Decisión 1): sin saber qué hay publicado, no se puede afirmar que
no se está bajando.

---

## Caso 5 — Una retención no frena al resto

Producto con diez publicaciones, tres de ellas con caída grande. Al cambiar el precio: **siete se
publican, tres quedan retenidas.** Si se retiene todo o se corta la corrida, está mal (FR-009).

---

## Caso 6 — Aprobar con el precio cambiado

1. Retené una publicación con propuesta `$70.000`.
2. Sin resolverla, cambiá el precio de la lista a `$75.000`.
3. Aprobá.

Tiene que **avisar** que se enviaría $75.000 y no $70.000, y exigir confirmación explícita. Al
confirmar se publica **$75.000** — el vigente, no el congelado.

---

## Caso 7 — La previa del cambio de lista

1. En la configuración, cambiá la lista general a una notoriamente más barata. Guardá.
2. Tiene que aparecer el resumen con los conteos, **sin haber aplicado nada**.
3. Cancelá → verificá en la base que `ml_configuracion` no cambió y que ningún precio se envió.
4. Repetí y confirmá → ahí sí se aplica, con el corte actuando publicación por publicación.

**El paso 3 es el que importa.** Que el resumen aparezca no sirve de nada si igual ya aplicó.

---

## Caso 8 — El chequeo no inventa diferencias

Con todos los precios correctos, correr:

```bash
php artisan ml:chequear-precios
```

Tiene que reportar **cero diferencias**. Si reporta ~30, está comparando las Premium contra la lista
general (Decisión 9) — el error de diagnóstico que se cometió el 26/08 y que hace inservible al panel.

---

## Rollout en producción

En este orden, sin saltear (Decisión 5):

1. `php artisan migrate` — nada cambia de comportamiento.
2. `php artisan ml:chequear-precios --refrescar-publicado` — puebla `precio_publicado`. **Sólo
   lectura contra Mercado Libre.**
3. Mirar el monitoreo: las 270 tienen que tener precio publicado conocido. **Si alguna quedó en
   `NULL`, se va a retener el primer cambio de precio que reciba.**
4. Recién ahí, activar el corte.

Saltear el paso 2 hace que el corte retenga todo el primer día y que la reacción sea desactivarlo.
