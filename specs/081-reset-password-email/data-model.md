# Data Model: Recuperación de contraseña por email

## `password_reset_tokens` (nueva tabla)

Migración estándar de Laravel (`Illuminate\Auth\Passwords\DatabaseTokenRepository`), sin
personalización de esquema.

| Campo | Tipo | Notas |
|-------|------|-------|
| `email` | string, PK | Email del usuario que pidió el reseteo (coincide con `usuarios.email`) |
| `token` | string | Hash del token enviado por email (nunca se guarda el token en claro) |
| `created_at` | timestamp, nullable | Momento de generación; usado para calcular expiración (60 min) |

Reglas:
- Un solo registro activo por email — al pedir un nuevo link se sobreescribe el anterior
  (comportamiento nativo del `DatabaseTokenRepository`), cumpliendo FR-006.
- No tiene relación de FK formal con `usuarios` (se vincula por `email`, igual que el mecanismo
  estándar de Laravel) — evita acoplar la tabla a la PK de `usuarios` innecesariamente.
- Sin soft delete: no es un documento fiscal/contable (constitución principio III no aplica acá),
  y los registros son efímeros por diseño (expiran/se consumen).

## `usuarios` (existente — sin cambios de esquema)

Esta feature no agrega columnas a `usuarios`. Se usa el campo `password` ya existente (hasheado,
`Hash::make`) para la actualización.

## Notificación de reseteo (no persistida)

No es una entidad de base de datos — es una notificación (`App\Notifications\ResetPasswordNotification`)
que se envía en el momento y no deja rastro en tabla salvo, opcionalmente, el log de aplicación en
caso de fallo de envío (FR-012), usando el canal de logging estándar de Laravel, no una tabla
nueva.
