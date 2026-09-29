# Checklist de calidad — spec 112

**Feature**: [spec.md](../spec.md) | **Fecha**: 2026-09-29

## Calidad del contenido

- [x] Sin detalles de implementación en la spec
- [x] Centrada en el valor: que Mercado Libre no ofrezca lo que no hay
- [x] Legible por alguien no técnico
- [x] Secciones obligatorias completas

## Completitud de requisitos

- [x] Causa **reproducida con datos reales** (secuencia horaria del 28/09), no supuesta
- [x] Alcance medido en producción: 75 productos, 172 publicaciones, 203 ventas/30 días
- [x] **Origen de la regla investigado**: spec 013 FR-002, spec 036, commit `4b7a0e66`
- [x] Verificado que el motivo original (evitar bucles) **ya no aplica**, leyendo el código
- [x] Incluye Tiendanube, que tiene el mismo patrón
- [x] Dice explícitamente qué **no** resuelve (la latencia de los 5 minutos)
- [x] Los 3 tests que contradicen la regla nueva están identificados de antemano

## Listo para implementar

- [x] Cada requisito tiene su tarea
- [x] El camino que hoy funciona tiene test de no-regresión propio
- [x] El riesgo de volumen de API tiene medición antes/después

## Notas de la validación

**Dos explicaciones mías fueron incorrectas antes de llegar a la buena, y las dos se cayeron
mirando datos.**

La primera: *"se perdió la marca de pendiente"*. Falso — la exclusión es deliberada y está en el
código.

La segunda: *"Mercado Libre descontó desde su propio conteo paralelo, que era 22"*. También falso.
El historial de órdenes mostró que **el 22 lo mandó el propio CRM** a las 18:19, con datos viejos
porque todavía no había importado las ventas de las 17:53 y 18:10. No hay contabilidad paralela de
Mercado Libre: hay un número nuestro desactualizado.

Esa corrección cambió el diagnóstico de fondo. Si el problema fuera "ML lleva su propia cuenta", la
exclusión estaría bien conceptualmente. Como el problema es "le mandamos un número viejo y después
no lo volvemos a tocar", la exclusión es exactamente lo que hay que sacar.

**El usuario aportó el matiz que acotó la gravedad**: preguntó si una venta manual lo corregiría.
Sí — la exclusión sólo aplica a movimientos originados en órdenes de ML. Eso convirtió "quedan mal
para siempre" en "quedan mal hasta el próximo movimiento", y está escrito como tal.

**El usuario pidió explícitamente verificar todo antes de tocar** por ser la parte más sensible de
la aplicación. Esa verificación encontró dos cosas que habrían hecho fracasar la implementación:

1. **Tres tests en verde exigen el comportamiento actual.** Uno afirma literalmente *"La publicación
   vendida ya la descontó ML"*. Sin detectarlos antes, la implementación los habría roto y parecido
   una regresión.
2. **La regla nació como FR-002 de la spec 013 para evitar bucles**, no como una optimización. Hubo
   que verificar en el código que ese bucle no existe: `procesarVinculos()` no crea ningún
   `MovimientoStock`, sólo escribe columnas de control del vínculo.

**Se evaluaron dos opciones** y el usuario eligió la recomendada:

- **A (elegida)**: quitar la exclusión siempre. Más simple y predecible; un PUT extra por venta.
- **B**: quitarla sólo en productos multi-publicación. Ahorra llamadas a cambio de una rama
  condicional más en el punto más sensible del sistema.

**Lo que la spec NO promete**: eliminar el empujón con datos viejos. Eso depende de la latencia de
importación y se ataca con la mejora 7.y, hoy postergada. Esta spec garantiza que la próxima pasada
corrija, no que no haya una pasada equivocada.
