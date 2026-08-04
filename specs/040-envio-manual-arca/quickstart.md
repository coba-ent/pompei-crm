# Quickstart: Envío Manual a ARCA desde el listado de Ventas

## Prerequisitos

- Certificado ARCA y Punto de Venta configurados (spec 034).
- Función Avanzada "Facturación Electrónica" **activa** (fue desactivada el 04/08/2026 como
  mitigación del incidente — hay que reactivarla a propósito para probar esto, en
  Configuración & Ajustes → Funciones Avanzadas, idealmente contra **homologación**, no producción).

## Escenario 1 — Confirmar que ya NO hay envío automático (US1, regresión del incidente)

1. Crear una Venta con Tipo de Comprobante B.
2. Registrar una Cobranza sobre esa Venta (confirmar el cobro).
3. **Resultado esperado**: la Venta queda sin `ComprobanteFiscal` — no se disparó ningún envío a
   ARCA. Verificar en `arca_logs_auditoria` que no se generó ningún registro nuevo tras el paso 2.

## Escenario 2 — Enviar una Venta a ARCA manualmente (US1)

1. En el listado de Ventas, ubicar la fila de la Venta del Escenario 1 (o cualquier otra A/B/C sin
   CAE) — debe mostrar la acción "Enviar a ARCA".
2. Ejecutar la acción y confirmar el diálogo.
3. **Resultado esperado**: se abre un **modal** (no un toast) con el CAE y vencimiento obtenidos (o el
   motivo exacto si ARCA rechaza), que permanece visible hasta cerrarlo. Al cerrarlo, la fila deja de
   mostrar "Enviar a ARCA" si quedó aprobada — sin recargar la página.

## Escenario 3 — Acción no disponible cuando corresponde (US1, edge cases)

1. Repetir el Escenario 2 sobre la misma Venta ya aprobada. **Resultado esperado**: la acción ya no
   aparece en esa fila.
2. Sobre una Venta con Tipo de Comprobante distinto de A/B/C. **Resultado esperado**: la acción no
   aparece.
3. Con la Función Avanzada "Facturación Electrónica" desactivada. **Resultado esperado**: la acción
   no aparece en ninguna fila del listado.
4. Con la Función Avanzada activa pero sin certificado fiscal configurado, ejecutar "Enviar a ARCA".
   **Resultado esperado**: **toast** de error explicando que falta el certificado (FR-012) — no un
   modal (nunca se llegó a contactar ARCA), y sin dejar la Venta en un estado ambiguo.

## Escenario 4 — Documentación corregida (US2)

1. Abrir `docs/documentacion_principal_crm.md`, sección de Facturación Electrónica.
2. **Resultado esperado**: ya no dice "al confirmar el primer cobro... automáticamente" — describe la
   acción manual "Enviar a ARCA" del listado de Ventas, con una nota del incidente del 04/08/2026.
3. Abrir `specs/034-facturacion-electronica-arca/spec.md`, `FR-004`. **Resultado esperado**: corregido
   para reflejar el envío manual, con referencia a esta spec (040).

## Validación de éxito

- `tests/Feature/EnvioManualArcaTest.php` en verde.
- `tests/Feature/EmisionComprobanteRechazoTest.php` y
  `tests/Feature/EmisionComprobanteNotaCreditoDebitoTest.php` (spec 034) siguen en verde tras
  actualizarlos para usar la acción manual en vez del trigger automático.
- Contrastar manualmente los Escenarios 1-4 en el navegador, **contra homologación** (nunca contra
  producción hasta confirmar que el bug de IVA del incidente está resuelto).
