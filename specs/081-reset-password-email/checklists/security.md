# Specification Quality Checklist: Seguridad, diseño obligatorio y consistencia con login

**Purpose**: Validar la calidad de los requisitos de la spec 081 antes de implementar, con foco en
seguridad de autenticación, cumplimiento de las reglas de diseño obligatorias del proyecto
(modal+AJAX+toast) y consistencia con el flujo de login existente.
**Created**: 2026-08-25
**Feature**: [spec.md](../spec.md)

## Seguridad de autenticación

- [x] CHK001 - ¿Está especificado que la respuesta al pedido de recuperación es idéntica exista o no la cuenta, para no revelar información de cuentas? [Completeness, Spec §FR-003]
- [x] CHK002 - ¿Está definido que los tokens de recuperación son de un solo uso y se invalidan al usarse? [Completeness, Spec §FR-004, FR-008]
- [x] CHK003 - ¿Está especificado el comportamiento cuando se generan múltiples tokens seguidos para el mismo email (invalidación del anterior)? [Coverage, Spec Edge Cases]
- [x] CHK004 - ¿Está definido un límite de frecuencia de pedidos de recuperación para prevenir abuso? [Completeness, Spec §FR-011]
- [x] CHK005 - ¿Está especificado que un fallo de envío de correo no se expone al usuario final, y sí se registra para diagnóstico? [Completeness, Spec §FR-012]
- [x] CHK006 - ¿Está definido el comportamiento para cuentas inactivas/desactivadas que piden recuperación (sin revelar su estado)? [Coverage, Spec Edge Cases]
- [x] CHK007 - ¿Están especificados los requisitos mínimos de complejidad que debe cumplir la nueva contraseña? [Completeness, Spec §FR-009]
- [x] CHK008 - ¿Está definido que el cambio de contraseña con sesión activa exige validar la contraseña actual antes de aceptar la nueva? [Completeness, Spec §FR-013, US3]
- [ ] CHK009 - ¿Está especificado si el usuario queda o no logueado automáticamente tras resetear su contraseña desde el link de email? [Clarity, Spec §FR-010]
- [x] CHK010 - ¿Es medible/verificable el requisito de expiración por tiempo del token (no queda como "temporal" sin cuantificar)? [Measurability, Spec Assumptions]

## Cumplimiento de reglas de diseño obligatorias del proyecto

- [x] CHK011 - ¿Está especificado que el pedido de link se hace vía modal Bootstrap + AJAX, sin recargar la página? [Completeness, Spec §FR-001]
- [x] CHK012 - ¿Está especificada la notificación de resultado (éxito/error) como toast, y no como alert nativo o mensaje flash con recarga? [Consistency, Spec US1 escenario 2]
- [x] CHK013 - ¿Está especificado que el cambio de contraseña logueado (US3) sigue el mismo patrón modal+AJAX que el resto de operaciones CRUD del CRM? [Consistency, Spec §FR-013]
- [ ] CHK014 - ¿Está justificada explícitamente en la spec/plan la excepción de que la pantalla de "definir nueva contraseña" (accedida por link de email, fuera de sesión) no es un modal? [Clarity, Gap]
- [x] CHK015 - ¿Está especificado el comportamiento de validación de formato de email en el modal antes de enviar el pedido (sin depender sólo del backend)? [Completeness, Spec §FR-002]

## Consistencia con el flujo de login existente

- [x] CHK016 - ¿Está especificado dónde vive el punto de entrada ("¿Olvidaste tu contraseña?") en relación a la pantalla de login ya existente? [Consistency, Spec §FR-001]
- [x] CHK017 - ¿Está especificado que tras un reseteo exitoso el usuario es dirigido al login existente (mismo flujo de autenticación, sin bypass)? [Consistency, Spec §FR-010]
- [x] CHK018 - ¿Está aclarado que la pantalla de perfil donde vive el cambio de contraseña logueado (US3) es la ya existente, sin crear una pantalla nueva? [Consistency, Spec Assumptions]
- [ ] CHK019 - ¿Está especificado si el pedido de recuperación aplica igual a todos los roles/usuarios del sistema de permisos propio, o si hay alguna excepción (ej. usuarios de integración automática)? [Coverage, Gap]

## Requisitos generales de calidad

- [x] CHK020 - ¿Las historias de usuario están priorizadas de forma que la Historia 1 + Historia 2 formen un MVP completo sin depender de la Historia 3? [Completeness, Spec User Scenarios]
- [x] CHK021 - ¿Los criterios de éxito (Success Criteria) son medibles y no dependen de detalles de implementación? [Measurability, Spec Success Criteria]

## Notes

- Ítems sin marcar (CHK009, CHK014, CHK019) son gaps menores de clarity/documentación, no bloqueantes: se resuelven naturalmente en plan.md (ya definidos ahí — ver research.md Decisión 5 para CHK014) o quedan como detalle de implementación de bajo riesgo (CHK009, CHK019 — no hay razón de negocio para excluir ningún rol, y el diseño estándar de Laravel no deja sesión iniciada tras reset). No requieren volver a `/speckit-specify`.
