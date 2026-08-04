# Quickstart: Robustez de datos fiscales en la emisión de CAE (ARCA)

Validación funcional de esta spec. Todos los escenarios usan tests automatizados (mock del cliente
WSFEv1) — no requieren contactar a ARCA real. El Escenario 4 es la única validación manual sugerida,
contra **homologación** (nunca producción), antes de dar la spec por cerrada.

## Prerrequisitos

- Rama/feature `042-robustez-emision-arca` implementada.
- `php artisan test --filter=MapeadorComprobante` y `--filter=ValidadorDatosFiscales` en verde.
- Certificado fiscal de **homologación** configurado (no el de producción) para el Escenario 4.

## Escenario 1 — Venta con alícuota única (regresión, no debe cambiar)

1. Crear una Venta Tipo B con todos los ítems al 21% de IVA.
2. Ejecutar `EmisorComprobante::emitir()` (mock WSFEv1).
3. **Esperado**: el payload armado tiene un único bloque `AlicIva` (`Id=5`), igual que antes de esta
   spec — sin regresión para el caso simple.

## Escenario 2 — Venta con alícuotas mixtas (caso que causó el incidente)

1. Crear una Venta Tipo B con ítems al 21% y al 10,5%.
2. Ejecutar `EmisorComprobante::emitir()` (mock WSFEv1 que simula aprobación).
3. **Esperado**: el payload arma dos bloques `AlicIva` (uno por alícuota), cada uno con
   `BaseImp`/`Importe` consistentes con su porcentaje real; la Venta obtiene CAE aprobado (mock).

## Escenario 3 — Rechazos de precondición (no llegan a contactar a ARCA)

1. Venta con un ítem de alícuota no soportada (ej. 15%) → rechazo de precondición, mock WSFEv1 nunca
   invocado.
2. Venta con cliente sin Condición de IVA cargada → rechazo de precondición, mock WSFEv1 nunca
   invocado.
3. Venta con inconsistencia de importes fuera de tolerancia ($0.02 o más) → rechazo de precondición.

## Escenario 4 — `CondicionIVAReceptorId` presente en la solicitud real (validación manual, homologación)

1. Con certificado de **homologación** configurado, enviar a ARCA una Venta de prueba (Tipo B,
   Consumidor Final) desde "Enviar a ARCA" (spec 040).
2. Consultar `arca_logs_auditoria.payload_solicitud` del intento.
3. **Esperado**: el payload incluye `"CondicionIVAReceptorId": 5` (Consumidor Final); ARCA no rechaza
   por el aviso de "Condición Frente al IVA del receptor" (Events.Evt code 39 deja de aparecer, o
   aparece sólo como informativo sin afectar el resultado).

## Verificación de no-regresión

- `php artisan test --filter=EmisionComprobante` (spec 034/040) sigue en verde — el cambio no debe
  romper ningún test existente de emisión de comprobantes, NC/ND incluida.
