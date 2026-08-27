# Implementation Plan: Recuperación de contraseña por email

**Branch**: `081-reset-password-email` | **Date**: 2026-08-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/081-reset-password-email/spec.md`

## Summary

Agregar recuperación de contraseña por email al sistema de auth propio del CRM (single-tenant,
sin Breeze/Fortify): un modal desde el login para pedir el link, envío de correo vía SMTP con el
Password Broker estándar de Laravel sobre el modelo `User`/tabla `usuarios` ya existente, una
pantalla para definir la nueva contraseña, y como alcance secundario (P3) un modal de cambio de
contraseña con sesión activa desde el perfil. Toda interacción AJAX + modal Bootstrap + toasts,
sin recargar página, según las reglas de diseño obligatorias del proyecto.

## Technical Context

**Language/Version**: PHP 8.2, Laravel 12

**Primary Dependencies**: `Illuminate\Auth\Passwords\PasswordBroker` (núcleo de Laravel, ya
disponible sin instalar paquetes nuevos), Bootstrap 5 (NexaDash), jQuery + AJAX, Toastr

**Storage**: MySQL — nueva tabla `password_reset_tokens` (migración estándar de Laravel, no existe
todavía en este proyecto)

**Testing**: PHPUnit (Feature tests) — obligatorio por tratarse de un flujo de seguridad/acceso,
aunque la constitución sólo exige testing estricto para lógica fiscal/dinero; se cubre igual por
ser autenticación (superficie de ataque) y por FR-003/FR-006/FR-008/FR-011 (comportamientos no
observables a simple vista en el navegador)

**Target Platform**: Servidor web Linux (mismo VPS/hosting compartido del CRM)

**Project Type**: Web (Laravel monolito con Blade — no aplica split frontend/backend)

**Performance Goals**: N/A (flujo de baja frecuencia, sin requisitos de throughput especiales)

**Constraints**: El correo se envía por SMTP configurado en `.env` (cuenta la va a crear el
usuario del proyecto); mientras no esté configurada, debe degradar sin romper (driver `log` en
local). Mensajes de error/confirmación no deben revelar si un email existe (FR-003).

**Scale/Scope**: Un solo tipo de usuario interno del CRM (no hay clientes externos con login);
volumen bajo (equipo interno del negocio).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **Principio I (Documentación de dominio como fuente de verdad)**: `docs/documentacion_principal_crm.md`
  §1 ya documenta `usuarios` como "tabla estándar de autenticación de Laravel". Esta feature no
  cambia esa tabla; sólo agrega `password_reset_tokens` (tabla auxiliar, sin datos de negocio) y
  documenta el flujo en la sección de Usuarios y Permisos. Cumple — se actualiza el doc antes de
  `tasks` con la tabla nueva y el flujo.
- **Principio II (Desarrollo spec-driven)**: se está siguiendo la cadena completa specify → plan →
  tasks. Cumple.
- **Principio III (Corrección fiscal ARCA)**: no aplica — esta feature no toca comprobantes,
  CAE ni cálculos fiscales.
- **Principio IV (Testing donde hay dinero o impacto fiscal)**: no hay impacto fiscal/dinero, pero
  se decide igualmente cubrir con tests de Feature por ser autenticación (ver Testing arriba). No
  es una violación, es un refuerzo voluntario — no requiere justificación de complejidad.
- **Principio V (Convenciones Laravel + dominio en español)**: se usa el mecanismo estándar de
  Laravel (Password Broker) tal cual, sin pelear contra el framework; los textos de UI y rutas
  nombradas van en español (`contrasena.olvide`, `contrasena.resetear`, etc.), igual que el resto
  del proyecto. Cumple.

Sin violaciones. No aplica Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/081-reset-password-email/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

Proyecto Laravel monolito existente (Blade + Vite), se extiende la carpeta `Auth` ya presente:

```text
app/
├── Http/
│   ├── Controllers/
│   │   └── Auth/
│   │       ├── AuthenticatedSessionController.php   # existente (login/logout)
│   │       ├── PasswordResetLinkController.php       # nuevo: pedir link (AJAX)
│   │       └── NewPasswordController.php             # nuevo: definir nueva contraseña
│   └── Requests/
│       └── Auth/
│           ├── LoginRequest.php                       # existente
│           └── NewPasswordRequest.php                 # nuevo: valida password+confirmación
├── Http/Controllers/
│   └── PerfilController.php                           # existente o a extender: cambio de
│                                                        # contraseña con sesión activa (US3)
├── Notifications/
│   └── ResetPasswordNotification.php                  # nuevo: notificación por email (mailable)
└── Models/
    └── User.php                                       # existente, sin cambios de esquema

database/migrations/
└── xxxx_xx_xx_create_password_reset_tokens_table.php  # nuevo (migración estándar Laravel)

resources/views/
├── auth/
│   ├── login.blade.php                                # existente, agrega modal "olvidé mi contraseña"
│   └── reset-password.blade.php                        # nuevo: pantalla para definir nueva contraseña
└── emails/
    └── reset-password.blade.php                        # nuevo: plantilla del correo

resources/js/
└── auth-password.js                                    # nuevo: AJAX de los modales/formularios

routes/
└── auth.php                                            # extender con rutas de reset

tests/Feature/
└── PasswordResetTest.php                               # nuevo
```

**Structure Decision**: Se extiende la estructura MVC estándar de Laravel ya usada por el
proyecto (`app/Http/Controllers/Auth/`, `routes/auth.php`, `resources/views/auth/`), sin crear
carpetas ni patrones nuevos. No hay separación frontend/backend — Blade + AJAX sobre el mismo
monolito, como el resto del CRM.

## Complexity Tracking

No aplica — sin violaciones de la Constitution Check.
