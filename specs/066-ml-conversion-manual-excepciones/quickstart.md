# Quickstart — Validación de la feature

Cómo comprobar que esto quedó bien, sin depender de que Mercado Libre nos mande una orden en mediación justo
cuando la necesitamos.

## Prerrequisitos

- Base local con la integración de Mercado Libre configurada (función avanzada activa, cuenta conectada,
  cuenta de Tesorería y depósito seteados).
- Al menos una publicación vinculada a un producto con stock.
- Modo sólo lectura **desactivado**: bloquea todo, incluida la conversión forzada.

```bash
php artisan migrate
```

## Escenario 1 — Una orden en mediación no se convierte sola (US1, SC-002)

Es el agujero que la feature viene a cerrar; empezar por acá.

1. Tomar una orden pagada sin Venta y marcarla en mediación (`en_mediacion = true`), simulando lo que
   escribiría la sincronización al ver `payments[].status = in_mediation`.
2. Reevaluarla como lo haría el cron.
3. Correr la creación automática y el botón "Transformar todas en Venta".

**Esperado**: la orden queda en `RequiereAtencion` con motivo `orden_en_mediacion`; **no** se creó ninguna
Venta por ninguno de los dos caminos; en el resumen del lote aparece bajo `excluidas`, no bajo `fallidas`.

**Antes de esta feature**: la orden salía `Lista` y el cron le creaba la Venta. Vale la pena verificarlo
contra `main` una vez, para ver el problema con los propios ojos.

## Escenario 2 — La confirmación no se puede saltear (FR-010, principio III)

El más importante de todos: del otro lado de esta guarda se emite un comprobante fiscal.

1. Tomar una orden cancelada sin Venta.
2. Hacer `POST ingresos/mercadolibre/{orden}/convertir` **sin** `forzar_conversion`, salteando la interfaz.

**Esperado**: 409 con `requiere_confirmacion: true` y el motivo. **Ninguna Venta creada.**

3. Repetir con `forzar_conversion: true`.

**Esperado**: 200, Venta creada, y en la orden quedan `forzada_motivo = orden_cancelada`, `forzada_por_id` y
`forzada_en`.

## Escenario 3 — Forzar no saltea los problemas de datos (FR-013)

1. Tomar una orden cancelada **cuya publicación no está vinculada** a ningún producto.
2. Convertirla con `forzar_conversion: true`.

**Esperado**: 409 con el motivo `publicacion_sin_vincular`. Forzar habilita una decisión de negocio, no
crea una Venta con datos incompletos.

Repetir con una orden **pendiente de pago**: tiene que seguir rechazándose aun forzando.

## Escenario 4 — No se avisa dos veces por lo mismo (FR-018, SC-007)

1. Forzar la conversión de una orden cancelada (escenario 2).
2. Correr la detección de cancelaciones posteriores, como haría la sincronización siguiente.

**Esperado**: la orden queda `Convertida`, **sin** aviso. La persona ya decidió eso.

3. Cambiar esa misma orden a mediación y volver a correr la detección.

**Esperado**: ahora **sí** aparece el aviso, con motivo `orden_en_mediacion`. Es información nueva.

## Escenario 5 — La orden se destraba sola (FR-007, SC-005)

1. Orden en mediación, en `RequiereAtencion`.
2. Quitar la mediación (reclamo resuelto a favor del negocio) y reevaluar.

**Esperado**: vuelve a `Lista` y el cron la convierte con normalidad. Nadie tuvo que destrabarla a mano.

## Escenario 6 — Lo que no cambia (SC-006)

Con una orden pagada normal, sin ningún estado excepcional: se convierte igual que siempre, por el cron, por
el lote y a mano, **sin** pedir confirmación.

Es el escenario que más fácil se rompe sin querer, y el que más se nota si se rompe.

## Escenario 7 — La UI

- El listado muestra el motivo de cada orden frenada sin abrirla, y el filtro las agrupa (FR-016, FR-017).
- La acción de convertir está **visible y habilitada** en una orden cancelada (FR-020).
- Al pulsarla aparece la confirmación con el motivo; cancelar no crea nada, confirmar crea la Venta.
- Todo con modal de Bootstrap y toast de Toastr, sin recargar la página (reglas 2 y 3 del proyecto).

## Tests automatizados

```bash
php artisan test --filter=MercadoLibreConversionForzada
```

Cobertura mínima exigida por el principio IV de la constitución, porque acá se emite un comprobante:

- los cuatro estados excepcionales quedan fuera del automático y del lote
- el rechazo sin confirmación (escenario 2) — es la única barrera real
- la conversión forzada crea la Venta con cliente, cobro y stock correctos
- forzar no saltea problemas de datos ni una orden pendiente de pago
- el aviso no se duplica por el motivo forzado, pero sí aparece con uno nuevo
- una orden normal se sigue convirtiendo sin pedir nada
