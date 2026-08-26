# Specification Quality Checklist: Prevalidación y confirmación previa de la importación

**Purpose**: Validar que la spec esté completa y sea de calidad antes de planear
**Created**: 2026-08-26
**Feature**: [spec.md](../spec.md)

## Content Quality

- [X] Sin detalles de implementación (lenguajes, frameworks, APIs)
- [X] Centrada en el valor para el usuario y la necesidad de negocio
- [X] Escrita para que la entienda alguien no técnico
- [X] Todas las secciones obligatorias completas

## Requirement Completeness

- [X] No quedan marcadores [NEEDS CLARIFICATION]
- [X] Los requisitos son testeables y no ambiguos
- [X] Los criterios de éxito son medibles
- [X] Los criterios de éxito son agnósticos de tecnología
- [X] Todos los escenarios de aceptación están definidos
- [X] Los casos borde están identificados
- [X] El alcance está acotado
- [X] Dependencias y supuestos identificados

## Feature Readiness

- [X] Todos los requisitos funcionales tienen criterio de aceptación claro
- [X] Los escenarios de usuario cubren los flujos principales
- [X] La feature cumple los resultados medibles de Success Criteria
- [X] No se filtran detalles de implementación en la especificación

## Notas de la validación

Tres decisiones que podrían haber quedado como [NEEDS CLARIFICATION] fueron **resueltas con el
usuario antes de escribir la spec** (26/08/2026), así que no quedó ninguna abierta:

1. **Ante filas con error, ¿bloquear o avisar?** → **Bloquear** (FR-005). El usuario eligió la opción
   más estricta sabiendo que cambia la tolerancia por fila vigente.
2. **Fórmulas de Excel sin calcular** → **el sistema las calcula** (FR-011), en vez de sólo detectarlas
   y avisar.
3. **Alcance del bug del resumen** → **causa raíz + blindaje** (FR-021 a FR-024), no sólo el parche.

**Riesgo declarado, no resuelto**: FR-005 revierte la tolerancia por fila de las specs 006/026. Está
documentado en la sección *Cambio de comportamiento deliberado* con su caso límite (una fila mala en
9.000 bloquea el archivo entero). Es una decisión del usuario, tomada de forma informada, no un
descuido de la spec.

**Verificación pendiente para el plan**: FR-015 asume que puede no existir exportación de Clientes y
Proveedores. Hay que confirmarlo contra el código antes de escribir tareas para eso.
