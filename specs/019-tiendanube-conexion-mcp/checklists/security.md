# Security & Consistency Checklist: Conexión Tiendanube vía OAuth/MCP

**Purpose**: Validar la calidad de los requisitos (no la implementación) con foco en que ningún test
toque la cuenta real de Tiendanube, en el manejo de tokens/secretos OAuth, y en la coherencia con lo ya
reutilizado de spec 015.
**Created**: 2026-07-29
**Feature**: [spec.md](../spec.md) | [plan.md](../plan.md)
**Depth**: Standard | **Audience**: Autor/revisor antes de `/speckit-tasks` | **Foco**: cuenta real
protegida en testing, secretos OAuth, coherencia con spec 015

## Requirement Completeness

- [x] CHK001 - ¿Está especificado, para cada acción que invoca al servidor MCP (auto-registro, authorize,
  token, cada tool call), que los tests correspondientes deben usar un doble de prueba y no la cuenta
  real? [Completeness, Spec §Restricción crítica] — resuelto: implementado; los 35 tests de
  `Tests\Feature\Integraciones\Tiendanube*` usan `Http::fake()` para register/token/tools-call, ninguno
  llama a `admin-mcp.tiendanube.com` de verdad (verificado corriendo la suite).
- [x] CHK002 - ¿Están definidos los requisitos de qué hacer si el `client_secret` ya guardado no puede
  descifrarse (cambio de `APP_KEY`), análogo al edge case ya cubierto para `access_token` en spec 015?
  [Gap] — resuelto: nuevo edge case en spec.md (registra un cliente OAuth nuevo, FR-001).
- [x] CHK003 - ¿Especifica la spec qué pasa si dos usuarios del CRM presionan "Conectar con Tiendanube"
  casi simultáneamente (dos flujos de `state`/PKCE en paralelo)? [Gap] — resuelto por diseño de
  implementación: `state`/`code_verifier` viven en la sesión de cada usuario (`TiendanubeOAuthController`),
  no en una tabla compartida como la `MercadoLibreSolicitudVinculacion` de spec 011 — dos conexiones en
  paralelo no interfieren entre sí, no hace falta lock.
- [x] CHK004 - ¿Están documentados los requisitos de qué mostrarle al usuario si el auto-registro
  (`POST /register`) falla por indisponibilidad del servidor MCP, más allá del edge case genérico ya
  listado? [Completeness, Spec §Edge Cases] — resuelto: implementado (`RegistroClienteFallidoException` +
  `TiendanubeOAuthController::conectar()` redirige con `tn_error`), cubierto por
  `test_el_auto_registro_fallido_informa_el_error_sin_redirigir_a_tiendanube`.

## Requirement Clarity

- [x] CHK005 - ¿Es inequívoco qué campos exactos se excluyen de toda respuesta JSON y del historial
  (FR-005), o queda a criterio de la implementación decidir qué es "client_secret en claro"? [Clarity,
  Spec §FR-005] — resuelto: implementado (`$hidden = ['access_token', 'client_secret']` en
  `TiendanubeConfiguracion`, `TiendanubeOperacionLog::sanear()` redacta ambos campos), verificado por
  `test_ningun_endpoint_de_la_superficie_expone_secretos`.
- [x] CHK006 - ¿Define la spec con precisión cuánto dura el `state`/PKCE antes de vencer (FR-002 dice "de
  un solo uso" pero no un valor concreto de vencimiento)? [Clarity, Spec §FR-002] — resuelto: 10 minutos,
  FR-002.
- [x] CHK007 - ¿Es claro, en FR-003a, qué constituye "la verificación falla" — cualquier `isError: true`,
  o específicamente un código HTTP de error, o ambos? [Clarity, Spec §FR-003a] — resuelto: ambos casos
  cubiertos explícitamente, FR-003a.

## Requirement Consistency

- [x] CHK008 - ¿Es consistente el tratamiento del kill-switch de modo sólo lectura (FR-012) con el ya
  definido en spec 015 (FR-016..019), sin que el cambio de transporte (JSON-RPC en vez de REST) haya
  introducido una divergencia de comportamiento no documentada? [Consistency, Spec §FR-012] — resuelto:
  implementado en el mismo único punto (`ClienteTiendanube::peticion()`), re-verificado sin cambios de
  intención por `TiendanubeModoSoloLecturaTest` (4/4 tests en verde).
- [x] CHK009 - ¿Usa esta spec la misma terminología de estados de conexión que spec 015 (Conectada /
  Caída), documentando explícitamente por qué "Desconectada" y "No configurada" se unifican acá cuando en
  spec 015 eran estados distintos? [Consistency, Spec §FR-006] — resuelto: documentado en data-model.md §1
  y en el docblock de `App\Enums\Tiendanube\EstadoConexion`.
- [x] CHK010 - ¿Es consistente la política de retención del historial (FR-013) con la ya vigente para
  `tn_operaciones_log`/`ml_operaciones_log`, sin introducir un criterio nuevo por error? [Consistency,
  Spec §FR-013] — resuelto: sin cambios (`TiendanubeOperacionLog::RETENCION_DIAS`/`RETENCION_MAXIMO_REGISTROS`
  intactos), re-verificado por `test_la_retencion_no_borra_registros_dentro_de_la_ventana`.

## Security & Secrets Handling

- [x] CHK011 - ¿Especifica la spec que `client_secret` se cifra con el mismo mecanismo ya usado para
  `access_token` (cast `encrypted` de Laravel), o queda implícito? [Clarity, Spec §Key Entities] —
  resuelto: implementado (`'client_secret' => 'encrypted'` en `TiendanubeConfiguracion::$casts`).
- [x] CHK012 - ¿Están definidos los requisitos de qué guarda el historial de operaciones cuando una
  llamada falla por causa de credenciales (401) — se especifica explícitamente que el mensaje de error
  nunca debe incluir el token, o se asume? [Completeness, Spec §FR-005/FR-011] — resuelto: implementado
  (mensaje estático "La credencial fue rechazada por Tiendanube..." sin interpolar el token, más el
  saneado de `TiendanubeOperacionLog::sanear()`), verificado por
  `test_ningun_dato_sensible_aparece_en_el_historial_tras_una_operacion` y
  `test_401_marca_caida_con_ultimo_error_legible_y_no_reintenta`.
- [x] CHK013 - ¿Documenta la spec qué pasa con `client_id`/`client_secret` al desconectar — se especifica
  explícitamente que se conservan (para no auto-registrar de nuevo), o sólo queda en la implementación?
  [Clarity, Spec §FR-007] — resuelto: FR-007 actualizado, se conservan explícitamente.

## Scenario Coverage

- [x] CHK014 - ¿Cubre la spec el escenario de recuperación completo (conexión caída → usuario reconecta →
  vuelve a "Conectada") con los mismos pasos que exige la Historia 4? [Coverage, Spec §User Story 4] —
  resuelto: implementado y cubierto de punta a punta por
  `test_recuperacion_completa_caida_reconectar_vuelve_a_conectada`.
- [x] CHK015 - ¿Están cubiertos los escenarios de rechazo del propio flujo OAuth (usuario cancela la
  aprobación en Tiendanube, Tiendanube devuelve `error=access_denied`) con la misma profundidad que los
  de éxito? [Coverage, Spec §Edge Cases] — resuelto: nuevo edge case explícito.
- [x] CHK016 - ¿Contempla la spec qué pasa si el usuario presiona "Conectar con Tiendanube" mientras ya
  existe una conexión activa (reconexión sin desconectar antes)? [Coverage, Gap] — resuelto: nuevo edge
  case explícito (se trata como reconexión, no reemplaza hasta completarse con éxito).

## Non-Functional Requirements

- [ ] CHK017 - ¿Especifica la spec un requisito no funcional sobre el tiempo máximo aceptable de espera
  en el flujo de conexión (registro + authorize + token + verificación) antes de considerarlo una falla,
  más allá del SC-001 de "menos de 1 minuto" que incluye la interacción humana de aprobar en el
  navegador? [Gap]
- [x] CHK018 - ¿Documenta la spec un requisito de auditoría (quién conectó/desconectó y cuándo) análogo
  al `actualizada_por` ya exigido en spec 015/011? [Completeness, Spec §Key Entities] — resuelto:
  implementado (`actualizada_por` seteado en `callback()`, `desconectar()` y `modoSoloLectura()`;
  `conectada_en` como fecha de conexión).

## Dependencies & Assumptions

- [x] CHK019 - ¿Está explícitamente validada (o marcada como pendiente de verificación) la suposición de
  que el servidor MCP no depende del plan de la tienda, antes de comprometerse a no construir ninguna
  alternativa de respaldo? [Assumption, Spec §Assumptions] — resuelto: validada empíricamente (research.md
  §R1, contra la cuenta real de Pompei Sanitarios) y documentada en spec.md §Assumptions con la salvedad de
  que un cambio de política de Tiendanube queda fuera de control del CRM.
- [x] CHK020 - ¿Documenta la spec la dependencia de que no exista una cuenta de prueba/sandbox de
  Tiendanube, con el mismo nivel de detalle que condiciona toda la estrategia de testing (SC-005)?
  [Dependency, Spec §Assumptions] — resuelto: documentado en spec.md §Assumptions y honrado en la
  implementación (toda la suite de tests usa `Http::fake()`, cero llamadas reales).
- [x] CHK021 - ¿Está validada la asunción de que el token no requiere renovación (research.md R3), con un
  plan documentado de qué revisar si Tiendanube cambiara esa política? [Assumption, Spec §Assumptions] —
  resuelto: research.md §R3 documenta "A verificar en producción" como plan de revisión si Tiendanube
  empezara a emitir tokens de vida más corta.

## Ambiguities & Conflicts

- [ ] CHK022 - ¿Hay algún punto donde esta spec y la sección §5.3 de `documentacion_principal_crm.md`
  (ya actualizada para reflejar la corrección) puedan quedar en conflicto una vez que las specs 017/018
  se implementen sobre esta base? [Conflict, Gap]
- [x] CHK023 - ¿Es ambiguo si el auto-registro de cliente OAuth (FR-001) debe repetirse si el
  `client_secret` guardado resulta ilegible (CHK002), o si en ese caso el flujo debe registrar un cliente
  nuevo? [Ambiguity, Spec §FR-001] — resuelto junto con CHK002.
