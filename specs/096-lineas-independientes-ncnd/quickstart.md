# Quickstart: verificar que cada línea se ajusta por separado

**Feature**: 096-lineas-independientes-ncnd | **Date**: 2026-09-03

Verificar en LOCAL. La verificación contra datos de producción (venta 24854) se hace por lectura
directa contra el VPS o replicando el caso en local — nunca creando/guardando en producción.

## Prerequisitos

- Servidor local levantado sobre una base con datos, o el caso replicado en un test/tinker.

## Caso de referencia (verificado en producción, 03/09/2026)

**Venta 24854** (`0001-00000347`), tipo B, sin descuento general, total **$94.380,00**:

| Línea | Precio | Bonif. | Cant. |
| --- | --- | --- | --- |
| 1 | $13.000 | — | 1 |
| 2 | $25.000 | 10% | 1 |
| 3 | $50.000 | 15% | 1 |

Las 3 líneas son el **mismo producto**. Sin notas previas.

## Verificación 1 — Precarga trae 3 líneas, no 1

Abrir el alta de NC/ND sobre la venta 24854 con "afecta stock = Sí".

- **Esperado**: 3 filas de producto, cada una con su propio precio y bonificación (13.000 sin
  bonif., 25.000 con 10%, 50.000 con 15%), cantidad 1 cada una.
- **Antes de este fix**: 1 sola fila, $13.000, sin bonif., cantidad 3.

## Verificación 2 — El total cierra

Sobre el mismo alta, sin tocar nada.

- **Esperado**: total propuesto $94.380,00 (SC-001).
- **Antes de este fix**: $47.190,00.

## Verificación 3 — Borrar una línea no afecta a las otras (US2)

Sobre el formulario precargado, borrar la línea de $50.000 antes de guardar.

- **Esperado**: la nota se guarda con las 2 líneas restantes ($13.000 y $25.000 con 10%), cada una
  con su cantidad y bonificación originales, sin fusión ni recálculo cruzado.

## Verificación 4 — Segunda nota sobre el mismo comprobante

Después de guardar la nota de la Verificación 3 (2 líneas: $13.000 y $25.000 con 10% bonif.),
abrir un alta nueva sobre la misma venta 24854.

- **Esperado**: la precarga trae únicamente la línea de $50.000 con 15% (la que nunca se ajustó) —
  no vuelve a ofrecer las 2 ya ajustadas, ni las mezcla.

## Verificación 5 — Fallback agregado (caso simulado, FR-006)

No hay forma de simular esto sobre datos reales sin crear una nota — se verifica con un test
(`NotaCreditoDebitoLineasIndependientesTest`) que arma una `NotaCreditoDebitoItem` SIN
`venta_item_id` (simulando una nota vieja) sobre un producto con 2 líneas, y confirma que el
`pendiente` de ese producto se calcula agregado (como hoy), no por línea, hasta que se crea una
segunda nota que sí trae la referencia.

## Verificación 6 — No regresión sobre comprobantes sin producto repetido

Abrir el alta sobre alguno de los comprobantes de referencia de la spec 095 (venta 24740, con
descuento general 5%, sin producto repetido) y confirmar que el total propuesto sigue siendo
$218.458,32 — sin cambios por este fix.

## Tests

```
php artisan test --filter="NotaCreditoDebito"
```

Cobertura obligatoria (principio IV):

- Producto repetido: precarga trae N líneas independientes con su precio/bonif./cantidad propios.
- Total propuesto coincide con el del comprobante cuando hay producto repetido.
- Borrar una línea precargada no afecta a las demás al guardar.
- Segunda nota sobre el mismo comprobante no vuelve a ofrecer lo ya ajustado por línea.
- Fallback agregado: producto con una nota vieja sin referencia de línea sigue calculándose agregado.
- Transición de fallback a por-línea: en cuanto una nota nueva trae la referencia, el cálculo pasa a
  ser por línea para ese producto.
- No regresión: comprobante sin producto repetido, comportamiento idéntico al actual.
- No regresión: edición de una NC/ND existente sigue sin depender del comprobante de origen.
