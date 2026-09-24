# Research — Vuelto en la cobranza de una Venta (spec 110)

**Fecha**: 2026-09-24
**Relevado contra**: base MySQL local `contagram` (9.630 productos, 25.253 cobros, 23.659 ventas) y
el código de `main` en `ecd9f9a2`.

## Decisión 1 — Tipo de movimiento propio `vuelto` en el ENUM

**Decisión**: agregar el valor `vuelto` al ENUM `movimientos_tesoreria.tipo`, que hoy es
`('saldo_inicial','movimiento_entre_cuentas','cobro','pago','gasto','ingreso')`.

**Rationale**: la spec exige (FR-005) que el vuelto nunca se compute como gasto. Con un tipo
dedicado eso queda garantizado **por construcción**: los informes de Gastos filtran por
`tipo = 'gasto'` y el vuelto simplemente no entra, sin depender de que cada consulta se acuerde de
excluirlo. Es además lo que hace legible la grilla de tesorería: el operador ve "Vuelto" y entiende
la operación sin abrir la venta.

**Alternativas consideradas**:

- **Reusar `cobro` con monto negativo**: no requiere migración, pero rompe todo informe que sume
  `WHERE tipo='cobro'` asumiendo signo positivo. Medido: los 25.253 cobros de la base son **100%
  positivos**, así que hay código y reportes que dependen de esa invariante. Descartada.
- **Reusar `movimiento_entre_cuentas`**: semánticamente falso. Ese tipo modela plata que va de una
  cuenta propia a otra cuenta propia (y de hecho en la base está balanceado: 5.270 negativos contra
  5.268 positivos). El vuelto sale del negocio hacia el cliente. Descartada.
- **Reusar `gasto`**: es exactamente el problema que la spec viene a eliminar. Descartada.

**Riesgo y mitigación**: agregar un valor a un ENUM de MySQL es un `ALTER TABLE` sobre una tabla con
48.656 filas. Es una operación de metadatos en MySQL 8 (no reescribe la tabla) pero igual se corre
con backup previo. **SQLite no valida ENUMs**, así que la suite verde no prueba nada de esto: la
migración se valida sí o sí contra MySQL local (ver `quickstart.md`).

## Decisión 2 — Signo del movimiento de vuelto: negativo

**Decisión**: el movimiento de vuelto se registra con **monto negativo**, igual que `pago` y `gasto`.

**Rationale**: no es una preferencia de estilo, es la convención vigente del sistema, verificada
contra los datos reales:

| tipo | movimientos | negativos | positivos |
|---|---:|---:|---:|
| `cobro` | 25.253 | 0 | 25.253 |
| `pago` | 3.342 | 3.342 | 0 |
| `gasto` | 9.442 | 9.442 | 0 |
| `ingreso` | 61 | 0 | 61 |

Los egresos son negativos **sin una sola excepción**. `Pagos::registrarPago()` lo hace explícito
pasando `-$monto` a `registrarMovimiento()`. El saldo de una cuenta se calcula sumando montos con
signo, así que un vuelto positivo inflaría la caja en lugar de reducirla.

**Alternativas consideradas**: guardar el vuelto en positivo y restarlo por tipo en cada consulta de
saldo. Descartada: obliga a tocar todas las consultas de saldo y rompe la invariante que el sistema
sostiene hace 5 años.

## Decisión 3 — Persistencia del vuelto: dos columnas en `cobros`

**Decisión**: agregar a la tabla `cobros` dos columnas nullable: `vuelto` (decimal 14,2) y
`cuenta_vuelto_id` (FK a `cuentas_tesoreria`). Un cobro sin vuelto las deja en NULL.

**Rationale**: el vuelto es un atributo **del cobro**, no una entidad aparte — nace y muere con él
(spec, sección Contexto). Guardarlo en el propio cobro permite reconstruir la operación completa
desde una sola fila, que es lo que necesita FR-015 (mostrarlo en la ficha de la venta) y FR-012/013
(editar y anular de forma consistente). Nullable garantiza FR-014: los 25.253 cobros existentes
quedan intactos y se comportan igual.

**Alternativas consideradas**:

- **Tabla `vueltos` aparte**: sobra para una relación 1:0..1 que nunca se consulta sin su cobro.
  Agrega un JOIN a las consultas de cobranza sin beneficio.
- **Sólo el movimiento de tesorería, sin columnas en `cobros`**: el dato existiría únicamente en
  tesorería y habría que inferir la relación por el vínculo polimórfico. Reconstruir "cuánto vuelto
  tuvo este cobro" requeriría buscar movimientos por `origen`, y al editar no habría contra qué
  comparar. Descartada.

## Decisión 4 — Los dos movimientos se distinguen por `tipo`, no por heurística

**Decisión**: el movimiento de ingreso y el de vuelto comparten el vínculo polimórfico
(`origen_type = Cobro`, `origen_id = <id>`) y se distinguen **por su `tipo`** (`cobro` vs `vuelto`).

**Rationale**: es el punto más delicado del plan. `Cobranzas::anularCobro()` y
`actualizarCobro()` hoy resuelven el movimiento así:

```php
$movimiento = $cobro->movimientoTesoreria
    ?? $this->tesoreria->movimientoHuerfanoDe('cobro', ...);
```

La relación `movimientoTesoreria` es un `morphOne`. Con dos movimientos apuntando al mismo cobro,
un `morphOne` sin filtrar devolvería **cualquiera de los dos**, de forma no determinística. Si
devuelve el de vuelto, anular un cobro dejaría el ingreso vivo en la cuenta — exactamente el
incidente que el docblock de `anularCobro()` documenta (25.259 cobros importados sin vínculo
dejaban el ingreso vivo).

**Por eso**: la relación existente `movimientoTesoreria` DEBE filtrarse por `tipo = 'cobro'` y se
agrega una relación separada `movimientoVuelto` filtrada por `tipo = 'vuelto'`. Sin ese filtro, la
feature introduce un bug de saldo fantasma.

**Alternativas consideradas**: una columna discriminadora nueva en `movimientos_tesoreria`.
Descartada: el `tipo` ya discrimina y agregar otra columna duplicaría la fuente de verdad.

## Decisión 5 — La fórmula de saldo NO se toca

**Decisión**: no se modifica `Venta::aCobrar()`, ni `estadoCobro()`, ni `SqlCredito`, ni ninguna de
las 5 réplicas SQL de la fórmula de saldo.

**Rationale**: éste es el hallazgo que más riesgo elimina. Como el cobro se imputa por el **neto**
(FR-002) y el neto siempre salda exactamente la venta (FR-007, clarificación del 24/09), el valor
que se guarda en `cobros.monto` sigue siendo un importe normal que cierra la venta. La fórmula
`total + ND − NC − cobrado` no se entera de que hubo un vuelto.

Esto importa mucho: el docblock de `SqlCredito` documenta un incidente real donde tocar
`estadoCobro()` sin actualizar su réplica SQL dejó **457 ventas por $43,3M mal clasificadas**. Al no
tocar la fórmula, esta spec no puede reproducir ese incidente.

**Consecuencia de diseño**: `cobros.monto` guarda el **neto imputado**, no el importe recibido. El
recibido se reconstruye como `monto + vuelto`. Se documenta explícitamente porque es
contraintuitivo: quien lea la tabla debe saber que `monto` es lo que se imputó a la venta.

**Alternativa considerada**: guardar en `cobros.monto` el importe recibido y restar el vuelto en la
fórmula de saldo. Descartada de plano: obliga a tocar las 5 réplicas SQL y reintroduce el riesgo del
incidente de las 457 ventas.

## Decisión 6 — La validación `lte` se mantiene, medida contra el neto

**Decisión**: `StoreCobroRequest` y `UpdateCobroRequest` conservan su tope, pero lo aplican al
**neto** (`monto − vuelto`) en lugar de al monto crudo.

**Rationale**: la clarificación del 24/09 (FR-007) pide que el neto salde exactamente la venta. Eso
es más estricto que el `lte` actual, no menos. El pedido original del cliente ("que la validación no
me lo impida") se satisface porque lo que ahora puede superar el saldo es el **importe recibido**,
mientras que el neto —lo que realmente se imputa— sigue acotado.

**Consecuencia importante**: esta spec **NO habilita sobrepagos**. Una cobranza sin vuelto sigue sin
poder superar el saldo, igual que hoy. El análisis previo sobre las 57 ventas sobrepagadas
históricas y el saldo a favor queda fuera de alcance y sin efecto.

## Decisión 7 — Default configurable en `configuracion_ventas`

**Decisión**: agregar `cuenta_vuelto_id` (FK nullable) a `configuracion_ventas`, la fila única de
defaults globales.

**Rationale**: es la tabla que ya cumple ese rol para categoría, vendedor, lista de precios, tipo de
comprobante y depósito, tanto de Venta como de Presupuesto y Compra. El modelo `ConfiguracionVentas`
ya existe con su `$fillable` y sus relaciones `BelongsTo`; sumar un campo es aditivo puro y no
introduce una tabla ni un patrón nuevo.

El default se **presenta preseleccionado** en el modal pero es editable en la operación (FR-010), y
cambiarlo ahí no persiste en la configuración global.

**Alternativa considerada**: una tabla `configuracion_tesoreria` nueva. Descartada por no justificar
una tabla para un solo campo cuando la convención del proyecto ya tiene dónde ponerlo.

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| `morphOne` devuelve el movimiento equivocado y anular deja saldo fantasma | Filtrar `movimientoTesoreria` por `tipo='cobro'` (Decisión 4). Test explícito de anulación. |
| El ENUM nuevo no se valida en la suite (SQLite lo ignora) | Validación obligatoria en navegador contra MySQL local, documentada en `quickstart.md`. |
| Pantallas que mapean tipos a etiquetas no conocen `vuelto` y muestran vacío | Relevadas 2: `CuentaTesoreriaController::LABELS` y el filtro de tipo de operación del ledger. Se actualizan ambas. |
| `movimientoHuerfanoDe()` aparea por (tipo, cuenta, fecha, monto) y podría tomar un vuelto | Ya recibe el tipo como primer parámetro; al pasarle `'cobro'` nunca matchea un `'vuelto'`. Sin cambios. |
| El ALTER del ENUM corre sobre producción con datos reales | Backup previo (`mysqldump`) y `migrate:status` antes de migrar, según el procedimiento de deploy del proyecto. |
