# Tasks: Corte de seguridad para las bajadas de precio hacia Mercado Libre

**Feature**: `084-corte-bajada-precios-ml` | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

`[P]` = puede hacerse en paralelo con las otras `[P]` del mismo bloque (archivos distintos, sin
dependencia entre ellas).

**Regla de esta feature, por el principio IV de la constitución**: todo test de una regla del corte
**tiene que fallar contra el código actual antes de escribir la implementación**. Un test que pasa
con el código viejo no prueba nada — es la lección de `PrecioProductoObserverPremiumTest`.

---

## Fase 1 — Cimientos (bloquea todo lo demás)

- [ ] **T001** Migración: `umbral_caida_precio_pct` en `ml_configuracion`, decimal(5,2), default
      `20.00`, no nulo. Agregar al `$fillable` y al `$casts` de `MercadoLibreConfiguracion`.
- [ ] **T002** `[P]` Migración: `precio_publicado` (decimal(14,2), nullable) y `precio_publicado_en`
      (timestamp, nullable) en `ml_publicacion_producto`. `$fillable` y `$casts` del modelo.
- [ ] **T003** `[P]` Migración: tabla `retenciones_precio_ml` según [data-model.md](./data-model.md),
      **incluida la columna generada + índice único** que garantiza una sola `abierta` por
      publicación. La restricción va en la base, no sólo en el código.
- [ ] **T004** Modelo `MercadoLibreRetencionPrecio`: relaciones a publicación, lista y usuario;
      scopes `abiertas()` y `deLaPublicacion()`; casts de los enums.
- [ ] **T005** En `MercadoLibrePublicacionProducto`: relación `retenciones()`, accessor
      `retencionAbierta`, scope `soloRetenidas()`. **Sin columna booleana `retenida`** — se deriva.

**Punto de control**: `php artisan migrate` corre limpio y `php artisan test` sigue en verde. Todavía
no cambió ningún comportamiento.

---

## Fase 2 — US1: el corte (P1) 🎯 MVP

Es la historia que sola ya justifica la feature: sin ella, todo lo demás avisa tarde.

### Tests primero — cada uno tiene que fallar antes de T012

- [ ] **T006** `[P]` `EvaluadorCambioPrecioTest`: la tabla de umbrales del
      [quickstart §Caso 3](./quickstart.md) — +30% publica, −15% publica, **−20% exacto publica**,
      −20,1% retiene. El borde es el que se olvida.
- [ ] **T007** `[P]` `EvaluadorCambioPrecioTest`: precio ≤ 0 retiene siempre, incluso con umbral 100.
- [ ] **T008** `[P]` `EvaluadorCambioPrecioTest`: `precio_publicado = NULL` retiene, **incluso si el
      precio nuevo es más alto** (motivo `sin_referencia`).
- [ ] **T009** `[P]` `EvaluadorCambioPrecioTest`: umbral 0 retiene toda bajada; umbral 100 no retiene
      por porcentaje pero **sí** por precio inválido y sin referencia.
- [ ] **T010** `[P]` `RetencionPrecioFlujoTest`: reproducir los dos incidentes reales — bajada del
      31,2% (Premium→Clásica) y división por 1000 — y verificar que **no sale ningún PUT** y que el
      precio publicado no cambia. Es la prueba que le da sentido a la spec.
- [ ] **T011** `[P]` `RetencionPrecioFlujoTest`: de diez publicaciones con tres que superan el
      umbral, siete se publican y tres quedan retenidas (FR-009).

### Implementación

- [ ] **T012** `EvaluadorCambioPrecio`: recibe vínculo + precio propuesto + configuración y devuelve
      "publicar" o "retener con motivo". **Sin efectos secundarios**: no escribe ni llama a la API,
      sólo decide. Así se testea a fondo sin montar medio sistema.
- [ ] **T013** Enganchar el evaluador en `SincronizadorPrecios::enviarUno()`, **después** de
      `verificarCortes()` y antes del PUT. Al retener: abrir la retención, marcar
      `reemplazada` la anterior si había, apagar `precio_pendiente`, devolver "no enviado".
      ⚠️ **`SincronizadorPreciosTest` tiene que seguir en verde sin tocarlo.** Si hay que modificarlo,
      el enganche quedó en el lugar equivocado.
- [ ] **T014** Escribir `precio_publicado` / `precio_publicado_en` en cada envío exitoso, dentro del
      mismo método. Es lo que alimenta al corte de ahí en adelante.
- [ ] **T015** Excluir las publicaciones con retención abierta de `enviarPendientes()` (Decisión 4).
- [ ] **T016** `MercadoLibreRetencionPrecioController@index`: DataTables server-side con el payload de
      [contracts §1](./contracts/retenciones-api.md), incluido `precio_vigente_lista`.
- [ ] **T017** `@aprobar`: exige `confirma_precio_distinto` cuando el vigente difiere del propuesto
      (422 con los dos importes); envía el **vigente**; `409` si ya no está abierta; **deja la
      retención abierta si Mercado Libre rechaza el envío**.
- [ ] **T018** `[P]` `@rechazar`: cierra sin enviar nada. `409` si ya no está abierta.
- [ ] **T019** `[P]` Rutas y `ResolverRetencionPrecioRequest`.
- [ ] **T020** Panel de retenidas en Vinculaciones: DataTables por AJAX, modal Bootstrap para
      aprobar/rechazar, toasts de resultado, sin recargar la página (FR-033).
- [ ] **T021** `[P]` Registrar retención, aprobación y rechazo en `ml_operaciones_log`, sin datos
      sensibles (FR-031/FR-032).
- [ ] **T022** `RetencionPrecioFlujoTest`: aprobar publica y cierra; rechazar no publica y cierra;
      una propuesta nueva reemplaza a la abierta; aprobar con precio cambiado exige confirmación.

### El umbral tiene que poder editarse (FR-003)

- [ ] **T022b** Campo del umbral en la pantalla de configuración de Mercado Libre, con validación de
      rango **0 a 100** en el FormRequest. Los dos extremos son válidos: `0` retiene toda bajada,
      `100` no retiene por porcentaje pero **sigue** reteniendo precio inválido y sin referencia. El
      texto de ayuda tiene que decirlo — si no, alguien va a poner 100 creyendo que apaga el corte.
- [ ] **T022c** `[P]` Test: guardar un umbral fuera de rango responde 422; guardar uno válido cambia
      inmediatamente el comportamiento del corte.

**Punto de control US1**: reproducir el [quickstart casos 1, 2, 3, 4, 5 y 6](./quickstart.md). Con
esto sola la feature ya protege.

---

## Fase 3 — US2: confirmación al cambiar la lista (P2)

- [ ] **T023** `[P]` `CambioListaConfirmacionTest`: guardar cambiando la lista **sin**
      `confirma_republicacion` responde 422 con el impacto y **no republica ni guarda**.
- [ ] **T024** `[P]` `CambioListaConfirmacionTest`: guardar sin cambiar ninguna lista no pide
      confirmación ni republica (FR-019).
- [ ] **T025** `PrevisualizadorCambioLista`: calcula afectadas, suben, bajan, sin cambio, quedarían
      retenidas, sin precio en la lista, y la caída máxima. **Contra `precio_publicado`, sin llamar a
      la API** (Decisión 7).
- [ ] **T026** Endpoint `POST .../ventas/previa`.
- [ ] **T027** En `MercadoLibreConfiguracionController`: exigir `confirma_republicacion` cuando cambia
      alguna lista, antes de guardar y de republicar.
- [ ] **T028** Frontend en `mercadolibre.js`: al guardar con cambio de lista, pedir la previa y
      mostrar el modal con los números; cancelar no manda nada.

**Punto de control US2**: [quickstart caso 7](./quickstart.md), con foco en el paso 3 — cancelar
**no** puede haber aplicado nada.

---

## Fase 4 — US3: chequeo periódico (P3)

- [ ] **T029** `[P]` `ChequeoPreciosPublicadosTest`: con todo correcto, **cero diferencias**. Montar
      publicaciones Premium con su precio Premium: si el chequeo las reporta, está comparando contra
      la lista general (Decisión 9).
- [ ] **T030** `[P]` `ChequeoPreciosPublicadosTest`: una retenida se informa como retenida y **no**
      como desfasaje (FR-023); una no consultable va aparte y no cuenta como coincidente (FR-024).
- [ ] **T031** `ChequeoPreciosPublicados`: recorre los vínculos, consulta el precio en Mercado Libre y
      compara contra `resolverListaPrecio()`. **Prohibido reimplementar la resolución de lista.**
- [ ] **T032** Que el chequeo actualice `precio_publicado` / `precio_publicado_en` con lo que ve.
      Esto es lo que hace posible el backfill de la Decisión 5.
- [ ] **T033** Comando `ml:chequear-precios` con `--refrescar-publicado` y `--json`. Agendarlo diario,
      en horario de baja actividad, separado del cron de stock.
- [ ] **T034** Persistir el resultado de la última corrida con su momento (FR-025).
- [ ] **T035** `MonitoreoController`: panel de precios junto al de stock, con el payload de
      [contracts §6](./contracts/retenciones-api.md), y ejecución a demanda (FR-026).
- [ ] **T036** Vista y JS del panel: resumen, detalle por publicación, bloque de retenidas separado y
      fecha de última corrida. Responsive — el monitoreo se mira desde el celular.

**Punto de control US3**: [quickstart caso 8](./quickstart.md) — cero diferencias con todo correcto.

---

## Fase 5 — US4: las dos ventanas silenciosas (P4)

- [ ] **T037** `[P]` Agregar a `PrecioProductoObserverPremiumTest`: un vínculo sin `listing_type_id`
      **no** recibe precio y queda pendiente. ⚠️ Hoy ese test afirma lo contrario (documenta el
      comportamiento actual): esta tarea **invierte esa aserción**, y el cambio de intención tiene que
      quedar escrito en el docblock del test.
- [ ] **T038** Implementar: sin tipo conocido no se publica precio; queda pendiente (FR-029).
- [ ] **T039** `[P]` Al completarse el tipo de un vínculo, resolver su pendiente con la lista que
      corresponde (FR-030).
- [ ] **T040** `[P]` Advertencia de Premium sin precio en la lista Premium, en el monitoreo y en
      Vinculaciones (FR-028).

---

## Fase 6 — Cierre

- [ ] **T041** Suite completa en verde: `php artisan test`.
- [ ] **T042** ⚠️ **Verificar en el navegador contra MySQL**, no sólo con la suite. La suite corre en
      SQLite y no reproduce `ONLY_FULL_GROUP_BY` ni el escape de barras invertidas en los morphs
      (memoria del proyecto): la suite verde no garantiza que funcione en producción.
- [ ] **T043** Actualizar el registro de chequeos y dejar el comando anotado donde se listan las
      corridas periódicas.
- [ ] **T044** Rollout en producción **en el orden de la Decisión 5**: migrar → `ml:chequear-precios
      --refrescar-publicado` → verificar en el monitoreo que las 270 tienen `precio_publicado` →
      recién ahí activar el corte. **Saltear el poblado retiene todo el primer día.**
- [ ] **T045** Dejar anotada la brecha de Tiendanube en §5 del documento principal (CHK032): comparte
      la exposición de publicar cualquier precio sin validar y necesita su propia spec.

---

## Dependencias

```
Fase 1 (T001–T005)
   │
   ├──► Fase 2  US1 · el corte          ← MVP, entregable solo
   │        │
   │        ├──► Fase 3  US2 · confirmación   (necesita el conteo de "quedarían retenidas")
   │        └──► Fase 4  US3 · chequeo        (necesita distinguir retenidas de desfasajes)
   │
   └──► Fase 5  US4 · ventanas silenciosas    (independiente de US1)
                    │
Fase 6 ◄────────────┘
```

**T032 antes que T044**: el backfill del rollout usa el chequeo. Sin T032 no hay con qué poblar
`precio_publicado` y el corte nace reteniendo todo.

## Estrategia de entrega

- **Entregable mínimo**: Fases 1 + 2. Ahí ya ninguna bajada grande llega sola a Mercado Libre.
- **Fase 4 antes que la 3 si hay que elegir**: el chequeo también sirve como diagnóstico y es el que
  puebla `precio_publicado`.
- **Fase 5 es la de menor urgencia**: hoy no hay ningún caso de ninguna de las dos ventanas.
