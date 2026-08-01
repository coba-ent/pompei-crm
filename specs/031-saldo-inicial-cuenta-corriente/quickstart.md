# Quickstart: validar Saldo Inicial en Cuenta Corriente

## Prerrequisitos

- Un Cliente con `saldo_inicial = 50000` y `saldo_inicial_fecha` hace 45 días, sin ninguna Venta.
- Ese mismo Cliente, con una Venta adicional `a_cobrar = 10000` a vencer.
- Un segundo Cliente con `saldo_inicial = -5000` (saldo a favor) y sin Ventas.
- Un tercer Cliente con `saldo_inicial = 0` (o null) y una Venta normal — control, no debería cambiar.

## Escenario 1 — Saldo inicial aparece en Saldos Clientes (User Story 1, SC-001)

1. Ir a Informes → Cuenta Corriente (tab "Saldos Clientes").
2. Verificar que el primer Cliente aparece con $50.000 en el bucket "31 y 60" y en "Total", aunque no
   tenga ninguna Venta — antes de esta feature, no aparecía en absoluto.
3. Comparar el Total General de esta pantalla contra el bloque "Cuentas a Cobrar" del Dashboard —
   deben seguir coincidiendo exacto (SC-002, mismo invariante ya sostenido por spec 029 SC-003).

## Escenario 2 — Saldo inicial + Venta en el mismo cliente (User Story 1, Acceptance Scenario 2)

1. Sobre el mismo Cliente del escenario 1 (ahora con la Venta a vencer agregada), verificar que su
   Total es $60.000, con $50.000 en "31 y 60" y $10.000 en "A Vencer".

## Escenario 3 — Fila "Saldo Inicial" en Movimientos (User Story 2, SC-003)

1. Ir al tab "Movimientos", filtrar por el Cliente del escenario 2.
2. Verificar que aparece una fila con Operación "Saldo Inicial", A Cobrar = $50.000, y el resto de
   columnas vacías, además de la fila de su Venta.
3. Sumar manualmente el "A Cobrar" de todas sus filas (Saldo Inicial + Venta) → debe coincidir con el
   Total ($60.000) que ese cliente mostraba en "Saldos Clientes" (SC-003).
4. Filtrar por Operación = "Saldo Inicial" → sólo quedan filas de ese tipo.

## Escenario 4 — Saldo inicial negativo (saldo a favor) (User Story 3)

1. Ir a "Saldos Clientes" y buscar el segundo Cliente (saldo inicial -$5.000).
2. Verificar que aparece con Total = -$5.000 en el bucket que le corresponda por fecha (no queda
   excluido del listado por tener saldo "negativo", sólo se excluye si el total da ≈ 0).

## Escenario 5 — Sin regresión para clientes sin saldo inicial (SC-004)

1. Verificar que el tercer Cliente (saldo inicial 0/null) muestra exactamente el mismo Total que
   mostraba antes de esta feature (sólo su Venta, sin ningún monto adicional).
