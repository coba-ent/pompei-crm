# Quickstart: Validar el neteo de NC/ND en Rankings del Dashboard

## Prerrequisitos

- Entorno local con XAMPP corriendo, DB `contagram` con datos de prueba (ventas, clientes,
  productos, notas de crédito/débito).
- Login con un usuario con permisos `ventas.ver` + `clientes.ver` (Ranking de Clientes) y
  `ventas.ver` + `productos.ver` (Ranking de Productos) — ver spec 070.

## Escenario 1 — NC en el mismo período (piso descartado)

1. Crear una Venta de $10.000 a un Cliente X, `fecha_emision` dentro del mes actual.
2. Emitir una NC de $12.000 sobre esa venta (mayor al total), `fecha_emision` también en el mes
   actual.
3. Abrir `/dashboard` con período "Mes Actual".
4. **Esperado**: el Cliente X aparece en el Ranking de Clientes con **-$2.000** (no $0, no
   desaparece de la lista si entra en el Top 10 por otro motivo, no se recorta).

## Escenario 2 — ND sin techo

1. Cliente Y con una Venta de $5.000 en el mes actual.
2. ND de $50.000 sobre esa venta, misma fecha (mes actual).
3. **Esperado**: Cliente Y aparece con $55.000, sin techo.

## Escenario 3 — NC en período distinto al de la venta

1. Cliente Z con una Venta de $10.000 emitida en julio.
2. NC de $3.000 sobre esa venta, pero `fecha_emision` de la NC en agosto.
3. Abrir `/dashboard` con período "julio": **esperado** $10.000 (la NC no resta acá).
4. Abrir `/dashboard` con período "agosto": **esperado** el Cliente Z aparece con **-$3.000**
   (ajuste "suelto", sin piso, sin necesitar una venta propia en agosto).

## Escenario 4 — Ranking de Productos con devolución parcial

1. Producto P vendido 20 unidades en el mes actual (una o varias ventas).
2. NC que ajusta 5 unidades de Producto P, misma venta, mismo período.
3. **Esperado**: Producto P aparece con 15 unidades netas.

## Escenario 5 — Nota sin ítems desglosados

1. Cliente W con una Venta de $8.000 con 2 productos.
2. NC global de $8.000 sobre esa venta **sin** ítems cargados (nota_credito_debito_items vacío).
3. **Esperado**: Ranking de Clientes resta los $8.000 para Cliente W. Ranking de Productos **no**
   se ve afectado por esta nota (ninguno de los 2 productos pierde cantidad).

## Escenario de control — Informes no cambia

1. Abrir Informes > Ventas > Ranking de Clientes para el mismo período y mismos datos de arriba.
2. **Esperado**: el resultado es idéntico al que mostraba antes de esta feature (spec 069 no se
   toca).

## Escenario de control — KPIs/Totales no cambian

1. Comparar el total de Ventas que muestran los KPIs del Dashboard contra la suma de todos los
   montos del Ranking de Clientes (no sólo el Top 10) para el mismo período.
2. **Esperado**: coinciden centavo a centavo (SC-001).
