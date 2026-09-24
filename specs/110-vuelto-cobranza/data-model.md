# Data Model — Vuelto en la cobranza de una Venta (spec 110)

**Fecha**: 2026-09-24

Todos los cambios son **aditivos**. No se modifica ni se borra ninguna columna existente, y ninguna
fila actual cambia de valor.

## 1. `cobros` — dos columnas nuevas

| Columna | Tipo | Null | Default | Descripción |
|---|---|---|---|---|
| `vuelto` | `decimal(14,2)` | SÍ | `NULL` | Importe devuelto al cliente en el acto. NULL = cobro sin vuelto (comportamiento actual). |
| `cuenta_vuelto_id` | `bigint unsigned` FK → `cuentas_tesoreria.id` | SÍ | `NULL` | Cuenta de la que salió el vuelto. Obligatoria sólo si `vuelto > 0`. |

**⚠️ Semántica de `cobros.monto` (leer antes de tocar esta tabla)**

`monto` guarda el **neto imputado a la venta**, no el importe recibido del cliente. El recibido se
reconstruye como `monto + COALESCE(vuelto, 0)`.

Se eligió así deliberadamente (ver `research.md` Decisión 5): mantener `monto` como el importe que
salda la venta hace que la fórmula de saldo (`total + ND − NC − cobrado`) siga siendo correcta sin
tocar ninguna de sus **5 réplicas SQL** (filtros del listado, KPIs, aging, informe de cuenta
corriente, movimientos de clientes). El docblock de `App\Services\Ingresos\SqlCredito` documenta un
incidente real donde desalinear la fórmula de su réplica dejó 457 ventas por $43,3M mal
clasificadas.

**Reglas de validación**:

- `vuelto > 0` ⟹ `cuenta_vuelto_id` obligatoria (FR-011)
- `vuelto < monto_recibido` — estricto, no `<=` (FR-006)
- `monto_recibido − vuelto == saldo pendiente de la venta` (FR-007, clarificación del 24/09)
- `vuelto` NULL o `0` ⟹ `cuenta_vuelto_id` debe quedar NULL

**Compatibilidad**: los 25.253 cobros existentes quedan con ambas columnas en NULL y se comportan
exactamente igual (FR-014).

## 2. `movimientos_tesoreria.tipo` — valor nuevo en el ENUM

```
ANTES: ('saldo_inicial','movimiento_entre_cuentas','cobro','pago','gasto','ingreso')
AHORA: ('saldo_inicial','movimiento_entre_cuentas','cobro','pago','gasto','ingreso','vuelto')
```

**Signo**: negativo, igual que `pago` y `gasto`. Verificado contra los datos: los 3.342 pagos y los
9.442 gastos son 100% negativos; los 25.253 cobros, 100% positivos (`research.md` Decisión 2).

**Vínculo**: `origen_type = App\Models\Cobro`, `origen_id = <id del cobro>` — el **mismo** que el
movimiento de ingreso. Los dos se distinguen por `tipo`.

Una cobranza con vuelto produce dos filas:

| tipo | cuenta | monto | origen |
|---|---|---:|---|
| `cobro` | la elegida para el cobro | `+155000.00` | Cobro #N |
| `vuelto` | `cuenta_vuelto_id` | `-15000.00` | Cobro #N |

## 3. `configuracion_ventas` — una columna nueva

| Columna | Tipo | Null | Descripción |
|---|---|---|---|
| `cuenta_vuelto_id` | `bigint unsigned` FK → `cuentas_tesoreria.id` | SÍ | Cuenta por defecto para vueltos. Preselecciona el campo en el modal; el operador puede cambiarla sin alterar este valor (FR-010). |

Fila única de defaults globales, ya usada para categoría/vendedor/lista de precios/depósito de
Venta, Presupuesto y Compra. Se suma al `$fillable` de `App\Models\ConfiguracionVentas` con su
relación `BelongsTo`.

## 4. Relaciones de Eloquent — el punto crítico

**`App\Models\Cobro::movimientoTesoreria()` DEBE filtrarse por tipo.**

Hoy es:

```php
public function movimientoTesoreria(): MorphOne
{
    return $this->morphOne(MovimientoTesoreria::class, 'origen');
}
```

Con dos movimientos apuntando al mismo cobro, ese `morphOne` devuelve **uno cualquiera de los dos,
de forma no determinística**. Si devuelve el de vuelto:

- `Cobranzas::anularCobro()` borraría el vuelto y dejaría **el ingreso vivo en la cuenta**
- `Cobranzas::actualizarCobro()` editaría el movimiento equivocado

Es exactamente el modo de falla que el docblock de `anularCobro()` documenta como incidente previo
("sin el fallback, anular un cobro histórico borraba el cobro y dejaba el ingreso vivo").

**Cambio requerido**:

```php
// El de ingreso: filtrado por tipo, si no devuelve cualquiera de los dos.
public function movimientoTesoreria(): MorphOne
{
    return $this->morphOne(MovimientoTesoreria::class, 'origen')->where('tipo', 'cobro');
}

// El del vuelto: relación separada.
public function movimientoVuelto(): MorphOne
{
    return $this->morphOne(MovimientoTesoreria::class, 'origen')->where('tipo', 'vuelto');
}
```

Este filtro es **retrocompatible**: los cobros existentes sólo tienen movimientos `tipo='cobro'`, así
que la relación devuelve lo mismo que antes.

**`movimientoHuerfanoDe()` no se toca**: ya recibe el tipo como primer parámetro y se lo llama con
`'cobro'`, así que nunca aparea un movimiento de vuelto.

## 5. Migraciones

Tres migraciones, todas aditivas:

1. `add_vuelto_to_cobros_table` — las dos columnas + FK a `cuentas_tesoreria`
2. `add_vuelto_to_movimientos_tesoreria_tipo_enum` — `ALTER TABLE ... MODIFY COLUMN tipo ENUM(...)`
3. `add_cuenta_vuelto_to_configuracion_ventas_table` — la columna + FK

**Sobre la migración 2**: agregar un valor al final de un ENUM es un cambio de metadatos en MySQL 8
y no reescribe la tabla (48.656 filas). Aun así corre en producción con backup previo.

**⚠️ SQLite no valida ENUMs.** La suite de tests corre en SQLite y **no va a detectar** un error en
esa migración. La validación contra MySQL local es obligatoria (ver `quickstart.md`).

El `down()` de la migración 2 debe contemplar que revertir el ENUM con filas `tipo='vuelto'`
existentes fallaría; se documenta que el rollback exige borrarlas o reasignarlas primero.
