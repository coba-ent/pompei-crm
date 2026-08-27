# Data Model: Orden de cuentas de tesorería por drag & drop

**Feature**: 085-orden-cuentas-tesoreria
**Fecha**: 2026-08-27

## Resumen

**Esta feature NO agrega ni modifica ninguna estructura de base de datos.** No hay migración.

El atributo que se edita ya existe y ya está en uso: la columna `orden` de `cuentas_tesoreria`,
creada en `database/migrations/2026_07_25_060001_create_cuentas_tesoreria_table.php`. Lo único que
cambia es **quién la escribe**: hasta ahora sólo se podía tocar a mano en la base; a partir de esta
feature la escribe el endpoint de reordenamiento.

## Entidad afectada: `CuentaTesoreria` (`cuentas_tesoreria`)

| Campo | Tipo | Estado | Rol en esta feature |
|-------|------|--------|---------------------|
| `id` | bigint PK | Existente | Identifica cada cuenta en la lista ordenada que envía el cliente |
| `tipo` | enum/string (`efectivo`, `banco`, `a_cobrar`, `a_pagar`) | Existente, **no se modifica** | Delimita el bloque: el reordenamiento nunca cruza tipos (FR-003, FR-011) |
| `orden` | `smallInteger` nullable | Existente, **único campo escrito** | Posición de presentación dentro del tipo |
| `nombre` | string | Existente, no se modifica | Desempate alfabético cuando dos cuentas comparten `orden` |
| `visible` | boolean | Existente, no se modifica | Las cuentas ocultas participan del orden aunque no se muestren en las cards |
| `es_sistema` | boolean | Existente, no se modifica | No restringe el reordenamiento: una cuenta de sistema se mueve como cualquier otra |

### Reglas de validación

- `orden` pasa a ser **consecutivo y sin huecos dentro de cada tipo**: `1..N` para las N cuentas de
  ese tipo (FR-006). La columna sigue siendo `nullable` en el esquema porque las cuentas de tipos
  que nunca se reordenaron conservan sus `NULL` heredados; el scope `ordenadas()` ya los maneja
  mandándolos al final.
- **No hay unicidad a nivel de base** sobre `(tipo, orden)`. Se evaluó y se descartó: el `NULL`
  heredado la haría inaplicable sin una migración de datos previa, y la unicidad efectiva la
  garantiza el endpoint, que reescribe el bloque entero en una transacción. El desempate por
  `nombre` en `ordenadas()` sigue cubriendo los duplicados heredados.
- Un reordenamiento válido debe contener **exactamente** el conjunto de ids de las cuentas del tipo
  declarado: sin ids ajenos, sin faltantes, sin repetidos (FR-008).

### Invariantes

- Un reordenamiento **nunca** modifica `tipo`, `nombre`, `visible`, `es_sistema`, `saldo_inicial`,
  `saldo_inicial_fecha`, ni ningún registro de `movimientos_tesoreria` (FR-011).
- Los saldos son derivados (`Σ movimientos`, ver `CuentaTesoreria::saldoA()`), así que ningún total
  de A Cobrar / A Pagar / Cajas / Bancos / Disponible puede cambiar como consecuencia de un
  reordenamiento (SC-003).
- La operación es atómica por bloque: o se escriben las N filas del tipo, o ninguna (FR-007).

## Lectura del orden — sin cambios

El scope existente `CuentaTesoreria::scopeOrdenadas()` sigue siendo el único punto de lectura del
orden, y **no se modifica**:

```
orderByRaw('orden IS NULL')->orderBy('orden')->orderBy('nombre')
```

Consumidores actuales, que heredan el orden nuevo automáticamente (FR-012, SC-008):

- `TesoreriaController::configCuentas()` → listado del modal de configuración.
- `Tesoreria::saldos()` → cards A Cobrar / A Pagar / Cajas / Bancos.
- `TesoreriaController::cuentasOpciones()` → selectores Select2 de cuenta (transferencias y demás
  pantallas que eligen cuenta de tesorería).
- `TesoreriaController::movimientos()` → filtro de cuentas del informe de movimientos.

Esto es lo que hace que FR-012 no requiera trabajo adicional: el orden se escribe en un solo lugar
y se lee por un solo scope.
