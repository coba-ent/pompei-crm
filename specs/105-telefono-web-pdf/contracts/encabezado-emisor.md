# Contrato — Partial `pdf.partials.encabezado-emisor`

**Feature**: 105-telefono-web-pdf | **Date**: 2026-09-18

Archivo: `resources/views/pdf/partials/encabezado-emisor.blade.php`

Este partial es el **único** encabezado de emisor del CRM. Es un contrato compartido: lo que se
cambie acá cambia simultáneamente en los cinco comprobantes imprimibles.

## Entrada

| Variable | Tipo | Obligatoria | Comportamiento |
|---|---|---|---|
| `$datosEmpresa` | `App\Models\DatosEmpresa` o `null` | sí (puede ser `null`) | Si es `null`, el partial **no renderiza nada**. El comprobante se genera igual, sin encabezado de emisor. |

Los cinco controladores ya resuelven esta variable con `DatosEmpresa::instancia()` y ya la pasan a su
vista. Esta feature **no cambia la entrada del partial**.

## Consumidores (los cinco, sin excepción)

| Comprobante | Vista que lo incluye |
|---|---|
| Venta | `resources/views/ventas/pdf.blade.php` |
| Presupuesto | `resources/views/presupuestos/pdf.blade.php` |
| Nota de Crédito/Débito | `resources/views/notas-credito-debito/pdf.blade.php` |
| Remito | `resources/views/remitos/pdf.blade.php` |
| Recibo | `resources/views/recibos/pdf.blade.php` |

**Invariante del contrato**: ningún consumidor define su propio encabezado de emisor ni pasa flags
para alterarlo. Si una feature futura necesita que un comprobante muestre un encabezado distinto, eso
se resuelve cambiando este contrato explícitamente, no agregando una variante paralela.

## Salida — orden de los datos

El bloque de datos del emisor se imprime en este orden, cada línea **sólo si su valor está cargado**:

1. Razón social *(en negrita)*
2. `CUIT: {cuit}`
3. Domicilio fiscal ← **la "dirección" del pedido; ya existía**
4. `Condición de IVA: {condicion_iva}`
5. `Tel: {telefono}` ← **NUEVO**
6. `{sitio_web}` ← **NUEVO**

El logo, cuando existe, se muestra a la izquierda de ese bloque (celda de tabla de 70px).

**Justificación del orden**: los datos de contacto van después de los fiscales porque el encabezado
se lee de arriba hacia abajo en orden de formalidad — identidad e información fiscal primero, canales
de contacto después. Es el orden habitual en un comprobante argentino.

## Reglas de renderizado

- **R1 (FR-005)**: un campo vacío o `null` no imprime **nada**: ni el valor, ni su etiqueta, ni un
  renglón en blanco. Se implementa con el mismo patrón `@if` que ya usan las cuatro líneas
  existentes. No se admite imprimir `Tel:` sin número.
- **R2 (FR-007)**: los valores se imprimen tal como fueron cargados. No se reformatea el teléfono ni
  se normaliza la URL. `sitio_web` **no** se convierte en un `<a href>`: es un documento impreso.
- **R3 (FR-006)**: el partial nunca puede hacer fallar la generación del PDF. `$datosEmpresa === null`
  ya está contemplado con el `@if` que envuelve todo el bloque.
- **R4**: los valores se escapan con la sintaxis estándar de Blade (`{{ }}`), nunca `{!! !!}`.
- **R5**: el maquetado no debe romperse con valores largos. El bloque de datos vive en una celda de
  tabla de ancho automático, así que el texto largo corta de línea en lugar de desplazar el logo.

## Lo que este contrato NO hace

- No agrega un domicilio comercial.
- No decide qué comprobantes lo incluyen (eso ya está fijo: los cinco).
- No toca datos fiscales del comprobante: importes, tipo, numeración, CAE ni QR (FR-009).
