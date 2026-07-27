# Data Model — Módulo Tesorería

Deriva de `spec.md` (Key Entities + FRs), `research.md` y `docs/informe_contagram_tesoreria.md`.
Convenciones del proyecto: español, snake_case, single-tenant (sin `empresa_id`), `id` bigIncrements,
`created_at`/`updated_at`.

## Tabla `cuentas_tesoreria`

Catálogo de cuentas de dinero del negocio. El saldo NO se almacena (derivado — research §1).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | ej. "Caja del Local", "Banco Galicia" |
| tipo | enum(`a_cobrar`,`a_pagar`,`banco`,`efectivo`) | fijo tras crear (FR-004). Determina el bloque de Saldos: `efectivo`→Cajas, `banco`→Bancos, `a_cobrar`/`a_pagar`→sus bloques |
| visible | boolean, default true | "Mostrar/Ocultar Cuenta" (FR-005). Oculta = no aparece en Saldos ni selectores, sí en config y su ficha |
| es_sistema | boolean, default false | true para Cheque de Terceros / Cheque Propio (FR-006): no editable ni eliminable |
| saldo_inicial | decimal(14,2), default 0 | monto de apertura; se materializa como un movimiento `saldo_inicial` (FR-002) |
| saldo_inicial_fecha | date | fecha de apertura del saldo inicial |
| orden | smallint, nullable | orden de despliegue dentro de su bloque/tipo (opcional, para calcar el orden del relevamiento) |

Índices: `index(tipo)`, `index(visible)`.

**Reglas / métodos de dominio**:
- `scopeVisibles($q)`: `where('visible', true)`.
- `scopePorTipo($q, $tipo)`.
- `esCaja()` = `tipo === 'efectivo'`; `esBanco()` = `tipo === 'banco'`.
- `tieneOperaciones()`: existe algún `MovimientoTesoreria` de la cuenta con `tipo != 'saldo_inicial'`
  (bloquea `destroy()` — FR-007). Mismo patrón que `Deposito::tieneOperaciones()`.
- `saldoA(?Carbon $fecha = null)`: `movimientos()->when($fecha, fn=>where('fecha','<=',$fecha))->sum('monto')`.
- No editable / no eliminable si `es_sistema` (FR-006).

## Tabla `movimientos_tesoreria`

Ledger. Una fila = un asiento en una cuenta. El signo de `monto` da ingreso/egreso (research §2).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| cuenta_tesoreria_id | FK → cuentas_tesoreria | cascade on delete (sólo aplica a cuentas sin operaciones, que se pueden borrar) |
| fecha | date | fecha del movimiento |
| tipo | enum(`saldo_inicial`,`movimiento_entre_cuentas`,`cobro`,`pago`,`gasto`) | "Operación" del ledger. Sólo `saldo_inicial` y `movimiento_entre_cuentas` los genera esta spec; el resto los generarán otros módulos (FR-030) |
| monto | decimal(14,2) | **con signo**: positivo = ingreso, negativo = egreso |
| detalle | string, nullable | "Detalles" del ledger: contraparte de la transferencia / cliente / proveedor / subcategoría de gasto |
| nro_comprobante | string, nullable | "N° Factura" (ej. "B 0001-00000003"); sólo dato, sin validez fiscal |
| observacion | text, nullable | texto libre (en transferencias, y donde el circuito de cheques anota N° y fecha de depósito) |
| transferencia_id | uuid/string, nullable, index | agrupa las 2 patas de una misma transferencia (research §3) |
| origen_type / origen_id | nullable (morphTo) | documento que originó el movimiento (Cobro/Pago/Gasto/OtroIngreso), cuando exista. Null en movimientos nativos |
| usuario_id | FK → usuarios, nullable | quién registró el movimiento |
| deleted_at | timestamp, nullable | **SoftDeletes** (principio III — movimientos con impacto contable) |

Índices: `index(cuenta_tesoreria_id, fecha, id)` (para el balance corrido y saldos por fecha),
`index(tipo)`, `index(transferencia_id)`, `index(['origen_type','origen_id'])`.

**Reglas / métodos de dominio**:
- `origen()`: `morphTo()`.
- `cuenta()`: `belongsTo(CuentaTesoreria)`.
- `scopeHastaFecha($q, $fecha)`: `where('fecha','<=',$fecha)`.
- `scopeDelTipo($q, $tipo)`.
- Accesores de presentación: `ingreso` = `monto > 0 ? monto : null`; `egreso` = `monto < 0 ? -monto : null`.
- `esNativo()` = `in_array($tipo, ['saldo_inicial','movimiento_entre_cuentas'])` — sólo los nativos se
  borran físicamente desde la ficha; los de origen documental se soft-deletean con su documento.

## Enum `tipo` de cuenta ↔ bloques de la vista Saldos

| tipo cuenta | Bloque Saldos | Columna |
|---|---|---|
| `a_cobrar` | A Cobrar (verde) | — |
| `a_pagar` | A Pagar (rojo) | — |
| `efectivo` | Disponible (celeste) | Cajas |
| `banco` | Disponible (celeste) | Bancos |

## Cálculos clave (todos derivados)

- **Saldo de una cuenta a fecha F**: `SUM(monto) WHERE cuenta_tesoreria_id = C AND fecha <= F`.
- **Total A Cobrar / A Pagar / Cajas / Bancos**: suma de los saldos de las cuentas visibles de cada
  tipo. **Total Disponible** = Total Cajas + Total Bancos.
- **Balance corrido (ficha)**: `SUM(monto) OVER (PARTITION BY cuenta_tesoreria_id ORDER BY fecha, id)`.
- **Informe Movimientos (flujo)** en rango [D, H]:
  - Total Cobros = `SUM(monto)` de movimientos con `tipo IN (cobro)` + Otros Ingresos, `monto > 0`,
    `fecha BETWEEN D AND H`, por cuenta (checkbox "Activo" filtra cuentas del total).
  - Total Pagos = `SUM(-monto)` de `tipo IN (pago, gasto)` en el rango, **excluyendo gastos pendientes**
    (los gastos pendientes no generan movimiento de tesorería hasta pagarse — FR-028).
  - Resultado = Total Cobros − Total Pagos.
  - Nota: mientras Ventas/Compras/Gastos no existan, estos totales reflejan sólo lo que haya (típicamente
    0 en cobros/pagos), lo cual es correcto.

## Seed inicial (`CuentasTesoreriaSeeder`)

Del relevamiento (informe §2-3). Cuentas del sistema primero:

| nombre | tipo | es_sistema | visible |
|---|---|---|---|
| Cheque de Terceros | a_cobrar | ✅ | ✅ |
| Cheque Propio | a_pagar | ✅ | ✅ |
| Caja del Local | efectivo | — | ✅ |
| Caja General | efectivo | — | ✅ |
| Banco Galicia | banco | — | ✅ |
| Banco Santander Río | banco | — | ✅ |
| Mercado Pago | banco | — | ✅ |
| AMEX | a_cobrar | — | ✅ |
| VISA | a_cobrar | — | ✅ |
| VISA Corporativa | a_pagar | — | ✅ |

Saldos iniciales en 0 por defecto (el usuario los ajusta); opcionalmente un seeder demo con saldos del
relevamiento para desarrollo. El nombre/tipo de "Mercado Pago" como banco sigue el bloque "Bancos" del
relevamiento (§2.3).

## Relaciones (resumen)

```
cuentas_tesoreria 1───N movimientos_tesoreria (cuenta_tesoreria_id)
movimientos_tesoreria N───1 origen (morphTo: Cobro/Pago/Gasto/OtroIngreso — futuros; null en nativos)
movimientos_tesoreria (transferencia_id) agrupa 2 filas de una transferencia
usuarios 1───N movimientos_tesoreria (usuario_id, quién registró)
```

## Actualización de documentación de dominio (al cierre — principio I)

- `docs/modelo_datos.md`: mover `cuentas_tesoreria` y `movimientos_tesoreria` de §6 (descartadas) a una
  sección propia implementada, con el esquema de arriba.
- `docs/documentacion_principal_crm.md`: agregar sección "Módulo Tesorería" con las 3 vistas (Saldos,
  Movimientos, Ficha) + configuración de cuentas + transferencias.
