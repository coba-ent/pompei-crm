# Contrato: datos de cabecera precargados en el alta de NC/ND

**Feature**: 095-espejo-comprobante-ncnd | **Date**: 2026-09-02

## Dónde vive

La vista del formulario ya emite `window.NotaFormData.comprobanteOrigen` con tres campos
(`id`, `nroComprobante`, `depositoId`). Este contrato **amplía ese mismo objeto** con la cabecera del
comprobante. No se crea un endpoint nuevo ni se cambia `items-disponibles`, que sigue sirviendo sólo
los ítems (research, Decisión 2).

## Forma

```
comprobanteOrigen: {
  // ya existentes
  id, nroComprobante, depositoId,

  // nuevos — cabecera espejada
  tipoComprobante,          // "A" | "B" | "C" | "E" | null   (null si el comprobante no tiene)
  descuentoGeneralTipo,     // "porcentaje" | "monto" | null
  descuentoGeneralPct,      // number | null   (sólo si el tipo es "porcentaje")
  descuentoGeneralMonto,    // number | null   (sólo si el tipo es "monto")
  fechaEmision,             // "YYYY-MM-DD"
  fechaVencimiento,         // "YYYY-MM-DD" | null
  servicioDesde,            // "YYYY-MM-DD" | null
  servicioHasta,            // "YYYY-MM-DD" | null
  tercero: { id, nombre },  // Cliente en Ventas, Proveedor en Compras
  categoria: { id, nombre } | null,
  conceptos: [ { tipo, concepto, monto } ]   // percepciones e impuestos internos; [] si no hay
}
```

**Fechas en ISO** (`YYYY-MM-DD`) hacia el front, como el resto del proyecto: el helper de fechas se
encarga de mostrarlas en dd/mm/aaaa. Nunca se emite el texto ya formateado.

## Reglas de precedencia

1. **Ítems sobre totales**: la precarga de ítems parte de lo **pendiente de ajuste**; si el
   comprobante ya tiene notas, el total propuesto refleja lo pendiente y no coincide con el total del
   comprobante (FR-009 prevalece sobre FR-001).
2. **Fechas**: cada fecha usa la del comprobante; si no está cargada, cae en `fechaEmision` (FR-005).
3. **Descuento general**: se hereda con su modalidad. En modo monto se envía el importe tal cual, sin
   convertir (FR-002).
4. **Sin tipo de comprobante**: si el comprobante no tiene (vacío o "Sin Factura"), se envía `null` y
   el campo queda vacío — no se infiere (FR-004).
5. **Sin ítems** ("afecta stock = No"): se aplica sólo la cabecera; monto y descripción quedan vacíos
   (FR-013). El descuento general no aplica.

## Comportamientos del front

| Situación | Comportamiento esperado |
| --- | --- |
| Se abre el alta | Los campos de cabecera quedan poblados y el total propuesto se calcula solo. |
| El usuario edita cualquier campo | Se respeta su valor; se guarda lo que quedó en pantalla (FR-008). |
| El tipo elegido difiere del origen | Advertencia antes de guardar, explicando que una nota con el tipo cruzado no se corrige editando. No bloquea (FR-004a). |
| El descuento en modo monto supera el subtotal restante | Aviso y no se guarda hasta corregirlo. No se reajusta solo (FR-012). |
| Edición de una nota existente | Sin cambios: precarga desde la nota, no desde el comprobante (FR-011). |

## Equivalencia Ventas / Compras

El contrato es idéntico en los dos flujos. La única diferencia es el contenido de `tercero`: Cliente
en Ventas, Proveedor en Compras (FR-010).
