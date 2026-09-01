# Plan técnico — spec 094

## Enfoque

Un comando de consola que lee los tres Excel, arma los movimientos en memoria, **verifica antes de
escribir**, escribe en una transacción, y **verifica de nuevo antes de confirmar**. Si la
verificación final falla, hace rollback y no queda nada.

No hay migraciones de esquema nuevas salvo la columna que identifica la corrida.

## Piezas

### 1. `LectorInformeStockContagram`

Lee un `Informe Stock AAAA.xlsx` y devuelve filas normalizadas. Responsabilidad única: entender el
formato del export.

- Cabeceras en la **fila 4**; los datos arrancan en la 5. Las filas 1–2 son el resumen del período.
- Parsea la fecha tolerando los dos formatos (Decisión 11) y **aborta** si cae fuera del año del
  archivo.
- **Descarta cantidad 0** (Decisión 2) — acá, lo más temprano posible, para que las 22.326 filas de
  ruido no lleguen a ninguna otra pieza.
- Normaliza el depósito: `Local` → 5, `Full` → 6, `Depósito Tiendanube` → 5 con marca.

### 2. `ResolvedorOperacionLegacy`

Dado el `ID` y el año, devuelve la venta o compra del CRM. Trabaja **por lote, no por fila**: se
traen todos los `legacy_id` de una consulta y se matchea en memoria. 31.518 consultas individuales
sería inaceptable.

El tipo de operación sale de la columna `Operación`: `Venta`/`Nota de Crédito (Venta)` → `Venta`;
`Compra`/`Nota de Crédito (Compra)` → `Compra`. Las `... eliminada` no resuelven a nada (la
operación ya no existe) y se cargan sin origen.

### 3. `FiltroCorteMigracion`

Aplica la Decisión 5. Precarga en un set los `origen_type`+`origen_id` que **ya tienen movimiento** y
saltea sus filas. Para las filas sin `ID`, corta por fecha ≥ 13/08/2026.

### 4. `VerificadorCargaHistorica`

El corazón de la garantía. Toma dos fotos y las compara:

- `stock_actual` de los 9.781 productos (FR-014).
- Publicaciones de ML y variantes de Tiendanube con `stock_pendiente` (FR-015).

Corre **antes** y **después**. Cualquier diferencia → excepción → rollback.

### 5. `stock:importar-movimientos-historicos`

El comando. Orquesta, informa y decide.

```
php artisan stock:importar-movimientos-historicos actualziacion/stock
    [--anios=2024,2025,2026]
    [--escribir]        # sin esto es dry-run
    [--deshacer=N]      # revierte la corrida N
```

Salida del dry-run: cuántas filas se leyeron, cuántas se descartaron por cantidad 0, cuántas se
saltearon por el corte, cuántas matchearon operación, cuántas quedaron huérfanas, cuántos productos
no se encontraron, y el resultado de la verificación de saldos.

## Cómo se insertan los movimientos

```php
DB::table('movimientos_stock')->insert($lote);   // NO el modelo Eloquent
```

Query builder directo (Decisión 6). No pasa por `MovimientoStockObserver` ni por
`MovimientoStockAuditoriaObserver`. Lotes de 500.

Cada fila:

| Columna | Valor |
|---|---|
| `producto_id` | por `codigo` |
| `deposito_id` | 5 o 6 |
| `tipo` | por operación, ver mapeo abajo |
| `cantidad` | tal cual, con signo |
| `descripcion` | la operación de Contagram + su descripción |
| `origen_type` / `origen_id` | la venta o compra, o `NULL` |
| `fecha` | la fecha real de la operación |
| `usuario_id` | `NULL` (FR-023); el nombre del usuario de Contagram va en `descripcion` |
| `carga_historica_id` | la corrida, para poder deshacer |

### Mapeo del tipo

Hay **19 valores distintos** de `Operación` en el dato real. El mapeo es explícito, no por defecto:

| Operación de Contagram | `tipo` |
|---|---|
| `Venta`, `Compra`, `Nota de Crédito (Venta)`, `Nota de Crédito (Compra)` | `entrada` / `salida` según el signo |
| `Aumento`, `Disminución` | `ajuste` |
| `Aumento/Disminución por Importación`, `Importación` | `ajuste` |
| `Aumento/Disminución por Sincronización` | `ajuste` |
| `Registro Inicial` | `ajuste` |
| Cualquier `... Eliminado/a` | el tipo de su operación base |

Una operación que no esté en esta tabla **aborta la corrida**. Que aparezca un valor nuevo significa
que el export cambió, y adivinar su tipo es exactamente lo que no hay que hacer.

## La única migración

`carga_historica_id` (bigint, nullable, indexada) en `movimientos_stock`. Es lo que permite deshacer
exactamente lo insertado (FR-018) sin depender de un rango de fechas o de ids.

Los movimientos que genera el CRM normalmente la dejan en `NULL`, así que la separación entre lo
histórico y lo real es explícita y consultable.

## Orden de trabajo

1. Migración de `carga_historica_id`.
2. Lector + tests con el formato real (las tres filas por depósito, las dos formas de fecha).
3. Resolvedor + filtro de corte.
4. Verificador.
5. Comando en dry-run.
6. **Prueba sobre un clon fresco del VPS.**
7. Deshacer.
8. Corrida real con backup.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Los observers empujan stock a ML/Tiendanube | Decisión 6 + verificación FR-015 |
| Se altera el stock actual | Tablas separadas + foto antes/después |
| Fechas mal parseadas | Abortar si caen fuera del año del archivo |
| Correr dos veces | FR-006 lo hace idempotente |
| Memoria con 53.844 filas | Procesar por año; PhpSpreadsheet en modo sólo-datos |
| Un error a mitad de camino | Transacción + `carga_historica_id` para deshacer |

## Fuera de alcance

- 2021, 2022 y 2023.
- Recalcular o corregir el stock actual.
- Pantalla en la interfaz.
- Movimientos de tesorería (los de la migración ya se cargaron desde los exports de `Cuentas/`).
