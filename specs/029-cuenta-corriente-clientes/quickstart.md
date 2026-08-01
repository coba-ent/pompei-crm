# Quickstart: validar Cuenta Corriente Clientes

## Prerrequisitos

- Al menos un Cliente con una Venta vencida hace más de 90 días sin cobrar, otro con una Venta a vencer, y un tercero totalmente cobrado (para ver los tres casos de bucket).

## Escenario 1 — Saldos Clientes con aging correcto (User Story 1, SC-001, SC-003)

1. Ir a Informes → Cuenta Corriente (tab "Saldos Clientes" por defecto).
2. Verificar que el cliente con deuda vencida hace >90 días aparece con el monto en la columna ">90" y en "Total"; el cliente 100% cobrado no aparece.
3. Click en el encabezado "Total" → la tabla se reordena.
4. Comparar el Total General de esta pantalla (suma de la columna Total) contra el bloque "Cuentas a Cobrar" del Dashboard, misma fecha — deben coincidir exacto (SC-003, misma fuente de cálculo). Comparar también contra el "Total A Cobrar" de `/tesoreria` — es un chequeo informativo (cálculo independiente vía `movimientos_tesoreria`, ver research.md R6); si difiere, no es un bug de esta pantalla.

## Escenario 2 — Movimientos con detalle por cliente (User Story 2, SC-002)

1. Ir al tab "Movimientos", filtrar por el cliente con deuda >90 días.
2. Verificar que aparecen sus Ventas (Total Venta/Cobrado/A Cobrar) y, si tiene, sus Cobros (Medio de Cobro) y Notas.
3. Sumar manualmente el "A Cobrar" de sus filas tipo Venta → debe coincidir con el "Total" que ese cliente mostraba en "Saldos Clientes" (SC-002).
4. Filtrar por Operación = "Cobro" → sólo quedan filas de cobro.

## Escenario 3 — Sin recargas, DataTables server-side

1. Confirmar en DevTools (Network) que cambiar de tab, filtrar o paginar dispara requests XHR a `informes.cuenta-corriente.saldos.data` / `.movimientos.data`, sin navegación de página completa.

## Verificación cruzada con la regla de oro

- Comparar columnas/orden contra `docs/capturas/saldos/WhatsApp Image 2026-07-30 at 7.21.55 PM (2).jpeg` (Saldos Clientes) y `...7.21.55 PM (1).jpeg` (Movimientos).
