# Quickstart: validar recuperación de contraseña por email

## Prerrequisitos

- Migraciones corridas (incluye la nueva `password_reset_tokens`): `php artisan migrate`
- En local, `MAIL_MAILER=log` (o Mailpit ya configurado en `.env`) para ver el correo sin SMTP real.
- Un usuario existente en `usuarios` con email conocido (ver `CREDENCIALES_ACCESO.txt`).

## Escenario 1 — Pedir el link (Historia 1)

1. Ir a `/login`.
2. Clic en "¿Olvidaste tu contraseña?" → se abre el modal (sin recargar).
3. Ingresar el email de un usuario existente → confirmar.
4. **Esperado**: toast genérico de confirmación; con `MAIL_MAILER=log`, revisar
   `storage/logs/laravel.log` y confirmar que se generó el email con el link de reseteo.
5. Repetir con un email inexistente → **esperado**: mismo toast, sin entrada nueva en el log de
   mails (FR-003).

## Escenario 2 — Definir nueva contraseña (Historia 2)

1. Copiar el link generado en el paso anterior (`/resetear-contrasena/{token}?email=...`).
2. Abrirlo en el navegador → **esperado**: formulario con email precargado (no editable).
3. Completar nueva contraseña + confirmación válidas → enviar.
4. **Esperado**: toast de éxito, redirección a `/login`.
5. Loguearse con la nueva contraseña → **esperado**: login exitoso.
6. Reabrir el mismo link usado en el paso 2 → **esperado**: mensaje de link inválido/vencido, con
   opción de volver a pedir uno nuevo.

## Escenario 3 — Cambio de contraseña logueado (Historia 3, P3)

1. Loguearse, ir a la pantalla de perfil.
2. Abrir el modal de "Cambiar contraseña".
3. Ingresar la contraseña actual (correcta) + nueva contraseña + confirmación → confirmar.
4. **Esperado**: toast de éxito, sin recarga de página.
5. Repetir con contraseña actual incorrecta → **esperado**: error en el modal, sin actualizar nada.

## Validación de límite de frecuencia (Edge case, FR-011)

1. Pedir el link dos veces seguidas para el mismo email en menos de 60 segundos.
2. **Esperado**: ambos pedidos devuelven el mismo toast genérico; sólo se genera/loguea un email
   nuevo la primera vez (o según la ventana de throttle nativa del Password Broker).

## Post-desarrollo

- Si se creó o reseteó a mano algún usuario de prueba durante estas validaciones, actualizar
  `CREDENCIALES_ACCESO.txt` en el mismo cambio (FR-014).
