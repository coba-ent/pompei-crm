# Quickstart — validación de gestión de precios de Mercado Libre (spec 016)

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)

Guía de validación end-to-end. No contiene código de implementación: eso vive en `tasks.md` y en la fase
de implementación.

---

## Prerrequisitos

1. **Todo lo de `specs/012-ventas-mercadolibre/quickstart.md` y `specs/013-stock-mercadolibre/quickstart.md`**
   ya validado: cuenta conectada, función activa, modo sólo lectura desactivado.
2. **Al menos un vínculo publicación↔producto** existente (spec 012, pantalla de Vinculaciones).
3. **Dos Listas de Precios activas** en el CRM (para poder probar tanto la lista configurada como una
   distinta, y el escenario de cambio de lista — US5).
4. **Lista de Precios configurada** en Configuración → Integraciones → Mercado Libre (US1).

```bash
php artisan migrate
npm run build
```

---

## Escenario 1 — Configurar la Lista de Precios (US1)

1. Ir a Configuración → Integraciones → Mercado Libre.
2. Seleccionar una Lista de Precios activa en el nuevo campo (junto a Depósito y Categoría de Venta).
3. Guardar.

**Esperado**: toast de confirmación sin recarga de página; al recargar la pantalla manualmente, la
selección persiste (SC-001). Guardar sin ninguna lista seleccionada también debe funcionar sin error.

---

## Escenario 2 — Un cambio de precio en la lista configurada actualiza Mercado Libre (US2)

1. Con la Lista de Precios configurada (Escenario 1) y un producto **vinculado** a una publicación
   (spec 012), abrir el modal de edición de ese Producto.
2. Cambiar el precio del producto dentro de la Lista de Precios configurada y guardar.
3. Revisar Ingresos → Mercado Libre → Vinculaciones: el vínculo debe pasar brevemente por **pendiente** y
   terminar en **sincronizado**, sin ninguna acción manual adicional.
4. Verificar en Mercado Libre (o en el historial de operaciones, Configuración → Mercado Libre) que el
   precio de la publicación coincide con el nuevo precio (SC-002).

**Casos negativos** (SC-003):

| Acción | Resultado esperado |
|---|---|
| Cambiar el precio de un producto **sin** vínculo con Mercado Libre | Ningún envío, ningún vínculo afectado |
| Cambiar el precio del mismo producto en una Lista de Precios **distinta** a la configurada | Ningún envío |
| Sin ninguna Lista de Precios configurada, cambiar cualquier precio | Ningún envío (comportamiento igual al actual) |

**Verificar el camino de importación** (FR-005): importar un Excel/CSV de precios que incluya la columna
`precio_lista_<id>` de la lista configurada para un producto vinculado, y confirmar que dispara el mismo
envío que el paso 2, sin acción manual adicional.

**Verificar que no se toca el cálculo de Ventas de Mercado Libre** (FR-019/FR-020, SC-004): convertir una
orden de Mercado Libre en Venta y confirmar que los precios de línea siguen saliendo del importe pagado en
la orden, y que la Venta queda sin Lista de Precios asignada — sin relación con lo configurado en esta
spec.

---

## Escenario 3 — Sincronización manual bajo demanda (US3)

1. Provocar una falla puntual (por ejemplo, pausar en Mercado Libre la publicación de un producto
   vinculado y luego cambiar su precio en la lista configurada) o vincular un producto **después** de que
   su precio ya hubiera cambiado.
2. Ir a Ingresos → Mercado Libre → presionar **Sincronizar precios ahora**.

**Esperado**: toast con la cantidad de productos actualizados y con error, sin recargar la página; los
vínculos pendientes/con error se reintentan de inmediato (SC-006).

**Casos negativos**:

| Acción | Resultado esperado |
|---|---|
| Activar modo sólo lectura y presionar el botón | No disponible / bloqueada, motivo visible (FR-016) |
| Desactivar la función "Mercado Libre" | La acción no está disponible (FR-016) |
| Disparar dos sincronizaciones de precios a la vez | Sólo una se ejecuta, la otra se descarta (FR-015) |

---

## Escenario 4 — Rechazo de una publicación puntual (US4)

1. Pausar (o cerrar) en Mercado Libre la publicación de un producto vinculado.
2. Cambiar el precio de ese producto y de **otro** producto vinculado y activo, ambos en la lista
   configurada.

**Esperado**:

- El vínculo de la publicación pausada queda con **estado error**, motivo visible, y sigue **pendiente**
  (FR-010) — no se pierde el cambio.
- El vínculo del otro producto se sincroniza con normalidad (FR-013... no bloquea al resto, SC-005).
- Un rechazo por límite de solicitudes (429) o falla temporal se reintenta con espera creciente antes de
  marcarse como error (FR-009), reutilizando el mecanismo ya existente de `ClienteMercadoLibre`.

**Reintento posterior**: reactivar la publicación y presionar "Sincronizar precios ahora" (Escenario 3) —
debe volver a intentarse y, si Mercado Libre lo acepta, pasar a sincronizado.

---

## Escenario 5 — Cambiar la Lista de Precios configurada empuja todo de inmediato (US5)

1. Con al menos dos productos vinculados, cada uno con precio cargado tanto en la Lista de Precios A
   (configurada actualmente) como en la Lista de Precios B (distinta).
2. En Configuración → Integraciones → Mercado Libre, cambiar la Lista de Precios configurada de A a B y
   guardar.

**Esperado**: sin tocar cada producto individualmente, ambos vínculos reciben de inmediato el precio
vigente en la Lista B (SC-007).

**Caso de producto sin precio en la nueva lista**: un producto vinculado que no tiene precio cargado en la
Lista B no debe romper el guardado de la configuración ni afectar al resto — simplemente no se sincroniza
para ese vínculo.

**Caso bloqueado**: repetir el cambio de lista con el modo sólo lectura activo — la configuración se
guarda igual, pero el push inmediato no se ejecuta; los vínculos con precio en la nueva lista quedan
`precio_pendiente = true` para el próximo intento válido (verificable luego con "Sincronizar precios
ahora", Escenario 3).

---

## Suite automatizada

```bash
php artisan test --filter=MercadoLibre
```

Cobertura obligatoria por el principio IV de la constitución:

- `PrecioProductoObserver`: dispara el envío en los dos caminos de escritura (modal, importación) cuando
  corresponde (lista configurada + producto vinculado — FR-004/FR-005), y no dispara nada fuera de esas
  condiciones (FR-006).
- `SincronizadorPrecios`: no concurrencia de la acción manual (FR-015), continuidad tras un rechazo
  individual, registro de error sin descartar el pendiente (FR-009/FR-010).
- Cambio de Lista de Precios configurada dispara `sincronizarListaCompleta()` (FR-007) sólo cuando el
  valor efectivamente cambió y no es `null`.
- Cortes de FR-011/FR-012 (función desactivada, sólo lectura, conexión caída) sin generar ningún envío,
  conservando el pendiente.
- FR-019/FR-020: ningún test de `ConversorOrdenAVenta` cambia de resultado por esta spec — precios de
  línea y `lista_precio_id` de la Venta convertida siguen exactamente igual que antes.
