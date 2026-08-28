# Quickstart: validación manual (spec 088)

**Por qué existe este archivo**: memoria del proyecto — la suite verde en SQLite no garantiza el
comportamiento en MySQL. Esta feature toca código fiscal en producción real; antes de deployar hay que
correr esto a mano contra MySQL local, con los 14 valores ya conocidos de antemano (ver
[data-model.md §2](./data-model.md)).

## Prerrequisitos

- Migración `create_comprobantes_historicos_arca_table` aplicada (`php artisan migrate`).
- Base MySQL local (no SQLite) con al menos algunas ventas reales de Agosto 2026 ya cargadas (para
  verificar que el Libro IVA sigue sumando bien el resto del período, no sólo los históricos).

## Pasos

1. **Confirmar la carga**: `SELECT COUNT(*) FROM comprobantes_historicos_arca;` → debe dar **14**.
2. **Confirmar el total agregado**: sumar `total` de la tabla → debe dar **$1.604.530,47** (tolerancia
   de centavos por redondeo — ver invariante en data-model.md).
3. **Libro IVA Ventas, Agosto 2026**: abrir `informes/contador` → Libro IVA Ventas → filtrar Agosto
   2026 con ambas casillas (Electrónicas + Manuales) tildadas. Verificar:
   - Aparecen 14 filas más que antes de esta feature (comparar con el conteo pre-deploy).
   - La barra de totales del período subió exactamente el neto/IVA de los 14 históricos.
   - Ninguna fila existente (venta real) cambió de valor.
4. **Filtro Electrónicas/Manuales**: destildar "Facturas Manuales", dejar sólo "Aprobadas por ARCA" —
   los 14 históricos siguen apareciendo (FR-010: siempre clasifican como aprobados).
5. **IVA Digital, Agosto 2026**: generar el ZIP (botón "IVA Digital"). Abrir "Comprobantes
   Ventas...txt" y "Alicuotas Ventas...txt", verificar que las 14 líneas nuevas tienen los números de
   comprobante, CAE (en el campo que corresponda) y neto/IVA de la tabla de data-model.md §2.
6. **Test de aislamiento (el crítico)**: antes y después de la migración, comparar:
   - Reporte Final del período → mismo total, sin diferencia.
   - Cuenta Corriente de un cliente involucrado (ej. Roberto, CUIT 23247526749) → mismo saldo.
   - Tesorería → cero movimientos nuevos.
   - Informe de Stock → cero ajustes nuevos.
7. **Caso límite de la Decisión 2**: confirmar que existe una `Venta` real con `id=1` en la base de
   prueba (o crearla) y que el Libro IVA/IVA Digital no mezcla sus datos con el histórico id 1 — cada
   uno debe mostrar su propio cliente/importe, sin cruzarse.

## Resultado esperado

Todos los puntos anteriores en verde, sin ninguna diferencia inesperada — es el mismo criterio que ya
usó la verificación manual de la spec 086 (T021), que en su momento sí encontró un bug real
(compras "Sin Factura") que la suite en SQLite no vio.
