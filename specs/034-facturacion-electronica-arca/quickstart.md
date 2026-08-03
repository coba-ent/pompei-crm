# Quickstart: Facturación Electrónica (ARCA/AFIP)

## Prerequisitos

- Certificado de **homologación** ARCA (par `.crt`/`.key`) generado en el portal de homologación de
  AFIP/ARCA con un CUIT de prueba — no requiere el certificado real del negocio (ver
  `research.md` §5). El certificado real de producción es un prerequisito operativo aparte (spec
  Assumptions), necesario recién antes de pasar `ambiente=produccion`.
- Migraciones de este módulo corridas: `php artisan migrate` (crea `puntos_venta`,
  `certificados_fiscales`, `comprobantes_fiscales`, `arca_logs_auditoria`).

## Configuración inicial

1. En Configuración & Ajustes → Facturación Electrónica: cargar el certificado (`.crt`/`.key`),
   CUIT, ambiente = `homologacion`.
2. Crear un Punto de Venta con el número asignado en homologación, marcarlo `por_defecto`.

## Escenario 1 — Emitir una Venta con CAE (User Story 1)

1. Crear un Cliente con Condición de IVA "Consumidor Final" (Factura B).
2. Crear una Venta con ese cliente y al menos un producto.
3. Confirmar el cobro ("Cobrar").
4. **Resultado esperado**: el comprobante queda con `estado=aprobado`, `cae` y `cae_vencimiento`
   completos; "Ver Detalle" abre el PDF en el modal compartido sin watermark "NO VÁLIDO COMO
   FACTURA".

## Escenario 2 — Rechazo por datos fiscales inválidos (User Story 4)

1. Crear un cliente con Condición de IVA "Responsable Inscripto" pero sin CUIT cargado.
2. Crear una Venta con Tipo de Comprobante A para ese cliente y confirmar el cobro.
3. **Resultado esperado**: toast de error indicando CUIT requerido para Factura A; la Venta queda
   en estado "A Cobrar" sin comprobante fiscal asignado; no se consume numeración.

## Escenario 3 — Recuperación tras timeout (Edge Case, FR-011)

1. (Test de integración, no manual) Simular timeout de `FECAESolicitar` con `SoapClient` mockeado
   después de que ARCA ya haya asignado CAE del lado del webservice de prueba.
2. Reintentar manualmente la emisión de la misma Venta.
3. **Resultado esperado**: `EmisorComprobante::verificarPendiente()` encuentra el CAE ya asignado
   vía `FECompConsultar` y lo persiste, sin generar un segundo comprobante para la misma Venta.

## Validación de éxito

- `tests/Feature/EmisionComprobanteVentaTest.php`, `EmisionComprobanteRechazoTest.php` en verde.
- `tests/Unit/Services/Arca/MapeadorComprobanteTest.php`,
  `ValidadorDatosFiscalesTest.php` en verde.
- Contrastar manualmente en el ambiente de homologación (no automatizable end-to-end sin credenciales
  reales) siguiendo Escenario 1 y 2.
