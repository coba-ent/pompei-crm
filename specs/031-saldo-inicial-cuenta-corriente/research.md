# Research: Saldo Inicial en Cuenta Corriente

## R1 — Cómo sumar el saldo inicial al aging existente sin duplicar la lógica de buckets

**Decision**: Extraer la clasificación por bucket (a_vencer / vencido 0-30 / 31-60 / 61-90 / +90 según
una fecha de referencia) a un método privado `sumarABuckets(array &$buckets, float $saldo, ?Carbon
$fechaReferencia, Carbon $fecha, ...)` reutilizado tanto para los documentos existentes (Venta/Compra)
como para el nuevo "saldo" sintético del saldo inicial. En `aging()` y `porCliente()`, después de
recorrer los documentos, se agrega un segundo recorrido sobre `Cliente::where('saldo_inicial', '!=',
0)->get()` (o `Proveedor` para `tipo='proveedor'`), sumando cada `saldo_inicial` a los mismos buckets
usando `saldo_inicial_fecha` como fecha de referencia.

**Rationale**: Evita reescribir la lógica de clasificación por antigüedad dos veces (una para
documentos, otra para saldos iniciales) — el mismo bug de "off-by-one en el corte de 30/60/90 días"
tendría que arreglarse en un solo lugar si aparece. Mantiene `aging()`/`porCliente()` como el único
punto de cálculo (ya es el principio que sostiene R1 de spec 029).

**Alternatives considered**: Modelar el saldo inicial como una "Venta sintética" (crear un registro
fantasma con `total = saldo_inicial`, `fecha_vto_cobro = saldo_inicial_fecha`) para que el loop
existente lo procese sin cambios. Se descarta: `Venta::aCobrar()` no acepta saldos negativos de forma
natural (asume Total ≥ 0 con NC/ND/Cobrado restando), y mezclar un objeto no persistido con
`Venta::with(...)->get()` complica el tipo de la colección sin necesidad.

## R2 — Por qué el saldo inicial NO respeta el filtro `saldo <= TOLERANCIA` que sí aplican Venta/Compra

**Decision**: El recorrido de documentos (Venta/Compra) sigue descartando saldos `<= TOLERANCIA`
porque `aCobrar()`/`aPagar()` son magnitudes que sólo tienen sentido como "pendiente positivo". El
recorrido del saldo inicial usa un criterio distinto: sólo se descarta si `abs(saldo_inicial) <=
TOLERANCIA` (es decir, sólo si es ≈ 0) — un saldo inicial negativo se suma tal cual (con signo) a su
bucket, sin ningún `abs()` ni clamp (FR-005: saldo a favor, resta del total).

**Rationale**: Es la decisión explícita del User Story 3 — un saldo inicial negativo representa
crédito real del cliente y tiene que poder dejar su Total en negativo, algo que Venta/Compra no
necesitan expresar hoy (no hay "Venta con `aCobrar()` negativo" en el dominio actual).

**Alternatives considered**: Tratar cualquier saldo inicial negativo como 0 (ignorarlo). Se descarta
por decisión explícita del usuario (User Story 3, FR-005) — perdería información real de saldo a
favor que sí importa para no sobreestimar la deuda de ese cliente.

## R3 — `porCliente()` tiene que poder crear una fila para un Cliente/Proveedor SIN ninguna Venta/Compra

**Decision**: Antes de este cambio, `porCliente()` sólo agrega una entrada a `$acumulado[$entidadId]`
dentro del loop de documentos (`if (! isset($acumulado[$entidadId]))`) — un Cliente sin ninguna Venta
nunca entra al array. Con saldo inicial, hay que poder mostrar un Cliente que sólo tiene saldo inicial
y cero Ventas (User Story 1, Acceptance Scenario 1). El segundo recorrido (R1) tiene que aplicar la
misma inicialización perezosa (`if (! isset($acumulado[$entidadId])) { ... }`) para poder crear la fila
si todavía no existe.

**Rationale**: Es el caso de uso central de la feature (cliente migrado con sólo saldo inicial, sin
Ventas todavía en el sistema) — sin esto, la feature no resolvería el problema que la motiva.

**Alternatives considered**: Ninguna — es una consecuencia directa y no evitable del User Story 1.

## R4 — Fila sintética "Saldo Inicial" en el UNION de `queryMovimientos()` (spec 029)

**Decision**: Agregar un cuarto `SELECT` al UNION ya existente (`ventas` / `cobros` /
`notas_credito_debito`) sobre `clientes`, filtrado por `saldo_inicial != 0`, proyectando:
`id = clientes.id`, `fecha_emision = clientes.saldo_inicial_fecha`, `cliente_id = clientes.id`,
`operacion = 'saldo_inicial'`, y el resto de columnas (`categoria`, `total_venta`, `cobrado`,
`nro_comprobante`, `medio_cobro`, `descripcion`) en `NULL`, con `a_cobrar = clientes.saldo_inicial`.
Se agrega `'saldo_inicial'` a `OPERACIONES_DISPONIBLES` (filtro "Operación") y a las etiquetas del
frontend (`ETIQUETAS_OPERACION` en `informe-cuenta-corriente.js`) como "Saldo Inicial".

**Rationale**: Es la forma más directa de sostener el invariante FR-009 (suma de "A Cobrar" en
Movimientos = Total en Saldos Clientes) reutilizando el mismo mecanismo de UNION + filtros externos ya
validado por spec 029 (research.md R2 de esa spec) — no hay que tocar `DataTables::of()` ni el patrón
de filtros (`aplicarFiltrosMovimientos()` ya funciona igual sobre la fila nueva, porque el `WHERE` se
aplica sobre el alias `mov` de la subconsulta completa).

**Alternatives considered**: No mostrar el saldo inicial en Movimientos, sólo en el agregado. Se
descartó explícitamente con el usuario (User Story 2) porque rompe el invariante que spec 029 ya había
probado (SC-002) para todo cliente con saldo inicial, lo cual es confuso y se ve como bug.

## R5 — `saldo_inicial_fecha` nula con `saldo_inicial ≠ 0` → bucket "A Vencer"

**Decision**: Se reutiliza exactamente la misma regla que ya aplica el código existente para
`fecha_vto_cobro`/`fecha_vto_pago` nulos: `if ($vencimiento === null || ... >= $fecha) { $buckets['a_vencer'] += $saldo; }`.
El saldo inicial entra a ese mismo `if` sin ninguna rama especial adicional — "fecha nula" ya cae en
"a_vencer" en el código actual, así que no hace falta un caso nuevo, sólo que la fecha usada sea
`saldo_inicial_fecha` en vez de `fecha_vto_cobro`.

**Rationale**: Decisión ya tomada con el usuario (pregunta 1 de clarify: "A Vencer, recomendado") y
coincide con el comportamiento que el código ya tenía para el caso análogo de Venta sin vencimiento
cargado — cero código nuevo para este caso, sólo reutilización.

**Alternatives considered**: N/A — coincide con el default ya existente en el código, no hay
alternativa a evaluar.

## R6 — Performance: costo adicional de la query de Clientes/Proveedores con saldo inicial

**Decision**: `Cliente::where('saldo_inicial', '!=', 0)->get(['id', 'nombre', 'saldo_inicial',
'saldo_inicial_fecha'])` es una query acotada por índice implícito de comparación simple sobre una
tabla que hoy tiene volumen bajo-medio (mismo orden de magnitud que ya maneja `aging()` sin problema
reportado, research.md R1 de spec 029). No se agrega N+1: es una sola query adicional por llamada a
`aging()`/`porCliente()`, no una por cliente.

**Rationale**: Mismo criterio de "no optimizar prematuramente" ya aplicado en spec 029 R1 — el volumen
no lo justifica hoy; si crece, es un refactor interno sin cambiar el contrato público de los métodos.

**Alternatives considered**: Agregar el saldo inicial vía SQL agregado directamente en una única query
con `UNION` contra el resultado de Ventas. Se descarta por la misma razón que spec 029 R1 rechazó
mover todo `aging()` a SQL: mantener el cálculo en un solo lugar (PHP, en el servicio) es más
importante que la micro-optimización mientras el volumen no lo exija.
