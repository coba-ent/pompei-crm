# Quickstart: validar Bonificación efectiva por línea con Descuento General

Validación manual en **local** (nunca en el VPS de producción — ver memoria del proyecto). Los
pasos automatizados (tests de Feature) se listan en `tasks.md`; esta guía es para confirmar el
comportamiento end-to-end en el navegador, algo que un test de Feature en PHPUnit no cubre (el
recálculo en pantalla es JS puro).

## Prerrequisitos

- Servidor local corriendo (`php artisan serve` o XAMPP) contra la base local (nunca producción).
- Un cliente y al menos dos productos de catálogo cargados.
- Sesión logueada como usuario con permiso de Ventas/Presupuestos/Compras.

## Escenario 1 — Subtotal de fila refleja el Descuento General (US1)

1. Ir a **Ventas → Nueva Venta**.
2. Cargar dos ítems de catálogo, cantidad 1, sin tocar el campo "Desc." de ninguno (queda en 0).
3. Anotar el "Subtotal" que muestra cada fila (debería ser igual al precio unitario de catálogo).
4. En "Descuento General", escribir `10` (modo %, el default).
5. **Verificar**: el "Subtotal" y el "Total" de **cada fila** bajan un 10% respecto de lo anotado
   en el paso 3, sin necesidad de guardar ni recargar la página.
6. **Verificar**: el campo "Desc." de cada fila sigue en `0` — no cambió.
7. Editar el campo "Desc." de la primera fila a `5`.
8. **Verificar**: el Subtotal de esa fila refleja el efecto combinado (no 15%, sino
   `1 - 0.95*0.90 = 14,5%` sobre el bruto) — comparar contra una calculadora.
9. Repetir el mismo flujo con "Descuento General" en modo `$` (monto fijo) en vez de `%` — tocar
   el botón toggle junto al campo. **Verificar** que el Subtotal de fila también se ajusta.
10. Repetir el flujo completo en **Presupuestos → Nuevo Presupuesto** y **Compras → Nueva Compra**.

**Éxito**: en los tres módulos, el Subtotal/Total de cada fila cambia en tiempo real al tocar el
Descuento General, y el total de a pie de página no cambia de valor respecto de lo que ya
calculaba antes de esta feature (sólo cambia lo que se ve por fila).

## Escenario 2 — PDF muestra "Bonif." combinada (US2)

1. Con la Venta del Escenario 1 (Descuento General 10%, un ítem con 5% de línea), guardarla.
2. Abrir su PDF (modal `AppPdf`, no `window.open`).
3. **Verificar**: la columna "Bonif." del ítem sin descuento de línea propio muestra `10%` (no
   `-`).
4. **Verificar**: la columna "Bonif." del ítem con 5% de línea muestra el % combinado (`14,5%` si
   el Descuento General sigue en 10%), no `5%` ni `15%`.
5. Crear una segunda Venta sin Descuento General y con un ítem con 8% de descuento propio de
   línea. **Verificar**: la columna "Bonif." de ese ítem sigue mostrando `8%` (sin regresión del
   caso que ya andaba bien).
6. Repetir para el PDF de Presupuesto y de Compra.

**Éxito**: en los tres PDF, "Bonif." muestra el % real que bajó esa línea, sea cual sea su origen
(línea, general, o ambos combinados).

## Escenario 3 — NC/ND: Bonif. de línea NO cambia, pero el PDF suma la fila de Descuento General (US3)

1. Con la Venta del Escenario 1 aprobada (con CAE si `facturacion_electronica` está activa, o sin
   CAE si no), ir a **Egresos → Notas de Crédito/Débito → Nueva Nota**.
2. Seleccionar esa Venta como comprobante a ajustar. **Verificar** que el paso 2 precarga
   Descuento General 10% en la cabecera de la nota (comportamiento ya existente, spec 095/096 —
   no debería cambiar).
3. **Verificar**: el campo "Desc." de cada línea precargada muestra el descuento **propio** de esa
   línea del comprobante original (0% o 5% según el ítem, no el 10% general) — comportamiento ya
   existente, confirmar que sigue igual.
4. Guardar la nota y abrir su PDF ("Ver Detalle").
5. **Verificar**: la columna "%Bonif." de cada línea del PDF muestra el mismo valor que el campo
   "Desc." del paso 3 (0% o 5%) — **NO** el combinado con el 10% general.
6. **Verificar**: el bloque de totales del PDF incluye una fila **"Descuento General"** con un
   importe mayor a $0 — dato que hoy no aparece en ningún lado del documento.
7. Crear una segunda nota sobre un comprobante sin Descuento General. **Verificar**: la fila
   "Descuento General" del PDF muestra `$0,00` sin romper el layout ni mostrar "NaN"/vacío.

**Éxito**: NC/ND es la única de las 4 pantallas donde "Bonif." de línea no cambia respecto de hoy;
lo único nuevo es la fila de totales del PDF.

## Escenario 4 — Casos límite (Edge Cases de la spec)

1. Comprobante con un solo ítem y Descuento General 100%: **verificar** que el Subtotal de esa
   fila llega a `$0,00` exactos, sin quedar en negativo por redondeo.
2. Ítem de descripción libre (sin producto de catálogo asociado, cargado a mano): **verificar**
   que "Bonif." en el PDF se calcula igual que un ítem de catálogo (no depende de `producto_id`).
3. Comprobante con 5+ ítems y Descuento General no redondo (por ejemplo 7%): **verificar** que la
   suma de los Subtotales de fila coincide, con un margen de 1 centavo, con el "Subtotal con
   Descuento" de pie de página (research.md Decisión 4 — tolerancia esperada, no bug).

## Qué NO hace falta volver a probar

- El monto final (`total`/`monto`) de cualquiera de los 4 comprobantes: esta feature no lo toca,
  ya estaba bien calculado antes (SC-003). No hace falta re-auditar cifras ya verificadas en
  producción.
- Comprobantes ya emitidos (históricos): no requieren ninguna acción — esta feature es sólo de
  presentación hacia adelante (FR-007/SC-004).
