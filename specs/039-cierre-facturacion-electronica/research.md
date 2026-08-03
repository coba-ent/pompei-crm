# Research: Cierre de Facturación Electrónica — PDF NC/ND, Mi Perfil y Recibos

## 1. Reutilización del patrón de PDF de Venta para NC/ND

**Decision**: el PDF de NC/ND (`resources/views/notas-credito-debito/pdf.blade.php`) replica la
estructura de `resources/views/ventas/pdf.blade.php` (spec 034): mismo bloque de watermark
condicional, mismo bloque de CAE/vencimiento/QR fiscal cuando `comprobanteFiscal->aprobado()` es
true. Se agrega un bloque nuevo "Comprobante que ajusta" con tipo/número/fecha del
`ComprobanteFiscal` de la Venta original (via `NotaCreditoDebito::venta->comprobanteFiscal` o, si
la Venta no tiene comprobante fiscal propio en ese momento, los campos ya persistidos en la NC/ND
al crearla).

**Rationale**: `NotaCreditoDebito::comprobanteFiscal()` ya existe como `morphOne` (mismo mecanismo
polimórfico que usa `Venta`/`Compra`, ver `app/Models/NotaCreditoDebito.php:46-49`), así que no
hace falta modelar nada nuevo — sólo una vista y una acción de controlador nuevas.

**Alternatives considered**: extender `ventas/pdf.blade.php` con un `@if($esNotaCreditoDebito)`
generalizado — rechazado por mezclar dos documentos fiscalmente distintos en una sola vista,
dificultando mantenimiento futuro (igual que la razón por la que spec 034 mantuvo la vista de
Compra separada de la de Venta).

## 2. Modelo de datos de Mi Perfil

**Decision**: tabla `datos_empresa` de una sola fila (patrón "singleton row", `id` fijo o
`firstOrCreate`), sin relación con otras tablas. Campos: `razon_social`, `cuit`, `domicilio_fiscal`,
`condicion_iva`, `ingresos_brutos` (nullable), `ruta_logo` (nullable).

**Rationale**: consistente con el carácter single-tenant del CRM (constitución, restricciones
técnicas) — no hace falta un `empresa_id` en ningún lado. Mismo patrón que otras configuraciones
"únicas" del proyecto (p. ej. `certificados_fiscales.activo` filtra al certificado vigente, no hay
multi-CUIT).

**Alternatives considered**: guardar estos datos en `config/app.php` o variables de entorno —
rechazado porque deben ser editables desde la UI sin redeploy (regla de diseño: modal + AJAX, sin
tocar `.env`).

## 3. Recibos: no crear tabla nueva

**Decision**: el Recibo es una vista PDF que lee datos ya persistidos de `Cobranza` (Venta) o
`Pago` (Compra/Proveedor) existentes — no se crea entidad `Recibo` en la base de datos. El
"número correlativo interno de recibo" (FR-011) se deriva del `id` de la Cobranza/Pago con un
prefijo (`REC-{id}`), evitando una secuencia nueva que no aporta valor de negocio verificado.

**Rationale**: no hay informe con capturas reales que documente si Contagram numera Recibos con
una secuencia propia independiente — inventar esa secuencia sería violar el principio rector
(fidelidad estructural). Usar el `id` ya existente es reversible y no bloquea un futuro ajuste si
se releva la estructura real más adelante.

**Alternatives considered**: tabla `recibos` con secuencia propia — rechazada por falta de
evidencia de que así funciona Contagram real; se prefiere el mínimo indispensable y documentar la
brecha (ya reflejado en spec Assumptions y Edge Cases).

## 4. Generación de PDF

**Decision**: reutilizar `barryvdh/laravel-dompdf`, ya en uso por `ventas/pdf.blade.php` — sin
paquete nuevo.

**Rationale**: package ya instalado y probado en producción (spec 034); no hay motivo técnico para
introducir una alternativa (wkhtmltopdf, mPDF) sólo para estos documentos.

**Alternatives considered**: ninguna evaluada — mismo stack ya validado.

## 5. Encabezado emisor condicional

**Decision**: los PDFs de Venta y NC/ND consultan `DatosEmpresa::instancia()` (helper análogo a
`CertificadoFiscal::activo()`); si no hay fila cargada, el bloque de encabezado se omite
silenciosamente (FR-008) — no se lanza excepción ni se bloquea la generación del PDF.

**Rationale**: mismo criterio de resiliencia ya aplicado en spec 034 para certificado/Punto de
Venta no configurado (fallback sin romper el flujo).

**Alternatives considered**: bloquear la generación de PDF sin Mi Perfil cargado — rechazado, ya
que el PDF de Venta debe seguir funcionando igual que hoy mientras se completa la configuración.
