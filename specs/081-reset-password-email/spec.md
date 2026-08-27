# Feature Specification: Recuperación de contraseña por email

**Feature Branch**: `081-reset-password-email`

**Created**: 2026-08-25

**Status**: Draft

**Input**: User description: "Recuperación / cambio de contraseña por email: el usuario que olvidó su contraseña puede pedir un link de reseteo desde la pantalla de login, ingresando su email; el sistema le envía un correo (vía SMTP, cuenta a configurar en .env) con un link temporal y de un solo uso; al hacer clic define una nueva contraseña. Sistema de auth es propio (single-tenant, roles/permisos propios en tablas roles/permisos, sin Breeze/Fortify instalado — sólo AuthenticatedSessionController para login/logout en routes/auth.php). Debe seguir el patrón estándar de Laravel (tabla password_reset_tokens, Password broker, notificación por email) pero con las pantallas/modales siguiendo las reglas de diseño obligatorias del proyecto (modal Bootstrap + AJAX donde aplique, toasts, sin recargar página cuando sea posible). Actualizar CREDENCIALES_ACCESO.txt si se crea/resetea algún acceso de prueba durante el desarrollo."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Solicitar link de recuperación desde el login (Priority: P1)

Un usuario del CRM que olvidó su contraseña está en la pantalla de login. Hace clic en "¿Olvidaste tu contraseña?", ingresa su email en un modal, y el sistema le envía un correo con un link de recuperación válido por tiempo limitado.

**Why this priority**: Es el punto de entrada de todo el flujo; sin esto no hay recuperación posible. Es la funcionalidad mínima indispensable.

**Independent Test**: Se puede probar completamente ingresando un email de un usuario existente en el modal y verificando que llega un correo con un link único. Entrega valor por sí sola (el usuario sabe que el sistema reconoció su pedido) aunque el resto del flujo esté incompleto.

**Acceptance Scenarios**:

1. **Given** la pantalla de login, **When** el usuario hace clic en "¿Olvidaste tu contraseña?", **Then** se abre un modal Bootstrap pidiendo el email, sin recargar la página.
2. **Given** el modal de recuperación abierto, **When** el usuario ingresa el email de una cuenta activa y confirma, **Then** el sistema muestra un toast de confirmación genérico ("si el email existe, te enviamos un link") y dispara el envío del correo por AJAX.
3. **Given** el modal de recuperación abierto, **When** el usuario ingresa un email que no corresponde a ninguna cuenta, **Then** el sistema muestra el mismo toast genérico de confirmación (no revela si el email existe o no) y no envía ningún correo.
4. **Given** el modal de recuperación abierto, **When** el usuario ingresa un email con formato inválido, **Then** el sistema muestra un error de validación en el modal sin cerrarlo.

---

### User Story 2 - Definir nueva contraseña desde el link del email (Priority: P1)

El usuario recibe el correo, hace clic en el link, llega a una pantalla para definir su nueva contraseña, la confirma dos veces, y queda con su cuenta actualizada.

**Why this priority**: Completa el flujo — sin esto, pedir el link no tiene efecto. Junto con la Historia 1 forma el MVP completo de la feature.

**Independent Test**: Se puede probar generando manualmente un token válido en la tabla de reseteo, accediendo al link, y verificando que la contraseña se actualiza y permite loguearse con la nueva clave.

**Acceptance Scenarios**:

1. **Given** un link de recuperación válido y no usado, **When** el usuario lo abre, **Then** ve un formulario para ingresar nueva contraseña y su confirmación (mismo email precargado, no editable).
2. **Given** el formulario de nueva contraseña, **When** el usuario ingresa una contraseña que cumple los requisitos mínimos y coincide con la confirmación, **Then** el sistema actualiza la contraseña, invalida el link usado, muestra un toast de éxito y lo lleva al login.
3. **Given** el formulario de nueva contraseña, **When** las dos contraseñas ingresadas no coinciden, **Then** el sistema muestra un error sin perder los datos ya tipeados donde sea razonable.
4. **Given** un link de recuperación ya usado o vencido, **When** el usuario intenta abrirlo, **Then** el sistema muestra un mensaje explicando que el link ya no es válido y ofrece volver a pedir uno nuevo desde el login.

---

### User Story 3 - Cambio de contraseña propio desde una sesión activa (Priority: P3)

Un usuario logueado que sabe su contraseña actual quiere cambiarla por una nueva, desde su perfil, sin pasar por el flujo de email.

**Why this priority**: Es una comodidad complementaria (evita depender del correo cuando el usuario ya está autenticado), pero no es parte del pedido original del usuario y no bloquea el flujo de recuperación por email. Se incluye como alcance opcional de menor prioridad.

**Independent Test**: Logueado, se puede probar entrando a la pantalla de perfil, cambiando la contraseña con la actual + nueva, y verificando que el próximo login requiere la nueva.

**Acceptance Scenarios**:

1. **Given** un usuario logueado en su pantalla de perfil, **When** ingresa su contraseña actual correcta más una nueva contraseña válida y su confirmación, **Then** el sistema actualiza la contraseña y muestra un toast de éxito, sin recargar la página.
2. **Given** un usuario logueado en su pantalla de perfil, **When** ingresa una contraseña actual incorrecta, **Then** el sistema muestra un error sin actualizar nada.

---

### Edge Cases

- ¿Qué pasa si el usuario pide varios links de recuperación seguidos para el mismo email? El sistema debe invalidar los links anteriores y dejar sólo el último como válido (comportamiento estándar del Password Broker de Laravel).
- ¿Qué pasa si se hace clic en el link de recuperación dos veces (una vez ya usado)? Debe rechazarse la segunda vez con el mensaje de link inválido/vencido.
- ¿Qué pasa si el usuario está desactivado (borrado lógico / inactivo) y pide recuperar contraseña? No se envía correo (mismo mensaje genérico que un email inexistente), para no revelar el estado de la cuenta.
- ¿Qué pasa si el servidor SMTP falla al momento de enviar? El usuario ve igual el mensaje genérico de confirmación (para no filtrar información), pero el error queda registrado en logs de la aplicación para diagnóstico.
- ¿Qué pasa si alguien pide recuperación repetidamente en poco tiempo (abuso/spam)? El sistema debe aplicar un límite de frecuencia por email/IP antes de reenviar otro correo.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: La pantalla de login MUST mostrar un enlace "¿Olvidaste tu contraseña?" que abre un modal Bootstrap para pedir el email, sin recargar la página.
- **FR-002**: El sistema MUST validar el formato del email en el modal antes de enviar el pedido.
- **FR-003**: El sistema MUST responder siempre con el mismo mensaje genérico de confirmación al pedido de recuperación, exista o no una cuenta con ese email, para no revelar la existencia de cuentas.
- **FR-004**: El sistema MUST generar un token de recuperación único, de un solo uso, con expiración por tiempo, asociado al email solicitante.
- **FR-005**: El sistema MUST enviar un correo electrónico con un link que incluya ese token, usando la cuenta SMTP configurada en el entorno de la aplicación.
- **FR-006**: El sistema MUST invalidar cualquier token de recuperación previo del mismo email al generar uno nuevo.
- **FR-007**: Al abrir el link de recuperación, el sistema MUST validar que el token exista, no haya sido usado y no esté vencido, antes de mostrar el formulario de nueva contraseña.
- **FR-008**: El sistema MUST rechazar el acceso a un link ya usado o vencido, mostrando un mensaje claro y una vía para volver a solicitar un link nuevo.
- **FR-009**: El formulario de nueva contraseña MUST exigir la contraseña y su confirmación, y validar que coincidan y cumplan los requisitos mínimos de complejidad del sistema antes de aceptar el cambio.
- **FR-010**: Al confirmar la nueva contraseña, el sistema MUST actualizar la contraseña del usuario, invalidar el token usado, y notificar el éxito mediante un toast, redirigiendo al login sin dejar al usuario logueado automáticamente.
- **FR-011**: El sistema MUST limitar la frecuencia de pedidos de recuperación por email (y/o IP) para prevenir abuso, aplicando el mismo mensaje genérico de confirmación aunque el pedido sea descartado por el límite.
- **FR-012**: El sistema MUST registrar en logs de aplicación cualquier fallo al enviar el correo de recuperación, sin exponer ese error al usuario final.
- **FR-013**: Usuarios logueados MUST poder cambiar su propia contraseña desde su pantalla de perfil, ingresando la contraseña actual más la nueva contraseña y su confirmación, vía modal AJAX sin recargar la página.
- **FR-014**: Cualquier acceso de prueba (usuario, contraseña) creado o modificado durante el desarrollo/pruebas de esta feature MUST quedar reflejado en `CREDENCIALES_ACCESO.txt` en el mismo cambio.

### Key Entities *(include if feature involves data)*

- **Token de recuperación de contraseña**: representa un pedido de reseteo activo; vinculado a un email, con fecha de creación/expiración y estado de uso (usado/no usado). Sólo el más reciente por email es válido.
- **Usuario**: entidad ya existente del sistema de autenticación propio; esta feature sólo agrega la capacidad de actualizar su contraseña a través de este flujo, sin cambiar su estructura.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario puede pedir la recuperación de su contraseña y recibir el correo en menos de 2 minutos en condiciones normales de red.
- **SC-002**: Un usuario puede completar todo el flujo (pedir link, recibir correo, definir nueva contraseña y loguearse con ella) en menos de 5 minutos sin asistencia.
- **SC-003**: El 100% de los links de recuperación usados o vencidos son rechazados al reutilizarse.
- **SC-004**: El sistema nunca revela mediante la respuesta de la pantalla si un email pertenece o no a una cuenta existente.
- **SC-005**: El 100% de los pedidos de recuperación fuera del límite de frecuencia permitido son descartados sin enviar un correo adicional.

## Assumptions

- Se reutiliza el mecanismo estándar de Laravel para reseteo de contraseña (tabla `password_reset_tokens`, Password Broker) como mecanismo de generación/validación de tokens, adaptado al modelo de usuario propio del proyecto — no se reinventa el algoritmo de tokens.
- La expiración del token se fija en 60 minutos (default de Laravel), salvo que se documente lo contrario más adelante.
- El límite de frecuencia de reenvío usa el valor estándar de Laravel (rate limiting por email, 60 segundos entre pedidos) salvo que se decida algo distinto en el plan técnico.
- Los requisitos mínimos de complejidad de contraseña son los mismos ya usados en el alta de usuarios existente del módulo Usuarios y Permisos (no se agregan reglas nuevas de complejidad para esta feature).
- La cuenta SMTP real (host, usuario, contraseña) la va configurando el usuario del proyecto en el `.env`; mientras tanto el desarrollo/pruebas usa el driver `log` o un servidor de pruebas (ej. Mailpit) ya presente en la configuración local.
- El cambio de contraseña con sesión activa (Historia 3) vive en la pantalla de perfil ya existente ("Empresa" / dropdown de usuario), no se crea una pantalla nueva para eso.
- No se contempla en esta spec doble factor de autenticación ni políticas de expiración forzada de contraseña — quedan fuera de alcance.
