# Quickstart: Envío Manual a ARCA para NC/ND, con IVA real por línea

Validación manual en **LOCAL** (nunca en el VPS de producción — ver memoria de proyecto). Requiere
Función Avanzada "Facturación Electrónica" activa y un certificado de homologación configurado.

## Escenario 1 — Ya no se envía sola (US1, FR-001)

1. Crear/tener una Venta con `ComprobanteFiscal` aprobado (CAE real u homologación).
2. Crear una Nota de Crédito sobre esa Venta.
3. **Verificar**: la nota queda creada, sin `ComprobanteFiscal` propio, y no aparece ningún log nuevo en
   `arca_logs_auditoria` para esa nota.
4. En el Detalle de la Venta, la fila de esa nota muestra la acción "Enviar a ARCA" disponible y el
   indicador de estado en "Sin enviar".

## Escenario 2 — Envío manual exitoso (US1)

1. Sobre la nota del escenario 1, ejecutar "Enviar a ARCA".
2. **Verificar**: aparece el modal propio de confirmación de NC/ND (no el de Venta), con el texto
   correspondiente a "¿Enviar esta Nota de Crédito/Débito a ARCA...?".
3. Confirmar.
4. **Verificar**: se abre el modal de resultado (propio de NC/ND) mostrando CAE y vencimiento; al cerrarlo,
   la fila ya no ofrece "Enviar a ARCA" y el indicador de estado pasa a "Aprobado".

## Escenario 3 — IVA real por línea (US2)

1. Crear una Venta con dos ítems: uno de un producto al 21% y otro a 10.5%.
2. Crear una Nota de Crédito sobre ambas líneas (con línea de origen — spec 096).
3. Enviarla a ARCA (escenario 2).
4. **Verificar** (con logging temporal o inspección de `arca_logs_auditoria`/request real a homologación):
   el `FeDetReq` enviado trae `Iva.AlicIva` como array con **dos** bloques (Id 5 al 21% e Id 4 al 10.5%),
   cada uno con el neto/IVA real de su línea — no un único bloque calculado como `monto/1.21`.

## Escenario 4 — Fallback agregado con ítems mixtos (US2, FR-010a)

1. Tomar una nota con 2 ítems, uno con `venta_item_id` seteado y otro sin él (simulable editando en tinker
   o usando una nota vieja pre-spec-096).
2. Enviarla a ARCA.
3. **Verificar**: el `FeDetReq` trae un único bloque `AlicIva` (fallback agregado), no dos — confirma que
   no se combinó cálculo por línea con agregado.

## Escenario 5 — Rechazo de precondición por toast (Edge case)

1. Desactivar temporalmente la Función Avanzada "Facturación Electrónica".
2. Intentar "Enviar a ARCA" sobre una nota elegible (vía request directa si el botón ya no aparece).
3. **Verificar**: aparece un **toast** de error, no el modal de resultado — y no se generó ningún
   `ComprobanteFiscal` nuevo.
4. Reactivar la Función Avanzada al terminar la prueba.

## Escenario 6 — Estado ARCA visible en Detalle (US4)

1. Abrir el Detalle de una Venta con CAE aprobado.
2. **Verificar**: se ve el indicador de estado "Aprobado" sin pasar el mouse por ningún ícono.
3. Abrir el Detalle de una NC/ND sin enviar sobre esa misma Venta.
4. **Verificar**: el indicador de la nota dice "Sin enviar", distinto del de la Venta ("Aprobado") — no se
   confunden ambos estados.

## Paridad Compras (FR-011)

Repetir los escenarios 1, 2 y 6 sustituyendo Venta por Compra y `ventas.notas.enviarArca` por
`compras.notas.enviarArca` — mismo resultado esperado.
