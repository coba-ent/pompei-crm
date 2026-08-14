---

description: "Tareas de implementación — spec 066"
---

# Tasks: Conversión manual obligatoria para órdenes de Mercado Libre en estado excepcional

**Input**: Documentos de diseño en `/specs/066-ml-conversion-manual-excepciones/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md),
[data-model.md](./data-model.md), [contracts/](./contracts/conversion-forzada.md)

**Tests**: **obligatorios**. El principio IV de la constitución los exige cuando hay impacto fiscal, y acá una
conversión forzada termina en un comprobante. Los tests de US2 no son opcionales bajo ninguna circunstancia.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: puede correr en paralelo (archivos distintos, sin dependencias pendientes)
- **[Story]**: a qué historia pertenece (US1, US2, US3)

---

## Phase 1: Setup

**Purpose**: la base de datos y el catálogo de motivos, que todo lo demás necesita.

- [X] T001 Crear migración `database/migrations/2026_08_20_060001_add_mediacion_y_conversion_forzada_a_ml_ordenes.php` con `en_mediacion` (boolean, default false, not null), `forzada_motivo` (string 40 nullable), `forzada_por_id` (foreignId nullable → users, nullOnDelete) y `forzada_en` (timestamp nullable), con su `down()` completo
- [X] T002 [P] Agregar `en_mediacion` al `$fillable` y al `$casts` (boolean) y `forzada_en` a los casts de fecha en `app/Models/Integraciones/MercadoLibreOrden.php`
- [X] T003 [P] Agregar `motivosExcepcionales()` y `esExcepcional()` a `app/Enums/MercadoLibre/MotivoRequiereAtencion.php`, **sin** tocar `motivosDeCancelacionPosterior()` — son conjuntos distintos y unificarlos rompe la spec 063

**Checkpoint**: `php artisan migrate` corre limpio y el enum expone el conjunto nuevo.

---

## Phase 2: Foundational (bloqueante para todas las historias)

**Purpose**: una única definición de "estado excepcional" y una única precedencia de motivos. Sin esto, cada
historia inventaría su propia versión y divergirían.

- [X] T004 Crear `app/Services/MercadoLibre/MotivoExcepcional.php` con un método estático que reciba la orden y devuelva el motivo excepcional o `null`, respetando el orden **mediación → cancelada → reembolso parcial → alerta de fraude**; mover ahí la lógica de `DetectorCancelaciones::determinarMotivo()` en `app/Services/MercadoLibre/DetectorCancelaciones.php` y hacer que ese servicio lo consuma, para que la precedencia exista una sola vez
- [X] T005 Agregar `enEstadoExcepcional(): bool` y `motivoExcepcional(): ?MotivoRequiereAtencion` a `app/Models/Integraciones/MercadoLibreOrden.php`, delegando en el método compartido de T004
- [X] T006 [P] Persistir `en_mediacion` en `app/Services/MercadoLibre/SincronizadorOrdenes.php` usando `TraductorOrdenes::tieneMediacion()` sobre el payload crudo, dentro del mismo `updateOrCreate` que ya guarda la orden

**⚠️ Checkpoint**: la mediación se persiste y hay un único lugar que decide el motivo. Recién acá arrancan
las historias.

---

## Phase 3: User Story 1 — La orden en mediación no se convierte sola (P1) 🎯 MVP

**Goal**: que ni el cron ni el botón masivo conviertan una orden en estado excepcional.

**Independent Test**: marcar una orden pagada como en mediación, correr la creación automática y el botón
"Transformar todas en Venta", y comprobar que no se creó ninguna Venta.

### Tests

- [X] T007 [P] [US1] Test: una orden pagada con `en_mediacion = true` queda en `RequiereAtencion` con motivo `orden_en_mediacion` y **no** la convierte el cron, en `tests/Feature/Integraciones/MercadoLibreConversionForzadaTest.php`
- [X] T008 [P] [US1] Test: una orden con `estado_orden = ReembolsoParcial` queda en `RequiereAtencion` con su motivo y no se convierte sola, en el mismo archivo
- [X] T009 [P] [US1] Test: `convertirTodasLasListas()` no convierte ninguna de las cuatro excepcionales y las informa bajo `excluidas`, no bajo `fallidas`
- [X] T010 [P] [US1] Test de regresión (SC-006): una orden pagada normal se sigue convirtiendo por cron y por lote exactamente como antes
- [X] T011 [P] [US1] Test (FR-007): una orden en mediación que deja de estarlo vuelve a `Lista` en la evaluación siguiente, sin intervención manual

### Implementación

- [X] T012 [US1] Agregar a `EvaluadorConvertibilidad::evaluar()` en `app/Services/MercadoLibre/EvaluadorConvertibilidad.php` los dos casos faltantes —`en_mediacion` y `ReembolsoParcial`— devolviendo `RequiereAtencion` con su motivo, **respetando la precedencia de T004** y ubicándolos antes de las validaciones de datos
- [X] T013 [US1] Sumar `excluidas` y `detalle_excluidas` al resultado de `ConversorOrdenAVenta::convertirTodasLasListas()` en `app/Services/MercadoLibre/ConversorOrdenAVenta.php`, contando por separado de `fallidas` y cumpliendo `convertidas + fallidas + excluidas = total`

**Checkpoint**: el agujero está cerrado. Ya se puede entregar así: el sistema deja de convertir lo que no
debe, aunque todavía no permita forzar.

---

## Phase 4: User Story 2 — Forzar la conversión a mano (P1) 🔒 Fiscal

**Goal**: habilitar la conversión manual con confirmación explícita, validada en el servidor y registrada.

**Independent Test**: convertir una orden cancelada desde la pantalla, verificar la confirmación con el
motivo, confirmar, y comprobar que la Venta se creó y quedó el registro de quién la forzó.

### Tests (obligatorios — acá se emite un comprobante)

- [X] T014 [P] [US2] Test crítico (FR-010): `POST` de conversión de una orden excepcional **sin** `forzar_conversion` devuelve 409 y **no crea ninguna Venta**, en `tests/Feature/Integraciones/MercadoLibreConversionForzadaTest.php`
- [X] T015 [P] [US2] Test: con `forzar_conversion: true` la Venta se crea con cliente, cobro, movimiento de tesorería y descuento de stock correctos, y quedan `forzada_motivo`, `forzada_por_id` y `forzada_en`
- [X] T016 [P] [US2] Test de regresión (FR-021): la Venta forzada se crea **sin comprobante fiscal emitido** — hoy ya es así, el test existe para que no deje de serlo sin que nadie se entere
- [X] T017 [P] [US2] Test (FR-013): forzar **no** saltea los problemas de datos — publicación sin vincular, producto inexistente, publicación con variantes, cliente ambiguo y moneda distinta siguen rechazando
- [X] T018 [P] [US2] Test (FR-014): una orden **pendiente de pago** sigue rechazándose aun forzando; ídem con la función avanzada desactivada y con el modo sólo lectura activo
- [X] T019 [P] [US2] Test (FR-018/FR-019): tras forzar una cancelada, el detector **no** genera aviso por `orden_cancelada`; si la orden pasa a mediación, **sí** genera el aviso
- [X] T020 [P] [US2] Test: los cuatro motivos excepcionales se pueden forzar, cada uno registrando su propio motivo

### Implementación

- [X] T021 [US2] Agregar el parámetro `bool $forzada = false` a `convertir()` y `convertirBajoCandado()` en `app/Services/MercadoLibre/ConversorOrdenAVenta.php`, y condicionar a él **sólo** las guardas de estado excepcional: el rechazo por cancelada y la exigencia de `Pagada` cuando el motivo es cancelada o reembolso parcial — **cuidado de no habilitar por error las órdenes pendientes de pago**, que comparten esa guarda
- [X] T022 [US2] Persistir `forzada_motivo`, `forzada_por_id` y `forzada_en` dentro de la misma transacción que crea la Venta, y registrar la operación en `MercadoLibreOperacionLog` con `operacion = convertir_orden_forzada`
- [X] T023 [US2] **Verificación, no implementación** (FR-021): confirmar que `ConversorOrdenAVenta` sigue sin invocar `App\Services\Arca\EmisorComprobante` — hoy no lo hace y la Venta ya nace pendiente de emitir. Si el código cambió, ahí sí condicionarlo a `$forzada`. El test T016 es lo que fija la garantía
- [X] T023a [US2] Test (FR-022): la derivación del tipo de comprobante (A/B/C/E) según la condición de IVA del cliente da el mismo resultado en una conversión forzada que en una normal, en `tests/Feature/Integraciones/MercadoLibreConversionForzadaTest.php`
- [X] T024 [US2] Agregar `forzar_conversion` (`nullable`, `boolean`) a `app/Http/Requests/Integraciones/ConvertirOrdenRequest.php`
- [X] T025 [US2] En `MercadoLibreVentaController::convertir()` (GET) de `app/Http/Controllers/Ingresos/MercadoLibreVentaController.php`: dejar de abortar con 409 para las órdenes excepcionales y pasar `requiere_confirmacion`, `motivo_excepcional` y `motivo_etiqueta` a la vista, manteniendo el 409 para los problemas de datos
- [X] T026 [US2] En `convertirGuardar()` (POST): rechazar con 409 y `requiere_confirmacion: true` cuando la orden es excepcional y no llegó `forzar_conversion`, y propagar `$forzada` al conversor **evaluando el estado en ese momento** (FR-015)
- [X] T027 [US2] Hacer que `DetectorCancelaciones::detectar()` devuelva `null` cuando el motivo detectado coincide con `orden.forzada_motivo` (FR-018), sin alterar el resto de su comportamiento
- [X] T028 [US2] Aviso de confirmación en `resources/views/ingresos/mercadolibre/convertir.blade.php` con el motivo legible, en modal de Bootstrap
- [X] T029 [US2] Enviar `forzar_conversion` desde `resources/js/mercadolibre.js` al confirmar, con toast de Toastr y sin recargar la página — **enviar `1`/`0`, no `"true"`**, o la validación `boolean` devuelve 422

**Checkpoint**: la feature está completa y es usable de punta a punta.

---

## Phase 5: User Story 3 — Ver qué órdenes requieren decisión (P2)

**Goal**: que se distinga desde el listado qué está frenado y por qué.

**Independent Test**: con una orden de cada motivo, abrir el listado y comprobar que cada una muestra el suyo
y que el filtro las agrupa.

- [X] T030 [P] [US3] Mostrar el motivo de cada orden excepcional en `resources/views/ingresos/mercadolibre/index.blade.php`, sin romper el server-side de DataTables
- [X] T031 [P] [US3] Mantener la acción de convertir **visible y habilitada** para las órdenes excepcionales (FR-020) en la misma vista
- [X] T032 [US3] Verificar que el filtro por estado de conversión agrupa las órdenes frenadas, y ajustar la consulta server-side del controlador si hiciera falta

---

## Phase 6: Polish

- [X] T033 [P] Mostrar `excluidas` como categoría propia en el modal de resumen del lote, en `resources/js/mercadolibre.js`
- [X] T034 [P] Correr `php artisan test --filter=MercadoLibre` completo y confirmar que no hay regresiones en las specs 012, 025 y 063
- [ ] T035 Recorrer los 7 escenarios de [quickstart.md](./quickstart.md) a mano en local, con el modo sólo lectura desactivado — **pendiente**: no se hizo una prueba manual en navegador en esta sesión, sólo cobertura automatizada (T007-T023a)

---

## Dependencias

```
Phase 1 (T001-T003)
      ↓
Phase 2 (T004-T006)  ← bloqueante: la definición única de "excepcional"
      ↓
      ├─→ Phase 3 / US1 (T007-T013)  ← MVP, entregable solo
      │         ↓
      │   Phase 4 / US2 (T014-T029)  ← depende de que el evaluador ya excluya
      │         ↓
      └─→ Phase 5 / US3 (T030-T032)  ← sólo UI, puede ir en paralelo a US2
                ↓
          Phase 6 (T033-T035)
```

**US2 depende de US1**: forzar la conversión sólo tiene sentido cuando el evaluador ya frena esas órdenes.
Al revés no funciona.

**US3 es independiente de US2**: es presentación, puede hacerse en paralelo.

## Paralelismo

- **Phase 1**: T002 y T003 juntas, después de T001.
- **Phase 3**: los cinco tests (T007-T011) juntos; después T012 y T013.
- **Phase 4**: los siete tests (T014-T020) juntos. La implementación es mayormente secuencial porque T021,
  T022 y T023 tocan el mismo archivo.
- **Phase 5**: T030 y T031 juntas.

## Estrategia de entrega

**MVP = Phase 1 + 2 + 3.** Cierra el agujero por el que hoy se convierten órdenes en mediación, que es el
único daño activo. Es entregable por sí solo: el sistema deja de hacer lo que no debe, aunque todavía no
permita forzar.

**Segundo incremento = Phase 4.** Habilita el camino manual. Requiere el MVP arriba.

**Tercero = Phase 5 + 6.** Comodidad de uso.

> **Antes de dar por terminada la Phase 4**: ningún cambio en lógica fiscal o de dinero se cierra sin sus
> tests en verde (principio IV). T014 es el más importante de todos — es la única barrera entre el operador y
> una factura sobre una orden cancelada.
