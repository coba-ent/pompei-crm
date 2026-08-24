# Quickstart: validación de la spec 076

**Fecha**: 2026-08-24

Cómo probar que la feature quedó bien. Los escenarios 1 y 2 son el corazón: si alguno falla, no
sirve seguir.

## Prerrequisitos

- Base local con los datos reales de julio 2026 ya importados.
- Servidor local propio: `php artisan serve --port=8117`. **Verificar el título de la página antes
  de probar** (`curl -s http://127.0.0.1:8117/login | grep -o "<title>[^<]*</title>"` tiene que
  decir *Pompei*): los puertos 8000 y 8010 están tomados por otros proyectos y `artisan serve`
  informa "Server running" igual.
- Nunca en producción: el cliente la está usando.
- Archivos de contraste: `Informe_de_Ventas_Detallado_24-08-2026_1429_Hs.xlsx` y
  `migracion-nueva/excel-origen/Ventas/Ventas 2026.xlsx`.

---

## Escenario 0 — Línea de base ANTES de tocar nada

Anotar, para el 01/07/2026: el `Total Ventas` del KPI y el valor que hoy muestra la columna "Total
Comprobante" en la venta con más líneas del período. Sin esto no se puede demostrar que el
escenario 1 mejoró algo.

---

## Escenario 1 — El importe de línea cierra contra el total (FR-002, SC-001)

1. Abrir el Informe de Ventas para el **01/07/2026** y buscar la venta **23501**, que tiene 12
   líneas.
2. **Esperado**: 12 importes **distintos** en la última columna, que suman **$1.349.647,48**.
   Antes mostraba $1.349.647,46 doce veces.
3. Sumar la columna sobre todo el detalle del período.
4. **Esperado**: coincide con el KPI `Total Ventas` de arriba, con menos de un centavo de
   diferencia.
5. Buscar una venta **con percepciones o impuestos internos** y repetir la suma sobre sus líneas.
6. **Esperado**: cierra igual contra el total del comprobante. Es el caso que valida el prorrateo.
7. Buscar una nota de crédito.
8. **Esperado**: su importe sale en negativo.

> **Gate**: si acá algo no cierra, no seguir. El resto de la spec depende de esta columna.

---

## Escenario 2 — El archivo detallado es comparable con el de Contagram (SC-002)

1. Exportar el detallado del **01/07/2026** con el botón nuevo.
2. Abrirlo junto al de Contagram del mismo período.
3. **Esperado**: una sola hoja; los tres bloques de KPIs arriba; el encabezado en la fila 10; **44
   columnas** con los mismos rótulos y en el mismo orden.
4. Comparar fila por fila los importes de las líneas cuyo costo ya esté congelado.
5. **Esperado**: coinciden con menos de 0,1% de diferencia (SC-003). En las ventas históricas el
   CMV va a diferir: es esperado y lo explica la spec 075.

---

## Escenario 3 — El desglose impositivo imputa a una sola columna (FR-011, I3)

1. En el archivo del escenario 2, buscar una línea con IVA al 21%.
2. **Esperado**: el importe está en `IVA - 21%`, las otras cuatro alícuotas en cero, y el neto en
   `Importe Neto Gravado` (las otras dos columnas de neto en cero).
3. Buscar una línea exenta y una no gravada.
4. **Esperado**: cada una imputa a su propia columna de neto, sin IVA.

---

## Escenario 4 — Las cuatro salidas dicen lo mismo (SC-004)

Para la **misma línea** de la misma venta, comparar el importe en: la pantalla, el export resumen,
el export detallado y el PDF.

**Esperado**: el mismo número en los cuatro. Es el escenario que detecta que alguna salida quedó
con el criterio viejo.

---

## Escenario 5 — Contenido de las columnas (US3)

1. En la pantalla, mirar la columna de comprobante.
2. **Esperado**: dice `Venta` / `Nota de Crédito`, no `B 0001-00000051`.
3. Mirar la columna de producto en una línea de catálogo.
4. **Esperado**: el código antes del nombre.
5. Mirar una fila de nota de crédito.
6. **Esperado**: los importes en rojo y entre paréntesis.
7. Abrir el export resumen y mirar la columna de tipo de comprobante.
8. **Esperado**: `FCB` / `FCA` / `NCB`, no `A` / `B` sueltas.

---

## Escenario 6 — El comprobante con dos intentos en ARCA no duplica filas (I6)

1. Buscar una venta que tenga un comprobante fiscal **rechazado** y otro **aprobado**.
2. **Esperado**: aporta **una** fila por línea, no dos, y los totales del período no se mueven.

> Es el riesgo #1 del plan. Si no hay un caso así en la base local, forzarlo insertando un
> comprobante rechazado sobre una venta de prueba y borrándolo después.

---

## Escenario 7 — Un período grande no se rompe (SC-006)

Exportar el detallado de **todo 2026** (~6.000 líneas × 44 columnas).

**Esperado**: el archivo se descarga completo, con todas las filas, en un tiempo comparable al del
export resumen.

---

## Escenario 8 — Nada de lo que ya andaba se rompió

1. Correr `php artisan test --filter="Informe|Pivot"`.
2. **Esperado**: verde. El test del total repetido cambió a propósito (research §R5); cualquier
   otro que se rompa es una regresión real.
3. Abrir Rankings y "Arma tu Informe" y cruzar la medida `Total Venta`.
4. **Esperado**: sigue funcionando; sus valores pueden moverse **sólo** si la venta tiene conceptos
   extra, porque ahora se prorratean.

---

## Limpieza

Borrar cualquier venta, nota o comprobante de prueba **por los endpoints de la aplicación**, no por
SQL, para que se reviertan stock y tesorería. Verificar al final que el `Total Ventas` del período
volvió al valor del escenario 0.
