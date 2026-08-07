# Checklist: Calidad del Plan Técnico (Arquitectura, Constitución, Modelo de Datos)

**Purpose**: Validar que `plan.md` y sus artefactos (`data-model.md`, `contracts/`, `research.md`) están completos, sin ambigüedades y consistentes con `docs/modelo_datos.md` y la constitución del proyecto, antes de pasar a `/speckit-tasks`.
**Created**: 2026-08-07
**Feature**: [spec.md](../spec.md) / [plan.md](../plan.md)

**Depth**: Standard · **Audience**: Reviewer (previo a tasks/implement) · **Focus**: arquitectura, consistencia con la constitución, consistencia con `docs/modelo_datos.md`

## Requirement Completeness

- [x] CHK001 - ¿Está especificado el mecanismo exacto de captura de eventos (Observers de Eloquent) y por qué se descartaron alternativas? [Completeness, research.md Decisión 1]
- [x] CHK002 - ¿Están enumeradas todas las entidades transaccionales en alcance (Venta, Presupuesto, Cobro, Gasto, Compra, Movimiento de Tesorería, Movimiento de Stock) de forma consistente entre spec.md, plan.md y data-model.md? [Completeness, Consistency]
- [x] CHK003 - ¿Está definido el esquema completo de la tabla `logs_auditoria` (tipos, nullability, índices)? [Completeness, data-model.md]
- [x] CHK004 - ¿Está documentado el mecanismo de alta del nuevo permiso `auditoria.ver` (migración de seed, no sólo mención en contracts.md)? [Gap, research.md Decisión 6]

## Requirement Clarity

- [x] CHK005 - ¿Está cuantificado el objetivo de rendimiento del listado ("<2s con miles de registros") con una condición de índice concreta en vez de sólo una meta funcional? [Clarity, plan.md Technical Context]
- [x] CHK006 - ¿Está definido sin ambigüedad qué valor toma `usuario_nombre`/`origen_sistema` cuando la acción es de integración, y de dónde sale el label mostrado (ej. "Ventas Online")? [Clarity, research.md Decisión 2]

## Requirement Consistency

- [x] CHK007 - ¿Es consistente el patrón de `usuario_id` nullable + registro de acciones automáticas con el ya usado en `ml_operaciones_log`/`tn_operaciones_log` (`docs/modelo_datos.md` §8/§11), o están documentadas explícitamente las diferencias (retención indefinida vs. 30 días)? [Consistency, research.md Decisión 5]
- [x] CHK008 - ¿Es consistente la convención de nombres en español (`logs_auditoria`, `tipo_accion`, `tipo_operacion`) con el principio V de la constitución? [Consistency]
- [x] CHK009 - ¿Está resuelta la relación entre `entidad_tipo`/`entidad_id` (relación polimórfica de sólo lectura) y el hecho de que las entidades de origen usan soft delete — se documenta explícitamente que el evento de auditoría sobrevive al soft delete de la entidad, y qué pasa si se intenta resolver el link desde la UI hacia una entidad ya borrada? [Gap, data-model.md]

## Non-Functional Requirements

- [x] CHK010 - ¿Están especificados los índices necesarios para soportar cada filtro de FR-004 (Id, Operación, Usuario, fecha) sin degradar el tiempo de respuesta objetivo? [Coverage, data-model.md Índices]
- [x] CHK011 - ¿Se especifica si el registro síncrono del evento de auditoría debe abortar la transacción de negocio (Venta/Gasto/etc.) si falla la escritura en `logs_auditoria`, o si debe degradar de forma silenciosa para no bloquear operaciones críticas? [Ambiguity, plan.md Constraints]

## Dependencies & Assumptions

- [x] CHK012 - ¿Están identificadas las dependencias de paquetes ya existentes en el proyecto (yajra/laravel-datatables, maatwebsite/excel, Select2) en vez de introducir alternativas nuevas? [Dependency, plan.md Technical Context]
- [x] CHK013 - ¿Está validada la asunción de que la tabla `usuarios` nunca borra filas físicamente (sólo `activo`), sustento de la Decisión 3 de research.md? [Assumption, docs/modelo_datos.md §1]

## Ambiguities & Conflicts

- [x] CHK014 - ¿Está definido qué sucede si dos observers del mismo modelo (ej. un `updated` disparado por un recálculo interno en cascada, no por una acción de usuario) generan eventos de auditoría duplicados o ruidosos para una sola acción humana? [Gap, Edge Case no cubierto en spec.md]

## Notes

- Todos los gaps detectados (CHK004, CHK009, CHK011, CHK014) se resolvieron en la misma pasada,
  actualizando `research.md` (Decisiones 6 y 7) y `plan.md`/`data-model.md` en consecuencia.
