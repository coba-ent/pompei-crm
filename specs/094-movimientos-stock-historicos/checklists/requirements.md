# Checklist de calidad de la spec — 094

**Feature**: [spec.md](../spec.md) | **Fecha**: 2026-08-31

## Calidad del contenido

- [x] Sin detalles de implementación en la spec (viven en plan y research)
- [x] Centrada en el valor: poder leer el historial de un producto
- [x] Legible por alguien no técnico
- [x] Secciones obligatorias completas

## Completitud de requisitos

- [x] Sin marcadores de clarificación pendientes
- [x] Requisitos verificables y sin ambigüedad
- [x] Criterios de éxito medibles (SC-002 se mide comparando 9.781 filas)
- [x] Casos de borde identificados **contra el dato real**, no imaginados
- [x] Alcance acotado, y lo que queda afuera está cuantificado
- [x] Supuestos declarados y verificados

## Listo para implementar

- [x] Cada requisito funcional tiene tarea
- [x] Los requisitos de seguridad tienen test que falla si se rompen
- [x] Sin filtraciones de implementación

## Notas de la validación

Tres correcciones aplicadas durante el clarify, todas por medir el dato real en vez de asumir:

1. **"Los archivos están truncados" era falso.** El primer análisis leyó mal las fechas por el
   gotcha de los dos formatos y concluyó que faltaban meses. Los tres años están completos.
2. **"`Registro Inicial` es el apertura de inventario" era falso.** 15.961 de sus 15.964 filas
   tienen cantidad 0: son altas de producto. La spec llegó a apoyarse en eso antes de verificarlo.
3. **El export repite cada movimiento por depósito.** De las 53.844 filas, 22.326 están en 0.
   Cargarlas habría inflado el historial con ruido — justo lo contrario del objetivo.

Las tres se descubrieron leyendo los archivos, no razonando sobre ellos. Es la razón por la que la
Fase 6 (correr sobre un clon real antes de producción) no es opcional.
