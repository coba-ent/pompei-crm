# Checklist de calidad — spec 099

**Feature**: [spec.md](../spec.md) | **Fecha**: 2026-09-03

## Calidad del contenido

- [x] Sin detalles de implementación en la spec (viven en el plan)
- [x] Centrada en el valor: poder emitir la segunda nota de una factura de ML
- [x] Legible por alguien no técnico
- [x] Secciones obligatorias completas

## Completitud de requisitos

- [x] Sin marcadores de clarificación pendientes
- [x] Requisitos verificables y sin ambigüedad
- [x] Criterios de éxito medibles contra un caso real (compra 2478)
- [x] Casos de borde identificados **contra el dato de producción**, no imaginados
- [x] Alcance acotado y lo excluido está dicho (Ventas)
- [x] Supuestos verificados ejecutando el código, no leyéndolo

## Listo para implementar

- [x] Cada requisito tiene su tarea
- [x] El requisito de seguridad (FR-009) tiene un test que falla si se rompe
- [x] Sin filtraciones de implementación

## Notas de la validación

**El caso se verificó ejecutando el servicio en producción**, no razonando sobre el código:

```
itemsDisponibles()  → línea 12022, pendiente 1, precio $4.616.354
pendiente(100000)   → 0
```

Eso convirtió una hipótesis ("la validación suma mal") en un hecho medido, y de paso mostró que el
sistema **se contradice a sí mismo**: la pantalla ofrece una línea que la validación rechaza. Ese
hallazgo es el que define el criterio de éxito SC-003.

**Sobre el alcance**: el mismo defecto existe del lado de Ventas y se dejó afuera por decisión
explícita del usuario (03/09/2026). No es un olvido — queda anotado en la spec como brecha conocida
para que una sesión futura no lo trate como un bug nuevo.

**Sobre el riesgo**: esta validación es lo único que impide emitir una NC/ND por más de lo facturado
sobre un comprobante fiscal. Un error hacia el lado permisivo **no da un cartel** — deja pasar una
nota mal emitida y se descubre después. Por eso SC-002 (el rechazo sigue rechazando) tiene el mismo
peso que SC-001 (la línea libre se puede ajustar), y no se marca la feature como terminada con uno
solo de los dos en verde.
