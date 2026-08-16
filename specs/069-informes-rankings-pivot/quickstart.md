# Quickstart — Informes Tanda 3 (Rankings, Arma tu Informe)

Guía de validación manual en el navegador, para contrastar contra el relevamiento
(`docs/Informe-Modulo-Informes-2026-08-14/`, capturas 05-15) antes de dar la feature por cerrada.

## Prerrequisitos

- Base local con ventas, notas de crédito/débito y compras cargadas en un rango con datos reales
  (reutilizar los datos de prueba de las tandas 1/2 alcanza).
- `php artisan migrate` corrido (agrega `informes_vistas`).
- `npm run build` corrido (agrega el bundle `informes-pivot.js` y vendoriza PivotTable.js).

## Escenario 1 — Ranking predefinido (US1)

1. Entrar a Informes → Ventas. Verificar que aparece la barra de pestañas: "Informe de Ventas" (activa),
   "Rankings", "Arma tu Informe".
2. Abrir Rankings → Clientes. Verificar: clientes en filas, `fecha de emisión → año → mes` en
   columnas, fila/columna de Totales (FR-019).
3. Cambiar el rango de emisión a "Año actual". Verificar que el cruce se recalcula sin recargar.
4. Abrir el panel de filtros del informe y aplicar un filtro (p. ej. por categoría). Verificar que el
   ranking refleja el mismo conjunto que la tabla de detalle.
5. Ir a un período sin ventas. Verificar el mensaje de vacío, sin errores en consola.
6. Repetir 1-2 en Informes → Compras → Rankings → Proveedores.

## Escenario 2 — Reacomodar y exportar (US2)

1. En Ranking de Clientes, arrastrar la ficha "Clientes" del área de filas al área de columnas.
   Verificar que la tabla se reconstruye al instante (FR-011).
2. Cambiar "Dato" a "Cantidad de Ventas". Verificar que "Accion" se reduce a la única opción "Suma"
   (FR-014).
3. Volver "Dato" a "Total Venta". Verificar que reaparecen las 7 opciones de "Accion".
4. Elegir Accion "Suma como Fracción del Total". Verificar que las celdas pasan a porcentaje y suman
   100%.
5. Usar el embudo de una columna para excluir un valor. Verificar que sale del cruce y los totales
   bajan en ese monto.
6. Presionar "Exportar Excel". Abrir el archivo y verificar que reproduce exactamente lo que estaba
   en pantalla (encabezados, celdas, totales), en dos hojas.
7. Recorrer el selector "Mostrar Como": verificar que **no existe** — sólo están "Dato" y "Accion" en
   pantalla (FR-021, SC-008).

## Escenario 3 — Arma tu Informe con guardado (US3)

1. Abrir "Arma tu Informe" → "Crear Informe". Verificar las 13 fichas de dimensión sin asignar
   (FR-030).
2. Arrastrar "productos" a filas y "clientes" a columnas. Verificar el cruce con el dato de cada
   combinación.
3. Presionar "Guardar informe", escribir una descripción y confirmar. Verificar que la pestaña pasa
   a llamarse con esa descripción (FR-032).
4. Recargar la página completa (F5). Verificar que la pestaña guardada sigue apareciendo y que al
   abrirla reproduce el mismo cruce.
5. Abrir Informes → Compras. Verificar que la vista guardada en Ventas **no** aparece ahí (FR-035).
6. Volver a Ventas, eliminar la vista guardada. Verificar que la pestaña desaparece y que las ventas
   siguen intactas (revisar el detalle del informe).
7. Repetir el guardado con la misma descripción que una vista existente. Verificar el aviso de
   duplicado, sin que bloquee el guardado (edge case de la spec).

## Escenario 4 — Guardas de tamaño

1. (Si hay volumen de prueba suficiente, o simulando con `tinker`) Verificar el aviso de más de
   50.000 filas en el dataset, y que sugiere acotar el rango.
2. Armar un cruce de dos dimensiones de alta cardinalidad en columnas hasta superar 1.000 columnas.
   Verificar que no se renderiza y aparece el aviso de FR-019b en vez de colgar la pantalla.

## Checklist de fidelidad estructural

Contrastar contra las capturas 05 a 15:

- [ ] 3 selectores arriba del pivot: sólo Dato y Accion (Mostrar Como ausente).
- [ ] Drag & drop entre pool / filas / columnas funciona igual que en las capturas.
- [ ] Embudo de exclusión por columna presente.
- [ ] Ordenamiento por click en encabezado de columna presente.
- [ ] Botón "Exportar Excel" propio de cada pestaña.
- [ ] Modal "Guardar Informe" con campo único "Descripción" y botones Cancelar/Guardar.
- [ ] Vista guardada aparece como pestaña con el nombre ingresado, junto a Rankings y Arma tu Informe.
- [ ] Ningún lugar de la pantalla ofrece mapa de calor, gráfico de líneas/barras ni histograma.
