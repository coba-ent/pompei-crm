# Contrato interno: Emisión de comprobante con CAE

No es una API pública — es el contrato interno entre los controladores de Ventas/Compras
existentes (spec 008/030) y el servicio nuevo `App\Services\Arca\EmisorComprobante`, consumido vía
llamada a método de servicio (mismo patrón que `Tesoreria::registrarMovimiento()`,
`Cobranzas::registrarCobro()` ya existentes).

## `EmisorComprobante::emitir(Model $comprobantable, array $datos): ComprobanteFiscal`

**Input**:
- `$comprobantable`: instancia de `Venta`, `Compra` o `NotaCreditoDebito` ya guardada localmente.
- `$datos`: array normalizado con tipo de comprobante, cliente/proveedor (con datos fiscales),
  ítems con IVA, totales — ya validados por el flujo existente de Ventas/Compras.

**Comportamiento**:
1. Resuelve el `PuntoVenta` activo por defecto (FR-001). Si no hay ninguno configurado, lanza
   `CertificadoNoConfiguradoException` (FR-014: quien llama debe capturarla y usar el fallback sin
   validez fiscal).
2. Valida datos fiscales mínimos para el tipo de comprobante (FR-009) vía
   `ValidadorDatosFiscales`. Si falla, lanza `ArcaRechazoException` con el motivo, sin llamar a
   WSFEv1.
3. Obtiene/renueva el Ticket de Acceso WSAA (cacheado).
4. Mapea `$datos` al formato WSFEv1 vía `MapeadorComprobante`.
5. Llama `FECompUltimoAutorizado` para conocer el próximo número esperado (validación cruzada, no
   asignación local) y luego `FECAESolicitar`.
6. Ante éxito: crea `ComprobanteFiscal` con `estado=aprobado`, `cae`, `cae_vencimiento`, `numero`
   real devuelto por ARCA.
7. Ante rechazo de ARCA (respuesta con observaciones): crea `ComprobanteFiscal` con
   `estado=rechazado`, `motivo_rechazo`, sin `cae`; lanza `ArcaRechazoException` para que el
   controlador muestre el toast (FR-010) y no marque la Venta/Compra como facturada.
8. Ante timeout/caída de red: NO crea `ComprobanteFiscal` (se desconoce si ARCA llegó a procesar);
   lanza `ArcaNoDisponibleException`. El controlador debe permitir reintentar manualmente, y el
   reintento primero llama a `EmisorComprobante::verificarPendiente()` (FR-011) antes de reintentar
   la emisión, para evitar duplicar.
9. Todas las ramas (éxito, rechazo, error) registran un `LogAuditoriaArca` (FR-013).

**Errores** (todas extienden `App\Services\Arca\Excepciones\ArcaException`):
- `CertificadoNoConfiguradoException`: sin certificado/Punto de Venta activo — fallback FR-014.
- `ArcaRechazoException($motivo)`: ARCA respondió pero rechazó — FR-010.
- `ArcaNoDisponibleException`: timeout/caída — FR-010, FR-011.

## `EmisorComprobante::verificarPendiente(ComprobanteFiscal $pendiente): ?ComprobanteFiscal`

Consulta `FECompConsultar` para el punto de venta/tipo/número esperado; si ARCA ya tiene un CAE
asignado para esa operación (emitido pero la respuesta se perdió), actualiza y devuelve el
`ComprobanteFiscal` como `aprobado` sin volver a solicitar CAE (evita duplicados, FR-011). Si ARCA
no tiene registro, devuelve `null` y el llamador puede reintentar `emitir()` con seguridad.
