# Quickstart / Validación — Módulo Tesorería

Guía para validar la feature de punta a punta. No incluye código de implementación (eso va en
`tasks.md` / implementación).

## Prerrequisitos

- App Laravel corriendo (`php artisan serve`), MySQL `contagram` (XAMPP), assets compilados
  (`npm run dev` o `npm run build`).
- Migraciones y seeders aplicados:
  ```
  php artisan migrate
  php artisan db:seed --class=CuentasTesoreriaSeeder
  ```
- Usuario con permiso `tesoreria.*` (ver `CREDENCIALES_ACCESO.txt`).

## Escenario 1 — Ver Saldos (US1)

1. Entrar a **Tesorería** desde el sidebar → cae en la pestaña **Saldos** (`/tesoreria`).
2. Verificar los tres bloques: **A Cobrar** (verde), **A Pagar** (rojo), **Disponible** (celeste) con
   columnas **Cajas** y **Bancos**, cada bloque con su subtotal y el Disponible con Total general.
3. Cambiar "Buscar por Fecha" a una fecha pasada → los saldos se recalculan a ese corte.
4. **Esperado**: las cuentas del seed aparecen en su bloque correcto; Cajas = cuentas Efectivo, Bancos =
   cuentas Banco. Una cuenta con más egresos que ingresos puede verse negativa sin error.

## Escenario 2 — Administrar cuentas (US2)

1. Clic en el ícono de **ajustes (llave)** → tabla "Ajustes Cuentas Tesorería" agrupada por tipo.
2. **Nueva Cuenta**: Nombre "Caja Chica Prueba", Tipo **Efectivo**, Saldo Inicial $1.000, Fecha hoy →
   Crear. **Esperado**: aparece en la tabla y en Saldos → Disponible → Cajas con $1.000; su ledger tiene
   1 movimiento "Saldo Inicial".
3. **Editar** esa cuenta: cambiar nombre y saldo inicial → el Tipo aparece **bloqueado**. Guardar.
4. **Ocultar** la cuenta → desaparece de Saldos y de los selectores de transferencia, pero sigue en la
   configuración.
5. Abrir **Cheque de Terceros** / **Cheque Propio** → marcadas "(Cuenta del sistema)", sin poder editar
   ni eliminar. **Esperado**: los botones Editar/Eliminar están deshabilitados o devuelven 422.
6. Intentar **eliminar** una cuenta con movimientos (ej. la que recibió una transferencia en Esc. 3) →
   **Esperado**: 422 con mensaje "tiene operaciones asociadas; podés ocultarla".
7. Eliminar una cuenta recién creada sin movimientos más allá del saldo inicial → se elimina.

## Escenario 3 — Transferencia (US3)

1. En Saldos, clic **Movimiento entre Cuentas**.
2. Cuenta de salida **Caja del Local**, cuenta de entrada **Caja Chica Prueba**, Monto $500, Observación
   "fondeo caja chica". **Esperado**: el selector muestra el saldo de cada cuenta al elegirla.
3. Crear → Toastr "Movimiento creado con éxito"; Caja Chica sube $500, Caja del Local baja $500, **Total
   Disponible general NO cambia** (invariante partida doble — SC-002).
4. Abrir la ficha de ambas cuentas → el movimiento aparece con Operación "Movimiento entre Cuenta" y la
   contraparte en Detalles (egreso en una, ingreso en la otra).
5. Probar validaciones: misma cuenta salida=entrada → rechazado; monto 0 → rechazado.

## Escenario 4 — Ficha/ledger (US4)

1. Abrir la ficha de **Caja del Local** (`/tesoreria/cuentas/{id}`).
2. Verificar columnas: Id, Fecha, Operación, Detalles, Ingreso, Egreso, **Balance** (saldo corrido
   resaltado), N° Factura, Observación; balance acumulado correcto fila a fila (SC-005).
3. Filtrar por "Tipo de Operación" = Movimiento entre Cuenta → sólo transferencias; el balance sigue
   siendo el histórico real (no se recalcula por el filtro).
4. Selector de columnas y **Exportar** funcionan.

## Escenario 5 — Informe Movimientos (US5)

1. Pestaña **Movimientos**, elegir un rango de fechas que incluya la transferencia y el saldo inicial.
2. Verificar el resumen: Total Cobros / Total Pagos / Resultado.
3. Expandir **Cobros** y **Pagos** → desglose por cuenta; tildar/destildar una cuenta recalcula el total
   en vivo.
4. **Exportar a PDF** → se abre en el **modal PDF compartido** (no pestaña nueva).
5. **Esperado (estado actual, sin Ventas/Compras)**: cobros/pagos típicamente en 0; el informe no rompe
   y refleja lo que exista. Cuando Ingresos (spec 008) registre cobros, aparecerán acá sin cambios de
   código.

## Verificación automatizada (tests)

```
php artisan test --filter=Tesoreria
```

Cubre (Principio IV — es dinero):
- `TesoreriaCuentaTest`: alta con saldo inicial genera movimiento; tipo inmutable en update; ocultar;
  cuenta del sistema no editable/eliminable; bloqueo de borrado con operaciones.
- `TesoreriaTransferenciaTest`: partida doble (2 filas, signos opuestos); **Total Disponible idéntico
  antes/después** (SC-002); validaciones (misma cuenta, monto ≤ 0); borrar transferencia revierte ambas
  patas.
- `TesoreriaSaldosLedgerTest`: `saldoA(fecha)` correcto por corte; balance corrido consistente fila a
  fila; filtro por tipo de operación no altera el saldo.

## Checklist de cierre (docs — principio I)

- [ ] `docs/modelo_datos.md` actualizado: `cuentas_tesoreria` + `movimientos_tesoreria` implementadas.
- [ ] `docs/documentacion_principal_crm.md` actualizado: sección "Módulo Tesorería".
