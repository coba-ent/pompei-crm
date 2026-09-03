# Contract: Envío manual a ARCA de una NC/ND

## POST `{venta}/notas/{notaCreditoDebito}/enviar-arca` (nombre de ruta: `ventas.notas.enviarArca`)
## POST `{compra}/notas/{notaCreditoDebito}/enviar-arca` (nombre de ruta: `compras.notas.enviarArca`)

Análogas a `ventas.enviarArca` de spec 040, con el mismo verbo/patrón, pero anidadas bajo la nota (no bajo
la Venta/Compra) porque el sujeto de la acción es la NC/ND.

**Precondiciones** (rechazo antes de contactar ARCA — HTTP 422, cuerpo `{ok: false, motivo: string}`,
mostrado por **toast**, FR-006a):
- `FuncionAvanzada::activa('facturacion_electronica')` es `false`.
- La nota ya tiene `comprobanteFiscal` propio con `estado='aprobado'`.
- El `tipo_comprobante` de la nota no está en `{A,B,C}`.
- El comprobante original (`venta`/`compra`) no tiene `comprobanteFiscal` con `estado='aprobado'`.
- No hay certificado fiscal configurado (mismo chequeo que ya hace `EmisorComprobante`/`CertificadoNoConfiguradoException`).

**Éxito / rechazo real de ARCA** (HTTP 200, cuerpo `{ok: bool, cae, cae_vencimiento, motivo}` — mostrado
por el modal de resultado, FR-006):
- `ok: true` + `cae` + `cae_vencimiento` cuando `EmisorComprobante::emitir()` no lanza excepción.
- `ok: false` + `motivo` (mensaje de `ArcaRechazoException`/`ArcaNoDisponibleException`) cuando ARCA
  rechaza o no está disponible — la nota queda con su intento fallido registrado en `comprobantes_fiscales`
  (mismo comportamiento que ya tiene `EmisorComprobante`, no se modifica).

**Respuesta** (JSON, ejemplo éxito):
```json
{
  "ok": true,
  "cae": "86338366473746",
  "cae_vencimiento": "2026-09-13",
  "estado_arca": "aprobado"
}
```

**Idempotencia / doble click**: mismo resguardo ya existente en `EmisorComprobante::emitir()` (no se
modifica) — un segundo intento sobre una nota ya aprobada es rechazado como precondición (ya cubierto por
la primera regla de la lista de arriba, reevaluada en cada request).

## Cambio de contrato interno: `NotaCreditoDebitoController::emitirComprobanteFiscalNota()`

No es un endpoint HTTP — es el método privado que arma `$datos` para `EmisorComprobante::emitir()`. Cambia
de invocarse automáticamente desde `store()`/`storeCompra()` a invocarse desde las dos acciones nuevas de
arriba. Su firma no cambia; cambia el contenido que arma internamente (agrega `items`, ver
`data-model.md`).

## Vista: indicador de estado ARCA (US4, sin endpoint nuevo)

El Detalle de Venta/Compra ya carga `NotaCreditoDebito::with('comprobanteFiscal')` (o se agrega ese eager
load si falta) para poder mostrar `estado_arca` sin N+1 queries por fila de la tabla de notas.
