# Data Model: Cierre de Facturación Electrónica — PDF NC/ND, Mi Perfil y Recibos

## DatosEmpresa (tabla nueva: `datos_empresa`)

Fila única (single-tenant) con los datos fiscales del negocio emisor, consumida por los
documentos imprimibles del CRM (Venta, NC/ND, Recibo).

| Campo             | Tipo             | Notas                                                        |
|--------------------|------------------|---------------------------------------------------------------|
| `id`               | bigint PK        | —                                                              |
| `razon_social`     | string, nullable | —                                                              |
| `cuit`              | string(11), nullable | sin guiones, mismo formato que `certificados_fiscales.cuit` |
| `domicilio_fiscal` | string, nullable | —                                                              |
| `condicion_iva`    | string, nullable | mismo catálogo de Condición de IVA ya usado en Cliente/Proveedor |
| `ingresos_brutos`  | string, nullable | opcional (FR-005)                                              |
| `ruta_logo`        | string, nullable | ruta relativa en disco público (`storage/app/public/empresa/`) |
| `created_at` / `updated_at` | timestamp | —                                                     |

**Reglas de validación**:
- `cuit`, si se completa, debe tener 11 dígitos numéricos (mismo formato que `Cliente.cuit`).
- `ruta_logo`: sólo se persiste si el archivo subido es una imagen válida (jpg/png/webp), FR-014.
- No hay `SoftDeletes` — es configuración, no un registro de negocio con historial fiscal.

**Acceso**: método estático `DatosEmpresa::instancia(): ?self` que devuelve la única fila (o
`null` si nunca se cargó), análogo a `CertificadoFiscal::activo()`.

## NotaCreditoDebito (existente — sin cambios de esquema)

Ya expone `comprobanteFiscal(): MorphOne` (`app/Models/NotaCreditoDebito.php:46-49`), reutilizado
tal cual para el PDF nuevo. No se agregan columnas: los datos del comprobante ajustado se leen de
`NotaCreditoDebito::venta->comprobanteFiscal` (relación existente `Venta::comprobanteFiscal`,
spec 034) en el momento de generar el PDF — no se duplican en la NC/ND.

## Recibo (no es una tabla — vista derivada)

No se crea entidad. El PDF de Recibo se arma a partir de:
- **Recibo de Cobro**: `Cobranza` existente (Venta) + `Venta::cliente` + `Cobranza::cuenta`
  (Tesorería, medio de cobro).
- **Recibo de Pago**: `Pago` existente (Compra) + `Compra::proveedor` + `Pago::cuenta`.

Numeración interna mostrada en el PDF: `REC-{id}` derivado del `id` de la Cobranza/Pago (ver
`research.md` §3) — no persiste en ningún lado nuevo.

## ComprobanteFiscal (existente — sin cambios de esquema)

Reutilizado sin modificaciones (spec 034). El PDF de NC/ND consulta
`NotaCreditoDebito::comprobanteFiscal->aprobado()` para decidir si oculta el watermark, igual que
ya hace `ventas/pdf.blade.php` con `Venta::comprobanteFiscal`.
