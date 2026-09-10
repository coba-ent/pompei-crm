# Tasks — spec 102

`[P]` = paralelizable dentro del bloque.

**Regla de esta feature**: publicar un precio promocional es publicar el precio al que se vende, y
Tiendanube no valida nada. Cada test que verifica que algo *se envía* tiene al lado uno que verifica
que lo que *no debe enviarse* no se envía.

---

## Fase 1 — La configuración

- [X] **T001** Migración: `lista_precio_promocional_id` en `tn_conexion_rest`, nullable, con FK.
      Nace en `null` **a propósito**: sin ella la feature queda inerte y el deploy no cambia nada.
- [X] **T002** `[P]` Test: sin lista promocional configurada, el PUT **no lleva** el campo (FR-000a,
      SC-000). Es el que garantiza que deployar no active nada solo.
- [X] **T003** `[P]` Test: la lista promocional **no puede ser la misma** que la normal (FR-000b).
- [X] **T004** Selector en Configuración → Tiendanube, con Select2, al lado del existente.

## Fase 2 — El payload ⚠️

- [X] **T004a** ⚠️ Test: **editar la lista promocional no cambia el precio de venta.** Con el
      promocional en $10.000 y el de lista en $15.000, editar el promocional tiene que publicar
      `price: 15000` y `promotional_price: 10000` — no `price: 10000`. Es el test de FR-009b y el
      que justifica el cambio de firma.
- [X] **T004b** `enviarUno()` deja de recibir el importe por parámetro y lo resuelve del vínculo
      (usa T018). Ajustar los tres llamadores.
- [X] **T005** ⚠️ **EL TEST QUE PROTEGE DE BORRAR EN TIENDANUBE.** Sin precio promocional en el CRM,
      el cuerpo del PUT **no contiene la clave** `promotional_price` — ni con `null` ni con `""`.
      Verificado contra la API: omitirlo preserva la promoción, mandarlo en null la borra. La
      diferencia entre los dos casos es todo el requisito FR-003.
- [X] **T006** `[P]` Test: con precio en las dos listas, el PUT lleva `price` y `promotional_price`,
      los dos como string.
- [X] **T007** `[P]` Test: precio promocional en cero se trata como ausente (no se envía).
- [X] **T008** Armado del cuerpo del PUT en `SincronizadorPrecios`.

## Fase 3 — La validación que la API no hace ⚠️

- [X] **T009** ⚠️ Test: promocional **mayor** al precio de lista → **no se envía** y queda el error
      (FR-004). Tiendanube lo aceptaría con HTTP 200 y publicaría un "descuento" más caro.
- [X] **T010** `[P]` Test: promocional **igual** al precio → tampoco se envía. Un precio tachado
      idéntico al vigente no es una oferta.
- [X] **T011** ⚠️ Test: cuando el promocional se rechaza, **el precio de lista se envía igual**
      (FR-005). Un promo mal cargado no puede frenar la actualización del precio que cobra la tienda.
- [X] **T012** `[P]` Test: el mensaje del error nombra los dos importes (FR-006).
- [X] **T013** La validación en el sincronizador.

## Fase 4 — Los disparadores

- [X] **T014** `PrecioProductoObserver::ramaTiendanube()` acepta las dos listas (FR-007).
- [X] **T015** `[P]` Test: editar la lista promocional dispara el envío del vínculo.
- [X] **T016** `[P]` Test: editar la lista normal sigue disparándolo, y arrastra el promocional
      vigente (FR-009).
- [X] **T016a** `[P]` Test: `DB::afterCommit()` sigue en su lugar — el punto único **consulta** el
      precio recién guardado, así que adentro de la transacción leería el valor viejo. (Sin cambios
      en el mecanismo: se conserva tal cual estaba, ver `ramaTiendanube()`.)
- [X] **T017** Cambiar la lista promocional configurada empuja la lista nueva (FR-009a).
- [X] **T018** Punto único que resuelve los dos precios de un vínculo, usado por el observer,
      `ejecutar()` y `sincronizarListaCompleta()`. ⚠️ Tres lugares resolviendo lo mismo por su cuenta
      es exactamente el origen del bug de NC/ND de la spec 099. (`resolverPrecios()`.)

## Fase 5 — Verificación real

- [X] **T019** Suite completa: separado — `TiendanubePrecioPromocionalTest` (13 tests nuevos) más
      `TiendanubeSincronizacionPreciosRestTest`, `TiendanubePrecioProductoObserverTest` y
      `TiendanubeVentaPrecioRegresionTest` en verde. El resto de fallas de `tests/Feature/Integraciones/`
      son preexistentes en `main` (confirmado corriendo la suite completa con `git stash`: 59 fallas
      antes de esta spec vs 50 con los cambios — ninguna nueva).
- [ ] **T020** Probar contra la cuenta real con una variante **NO vinculada**, y restaurar su estado
      al terminar. — Pendiente: requiere acceso a la cuenta real de Tiendanube.
- [ ] **T021** ⚠️ Verificar que la variante `Espejo Pegar Ventosas` —la única con promoción cargada
      a mano— **no se rompa** (SC-002). — Pendiente: requiere acceso a la cuenta real.
- [X] **T022** Actualizar `docs/documentacion_principal_crm.md` con la lista promocional y su
      configuración. (También `docs/modelo_datos.md`.)

## Fase 6 — Producción

- [ ] **T023** Deploy con la columna en `null`: nada cambia hasta que se configure.
- [ ] **T024** ⚠️ **Requiere OK del usuario.** Configurar la lista promocional y correr la
      sincronización forzada.
- [ ] **T025** Verificar contra Tiendanube que las 85 variantes tengan su promocional publicado, y
      que ninguna haya quedado con un precio tachado más caro.

---

## Dependencias

```
Fase 1 (configuración) ──► Fase 2 (payload) ──► Fase 3 (validación) ──► Fase 4 (disparadores)
Fase 5 (verificación real) ──► Fase 6 (producción)
```

## Nota de entrega

**T004a, T005 y T011 no se saltean.** T004a es lo único que impide publicar el precio de oferta
como precio de venta al editar la lista promocional. T005 es lo único que impide que el CRM borre promociones en
Tiendanube; T011 es lo único que impide que un promocional mal cargado deje sin actualizar el precio
que la tienda efectivamente cobra.
