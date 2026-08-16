# Phase 1 — Quickstart / guía de validación: Informes Tanda 2

## Prerrequisitos

- XAMPP con MySQL levantado y la base local en uso.
- `composer install` y `npm install` ya corridos.
- Un usuario con el permiso `informes.ver` (ver `CREDENCIALES_ACCESO.txt`).
- Datos en el período de prueba: al menos 3 ventas con ítems, 1 Nota de Crédito y 1 Nota de Débito,
  1 producto comprado (para que tenga CMV > 0) y 1 producto nunca comprado (CMV = 0), cobros
  parciales sobre alguna venta, 1 compra con pago, 1 otro ingreso y 2 gastos (uno pendiente y uno
  pagado, en categorías distintas con subcategoría).

## Build

```bash
npm run build      # o npm run dev mientras se trabaja
```

Verificar que `informe-ventas.js` y `reporte-final.js` están declarados en `vite.config.js`.

## Recorrido manual

### Informe de Ventas — `/informes/ventas`

1. La pantalla abre con el **mes calendario completo** y muestra los 3 bloques de KPIs.
2. Verificar la ecuación del bloque 1: `Creadas + ND − NC = Total Ventas`.
3. Verificar que "Cantidad Prod./Serv." es la **suma de cantidades**, no el número de filas.
4. El detalle trae las 12 columnas en orden, con scroll horizontal y una fila por ítem; las filas de
   NC están en negativo.
5. En la fila de NC, `Result.` = `Precio Total Neto − CMV Total` (con signo). **En pantalla nunca se
   ve el valor desviado.**
6. Cambiar el rango a "Año actual": KPIs y tabla se recalculan **sin recargar**.
7. Paginar el detalle: **ningún KPI cambia**.
8. Aplicar cada filtro del panel, uno por uno, y confirmar que tabla y KPIs responden. Los selects
   de catálogo abren con buscador (Select2).
9. "Exportar a PDF" abre el **modal compartido**, no una pestaña nueva.
10. "Exportar Resumen" descarga un `.xlsx` de **dos hojas**. En la hoja legible, la celda `Resultado`
    de la fila de NC muestra la suma (réplica R1); en la hoja plana, la resta. Los totales de ambas
    hojas coinciden con los KPIs.

### Reporte Final — `/informes/reporte-final`

1. Abre en "Ventas Vs. Compras" con la cabecera Desde/Hasta/Total Ingresos/Total Egresos/Resultado y
   el banner informativo **descartable**.
2. Expandir Ingresos → Ventas: desglose por categoría con la columna "Activo" tildada.
3. Destildar una categoría: Total del bloque, Total Ingresos y Resultado bajan **en el acto**, sin
   petición de red (verificable en la pestaña Network del navegador).
4. Pasar a "Cobros Vs Pagos": Total Ingresos es menor que el devengado si hay ventas no cobradas del
   todo; cada categoría abre además por **Cuenta de Tesorería**, y Gastos abre por Subcategoría →
   Cuenta.
5. El gasto **pendiente** aparece en la vista devengado y **no** en la vista caja.
6. En pantalla, Total Egresos se muestra en positivo en las dos vistas.
7. Con una categoría destildada, exportar: el archivo refleja el escenario simulado.
8. En el Excel: hoja legible de la vista devengado con Total Egresos **negativo** y
   `Resultado = Ingresos + Egresos`; hoja legible de la vista caja con Total Egresos **positivo**,
   subtotales de bloque negativos y líneas de cuenta positivas (réplica R2). Desde/Hasta completos
   en las dos.

## Tests (obligatorios — constitución IV)

```bash
php artisan test --filter=Informes
```

| Test | Verifica |
|------|----------|
| `InformeVentasTest` | ecuación de KPIs, unidad de fila (una por ítem), signos de NC/ND, orden por defecto, respeto del borrado lógico, filtros clave (cliente, categoría, estado del cobro, tipo de operación) |
| `InformeVentasCmvTest` | promedio ponderado de compras; producto sin compras → CMV 0; `Costo Actual ≠ CMV`; `Resultado = Precio Neto − CMV` en todas las filas incluidas las NC |
| `ReporteFinalTest` | jerarquía de cada vista, gastos pendientes incluidos sólo en devengado, imputación por fecha de cobro/pago en la vista caja, rótulos de fallback, cuentas en $0,00 listadas |
| `ReporteFinalSimuladorTest` | el parámetro `excluidas[]` afecta los totales del export exactamente en el monto de las categorías excluidas |
| `ReplicasContagramTest` | **R1**: la celda desviada existe en la hoja legible, la hoja plana y los KPIs no cambian. **R2**: signos y fórmula de Resultado por hoja |
| `InformesAccesoTest` (existente, se extiende) | sin `informes.ver` no responden ni las vistas ni los endpoints nuevos |

## Rendimiento (SC-002)

Con ~5.000 ventas y sus notas en el rango, `/informes/ventas/data` y `/informes/ventas/stats` deben
responder en **< 3 s**. Si no, revisar que el filtro de rango se aplique **dentro** de cada rama del
`UNION ALL` (research R9) y que el costo promedio venga de una subconsulta agrupada, no
correlacionada.
