# Contrato: Enviar Venta a ARCA (manual)

## `POST ventas/{venta}/enviar-arca`

**Middleware**: `auth`, `permiso:ventas.ver` (spec 040 §Clarifications)

**Precondición** (validada en el controlador, no sólo en el flag de UI):
- `venta.tipo_comprobante` ∈ {A, B, C}
- `venta.comprobanteFiscal` es `null` o su `estado` ≠ `aprobado`
- `FuncionAvanzada::activa('facturacion_electronica')` es `true`
- `CertificadoFiscal::activo()` no es `null` (a diferencia del trigger automático eliminado, que
  silenciaba este caso como fallback — ver nota debajo, aquí es una precondición explícita porque el
  usuario decidió a propósito enviar esta Venta)

Si alguna precondición falla, responde `422` con `{ "ok": false, "mensaje": "..." }` explicando el
motivo (Venta sin tipo fiscal, ya enviada, o función desactivada) — sin intentar el envío. El
**código de estado HTTP es la señal que distingue el tipo de feedback en el cliente** (FR-007/FR-007a):
- `422` → rechazo de precondición → el cliente muestra **toast** (nunca se contactó a ARCA).
- `200` (con `ok: true` o `ok: false`) → hubo un intento real contra ARCA → el cliente abre el
  **modal de resultado** (FR-007), nunca un toast, sin importar si fue aprobado o rechazado.

**Éxito** (`200`):
```json
{
  "ok": true,
  "mensaje": "CAE obtenido correctamente." ,
  "comprobante_fiscal": { "cae": "...", "cae_vencimiento": "...", "numero": "..." },
  "puede_enviarse_arca": false
}
```

**Rechazo/Error de ARCA** (`200`, `ok: false` — la Venta no se rompe, sólo no se aprueba):
```json
{
  "ok": false,
  "mensaje": "<motivo del rechazo o de la falla de conexión>",
  "puede_enviarse_arca": true
}
```

Reutiliza el manejo de `ArcaRechazoException`/`ArcaNoDisponibleException` que ya existe en
`emitirComprobanteFiscal()` (hubo un intento real, responde `200` con `ok: false` y el motivo). El
caso `CertificadoNoConfiguradoException` **no** se deja llegar al método privado en este flujo manual
— se valida como precondición explícita (arriba) antes de llamarlo, precisamente porque
`emitirComprobanteFiscal()` la trata como `null` silencioso (pensado para el fallback automático de la
spec 034, FR-014), comportamiento que sería confuso para un click explícito del usuario.
