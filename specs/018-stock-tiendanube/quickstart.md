# Quickstart — validación de sincronización de stock y precios hacia Tiendanube (spec 018)

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)

Guía de validación end-to-end. No contiene código de implementación: eso vive en `tasks.md` y en la fase
de implementación.

> **Estrategia de prueba sin riesgo (ampliación 30/07/2026)**: toda esta guía —stock y precio— se valida
> con un **producto de prueba oculto** creado en la tienda real de Tiendanube (visible sólo internamente,
> no en el storefront público) y vinculado él solo a una variante en el CRM. Así se ejercitan escrituras
> reales contra `admin-mcp.tiendanube.com` sin arriesgar catálogo, stock ni precios de productos reales —
> mismo criterio que ya se usó para validar la conversión de órdenes (spec 017). El modo sólo lectura
> (kill-switch) sigue disponible como red de seguridad adicional mientras no se quiera ejercitar ninguna
> escritura real, incluso contra el producto de prueba.

**Prerrequisito de secuencia**: esta guía asume que `specs/017-ventas-tiendanube/` (listado de órdenes,
vinculación de variantes, conversión a Venta) ya está implementada en código, no sólo especificada — ver
plan.md, "Advertencia de secuencia de implementación".

---

## Prerrequisitos

1. **Todo lo de `specs/017-ventas-tiendanube/quickstart.md`** ya validado: tienda conectada, función
   activa, modo sólo lectura desactivado.
2. **Al menos un vínculo variante↔producto** existente, con `tn_product_id` completo (spec 017/018,
   pantalla de Vinculación de variantes).
3. **Depósito configurado para Tiendanube** (`tn_configuracion.deposito_id`) definido, o aceptar el
   depósito por defecto del CRM.

```bash
php artisan migrate
npm run build
```

---

## Escenario 1 — Una Venta manual actualiza Tiendanube (US1)

1. Cargar una Venta manual (no de Tiendanube) sobre un producto vinculado, con cantidad conocida.
2. Ir a Ingresos → Tiendanube → Vinculación de variantes y verificar que el vínculo pasó a **pendiente**.
3. Ejecutar:
   ```bash
   php artisan tiendanube:sincronizar-stock --forzar
   ```
4. Comparar la cantidad disponible de la variante en Tiendanube contra el stock del producto en el CRM
   (Informes → Stock, mismo depósito).

**Esperado**: coinciden exactamente; el vínculo vuelve a **sincronizado**, con fecha de último envío
(FR-017, SC-001).

**Verificar consolidación** (SC-003): cargar dos o tres Ventas seguidas sobre el mismo producto antes de
sincronizar. Revisar en Configuración & Ajustes → Tiendanube → historial de operaciones que hubo **una
sola** entrada para ese producto dentro del lote enviado a `update_stock_and_price`, no una por Venta
(corrección post-019: no es un endpoint REST por producto, es una tool en lote).

**Verificar el tope en cero** (SC-004): forzar (vía una orden de Tiendanube que venda de más, spec 017
FR-046d) que el stock local quede negativo, sincronizar, y confirmar que Tiendanube recibió **0**, nunca
un número negativo.

---

## Escenario 2 — Una orden de Tiendanube no rebota (US2)

1. Convertir una orden de Tiendanube en Venta (spec 017).
2. Revisar Ingresos → Tiendanube → Vinculación de variantes: el vínculo de esa variante **no** debe pasar
   a pendiente por ese movimiento (aunque sí puede estarlo por otro motivo).
3. Revisar el historial de operaciones: no debe haber ningún envío de stock asociado a ese movimiento
   puntual.

**Esperado**: SC-002 se cumple — cero envíos redundantes hacia Tiendanube por algo que Tiendanube ya
sabe.

**Verificar el orden de ejecución** (research R4): con una orden nueva pendiente de traer y, a la vez,
una Venta manual pendiente de empujar, ejecutar ambos comandos en el orden programado:

```bash
php artisan tiendanube:sincronizar-ordenes --forzar
php artisan tiendanube:sincronizar-stock --forzar
```

El segundo debe reflejar el stock **ya neto** de lo que trajo el primero.

---

## Escenario 3 — Sincronización manual bajo demanda (US3)

1. Con vínculos pendientes, Ingresos → Tiendanube → presionar **Sincronizar stock ahora**.

**Esperado**: toast con la cantidad de variantes actualizadas, sin recargar la página; los vínculos pasan
a sincronizados de inmediato, sin esperar la corrida programada.

**Casos negativos**:

| Acción | Resultado esperado |
|---|---|
| Activar modo sólo lectura y presionar el botón | Bloqueada, motivo visible (FR-009) |
| Desactivar la función "Tiendanube" | La acción no está disponible / bloqueada (FR-009) |
| Disparar dos sincronizaciones de stock a la vez | Sólo una se ejecuta, la otra se descarta (FR-008) |

---

## Escenario 4 — Rechazo de una variante puntual (US4)

1. Eliminar o despublicar en Tiendanube el producto de una variante vinculada.
2. Generar un movimiento de stock sobre ese producto y sobre **otro** producto vinculado y activo.
3. Sincronizar.

**Esperado**:

- El vínculo de la variante eliminada/despublicada queda con **estado error**, motivo visible (mensaje
  que informe la tool `update_stock_and_price` para ese ítem — formato exacto a confirmar, ver T032a),
  y sigue **pendiente** (FR-014) — no se pierde el cambio.
- El vínculo del otro producto se sincroniza con normalidad en la misma corrida (FR-015, SC-006).
- Un rechazo por límite de tasa o falla temporal se reintenta con espera creciente antes de marcarse como
  error (FR-013) — verificable revisando la duración registrada en el historial de operaciones.

**Reintento posterior**: republicar/recrear la variante, generar un nuevo movimiento sobre ese producto, y
sincronizar de nuevo — debe volver a intentarse y, si Tiendanube lo acepta, pasar a sincronizado.

---

## Escenario 5 — Regresión: comportamiento existente intacto

| Caso | Esperado |
|---|---|
| Producto **sin** vínculo con Tiendanube, Venta manual | Ningún envío, ningún vínculo afectado (FR-005) |
| Movimiento en un depósito **distinto** al configurado para Tiendanube | No marca ningún vínculo como pendiente |
| Eliminar un vínculo con un cambio pendiente | El pendiente desaparece con el vínculo, sin error residual |
| Órdenes de Tiendanube (spec 017) | Siguen funcionando exactamente igual |
| Sincronización de stock de Mercado Libre (spec 013) | Sigue funcionando exactamente igual — candados y Observer independientes |

---

## Escenario 6 — Configurar la Lista de Precios y que un cambio se refleje solo (US5/US6, ampliación)

1. Configuración de Tiendanube → elegir una Lista de Precios activa → guardar.
2. Con el producto de prueba oculto vinculado a una variante, cambiar su precio **dentro** de esa lista
   (modal de edición de Producto).

**Esperado**: la variante en Tiendanube queda con el nuevo precio de inmediato, sin esperar ninguna
corrida programada (SC-009) — a diferencia del stock, **no** hace falta ejecutar ningún comando.

**Verificar no-disparo** (SC-010): cambiar el precio del mismo producto en una Lista de Precios
**distinta** a la configurada, o el precio de un producto **sin** vínculo — confirmar que no se registra
ningún envío en el historial de operaciones.

**Verificar importación masiva** (FR-025): repetir el cambio de precio vía la importación de precios
(Excel/CSV) sobre el producto de prueba — mismo resultado que la edición manual.

---

## Escenario 7 — Sincronizar precios manualmente (US7, ampliación)

1. Provocar una falla de envío (por ejemplo, despublicar el producto de prueba en Tiendanube) y cambiar su
   precio en la lista configurada.
2. Ir a Productos → presionar **Sincronizar precios ahora**.

**Esperado**: un único toast resume el resultado de **ambas** integraciones (Mercado Libre y Tiendanube,
si las dos tienen vínculos pendientes) — el botón sigue siendo uno solo (research.md R10), aunque dispara
dos requests independientes (`productos.sincronizarPreciosMl` y `productos.sincronizarPreciosTn`).

**Casos negativos** (mismo patrón que el Escenario 3 de stock): modo sólo lectura activo o función
desactivada → bloqueada, motivo visible (FR-032/FR-037); dos sincronizaciones de precio simultáneas →
sólo una se ejecuta (FR-036).

---

## Escenario 8 — Rechazo de una variante puntual al sincronizar precio (US8, ampliación)

1. Con el producto de prueba despublicado en Tiendanube, cambiar su precio en la lista configurada.
2. Cambiar también el precio de **otro** producto vinculado y activo.
3. Sincronizar (evento o manual).

**Esperado**: el vínculo del producto despublicado queda con estado **error**, motivo visible, y
**pendiente** (FR-031); el otro producto se sincroniza con normalidad en el mismo intento (SC-012).

---

## Escenario 9 — Cambiar la Lista de Precios configurada actualiza todo de una vez (US9, ampliación)

1. Con productos vinculados con precio cargado en dos Listas de Precios distintas, cambiar cuál es la
   Lista de Precios configurada para Tiendanube (Configuración → Tiendanube) y guardar.

**Esperado**: todas las variantes vinculadas con precio cargado en la nueva lista reciben de inmediato el
precio vigente, sin tocar producto por producto (SC-013).

---

## Suite automatizada

```bash
php artisan test --filter=Tiendanube
```

Cobertura obligatoria por el principio IV de la constitución (impacto de stock y de precio):

- `MovimientoStockObserver` (rama Tiendanube): marca pendiente en los casos elegibles, no marca en la
  exclusión de bucle (FR-002) ni sin vínculo (FR-005) ni en otro depósito (FR-001); no interfiere con la
  rama Mercado Libre ya existente.
- `SincronizadorStock`: consolidación a un único envío por producto (FR-003), tope en cero (FR-004), no
  concurrencia (FR-008), continuidad tras un rechazo individual (FR-015).
- Cortes de FR-009/FR-010 (función desactivada, sólo lectura, conexión caída) sin generar ningún envío.
- `PrecioProductoObserver` (rama Tiendanube, ampliación): dispara sin importar el camino de escritura
  (FR-024/FR-025), no dispara fuera de alcance (FR-026), dispara al vincular (FR-027).
- `SincronizadorPrecios` (ampliación): reintento y registro de error (FR-030/FR-031), no concurrencia
  (FR-036), push inmediato al cambiar de lista (FR-028), exclusiones sobre el cálculo de precio de Venta
  (FR-039/FR-040) — regresión crítica sobre la spec 017.
