# Quickstart — validar la spec 063

Cómo comprobar que la feature funciona, de punta a punta. Los escenarios están ordenados: cada uno
se puede correr solo.

## Prerequisitos

- Migración aplicada (3 columnas nuevas en `ml_publicacion_producto`).
- Integración de Mercado Libre configurada, con al menos una publicación vinculada.
- En tests, la API se simula con `Http::fake()`; no se consulta Mercado Libre real.

## Escenario 1 — Una cancelación posterior queda marcada (US1)

```bash
php artisan test tests/Feature/Integraciones/CancelacionPosteriorTest.php
```

**Qué se prueba**: una orden pagada se convierte en Venta; después la orden pasa a `cancelled` y
corre la sincronización.

**Resultado esperado**:

- La orden queda en `requiere_atencion` con motivo `orden_cancelada`.
- **La Venta, su cobro, el movimiento de Tesorería y el stock no cambian.**
- Repetir la sincronización no duplica el aviso ni mueve la fecha de detección.
- Una orden cancelada sin Venta asociada no genera aviso.

## Escenario 2 — El aviso conduce a la venta y se cierra solo (US2)

**Qué se prueba**: que desde el aviso se llega a la Venta, y que el aviso se cierra cuando la Venta
se resuelve **por cualquiera de las vías que ya existen**.

**Resultado esperado**:

- Desde el aviso se llega a la Venta con un clic, con el motivo a la vista.
- Si se le emite una **nota de crédito** con el circuito existente → el aviso se cierra.
- Si se la **elimina** con la función existente → el aviso también se cierra.
- Si se **descarta** el aviso → la Venta queda intacta y el aviso desaparece, con registro de quién
  y cuándo.

**Lo que NO hay que probar acá**: que la nota de crédito repone stock, o que eliminar revierte el
cobro. Eso ya tiene sus propios tests y esta feature no lo modifica.

```sql
-- El aviso se cerró tras resolver la venta por cualquier vía
SELECT estado_conversion, motivo FROM ml_ordenes WHERE venta_id = <venta>;
```

## Escenario 3 — Reembolso parcial y mediación no se confunden (US3)

**Resultado esperado**:

- Una orden `partially_refunded` queda con motivo **reembolso parcial**, no cancelación.
- Una orden con pago `in_mediation` queda con motivo **en mediación**.
- Si la mediación se resuelve como cancelación, el motivo cambia y **se conserva la fecha de
  detección original**.
- Si se resuelve a favor del negocio y la orden vuelve a pagada, el aviso **se cierra solo**.

## Escenario 4 — Los errores dejan de reintentarse (US4)

```bash
php artisan test tests/Feature/Integraciones/ErroresSincronizacionStockTest.php
```

**Resultado esperado**:

- Tras 5 fallas consecutivas con el mismo error, la publicación queda como "requiere intervención".
- Una publicación marcada **no vuelve a incluirse** en las pendientes: no consume llamadas.
- Un error distinto reinicia el contador.
- Una sincronización exitosa limpia contador, fecha y marca.
- Reactivarla manualmente la devuelve al ciclo normal.

## Verificación contra producción (después de desplegar)

El número que valida SC-004, comparable con la medición previa (~305 fallidas cada 6 h):

```sql
SELECT endpoint, COUNT(*) AS fallidas
FROM ml_operaciones_log
WHERE operacion = 'sincronizar_stock' AND resultado = 'error'
  AND created_at > NOW() - INTERVAL 6 HOUR
GROUP BY endpoint;
```

Y el estado de las publicaciones bloqueadas:

```sql
SELECT ml_item_id, stock_error, stock_intentos_fallidos, stock_error_desde
FROM ml_publicacion_producto
WHERE stock_requiere_intervencion = 1;
```

**Esperado tras el despliegue**: las 5 publicaciones que hoy fallan quedan bloqueadas en los
primeros minutos y las llamadas fallidas caen a menos de 10 cada 6 horas.

## Qué NO valida este quickstart

- Las 4 ventas ya afectadas ($560.051,43): se corrigen a mano, aparte.
- Tiendanube.
- La emisión de la nota de crédito ante ARCA.
