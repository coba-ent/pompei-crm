# Contrato: rutas de recuperación de contraseña

Todas nuevas, agregadas a `routes/auth.php` dentro del grupo `middleware('guest')` salvo donde se
indique. Respuestas AJAX en JSON; la constitución exige nombres de ruta en español.

## POST `/olvide-mi-contrasena` → `contrasena.enviar-link`

Pide el link de recuperación (Historia 1).

**Request** (JSON o form-data, vía AJAX del modal):
```json
{ "email": "usuario@ejemplo.com" }
```

**Response 200** (siempre el mismo mensaje genérico, exista o no el email — FR-003):
```json
{ "message": "Si el email existe, te enviamos un link para recuperar tu contraseña." }
```

**Response 422** (email con formato inválido — FR-002):
```json
{ "errors": { "email": ["El email no tiene un formato válido."] } }
```

Nota: si el pedido es descartado por rate limiting (FR-011), la respuesta sigue siendo 200 con el
mismo mensaje genérico — nunca se expone el throttling al usuario.

---

## GET `/resetear-contrasena/{token}` → `contrasena.resetear`

Muestra la pantalla para definir nueva contraseña (Historia 2). Requiere `email` como query param
(estándar del link generado por `Password::sendResetLink`).

**Response 200**: vista Blade con formulario (token y email precargados, email no editable).

**Response**: si el token no existe/está vencido, la vista muestra el mensaje de link inválido en
vez del formulario (no es un error HTTP distinto — se resuelve en la misma vista, ver Edge Case).

---

## POST `/resetear-contrasena` → `contrasena.actualizar`

Confirma la nueva contraseña (Historia 2).

**Request**:
```json
{
  "token": "...",
  "email": "usuario@ejemplo.com",
  "password": "nuevaClave123",
  "password_confirmation": "nuevaClave123"
}
```

**Response 200** (éxito — FR-010):
```json
{ "message": "Contraseña actualizada. Ya podés iniciar sesión." }
```

**Response 422** (token inválido/vencido, o password no cumple validación/confirmación — FR-008,
FR-009):
```json
{ "errors": { "email": ["Este link ya no es válido. Pedí uno nuevo desde el login."] } }
```

---

## PUT `/configuracion/mi-perfil/contrasena` → `configuracion.mi-perfil.contrasena.actualizar` (dentro del grupo existente `routes/web.php:557`, `middleware('admin')`)

Cambio de contraseña con sesión activa (Historia 3, modal desde el perfil).

**Request**:
```json
{
  "password_actual": "claveActual",
  "password": "nuevaClave123",
  "password_confirmation": "nuevaClave123"
}
```

**Response 200**:
```json
{ "message": "Contraseña actualizada correctamente." }
```

**Response 422** (password_actual incorrecta, o nueva password no válida/no coincide):
```json
{ "errors": { "password_actual": ["La contraseña actual no es correcta."] } }
```
