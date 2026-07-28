# Quickstart — validación de sincronización de stock hacia Mercado Libre (spec 013)

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)

Guía de validación end-to-end. No contiene código de implementación: eso vive en `tasks.md` y en la fase
de implementación.

---

## Prerrequisitos

1. **Todo lo de `specs/012-ventas-mercadolibre/quickstart.md`** ya validado: cuenta conectada, función
   activa, modo sólo lectura desactivado.
2. **Al menos un vínculo publicación↔producto** existente (spec 012, pantalla de Vinculaciones).
3. **Depósito configurado para Mercado Libre** (`ml_configuracion.deposito_id`) definido, o aceptar el
   depósito por defecto del CRM.

```bash
php artisan migrate
npm run build
```

---

## Escenario 1 — Una Venta manual actualiza Mercado Libre (US1)

1. Cargar una Venta manual (no de Mercado Libre) sobre un producto vinculado, con cantidad conocida.
2. Ir a Ingresos → Mercado Libre → Vinculaciones y verificar que el vínculo pasó a **pendiente**.
3. Ejecutar:
   ```bash
   php artisan mercadolibre:sincronizar-stock --forzar
   ```
4. Comparar la cantidad disponible de la publicación en Mercado Libre contra el stock del producto en el
   CRM (Informes → Stock, mismo depósito).

**Esperado**: coinciden exactamente; el vínculo vuelve a **sincronizado**, con fecha de último envío
(FR-017, SC-001).

**Verificar consolidación** (SC-003): cargar dos o tres Ventas seguidas sobre el mismo producto antes de
sincronizar. Revisar en Configuración & Ajustes → Mercado Libre → historial de operaciones que hubo **un
solo** `PUT /items/...` para ese producto, no uno por Venta.

**Verificar el tope en cero** (SC-004): forzar (vía una orden de Mercado Libre que venda de más, spec
012 FR-046d) que el stock local quede negativo, sincronizar, y confirmar que Mercado Libre recibió
**0**, nunca un número negativo.

---

## Escenario 2 — Una orden de Mercado Libre no rebota (US2)

1. Convertir una orden de Mercado Libre en Venta (spec 012, Escenario 3).
2. Revisar Ingresos → Mercado Libre → Vinculaciones: el vínculo de esa publicación **no** debe pasar a
   pendiente por ese movimiento (aunque sí puede estarlo por otro motivo).
3. Revisar el historial de operaciones: no debe haber ningún envío de stock asociado a ese movimiento
   puntual.

**Esperado**: SC-002 se cumple — cero envíos redundantes hacia Mercado Libre por algo que Mercado Libre
ya sabe.

**Verificar el orden de ejecución** (research R4): con una orden nueva pendiente de traer y, a la vez,
una Venta manual pendiente de empujar, ejecutar ambos comandos en el orden programado:

```bash
php artisan mercadolibre:sincronizar-ordenes --forzar
php artisan mercadolibre:sincronizar-stock --forzar
```

El segundo debe reflejar el stock **ya neto** de lo que trajo el primero.

---

## Escenario 3 — Sincronización manual bajo demanda (US3)

1. Con vínculos pendientes, Ingresos → Mercado Libre → presionar **Sincronizar stock ahora**.

**Esperado**: toast con la cantidad de productos actualizados, sin recargar la página; los vínculos
pasan a sincronizados de inmediato, sin esperar la corrida programada.

**Casos negativos**:

| Acción | Resultado esperado |
|---|---|
| Activar modo sólo lectura y presionar el botón | Bloqueada, motivo visible (FR-009) |
| Desactivar la función "Mercado Libre" | La acción no está disponible / bloqueada (FR-009) |
| Disparar dos sincronizaciones de stock a la vez | Sólo una se ejecuta, la otra se descarta (FR-008) |

---

## Escenario 4 — Rechazo de una publicación puntual (US4)

1. Pausar (o cerrar) en Mercado Libre la publicación de un producto vinculado.
2. Generar un movimiento de stock sobre ese producto y sobre **otro** producto vinculado y activo.
3. Sincronizar.

**Esperado**:

- El vínculo de la publicación pausada queda con **estado error**, motivo visible, y sigue
  **pendiente** (FR-014) — no se pierde el cambio.
- El vínculo del otro producto se sincroniza con normalidad en la misma corrida (FR-015, SC-006).
- Un rechazo por límite de solicitudes (429) o falla temporal se reintenta con espera creciente antes de
  marcarse como error (FR-013) — verificable revisando la duración registrada en el historial de
  operaciones.

**Reintento posterior**: reactivar la publicación, generar un nuevo movimiento sobre ese producto, y
sincronizar de nuevo — debe volver a intentarse y, si Mercado Libre lo acepta, pasar a sincronizado.

---

## Escenario 5 — Regresión: comportamiento existente intacto

| Caso | Esperado |
|---|---|
| Producto **sin** vínculo con Mercado Libre, Venta manual | Ningún envío, ningún vínculo afectado (FR-005) |
| Movimiento en un depósito **distinto** al configurado para ML | No marca ningún vínculo como pendiente |
| Eliminar un vínculo con un cambio pendiente | El pendiente desaparece con el vínculo, sin error residual |
| Órdenes de Mercado Libre (spec 012, Escenarios 1-6) | Siguen funcionando exactamente igual |

---

## Suite automatizada

```bash
php artisan test --filter=MercadoLibre
```

Cobertura obligatoria por el principio IV de la constitución (impacto de stock):

- `MovimientoStockObserver`: marca pendiente en los casos elegibles, no marca en la exclusión de bucle
  (FR-002) ni sin vínculo (FR-005) ni en otro depósito (FR-001).
- `SincronizadorStock`: consolidación a un único envío por producto (FR-003), tope en cero (FR-004), no
  concurrencia (FR-008), continuidad tras un rechazo individual (FR-015).
- Cortes de FR-009/FR-010 (función desactivada, sólo lectura, conexión caída) sin generar ningún envío.
