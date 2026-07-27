# Quickstart / Validación — Módulo Egresos

Guía para validar la feature de punta a punta. Sin código de implementación.

## Prerrequisitos

- **Spec 007 (Tesorería) implementada y migrada** — dependencia dura (medios de pago + servicio
  `Tesoreria::registrarMovimiento`). Verificar que `/tesoreria` muestra cuentas.
- **Spec 008 (Ingresos) implementada** — se reutiliza `Services/Ingresos/CalculoComprobante`, la tabla
  `retenciones` y el controlador genérico de NC/ND.
- Specs 001-003/006 (Proveedores, Productos, Categorías) operativas.
- App Laravel corriendo, MySQL `contagram`, assets compilados.
- Migraciones + seeders:
  ```
  php artisan migrate
  php artisan db:seed --class=CategoriasGastoSeeder
  ```
- Usuario con permisos `compras.*` / `gastos.*`.

## Escenario 1 — Compra + Pago (US1) — el corazón

1. **Egresos → Compras** → "Nueva Compra" (página completa).
2. Elegir un proveedor con Categoría de Compras guardada → **se autocompleta**.
3. Agregar 1 producto → la columna IVA queda en "Elegir"; el panel muestra "Importe Neto No Gravado".
4. Elegir IVA 21% en el ítem → el panel pasa a "Importe Neto Gravado" y recalcula el total.
5. Completar el campo **Contador** (mes de imputación IVA Compras) con un mes distinto al de Emisión.
   Guardar.
6. **Esperado**: KPIs y listado reflejan la compra en estado "A Pagar" (derivado); el cálculo de totales
   es correcto (SC-001).
7. Abrir el Detalle → "+ Agregar Pago" → Monto precargado con el saldo pendiente, elegir "Caja del
   Local", Crear.
8. **Esperado**:
   - La compra pasa a **Pagado** (derivado de `pagado ≥ a_pagar`, no un campo forzado — Clarifications).
   - En `/tesoreria`, el saldo de Caja del Local **bajó exactamente** el monto pagado (SC-002); la ficha
     de la cuenta muestra un movimiento `Pago` con el proveedor en Detalles y el comprobante generado.
   - El documento del detalle lleva el watermark **"NO VÁLIDO COMO FACTURA"**.
9. **Pago parcial**: en otra compra, pagar la mitad → A Pagar refleja el resto (SC-003). Agregar un
   segundo pago que salde → Pagado.
10. **Soft delete**: eliminar una compra pagada → desaparece del listado y el saldo de Tesorería vuelve a
    su valor previo (0 saldo fantasma — SC-004).
11. Probar doble clic en Guardar → **una sola** compra creada (SC-007).

## Escenario 2 — Retención sobre una Compra (US2)

1. Sobre una compra ya guardada, "+ Agregar Retención".
2. Elegir Tipo **IVA**, Monto, N° de comprobante, Descripción. Crear.
3. **Esperado**: la retención queda listada en el detalle de la compra, vinculada vía `pago_id` (o sin
   pago si se registra antes de pagar).

## Escenario 3 — Gasto (US3)

1. **Egresos → Gastos** → "Nuevo Gasto" (modal, no página).
2. Monto $5.000, Categoría "Marketing" → "Crear Subcategoría" → "Facebook Ads", Medio de Pago "Banco
   Galicia", Descripción. Crear.
3. **Esperado**: el modal se cierra, el listado se actualiza in place (sin recarga); el saldo de Banco
   Galicia **bajó** $5.000 (SC-002 aplicado a Gastos también); no existe ficha de detalle — clic en el Id
   reabre el mismo modal en modo edición.
4. Crear otro gasto con **"Marcar como pendiente"** tildado → NO impacta ningún saldo (SC-005). Editarlo
   quitando pendiente y asignando cuenta → recién ahí genera el movimiento.
5. Verificar que el árbol de Categoría de Gasto (Marketing → Facebook Ads) es independiente del árbol de
   Categoría de Compras usado por Proveedores, aunque ambos vivan en la tabla `categorias`.

## Escenario 4 — Nota de Crédito/Débito sobre una Compra (US4)

1. Sobre una compra, menú de fila → **Crear NC/ND**.
2. Tipo **Crédito**, Documento que Ajusta = la compra, Fecha, Monto, Descripción. Guardar.
3. **Esperado**: la barra de ecuación de la compra refleja la NC (A Pagar disminuye por la NC). Una ND
   haría lo inverso (aumenta lo adeudado).

## Verificación automatizada (tests)

```
php artisan test --filter="Compra|Gasto|NotaCreditoDebitoCompra"
```

Cubre (Principio IV):
- `CompraCalculoTest`: totales (subtotal/IVA opcional/descuento/percepciones) correctos, incluyendo el
  caso `iva_pct=null` ("Importe Neto No Gravado"); idempotencia del guardado.
- `CompraPagoTest`: pago impacta el saldo de Tesorería exactamente (SC-002); A Pagar = Total + ND − NC −
  Pagado con pagos parciales y notas (SC-003); soft delete revierte el movimiento (SC-004).
- `GastoTest`: pendiente no impacta saldo (SC-005); conciliar genera el movimiento; eliminar revierte sin
  Observer.
- `NotaCreditoDebitoCompraTest`: NC/ND sobre compra afecta la barra de ecuación correctamente.

## Checklist de cierre (docs — Principio I)

- [ ] `docs/modelo_datos.md §7`: tablas de Egresos marcadas como implementadas; §5 (`retenciones`)
      actualizada para reflejar que ya tiene flujo real.
- [ ] `docs/documentacion_principal_crm.md §4`: "documentado, pendiente de implementar" → implementado;
      §4.3 actualizada (Tesorería ya provee medios de pago).
- [ ] `CREDENCIALES_ACCESO.txt` actualizado si se tocó algún acceso para pruebas manuales.
