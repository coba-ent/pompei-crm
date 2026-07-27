# Quickstart / Validación — Módulo Ingresos

Guía para validar la feature de punta a punta. Sin código de implementación.

## Prerrequisitos

- **Spec 007 (Tesorería) implementada y migrada** — dependencia dura (medios de cobro + servicio
  `Tesoreria::registrarMovimiento`). Verificar que `/tesoreria` muestra cuentas.
- Specs 001-006 (Clientes, Productos, Categorías, Listas, Stock) operativas.
- App Laravel corriendo, MySQL `contagram`, assets compilados.
- Migraciones + seeders:
  ```
  php artisan migrate
  php artisan db:seed --class=CategoriasIngresoSeeder
  ```
- Usuario con permisos `presupuestos.*` / `ventas.*` / `ingresos.*`.

## Escenario 1 — Presupuesto (US1)

1. **Ingresos → Presupuestos** → "Nuevo Presupuesto" (página completa).
2. Elegir un cliente que tenga Categoría de Ventas y Descuento por defecto → **se autocompletan**.
3. Agregar 1 producto (ej. Camisa, cant. 1) → la fila calcula subtotal/IVA/total y el pie el total.
4. Agregar una Percepción → aparece fila selector+monto. Guardar.
5. **Esperado**: KPIs y listado reflejan el presupuesto en estado Pendiente; "Ver" abre el documento
   imprimible; el cálculo de totales es correcto (SC-001).
6. Probar doble clic en Guardar → **un solo** presupuesto creado (SC-007).

## Escenario 2 — Venta + Cobranza (US2) — el corazón

1. Desde el presupuesto, menú de fila → **Crear Venta** → formulario pre-cargado; el presupuesto queda
   convertido (no reconvertible).
2. Elegir Tipo de Comprobante **B**; guardar → se asigna N° (ej. `0001-00000001`).
3. Presionar **Cobrar** → modal "Cobranza" con Total/A Cobrar y la grilla de **cuentas de Tesorería**.
4. Anotar el saldo actual de "Caja del Local" (en `/tesoreria`). Cobrar el total contra Caja del Local.
5. **Esperado**:
   - La venta queda **Cobrada**; el Detalle muestra la barra de ecuación y la cobranza listada.
   - En `/tesoreria`, el saldo de Caja del Local **subió exactamente** el monto cobrado (SC-002); la
     ficha de la cuenta muestra un movimiento `Cobro` con el cliente en Detalles y el N° de comprobante.
   - El documento del detalle lleva el watermark **"NO VÁLIDO COMO FACTURA"**.
6. **Cobro parcial**: en otra venta, cobrar la mitad → estado **Parcial**, A Cobrar = resto (SC-003).
   Agregar una segunda cobranza que salde → Cobrada.
7. **Soft delete**: eliminar una venta cobrada → la venta desaparece del listado y el saldo de Tesorería
   vuelve a su valor previo (0 saldo fantasma — SC-005). La venta sigue en la base (soft-deleted).

## Escenario 3 — Otros Ingresos (US3)

1. **Ingresos → Otros Ingresos** → "Nuevo Ingreso".
2. Monto $500, Categoría "Otros Ingresos" (o crear una nueva inline), Medio de Cobro "Caja General",
   Descripción. Crear.
3. **Esperado**: aparece en el listado (7 columnas); el saldo de Caja General subió $500 (movimiento de
   tesorería con origen = este ingreso).
4. Crear otro con **"Marcar como pendiente"** tildado → NO impacta ningún saldo (SC-006). Editarlo
   quitando pendiente y asignando cuenta → recién ahí genera el movimiento.

## Escenario 4 — Nota de Crédito/Débito (US4)

1. Sobre una venta, menú de fila → **Crear NC/ND**.
2. Paso 1: Tipo **Crédito**, ¿Afecta Stock? **Sí** → traer un producto de la venta. Paso 2: Fecha,
   Monto, Descripción. Guardar.
3. **Esperado**: el stock del producto se repone (movimiento de stock); la barra de ecuación de la venta
   refleja la NC (A Cobrar disminuye por la NC). Una ND haría lo inverso (aumenta lo adeudado).

## Verificación automatizada (tests)

```
php artisan test --filter="Presupuesto|Venta|OtroIngreso|NotaCredito"
```

Cubre (Principio IV):
- `PresupuestoCalculoTest`: totales (subtotal/IVA/descuento/percepciones) correctos; idempotencia del
  guardado.
- `VentaCobranzaTest`: cobro impacta el saldo de Tesorería exactamente (SC-002); A Cobrar = Total + ND −
  NC − Cobrado con cobros parciales y notas (SC-003); soft delete revierte el movimiento (SC-005).
- `OtroIngresoTest`: pendiente no impacta saldo (SC-006); conciliar genera el movimiento.
- `NotaCreditoDebitoTest`: NC que afecta stock genera el movimiento de stock correcto.

## Checklist de cierre (docs — Principio I)

- [ ] `docs/modelo_datos.md §5`: tablas de Ingresos marcadas como implementadas.
- [ ] `docs/documentacion_principal_crm.md §3`: "documentado, pendiente de implementar" → implementado;
      §3.5 actualizada (Tesorería ya provee medios de cobro).
- [ ] `CREDENCIALES_ACCESO.txt` actualizado si se tocó algún acceso para pruebas manuales.
