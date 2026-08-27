# Research: Recuperación de contraseña por email

## Decisión 1: Mecanismo de generación/validación de tokens

**Decision**: Usar el `PasswordBroker` estándar de Laravel (`Illuminate\Auth\Passwords`) con la
tabla `password_reset_tokens` (migración estándar, no personalizada), operando sobre el modelo
`User` ya existente (tabla `usuarios`).

**Rationale**: El modelo `User` (`app/Models/User.php`) ya extiende `Authenticatable` y el
proyecto usa el guard `web` de Laravel para login/logout (`AuthenticatedSessionController`). El
Password Broker es el mecanismo nativo de Laravel para este flujo exacto (token hasheado,
expiración configurable, invalidación de tokens previos) y no requiere Breeze/Fortify — sólo usa
componentes del núcleo (`Password::sendResetLink()`, `Password::reset()`). Reutilizarlo respeta
el principio V de la constitución (no pelear contra el framework) y evita reinventar lógica de
seguridad de tokens.

**Alternatives considered**:
- Tabla y lógica de tokens 100% custom: descartado, duplicaría trabajo ya resuelto y probado por
  el framework sin ganar nada, y complicaría el mantenimiento.
- Instalar Laravel Breeze/Fortify sólo para este flujo: descartado, el proyecto tiene un sistema
  de auth propio (roles/permisos custom) y traer un paquete completo de scaffolding para una sola
  feature sería una dependencia desproporcionada; el Password Broker se usa standalone sin ellos.

## Decisión 2: Envío del correo

**Decision**: Notificación custom `App\Notifications\ResetPasswordNotification` (implementa
`ShouldQueue` para no bloquear el request) que reemplaza la notificación default de Laravel,
enviada vía `Notification::send()` o sobrescribiendo `sendPasswordResetNotification()` en `User`.
La configuración SMTP se lee de `.env` (`MAIL_MAILER=smtp` + credenciales), la va a completar el
usuario del proyecto; en local/desarrollo se usa el driver `log` o Mailpit (ya presente en
`.env` actual: `MAIL_HOST=mailpit`).

**Rationale**: Permite controlar el diseño del email (idioma español, branding del negocio) y
loguear fallos de envío sin exponer el error al usuario (FR-012), cumpliendo con "Jobs/colas" de
la constitución (tareas asincrónicas van por colas).

**Alternatives considered**:
- Notificación default de Laravel (`Illuminate\Auth\Notifications\ResetPassword`): descartada
  porque su texto/diseño no está en español ni sigue el branding del negocio.

## Decisión 3: Rate limiting

**Decision**: Usar el rate limiting nativo que ya aplica `Password::sendResetLink()` (throttle de
60 segundos entre pedidos por el mismo email, comportamiento default de Laravel), sin agregar
middleware de throttle adicional en la ruta por ahora.

**Rationale**: Cubre el requisito FR-011 con el comportamiento estándar del framework, evitando
lógica de rate-limiting personalizada. Suficiente para el volumen bajo de usuarios internos del
CRM (Scale/Scope de la feature).

**Alternatives considered**:
- Rate limiter adicional por IP (`Illuminate\Support\Facades\RateLimiter` en la ruta): se deja
  como mejora futura documentada, no bloqueante para este alcance — el throttle por email ya
  cumple el requisito mínimo.

## Decisión 4: Requisitos de complejidad de contraseña

**Decision**: Reutilizar las mismas reglas de validación de contraseña que ya usa el alta de
usuarios en el módulo Usuarios y Permisos (`Illuminate\Validation\Rules\Password`, con el mismo
`->min()`/reglas que el `FormRequest` de creación de usuario existente).

**Rationale**: Evita introducir un segundo estándar de complejidad de contraseña en el mismo
sistema; consistencia con lo ya validado.

**Alternatives considered**: Definir reglas nuevas específicas para el reset — descartado, sin
justificación de negocio para divergir.

## Decisión 5: UI — modal de login y pantalla de nueva contraseña

**Decision**: El pedido de link es un modal Bootstrap + AJAX (Toastr para el resultado), como
exige la regla de diseño obligatoria del proyecto. La pantalla para definir la nueva contraseña
(a la que se llega desde el link del email, fuera de sesión) es una vista Blade normal —no un
modal— porque el usuario llega ahí por navegación directa (URL con token), no por una acción
dentro de una pantalla ya cargada; ese formulario sí se envía por AJAX (sin recargar al terminar,
redirect por JS tras el toast de éxito) para mantener el patrón "nunca se refresca la página para
una operación". El cambio de contraseña con sesión activa (US3) sí es 100% modal, igual que el
resto de operaciones CRUD del CRM.

**Rationale**: Coherente con las reglas obligatorias de UX del proyecto (modal+AJAX+toast) donde
aplica, reconociendo que la pantalla de "definir nueva contraseña" no tiene una pantalla padre
donde anidarse como modal (se accede por link de email estando deslogueado).

**Alternatives considered**: Modal también para la pantalla de nueva contraseña sobre el login —
descartado, técnicamente forzado (obligaría a redirigir al login y auto-abrir un modal con el
token en la URL), sin beneficio de UX real.
