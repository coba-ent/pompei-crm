# Línea de base ANTES de la migración (spec 075)

**Fecha de captura**: 2026-08-24
**Base**: local (`contagram`), antes de agregar `costo_unitario`.
**Método**: se sumaron directamente las columnas del detalle de `VentasInformeQuery` (las mismas
que alimentan las cards del Informe de Ventas) en lugar de leerlas de la pantalla, para que el
Escenario 2 de `quickstart.md` se pueda comparar con precisión de centavos y no de redondeo visual.
Script usado: `storage/tmp/base075b.php` (temporal, no versionado).

| Rango | Filas del detalle | Costo Mercadería Vendida | Resultado | Costo Actual |
|---|---:|---:|---:|---:|
| 01/07/2026 – 31/07/2026 | 1.018 | 24.600.037,57 | 55.862.355,06 | 40.948.412,11 |
| 01/01/2026 – 31/12/2026 | 5.876 | 122.698.231,34 | 277.915.410,02 | 210.479.644,66 |

Con todas las filas de `costo_unitario` en `NULL`, estos tres números deben quedar **idénticos**
después de la migración y del `COALESCE` (T014, gate de la Fase 3). Cualquier diferencia acá
significa que el fallback está mal armado.

---

## T041 / Escenario 7 — contraste contra el export real de Contagram (24/08/2026)

No hizo falta esperar a que existiera un período posterior al despliegue. Se hizo el backfill del
export real de julio 2026 (`actualziacion/julio/*.xlsx`, 15 archivos, 1.016 líneas únicas, el mismo
que fundamenta `research.md §R1` y que cuadra al centavo con las cards de Contagram) **dentro de una
transacción con `ROLLBACK`**, aplicando el método de `research.md §R9`:
`costo_unitario = CMV Total ÷ Cantidad`, con llave `Id` de venta + `Código` de producto.

### Resultado

| Comparación | Contagram | CRM | Diferencia |
|---|---:|---:|---:|
| **[A] Las 992 líneas de venta con costo congelado** | 41.352.118,83 | 41.352.118,83 | **0,0000 %** |
| [B] La card completa de julio | 40.574.923,05 | 40.959.894,97 | 0,9488 % |

**SC-001 se cumple.** [A] es la medición válida: sobre todas las líneas que tienen costo congelado,
el CRM reproduce el CMV de Contagram **al centavo**, sin una sola diferencia de redondeo. Contra la
línea de base rota del 39 %, la fórmula quedó verificada contra datos reales.

### Por qué [B] no cierra (dos causas, ninguna del cálculo)

1. **6 de las 998 líneas de venta de julio no tienen contraparte en el export** (items 31126, 31164,
   31167, 31351, 36601, 36602). Se quedan en el fallback y aportan ~162 mil de más.
2. **Las 19 líneas de nota de crédito del export (−925.663,45) no se pueden mapear**: los `Id` de
   notas del CRM **no conservan los de Contagram**, a diferencia de los de ventas. La nota 695 es de
   **julio** en el export y de **junio** en el CRM; las notas que el CRM fecha en julio son la
   665-684 y las del export son la 695-714 — dos conjuntos disjuntos.

El punto 2 es una **brecha de la migración de NC/ND, ajena a la spec 075**, y conviene revisarla
aparte: si los ids y las fechas de las notas están corridos, cualquier informe que las cruce con un
export de Contagram por `Id` va a dar distinto. Se verificó que el mecanismo del CRM sí funciona:
forzando un costo congelado en los 20 ítems de nota de julio, la rama de notas pasó de −603.120,77
a −222.222,06, o sea el `COALESCE` de la rama de notas responde como debe.

### La base quedó intacta

Tras el `ROLLBACK`: julio 24.600.037,57 / 55.862.355,06 y 2026 completo 122.698.231,34 /
277.915.410,02 — idénticos a la tabla de arriba. Cero líneas con `costo_unitario`.
