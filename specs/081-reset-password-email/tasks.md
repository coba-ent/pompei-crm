---

description: "Task list for feature implementation"
---

# Tasks: Recuperación de contraseña por email

**Input**: Design documents from `/specs/081-reset-password-email/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/rutas-auth.md, quickstart.md

**Tests**: Incluidos — la constitución exige testing reforzado en superficies de autenticación/seguridad (ver plan.md, Constitution Check).

**Organization**: Tareas agrupadas por historia de usuario para permitir implementación y prueba independiente de cada una.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Puede ejecutarse en paralelo (archivos distintos, sin dependencias)
- **[Story]**: Historia de usuario a la que pertenece (US1, US2, US3)
- Se incluyen paths exactos de archivo en cada descripción

## Path Conventions

Proyecto Laravel monolito único — `app/`, `resources/`, `routes/`, `database/`, `tests/` en la raíz del repo (ver plan.md § Project Structure).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Preparar la infraestructura de base para el flujo de reseteo de contraseña.

- [x] T001 ~~Publicar/crear la migración de `password_reset_tokens`~~ — no hizo falta: la tabla ya existe desde la migración base `0001_01_01_000000_create_users_table.php` del skeleton de Laravel (esquema idéntico al de `data-model.md`)
- [x] T002 Confirmado con `Schema::getColumnListing('password_reset_tokens')` en tinker (local y sqlite de test) que la tabla existe con las columnas esperadas

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Piezas compartidas por las tres historias — deben estar listas antes de implementar cualquier historia.

**⚠️ CRITICAL**: Ninguna historia puede empezar hasta terminar esta fase.

- [x] T003 Crear `App\Notifications\ResetPasswordNotification` (implementa `ShouldQueue`) en `app/Notifications/ResetPasswordNotification.php`, con texto en español y link a `contrasena.resetear`
- [x] T004 Crear la plantilla del correo en `resources/views/emails/reset-password.blade.php` (texto en español, branding del negocio)
- [x] T005 Sobrescribir `sendPasswordResetNotification()` en `app/Models/User.php` para usar `ResetPasswordNotification` en vez de la notificación default de Laravel
- [x] T006 Confirmar en `config/mail.php`/`.env.example` que `MAIL_MAILER` soporta `smtp` y `log` sin cambios adicionales de código (sólo documentar variables esperadas)

**Checkpoint**: Notificación y modelo `User` listos — las historias de usuario pueden implementarse.

---

## Phase 3: User Story 1 - Solicitar link de recuperación desde el login (Priority: P1) 🎯 MVP

**Goal**: El usuario pide, desde el login, un link de recuperación por email, sin revelar si la cuenta existe.

**Independent Test**: Ingresar un email existente en el modal del login y verificar que se genera/envía el correo con el link (ver quickstart.md Escenario 1); repetir con un email inexistente y verificar que la respuesta es idéntica y no se genera correo.

### Tests for User Story 1 ⚠️

> **NOTE: Escribir estos tests PRIMERO, confirmar que fallan antes de implementar**

- [x] T007 [P] [US1] Feature test: pedir link con email existente devuelve mensaje genérico y encola la notificación, en `tests/Feature/PasswordResetLinkTest.php`
- [x] T008 [P] [US1] Feature test: pedir link con email inexistente devuelve el mismo mensaje genérico y NO encola notificación, en `tests/Feature/PasswordResetLinkTest.php`
- [x] T009 [P] [US1] Feature test: pedir link con email de formato inválido devuelve 422, en `tests/Feature/PasswordResetLinkTest.php`
- [x] T010 [P] [US1] Feature test: pedidos repetidos en menos de 60s son descartados por throttle sin exponer el rate limit al usuario, en `tests/Feature/PasswordResetLinkTest.php`

### Implementation for User Story 1

- [x] T011 [US1] Crear `App\Http\Controllers\Auth\PasswordResetLinkController` con método `store()` que use `Password::sendResetLink()`, en `app/Http/Controllers/Auth/PasswordResetLinkController.php`
- [x] T012 [US1] Agregar ruta `POST /olvide-mi-contrasena` → `contrasena.enviar-link` en `routes/auth.php` (grupo `middleware('guest')`), según contrato en `contracts/rutas-auth.md`
- [x] T013 [US1] Agregar el link "¿Olvidaste tu contraseña?" y el modal Bootstrap (campo email) en `resources/views/auth/login.blade.php`
- [x] T014 [US1] Implementar el envío AJAX del modal (validación de formato + toast de resultado) en `resources/js/auth-password.js`
- [x] T015 [US1] Registrar/importar `auth-password.js` en el layout o vista de login correspondiente (Vite)

**Checkpoint**: User Story 1 funcional y testeable de forma independiente (el usuario puede pedir el link; falta aún poder usarlo).

---

## Phase 4: User Story 2 - Definir nueva contraseña desde el link del email (Priority: P1)

**Goal**: El usuario abre el link recibido y define una nueva contraseña, quedando su cuenta actualizada.

**Independent Test**: Generar manualmente un token válido, abrir el link, definir nueva contraseña, loguearse con ella; reabrir el mismo link y verificar que es rechazado (ver quickstart.md Escenario 2).

### Tests for User Story 2 ⚠️

- [x] T016 [P] [US2] Feature test: token válido + password válida actualiza la contraseña, invalida el token y responde éxito, en `tests/Feature/PasswordResetTest.php`
- [x] T017 [P] [US2] Feature test: password y confirmación no coinciden devuelve 422 sin actualizar, en `tests/Feature/PasswordResetTest.php`
- [x] T018 [P] [US2] Feature test: token ya usado o vencido es rechazado con mensaje claro, en `tests/Feature/PasswordResetTest.php`
- [x] T019 [P] [US2] Feature test: tras reseteo exitoso el usuario NO queda logueado automáticamente, en `tests/Feature/PasswordResetTest.php`

### Implementation for User Story 2

- [x] T020 [P] [US2] Crear `App\Http\Requests\Auth\NewPasswordRequest` con reglas de complejidad reutilizadas del alta de usuarios, en `app/Http/Requests/Auth/NewPasswordRequest.php`
- [x] T021 [US2] Crear `App\Http\Controllers\Auth\NewPasswordController` con `create()` (muestra formulario) y `store()` (usa `Password::reset()`), en `app/Http/Controllers/Auth/NewPasswordController.php` (depende de T020)
- [x] T022 [US2] Agregar rutas `GET /resetear-contrasena/{token}` → `contrasena.resetear` y `POST /resetear-contrasena` → `contrasena.actualizar` en `routes/auth.php`, según contrato en `contracts/rutas-auth.md`
- [x] T023 [US2] Crear vista `resources/views/auth/reset-password.blade.php` (formulario con email precargado no editable, token oculto; muestra mensaje de link inválido si corresponde)
- [x] T024 [US2] Implementar el envío AJAX del formulario de nueva contraseña (toast de éxito + redirect a login) en `resources/js/auth-password.js`

**Checkpoint**: Historias 1 y 2 completan el flujo de recuperación de punta a punta (MVP completo).

---

## Phase 5: User Story 3 - Cambio de contraseña propio desde una sesión activa (Priority: P3)

**Goal**: Un usuario logueado cambia su contraseña sabiendo la actual, desde su perfil, vía modal AJAX.

**Independent Test**: Logueado, abrir el modal de cambio de contraseña en el perfil, cambiarla con la actual + nueva válida, verificar que el próximo login requiere la nueva (ver quickstart.md Escenario 3).

### Tests for User Story 3 ⚠️

- [x] T025 [P] [US3] Feature test: contraseña actual correcta + nueva válida actualiza la contraseña, en `tests/Feature/PerfilPasswordUpdateTest.php`
- [x] T026 [P] [US3] Feature test: contraseña actual incorrecta devuelve 422 sin actualizar, en `tests/Feature/PerfilPasswordUpdateTest.php`

### Implementation for User Story 3

- [x] T027 [US3] Agregar método `actualizarPassword()` a `App\Http\Controllers\MiPerfilController` (`app/Http/Controllers/MiPerfilController.php`) que valide contraseña actual + nueva
- [x] T028 [US3] Agregar ruta `PUT /configuracion/mi-perfil/contrasena` → `configuracion.mi-perfil.contrasena.actualizar` dentro del grupo `Route::middleware('admin')->prefix('mi-perfil')->name('mi-perfil.')` ya existente en `routes/web.php:557`, según contrato en `contracts/rutas-auth.md`
- [x] T029 [US3] Agregar el modal Bootstrap "Cambiar contraseña" (contraseña actual, nueva, confirmación) en la vista de `mi-perfil` (`resources/views/**/mi-perfil/*.blade.php`) existente
- [x] T030 [US3] Implementar el envío AJAX del modal de cambio de contraseña (toast de resultado, sin recarga) en `resources/js/auth-password.js`

**Checkpoint**: Las tres historias funcionan de forma independiente.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Cierre de calidad y consistencia con las reglas del proyecto.

- [x] T031 [P] Actualizar `docs/documentacion_principal_crm.md` §5 (Configuración & Ajustes / Empresa) documentando el flujo de recuperación de contraseña — hecho durante `/speckit-plan` (regla de la constitución: docs se actualizan antes de `tasks`)
- [x] T032 [P] Actualizar `docs/modelo_datos.md` agregando la tabla `password_reset_tokens` (ver data-model.md) — hecho durante `/speckit-plan`
- [x] T033 Documentar en `.env.example` las variables `MAIL_*` esperadas para producción (host/usuario/password SMTP a completar por el usuario del proyecto)
- [x] T034 N/A — no se creó ni reseteó ningún acceso de prueba durante la implementación (tests usan `User::factory()`, no cuentas reales); si se hace una prueba manual en el navegador con una cuenta real, actualizar `CREDENCIALES_ACCESO.txt` en ese momento (FR-014)
- [ ] T035 Ejecutar manualmente `quickstart.md` completo en el navegador (los 3 escenarios + validación de rate limiting) — los escenarios equivalentes están cubiertos por los Feature tests automatizados (T007-T026), pero falta la pasada manual en navegador real
- [x] T036 Revisar que ningún mensaje de error/log expuesto al usuario revele si un email existe o no (FR-003, FR-012)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: sin dependencias — puede arrancar de inmediato
- **Foundational (Phase 2)**: depende de Setup — bloquea todas las historias
- **User Stories (Phase 3-5)**: dependen de Foundational
  - US1 y US2 comparten el mismo archivo JS (`resources/js/auth-password.js`) y el mismo Password Broker — se recomienda implementarlas en orden (US1 → US2) aunque sean conceptualmente independientes
  - US3 es independiente de US1/US2 (no depende del Password Broker ni de tokens), puede hacerse en paralelo por otro desarrollador
- **Polish (Phase 6)**: depende de que las historias que se vayan a entregar estén completas

### User Story Dependencies

- **User Story 1 (P1)**: puede empezar tras Foundational — sin dependencias de otras historias
- **User Story 2 (P1)**: puede empezar tras Foundational; en la práctica requiere que existan links generados por US1 para probarse end-to-end, pero su implementación de backend/rutas es independiente
- **User Story 3 (P3)**: puede empezar tras Foundational — totalmente independiente de US1/US2

### Within Each User Story

- Tests primero, deben fallar antes de implementar
- Requests/Models antes que Controllers
- Controllers antes que rutas/vistas
- Implementación core antes que integración JS/AJAX

### Parallel Opportunities

- T007-T010 (tests US1) en paralelo entre sí
- T016-T019 (tests US2) en paralelo entre sí
- T025-T026 (tests US3) en paralelo entre sí
- US3 completa (Phase 5) puede hacerse en paralelo a US1+US2 por otro desarrollador
- T031-T032 (docs) en paralelo entre sí y con T033-T034

---

## Parallel Example: User Story 1

```bash
# Lanzar todos los tests de User Story 1 juntos:
Task: "Feature test: pedir link con email existente en tests/Feature/PasswordResetLinkTest.php"
Task: "Feature test: pedir link con email inexistente en tests/Feature/PasswordResetLinkTest.php"
Task: "Feature test: email con formato inválido en tests/Feature/PasswordResetLinkTest.php"
Task: "Feature test: throttle de pedidos repetidos en tests/Feature/PasswordResetLinkTest.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 + User Story 2)

1. Completar Phase 1: Setup
2. Completar Phase 2: Foundational (bloqueante)
3. Completar Phase 3: User Story 1
4. Completar Phase 4: User Story 2
5. **STOP y VALIDAR**: correr `quickstart.md` Escenarios 1 y 2 — flujo de recuperación completo
6. Demo/deploy si está listo (User Story 1 sola no tiene valor de producto sin User Story 2 — a diferencia del template genérico, acá el MVP real de esta feature es US1+US2 juntas)

### Incremental Delivery

1. Setup + Foundational → base lista
2. US1 + US2 → flujo de recuperación por email completo (MVP real) → validar con quickstart.md
3. US3 → comodidad adicional (cambio de contraseña logueado) → validar con quickstart.md Escenario 3
4. Polish → docs, credenciales, revisión de seguridad final

### Parallel Team Strategy

1. Equipo completa Setup + Foundational junto
2. Con Foundational listo:
   - Developer A: User Story 1 → User Story 2 (comparten archivo JS y Password Broker, mejor en secuencia)
   - Developer B: User Story 3 (independiente)
3. Ambos integran en Polish

---

## Notes

- [P] = archivos distintos, sin dependencias
- [Story] mapea cada tarea a su historia de usuario para trazabilidad
- El MVP real de esta feature es US1+US2 combinadas (pedir link sin poder usarlo no entrega valor de producto)
- Verificar que los tests fallan antes de implementar
- Commitear después de cada tarea o grupo lógico
- No dar la feature por terminada sin correr `quickstart.md` completo y sin actualizar `CREDENCIALES_ACCESO.txt` si aplica
