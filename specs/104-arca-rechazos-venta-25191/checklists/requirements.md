# Checklist de calidad — spec 104

**Feature**: [spec.md](../spec.md) | **Fecha**: 2026-09-18

## Calidad del contenido

- [x] Sin detalles de implementación en la spec
- [x] Centrada en el valor: que la factura se pueda emitir
- [x] Legible por alguien no técnico
- [x] Secciones obligatorias completas

## Completitud de requisitos

- [x] Sin marcadores de clarificación pendientes
- [x] Requisitos verificables
- [x] Criterios de éxito medibles contra datos reales
- [x] Causa raíz **reproducida**, no supuesta
- [x] Alcance medido en producción (521 / 511 / 10 / 6)
- [ ] ⚠️ **El problema 2 sigue sin diagnosticar** — FR-007 es trabajo de investigación, no una
      solución acordada. La spec lo dice explícitamente en vez de simular que está resuelto.

## Listo para implementar

- [x] Cada requisito tiene su tarea
- [x] El requisito de no-regresión (FR-006) tiene verificación propia
- [x] Riesgo de descuadre identificado con mitigación

## Notas de la validación

**La primera hipótesis era incorrecta y se descartó midiendo.** Al ver la venta supuse que el
problema venía de que `subtotal_con_iva` se calculaba antes del descuento general. La consulta de
alcance lo desmintió: de las 521 ventas con desvío, **0 tenían descuento general**. Ese número
obligó a volver al código y encontrar la causa real —el doble redondeo por caminos separados—, que
después se reprodujo en aislado con los importes de la venta y dio los mismos centavos.

Vale dejarlo anotado porque las dos explicaciones sonaban parecidas ("el descuento general rompe el
IVA") pero llevaban a fixes distintos.

**El alcance cambió la decisión sobre los datos viejos.** Saber que 511 de 521 eran migradas y sólo
6 posteriores al corte convirtió "hay que corregir el histórico" en "el bug está vivo, corregilo
para adelante y no toques lo conciliado".

**El problema 2 queda deliberadamente abierto.** El mapeo local se ve correcto —código 1,
Responsable Inscripto, Factura A— y aun así ARCA lo rechaza. Sin la respuesta de
`FEParamGetCondicionIvaReceptor` cualquier arreglo sería adivinar. La spec pide consultar; no
inventa el código correcto.

**La tolerancia del validador no se toca.** Era la opción cómoda y quedó descartada explícitamente:
es el mecanismo que detectó el bug. Ampliarla habría hecho pasar la factura con un IVA que a ARCA
igual no le cierra.
