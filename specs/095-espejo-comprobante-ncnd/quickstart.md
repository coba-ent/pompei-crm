# Quickstart: verificar el espejo del comprobante en el alta de NC/ND

**Feature**: 095-espejo-comprobante-ncnd | **Date**: 2026-09-02

Guía para comprobar que la precarga funciona, contra comprobantes reales de la base clon de
producción. **Todo se verifica en local** — nunca en producción.

## Prerequisitos

- Servidor local levantado (`php artisan serve`) sobre la base clon.
- Confirmar que la app servida es la correcta antes de mirar nada: el título del login debe decir
  **Pompei**.

## Comprobantes de referencia

Identificados en la base clon el 02/09/2026:

| Caso | Comprobante | Qué tiene | Qué verificar |
| --- | --- | --- | --- |
| **Descuento general %** | Venta **24740** | Tipo A, descuento general 5%, 3 ítems, total $218.458,32 | Es el caso medido: hoy la NC daría $229.956,12, o sea **$11.497,80 de más**. Debe quedar en $218.458,32. |
| **Descuento general % (2)** | Venta **24741** | Tipo B, descuento general 15%, 2 ítems, total $103.906,03 | Total propuesto igual al de la venta y tipo precargado en B. |
| **Bonificación por línea** | Venta **24677** | Descuento 15% en la línea, sin descuento general | No debe romperse lo que ya funciona: la línea conserva su 15% y el general queda vacío. |
| **Compras** | Compra **2442** | Tipo A, descuento general 7%, total $468.700,81 | Mismo comportamiento que en Ventas, con Proveedor en lugar de Cliente. |

**Casos sin dato real en la base**: no hay ventas con descuento general en **modo monto** ni
comprobantes con **conceptos** cargados. Esos dos escenarios (FR-002 en modo monto, FR-007 y el aviso
de FR-012) se verifican con datos de prueba creados a mano en local, y se cubren con tests.

## Verificación

### 1. El caso que originó el reporte

Abrir el alta de NC/ND sobre la venta **24740** con "afecta stock = Sí" y, **sin tocar nada**,
comparar el total propuesto contra el total de la venta.

- **Esperado**: $218.458,32 en ambos lados, diferencia $0,00 (SC-001).
- **Hoy**: $229.956,12 — $11.497,80 de más.

Verificar además que el Descuento General muestra 5% y el Tipo de Comprobante A.

### 2. Los 8 datos de la tabla comparativa

Sobre la venta 24741, confirmar que llegan precargados: ítems, descuento de línea, descuento general,
tipo de comprobante, cliente y categoría, las 4 fechas, percepciones, y el total (SC-002).

### 3. Que precargar no impida editar

Sobre la venta 24740: borrar una línea, cambiar el descuento general y guardar. Debe guardarse lo que
quedó en pantalla, no lo precargado (SC-005, FR-008).

### 4. Los dos avisos

- **Tipo cruzado**: cambiar el Tipo de A a B → debe advertir antes de guardar, sin bloquear (FR-004a).
- **Descuento en modo monto** (con datos de prueba): dejar una sola línea de importe menor al
  descuento heredado → debe avisar y no guardar (FR-012).

### 5. No regresión

- Abrir el alta sobre una venta **sin** descuento: el general queda vacío y el total sigue
  coincidiendo.
- Abrir el alta sobre un comprobante que **ya tiene una NC**: el total propuesto refleja lo
  **pendiente de ajustar**, no el total del comprobante (FR-009).
- Editar una NC existente: sigue precargando desde la nota, sin cambios (FR-011, SC-006).

## Tests

Correr los tests de NC/ND y comparar contra la línea base **antes** de empezar: la suite del proyecto
ya tiene fallas preexistentes en otros módulos, así que lo que importa es que el número no empeore y
que los tests nuevos pasen.

```
php artisan test --filter="NotaCreditoDebito"
```

Cobertura obligatoria por el principio IV (toca importes y descuentos):

- Precarga del descuento general en modo porcentaje y en modo monto.
- Precarga del tipo de comprobante, incluido el caso sin tipo.
- Fechas heredadas y su respaldo en la Emisión.
- Conceptos heredados.
- Ítems: que siga mandando lo pendiente de ajuste.
- Que la edición de una nota existente no cambie.
- Que no se pueda guardar un total negativo.
