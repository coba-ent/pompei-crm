# Riesgo y No-Regresión Checklist: Bot de Mercado Libre con sugerencias de IA (Fase 1)

**Purpose**: Validar que los requerimientos que protegen contra el mayor riesgo de esta fase — romper
el flujo de aprobación humana/auditoría ya construido en la spec 032 al integrar la sugerencia de IA, y
generar sugerencias sin control cuando el switch está apagado — estén completos, claros y verificables
antes de pasar a tareas.
**Created**: 2026-08-02
**Feature**: [spec.md](../spec.md)

**Foco elegido**: a diferencia de la spec 032 (que introducía el riesgo desde cero), acá el riesgo
dominante es **de no-regresión** — esta fase modifica código que ya funciona en producción (según lo
implementado por el usuario en paralelo). El checklist se concentra en verificar que los requerimientos
dejan explícito qué NO debe cambiar, no sólo qué debe agregarse.

## Requirement Completeness

- [x] CHK001 - ¿Está especificado qué pasa si `GeneradorDeSugerencias` devuelve una respuesta vacía o
      demasiado larga? [Gap] — **Resuelto**: FR-011a + Edge Case nuevo en spec.md fijan el criterio
      concreto (vacía, o >350 caracteres — límite real de ML confirmado vía MCP de documentación
      oficial, ver research.md R8) → `estado=error`.
- [x] CHK002 - ¿Está especificado un límite de reintentos o timeout para la llamada al proveedor de IA
      dentro del Job, más allá de "estado=error"? [Gap, Spec §Edge Cases] — **Resuelto**: Edge Case +
      FR-011a nuevos: timeout único, sin reintentos automáticos; el usuario puede repedir bajo demanda.

## Requirement Clarity

- [x] CHK003 - ¿Es explícito en los requerimientos que el guard de doble respuesta y el manejo de error
      de envío de la spec 032 **no se modifican** al integrar la sugerencia, más allá de la mención en
      FR-009? [Clarity, Spec §FR-009] — **Ya cubierto**: FR-009 lo dice explícitamente ("sin duplicar ni
      modificar ese comportamiento... se aplican sin cambios"), y AC4 de US3 lo repite como escenario de
      aceptación.

## Requirement Consistency

- [x] CHK004 - ¿Es consistente el Edge Case "se reemplaza por la nueva sugerencia" con el hecho de que
      `ml_respuestas_enviadas.ml_sugerencia_id` referencia una sugerencia puntual — si se generó una
      segunda sugerencia antes de enviar la primera, queda claro cuál se está auditando? [Consistency,
      Spec §Edge Cases, data-model.md] — **Resuelto y con gap real corregido**: al revisar el código
      real de `EnvioRespuestaMercadoLibre::enviar()` (spec 032) se encontró que el método resuelve el
      mensaje a responder internamente (último mensaje del comprador), no lo recibe del caller — research.md
      R4 y tasks.md T023/T022 ahora exigen validar que la sugerencia enviada corresponde al mismo
      `ml_mensaje_id` que se está respondiendo antes de auditarla.

## Acceptance Criteria Quality

- [x] CHK005 - ¿SC-003 ("0% de sugerencias enviadas sin aprobación humana") es verificable con los
      mismos medios que SC-002 de la spec 032, o hace falta un chequeo adicional específico de esta
      fase? [Measurability, Spec §SC-003] — **Válido**: mismo invariante estructural que la spec 032
      (nunca hay un camino de código que envíe sin pasar por `responder()`/`EnvioRespuestaMercadoLibre`);
      se verifica igual, por inspección de código + los tests de T022 (no-regresión del guard existente).

## Edge Case Coverage

- [x] CHK006 - ¿Está cubierto qué pasa si se desactiva el switch mientras hay sugerencias `generando`
      en curso para varios mensajes a la vez (no sólo uno)? [Coverage, Spec §Edge Cases] — **Resuelto**:
      Edge Case nuevo en spec.md aclara que el criterio aplica individualmente a cada generación en
      curso, sin importar cuántas haya en simultáneo.

## Non-Functional Requirements

- [x] CHK007 - ¿Están especificados requerimientos de costo/límite de uso del proveedor de IA (tope de
      llamadas, alerta de gasto) para evitar sorpresas de facturación? [Gap] — **Ya cubierto**: última
      Assumption de spec.md documenta explícitamente que se acepta como riesgo operativo menor a
      monitorear manualmente, sin tope automático en esta fase.
- [x] CHK008 - ¿Está especificado qué pasa si el VPS todavía no tiene `queue:work` corriendo cuando se
      activa el switch en producción (Job encolado que nunca se procesa)? [Gap, Dependency] — **Ya
      cubierto**: última Assumption de spec.md ("si el switch se activa en producción antes de que el
      VPS tenga `queue:work` corriendo, las sugerencias quedan encoladas sin generarse... hasta que el
      worker esté activo").

## Dependencies & Assumptions

- [x] CHK009 - ¿Está documentada la dependencia dura del VPS con colas reales para producción,
      incluyendo qué se espera que pase si se activa el switch antes de esa migración? [Dependency,
      Spec §Contexto y fuentes / Assumptions] — **Ya cubierto**: sección "Contexto y fuentes" + Technical
      Context del plan.md + Assumptions de spec.md, de forma consistente entre los tres documentos.
- [x] CHK010 - ¿Está validada la asunción de que no hace falta un permiso nuevo para la configuración
      del bot (se reutiliza `configuracion.funciones`)? [Assumption, Spec §Assumptions] — **Ya cubierto**:
      Assumption explícita en spec.md, y FR-012 lo formaliza como requerimiento ("mismo permiso que
      gobierna Funciones Avanzadas") — consistente con `PermisoSeeder.php` actual (sin módulo nuevo).

## Notes

- Este checklist no reemplaza los Feature tests listados en `plan.md` (Testing) — es una validación de
  que los *requerimientos* sobre este riesgo están bien escritos, no de que el código funcione.
- CHK007/CHK008 son candidatos a resolverse como Assumptions adicionales en `spec.md` antes de
  `/speckit-tasks`, según impacto — no bloquean necesariamente si se documentan como riesgo operativo
  aceptado.
