# Quickstart: validar Percepciones/Impuestos Internos/Intereses en NC/ND

## Escenario 1 — Agregar una Percepción (Venta)

1. Detalle de una Venta → "Agregar" NC/ND → completar paso 1 (Stock=No, para simplificar) →
   Siguiente.
2. En la página completa, click "+ Percepciones".
3. **Esperado**: aparece una fila nueva con un selector "Seleccionar..." con las 27 percepciones
   (IVA Percepción, Ganancias, Sellos, IIBB × 24), un campo Monto y un botón de eliminar.
4. Elegir "IIBB Buenos Aires", Monto = 1000.
5. **Esperado**: el Total de la nota sube en $1000 respecto al subtotal de ítems.

## Escenario 2 — Agregar Impuesto Interno / Interés

1. Repetir con "+ Impuestos Internos" y "+ Intereses".
2. **Esperado**: en vez de un selector, aparece un campo de texto libre "Concepto" para tipear
   (ej. "Combustibles", "Interés por mora"), junto con Monto y eliminar.

## Escenario 3 — Eliminar una fila de concepto

1. Sobre una nota con 2+ conceptos cargados, click en el tacho de una fila.
2. **Esperado**: la fila desaparece y el Total se recalcula sin ese monto, sin recargar la página.

## Escenario 4 — Guardar y reabrir en edición

1. Completar Descripción/Cant./Precio/IVA (Stock=No) + 1 percepción + 1 interés, Guardar.
2. **Esperado**: vuelve al detalle de la Venta con la nota creada, monto incluye los conceptos.
3. Editar esa misma nota.
4. **Esperado**: las mismas 2 filas de concepto (percepción + interés) aparecen precargadas con el
   mismo tipo/concepto/monto con los que se guardaron.

## Escenario 5 — Fila sin concepto elegido no se persiste

1. Click "+ Percepciones" pero dejar el selector en "Seleccionar..." (sin elegir), Guardar.
2. **Esperado**: la nota se guarda sin esa fila (no aparece al reabrir en edición) — no bloquea el
   guardado.

## Escenario 6 — Compras (simetría)

1. Repetir Escenarios 1-4 sobre una Compra.
2. **Esperado**: mismo comportamiento.

## Validación automatizada

```bash
php artisan test --filter=NotaCreditoDebitoConceptos
```

Debe cubrir, como mínimo: persistencia de `conceptos` en `impuestos`, precarga en modo edición, que
el `monto` guardado incluya la suma de conceptos, y que filas sin `concepto` se descarten.
