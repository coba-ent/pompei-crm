# Data Model: Envío Manual a ARCA desde el listado de Ventas

Sin cambios de esquema. Se reutilizan entidades existentes.

## Venta (existente — sin cambios de esquema)

Se agrega un campo **calculado** (no persistido) en la respuesta AJAX del listado de Ventas
(`VentaController::data()`), usado sólo para decidir si la fila muestra la acción "Enviar a ARCA":

| Campo (respuesta AJAX) | Tipo    | Regla |
|-------------------------|---------|-------|
| `puede_enviarse_arca`   | boolean | `true` cuando `tipo_comprobante ∈ {A,B,C}` **y** no existe `comprobanteFiscal` con `estado='aprobado'` **y** `FuncionAvanzada::activa('facturacion_electronica')` es `true`. |

No se persiste — se recalcula en cada carga del listado a partir de datos ya existentes
(`Venta::comprobanteFiscal`, `FuncionAvanzada`).

## ComprobanteFiscal (existente — sin cambios de esquema)

Sin cambios. Se sigue creando/actualizando exclusivamente vía `EmisorComprobante::emitir()`, ahora
invocado desde la nueva acción manual en vez del trigger automático de `cobranzaStore`.

## FuncionAvanzada (existente — sin cambios de esquema)

Se lee (no se escribe) la fila `clave='facturacion_electronica'` para decidir disponibilidad de la
acción (FR-008). Ya fue desactivada manualmente como mitigación del incidente del 04/08/2026 — su
reactivación es responsabilidad del usuario, fuera de alcance de esta spec (ver `spec.md` §Assumptions).
