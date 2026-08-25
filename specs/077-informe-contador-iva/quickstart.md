# Quickstart — Validación del Informe "Información para tu Contador" (spec 077)

**Spec**: [spec.md](./spec.md) · **Contratos**: [contracts/endpoints.md](./contracts/endpoints.md)

Guía para validar que la feature funciona de punta a punta. No incluye código de implementación.

---

## Prerrequisitos

- Base local con datos (`contagram_migracion` o una copia — **nunca producción**, ver memoria del
  proyecto "NUNCA probar en producción").
- Servidor local levantado. **Ojo**: el puerto 8000 lo ocupa otro proyecto en esta máquina; usar uno
  libre (`php artisan serve --port=8123`).
- Usuario con permiso `informes.ver`.
- Assets compilados (`npm run build` o `npm run dev`).

---

## Escenario 1 — La pantalla arranca vacía (FR-006, FR-007)

1. Entrar a Informes → **Información para tu Contador**.
2. **Esperado**: pestaña "IVA VENTAS" activa; combos Mes y Año **sin elegir**; tabla vacía con
   "Utilizá los filtros y generá tu informe a medida"; los cinco totales en `$ 0,00`.
3. Abrir DevTools → Network. **Esperado**: **ninguna** llamada a `.../data` ni `.../stats`.
4. Apretar "Exportar" sin elegir período. **Esperado**: toast pidiendo elegir mes y año; **no** se
   descarga nada.

---

## Escenario 2 — Libro IVA Ventas de un período (US1)

1. Elegir Mes = Agosto, Año = 2026.
2. **Esperado**: la tabla se llena sin recargar la página y los totales se completan.
3. Verificar a mano que **la ecuación cierra exacta**:
   `No Gravados/Exentos + Gravados + IVA Total + Perc. IVA/IIBB Total = Total Facturado`.
   Sin diferencia de centavos (FR-011).
4. Scrollear la tabla a la derecha. **Esperado**: las 19 columnas en el orden del contrato, terminando en
   Imp. Internos e Imp. Municipales.
5. **Esperado**: Imp. Municipales en `$0,00` en todas las filas (brecha documentada).
6. Cambiar a otro mes. **Esperado**: tabla y totales se recalculan sin recargar.

---

## Escenario 3 — El mes de imputación manda en Compras (US2 — el caso más importante)

Es la regla que justifica el campo "Contador". Vale la pena armar el dato a propósito:

1. Crear (o buscar) una compra con **fecha de emisión en julio 2026** y campo **"Contador" = agosto 2026**.
2. Pestaña **IVA COMPRAS**, período agosto 2026. **Esperado**: la compra **aparece**.
3. Mismo informe, período julio 2026. **Esperado**: la compra **no aparece**.
4. Buscar una compra **sin** "Contador" cargado. **Esperado**: aparece en el mes de su fecha de emisión.
5. **Esperado** en toda la pestaña: la columna dice "Proveedor" y el filtro dice "Medio de Pago".

---

## Escenario 4 — Las NC/ND respetan su propio mes de imputación (FR-009a)

1. Buscar una NC/ND cuyo **Mes de Imputación** difiera del mes de su fecha de emisión.
2. **Esperado**: aparece en el período de imputación, no en el de emisión.
3. **Esperado**: figura como fila propia, con su tipo (NCA/NDA) y el importe con signo — crédito en
   negativo, débito en positivo.
4. **Esperado**: los totales del período reflejan ese signo (una NC grande baja el Total Facturado).

---

## Escenario 5 — ARCA vs. manuales, y que NO estén en Compras (US3, FR-014a)

1. En **IVA VENTAS**, período con ventas mixtas.
2. **Esperado** al abrir: "Facturas Aprobadas por ARCA" tildado, "Facturas Manuales" destildado.
3. Anotar la cantidad de resultados. Tildar además "Facturas Manuales".
   **Esperado**: la cantidad **sube** y los totales acompañan.
4. **Verificación de la partición (SC-004)**: resultados con sólo ARCA + resultados con sólo Manuales
   debe dar exactamente los resultados con ambos tildados. Sin faltantes ni duplicados.
5. Destildar los dos. **Esperado**: tabla vacía, totales en `$ 0,00`, **sin** mensaje de error.
6. **Caso de la venta reintentada**: buscar una venta con un intento rechazado y uno aprobado.
   **Esperado**: aparece **una sola vez**, y como aprobada por ARCA. *(Es el incidente de la Venta 24447
   — si aparece dos veces o como manual, el filtro está usando el `morphOne` en vez del `EXISTS`.)*
7. Cambiar a **IVA COMPRAS**. **Esperado**: **no hay casillas**; la tabla arranca pegada a la barra de
   totales.

---

## Escenario 6 — Filtros y columnas (US4)

1. Abrir "Filtros". **Esperado**: los 8 campos del contrato, con Select2 en los de datos dinámicos.
2. Filtrar por Condición de IVA = Responsable Inscripto → Buscar.
   **Esperado**: tabla **y totales** se acotan a esos comprobantes.
3. Cambiar el mes con el filtro puesto. **Esperado**: el filtro sigue vigente sobre el nuevo período
   (FR-029).
4. **Medio de Cobro (el caso de cardinalidad)**: buscar una venta con **varios cobros**. Filtrar por uno
   de esos medios. **Esperado**: la venta aparece **una sola vez** y sus importes **no** se multiplican
   en los totales.
5. Selector de columnas: destildar una. **Esperado**: desaparece de la tabla sin recargar y **los totales
   no cambian**.
6. Ir a IVA Compras y volver. **Esperado**: cada pestaña conserva su propio período, filtros y columnas
   (FR-030).

---

## Escenario 7 — Exportación (US5)

1. Con período y filtros puestos, y alguna columna oculta, apretar "Exportar".
2. Abrir el `.xlsx`. **Esperado**:
   - Mismos comprobantes e importes que la pantalla, en el mismo orden.
   - Las **19 columnas**, incluidas las que estaban ocultas (FR-034).
   - Bloque de totales arriba, coincidiendo con la pantalla.
   - Nombre tipo `Libro IVA Ventas 08-2026.xlsx`.

---

## Escenario 8 — Que no vuelva el 414 (research, Decisión 9)

Es la razón por la que el endpoint de datos va por POST.

1. DevTools → Network, generar un período.
2. **Esperado**: la llamada a `.../data` es **POST** y su URL es corta (sin el descriptor de columnas de
   DataTables en el querystring).
3. **Esperado**: status **200**, nunca 414.

> Contexto: el 24/08/2026 el informe de Compras devolvía 414 en el VPS por este motivo — la URL con 36
> columnas superaba el buffer de Nginx y ni siquiera llegaba a Laravel (no dejaba rastro en
> `laravel.log`). Se corrigió la infra, pero acá el código no depende de ese ajuste.

---

## Tests automatizados esperados

Constitución, principio IV (testing donde hay dinero o impacto fiscal). Como mínimo:

| Test | Cubre |
|---|---|
| La ecuación de totales cierra exacta, incluso con NC/ND y percepciones | FR-011, SC-002 |
| Compra con imputación ≠ emisión cae en el período imputado | FR-009, SC-003 |
| Compra sin imputación cae en el período de emisión | FR-009 |
| NC/ND cae en su propio mes de imputación | FR-009a |
| NC resta y ND suma en los totales | FR-022 |
| Partición ARCA/manuales: exhaustiva y sin solapamiento | FR-017, SC-004 |
| Venta con intento rechazado **y** aprobado cuenta una sola vez, como firme | FR-018 |
| Venta con varios cobros no se duplica al filtrar por medio de cobro | FR-031 |
| Imp. Internos y Municipales **no** entran en el Total Facturado | FR-011a |
| Comprobantes con borrado lógico quedan fuera | FR-022b |
| IVA Compras ignora los parámetros `arca`/`manuales` | FR-014a |
| Sin período → 422 en `data`, `stats` y `exportar` | FR-007, FR-036 |

> **Recordatorio del proyecto**: la suite corre en SQLite y MySQL es más estricto
> (`ONLY_FULL_GROUP_BY`). Este informe usa `GROUP BY` intensivamente, así que **verde en tests no alcanza**:
> hay que abrir la pantalla en el navegador contra MySQL antes de darla por cerrada.
