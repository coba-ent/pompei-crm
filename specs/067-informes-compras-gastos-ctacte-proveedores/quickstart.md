# Quickstart — Validación del Módulo Informes, Tanda 1

**Feature**: `067-informes-compras-gastos-ctacte-proveedores`

Guía para validar que los tres informes funcionan de punta a punta. No incluye código de
implementación — eso vive en `tasks.md` y en la implementación.

---

## Prerrequisitos

```bash
# XAMPP con MySQL arriba, DB `contagram`
php artisan migrate            # no debería haber migraciones nuevas de esta spec
npm run build                  # o `npm run dev` para desarrollo
php artisan serve
```

Datos mínimos para que los informes tengan algo que mostrar:

- ≥ 3 proveedores, uno de ellos con `saldo_inicial ≠ 0`.
- ≥ 4 compras en el mes actual, entre ellas:
  - una con ítems de **dos alícuotas de IVA distintas** (ej. 21% y 10,5%);
  - una con un ítem de **cantidad negativa** (bonificación del proveedor);
  - una con conceptos de percepción (uno con "IIBB" en el texto, uno con "IVA", uno con un texto que
    no matchee ninguno);
  - una **pagada parcialmente** y una **vencida** (`fecha_vto_pago` pasada con saldo > 0).
- ≥ 1 Nota de Crédito de compra que deje a un proveedor con saldo negativo.
- ≥ 6 gastos repartidos en 2 categorías, con al menos una subcategoría y **un gasto colgado directo
  de la categoría raíz** (sin subcategoría), y al menos uno marcado como pendiente.

---

## Escenario 1 — Informe de Compras (US1)

1. Sidebar → **Informes → Compras**. Verificar que es una URL propia (`/informes/compras`), no un
   fragmento `#`, y que el informe abre con el rango **Mes actual**.
2. Comprobar el bloque de KPIs: `Total Compras Creadas + ND − NC = Total Compras`. Sumar a mano los
   totales de las compras cargadas y confirmar que coincide **al centavo**.
3. Verificar que **Cantidad Prod./Serv.** es la suma de las cantidades de los ítems, no el número de
   líneas.
4. Pasar el mouse por el ⓘ de **Costo Actual** y confirmar que el tooltip explica que usa el costo
   vigente del producto, no el histórico.
5. Cambiar "Emisión" a **Año actual** → KPIs y tabla se recalculan **sin recargar la página**.
6. Elegir **Desde - Hasta** → confirmar los dos calendarios contiguos con la lista de accesos rápidos
   visible en paralelo y los campos de fecha tipeables.
7. Abrir el selector de columnas y activar IVA 21%, IVA 10,5%, Perc. IIBB, Otras Percepciones e
   Importe Neto Gravado. Confirmar que aparecen **en pantalla**.
8. Sobre la compra de dos alícuotas: verificar que cada alícuota va a su propia columna y que
   `Netos + IVAs + percepciones + imp. internos = Total Compra`.
9. Sobre la compra con percepciones: la de "IIBB" va a Perc. IIBB, la de "IVA" a Perc. IVA, y la
   inclasificable a **Otras Percepciones** — ninguna se pierde.
10. Verificar la fila de la Nota de Crédito: importes negativos, y el KPI Total Compras la resta
    **una sola vez**.
11. Verificar que "Total Comprobante" se repite en cada fila de una misma compra pero **no infla** el
    KPI.
12. Recargar la página: la selección de columnas del paso 7 **persiste**.
13. Aplicar filtros combinados (ej. dos proveedores + una categoría + Estado del Pago = Vencido) y
    confirmar el AND entre campos y el OR dentro de cada campo multi-valor.
14. Eliminar una compra desde el módulo Compras y confirmar que **desaparece** del informe y de los
    KPIs.

---

## Escenario 2 — Informe de Gastos (US2)

1. Sidebar → **Informes → Gastos** (`/informes/gastos`), abre con Mes actual.
2. Verificar el bloque Desde / Hasta / **Gasto Total**.
3. Confirmar la agrupación de dos niveles Categoría → Subcategoría con subtotal en cada nivel.
4. Expandir una subcategoría: aparece el detalle (Id, Fecha, Descripción, Medio de Pago, Total).
5. Sumar los subtotales de todas las Categorías → debe dar **exactamente** el Gasto Total.
6. Verificar que el gasto colgado de la categoría raíz aparece bajo **"Sin subcategoría"**, no
   desaparecido.
7. Filtrar por Estado del Pago = **Pendiente** → el Gasto Total y el detalle se restringen a los
   pendientes.
8. Con muchos gastos, paginar y confirmar que **los subtotales no cambian** al cambiar de página
   (prueba de que se calculan en el servidor, no sobre la página visible).

---

## Escenario 3 — Cuenta Corriente Proveedores (US3)

1. Sidebar → **Informes → Cuenta Corriente Proveedores**. Confirmar que la entrada de clientes ahora
   se llama **"Cuenta Corriente Clientes"**.
2. Tab **Saldos Proveedores** activo por defecto: columnas Proveedor, A Vencer, 0-30, 31-60, 61-90,
   \>90, Total. Ordenar por Total.
3. El proveedor con NC mayor a su deuda aparece con **Total negativo**, no oculto.
4. El proveedor con `saldo_inicial ≠ 0` y sin compras **aparece igual**.
5. Clic en el nombre de un proveedor → se abre el modal de ficha. Confirmar que **no tiene ningún
   botón de edición** y que ninguna interacción escribe.
6. Tab **Movimientos**: filtrar por un proveedor + Operación = Compra. Verificar las 11 columnas y
   que las celdas no aplicables están vacías (una fila de Pago no tiene Total Compra ni Categoría).
7. Sumar la columna "A Pagar" de las filas de Compra de ese proveedor + su fila de Saldo Inicial →
   debe coincidir con su Total en el tab Saldos.
8. Confirmar que **no existe** menú Ver/Editar/Eliminar por fila (fuera de alcance por diseño).
9. Ir al listado de **Compras**, abrir el menú de fila de una compra y elegir **"Cta Cte"**: ya no
   debe estar deshabilitado; debe abrir este informe con ese proveedor precargado y el tab
   Movimientos abierto.
10. Verificar que el Total General de esta pantalla coincide con el bloque de cuentas a pagar del
    Dashboard.

---

## Escenario 4 — Exportación (US4)

Para **cada uno de los tres informes**:

1. Aplicar filtros y un rango acotado.
2. **Exportar a Excel** → abrir el archivo y confirmar que tiene **exactamente dos hojas**: una
   formateada con agrupaciones y subtotales, y una plana de una fila por registro sin celdas
   combinadas.
3. Confirmar que los totales del archivo coinciden **al centavo** con los KPIs de la pantalla.
4. En Compras: confirmar que el Excel trae el desglose impositivo completo **aunque esas columnas
   estuvieran ocultas** en pantalla.
5. **Exportar a PDF** → debe abrirse **dentro del modal de PDF compartido**, no en una pestaña nueva.
6. Abrir las DevTools y confirmar que ninguna exportación provocó una recarga de página.

---

## Escenario 5 — Estados de borde

1. Elegir un rango sin datos (ej. un mes futuro) → los tres informes muestran KPIs en cero y un
   estado vacío explícito, **sin errores**.
2. Loguearse con un usuario sin el permiso de informes → las tres entradas **no aparecen** en el
   sidebar y el acceso directo por URL devuelve 403.
3. Cargar una compra sin ítems de producto (sólo conceptos) → aparece con Cantidad Prod./Serv. en
   cero, sin desaparecer del Total Compras.
4. Borrar (soft delete) un producto referenciado por una compra vieja → la fila del informe sigue
   mostrando la descripción histórica del ítem.

---

## Tests obligatorios (constitución IV — "donde hay dinero")

Ninguna de las tres pantallas se da por terminada sin estos en verde:

| Test | Verifica |
|------|----------|
| `InformeComprasTest::test_ecuacion_kpis` | `Creadas + ND − NC = Total Compras` |
| `InformeComprasTest::test_total_comprobante_no_se_suma_por_fila` | una compra de N ítems suma **una vez** al KPI |
| `InformeComprasTest::test_cantidad_prod_serv_suma_cantidades` | no cuenta líneas |
| `InformeComprasTest::test_nota_credito_usa_la_misma_formula` | sin ramas por tipo de comprobante (FR-016) |
| `InformeComprasTest::test_compra_eliminada_no_aparece_ni_suma` | soft delete respetado |
| `InformeComprasDesgloseImpositivoTest::test_iva_por_alicuota_reconstruye_el_total` | invariante fiscal |
| `InformeComprasDesgloseImpositivoTest::test_clasificacion_de_percepciones_no_pierde_importes` | IVA + IIBB + Otras = total percepciones |
| `InformeComprasDesgloseImpositivoTest::test_item_con_cantidad_negativa` | bonificación resta con su signo |
| `InformeGastosTest::test_suma_de_subtotales_igual_al_total` | FR-026 |
| `InformeGastosTest::test_gasto_sin_subcategoria_no_desaparece` | edge case |
| `InformeGastosTest::test_subtotales_no_dependen_de_la_pagina` | subtotales server-side |
| `CuentaCorrienteProveedorTest::test_buckets_de_aging` | los 5 tramos |
| `CuentaCorrienteProveedorTest::test_saldo_negativo_se_lista` | FR-031 |
| `CuentaCorrienteProveedorTest::test_saldo_inicial_sin_compras_crea_fila` | FR-032 |
| `CuentaCorrienteProveedorTest::test_saldos_coincide_con_movimientos` | invariante FR-036 |
| `InformesExportTest::test_excel_tiene_dos_hojas` | FR-040, los tres informes |
| `InformesExportTest::test_totales_export_coinciden_con_pantalla` | FR-043 |
| `InformesExportTest::test_pdf_se_sirve_inline` | FR-042 |
| `InformesConciliacionTest` | totales vs. listado de Compras, listado de Gastos y Dashboard (SC-004) |
| `InformesAccesoTest` | 200 con permiso, 403 sin él, en las tres rutas (FR-002) |

**Regresión obligatoria**: `CuentaCorrientePorClienteTest` y `CuentaCorrienteSaldoInicialTest` deben
seguir en verde **sin modificaciones** — son la prueba de que `App\Services\Tesoreria\CuentaCorriente`
no se tocó (research R7).

```bash
php artisan test --filter=Informe
php artisan test --filter=CuentaCorriente
```
