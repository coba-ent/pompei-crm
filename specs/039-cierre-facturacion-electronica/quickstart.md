# Quickstart: Cierre de Facturación Electrónica — PDF NC/ND, Mi Perfil y Recibos

## Prerequisitos

- Certificado ARCA y Punto de Venta ya configurados (spec 034) — el negocio ya tiene esto cargado
  en Configuración & Ajustes → Facturación Electrónica.
- Migraciones de este módulo corridas: `php artisan migrate` (crea `datos_empresa`).
- Al menos una Venta con `ComprobanteFiscal` aprobado (CAE real) para poder crear una NC/ND sobre
  ella (spec 008).

## Escenario 1 — Cargar Mi Perfil (User Story 2)

1. Configuración & Ajustes → Mi Perfil.
2. Completar Razón Social, CUIT, Domicilio Fiscal, Condición de IVA; subir un logo.
3. Guardar.
4. **Resultado esperado**: los datos persisten sin recargar la página (toast de éxito); abrir el
   PDF de una Venta ya cobrada y verificar que el encabezado ahora muestra estos datos y el logo.

## Escenario 2 — Ver el PDF de una NC/ND con CAE (User Story 1)

1. Sobre la Venta con CAE del prerequisito, crear una NC de Crédito (spec 008, wizard de 2 pasos).
2. En la sección de Notas de Crédito/Débito del Detalle de Venta, abrir "Ver Detalle" de esa NC.
3. **Resultado esperado**: el PDF se abre en el modal compartido mostrando CAE propio, vencimiento
   de CAE, QR fiscal, el encabezado emisor (Mi Perfil) y la referencia al comprobante de Venta
   ajustado (tipo/número/fecha), sin watermark "NO VÁLIDO COMO FACTURA".

## Escenario 3 — Ver un Recibo de Cobranza (User Story 3)

1. Sobre cualquier Venta con al menos una Cobranza registrada, abrir "Ver Recibo" de esa Cobranza.
2. **Resultado esperado**: se abre un PDF (modal compartido) con datos del emisor, del Cliente, el
   medio de cobro, el monto, la fecha y el número interno `REC-{id}`. Repetir con un Pago a
   Proveedor para confirmar el mismo comportamiento en Egresos.

## Validación de éxito

- `tests/Feature/PdfNotaCreditoDebitoTest.php`, `MiPerfilTest.php`, `ReciboPdfTest.php` en verde.
- Contrastar manualmente Escenario 1, 2 y 3 en el navegador (regla de diseño: modales AJAX, PDF en
  modal compartido, sin recarga de página).
