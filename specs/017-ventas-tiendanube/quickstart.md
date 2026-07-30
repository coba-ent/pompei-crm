# Quickstart — validación de Ventas de Tiendanube (spec 017)

**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Datos**: [data-model.md](./data-model.md)

Guía de validación end-to-end. No contiene código de implementación.

---

## Prerrequisitos

1. **Tienda conectada** vía OAuth (`specs/019-tiendanube-conexion-mcp/`, ya validado y en producción),
   función avanzada "Tiendanube" activa, modo sólo lectura desactivado.
2. **Al menos una cuenta de Tesorería activa** para imputar cobranzas (FR-045a).
3. **Al menos un depósito activo**.

```bash
php artisan migrate
npm run build
```

---

## Escenario 1 — Ver órdenes y excluir el canal Mercado Libre (US1)

1. Ir a Ingresos → Tiendanube y presionar "Sincronizar ahora".
2. Verificar que aparecen las órdenes reales de la tienda con estado, fecha, comprador y monto.
3. Si la tienda tiene el canal Mercado Libre conectado dentro de Tiendanube: confirmar que **ninguna**
   orden con ese origen aparece en el listado (FR-012a, SC-010).

**Esperado**: listado poblado sin recarga de página; cero órdenes `storefront=meli`.

---

## Escenario 2 — Vincular una variante y convertir manualmente (US2 + US3)

1. Abrir una orden en estado "Lista para convertir" con una variante sin vincular → "Crear Venta".
2. Elegir el producto del CRM para esa variante en el selector inline y guardar la Venta.
3. Abrir la Venta creada: confirmar cliente, líneas, total (igual al `total` de la orden en Tiendanube),
   cobrada contra la cuenta de Tesorería configurada, y stock descontado en el depósito configurado.
4. Convertir una **segunda** orden con la **misma** variante: confirmar que ya no pide vincular producto
   (SC-006).

**Esperado**: Venta con total exacto (SC-003), cobrada (SC-009), stock bajado (SC-008).

---

## Escenario 3 — Tipo de comprobante derivado del documento

**Corrección post-019**: no existe `billing_document_type` en la tool real (`list_orders`) — el dato
disponible es `customer.cpf_cnpj`, verificado vacío en las 9 órdenes reales de la tienda. Este escenario
requiere una orden de prueba con `cpf_cnpj` cargado (poco frecuente en la práctica); si no hay ninguna
disponible, el caso realista a validar es el punto 2 (sin dato → B), no el 1.

1. Convertir una orden de un comprador **nuevo** (sin Cliente previo en el CRM) cuyo `cpf_cnpj` tenga 11
   dígitos (formato CUIT) → Venta con comprobante A (aproximación de FR-040 corregida).
2. Convertir una orden de un comprador nuevo con `cpf_cnpj` de 7-8 dígitos o vacío (caso dominante en la
   cuenta real) → Venta con comprobante B.
3. Convertir una orden cuyo comprador **ya es Cliente** en el CRM con condición de IVA "Monotributista"
   cargada de antes, aunque su `cpf_cnpj` tenga 11 dígitos → Venta con comprobante **B** (usa la
   condición ya cargada, FR-039, no la aproximación).
4. Corregir manualmente el comprobante de una de las Ventas creadas (FR-043) y confirmar que se guarda.

**Esperado**: derivación según FR-039 (condición ya cargada primero) y la tabla de FR-040 (aproximación
sólo para Clientes nuevos/sin condición); corrección manual disponible en todos los casos.

---

## Escenario 4 — Creación automática (US5)

1. Activar "Creación automática de ventas" en la configuración de Tiendanube.
2. Sincronizar con una orden pagada y con su variante vinculada.
3. Confirmar que la Venta se crea sola, marcada como automática.
4. Repetir con una orden pagada y variante **sin vincular**: confirmar que queda "Requiere atención" sin
   crear Venta ni mover stock.

**Esperado**: SC-005 — 100% de las resolubles convertidas, 100% de las no resolubles señaladas.

---

## Escenario 5 — Cancelación posterior (US6)

1. Convertir una orden en Venta.
2. Simular que esa orden pasa a `status=cancelled` (o `payment_status=refunded`) en Tiendanube y
   sincronizar de nuevo.
3. Confirmar que el listado lo refleja de forma destacada y que la Venta del CRM **no** cambió.

---

## Regresión mínima

- Confirmar que la conversión de una orden de **Mercado Libre** (spec 012) sigue funcionando idéntica —
  `StockDeVenta::resolverDeposito()` ahora tiene una rama más, no debe alterar la existente.
- Correr la suite de tests de `tests/Feature/Integraciones/` de las specs 011-013 y 015, confirmar que
  siguen en verde.
