# Quickstart — Validación del vuelto en cobranzas (spec 110)

**Fecha**: 2026-09-24

⚠️ **Leer primero**: la suite corre en **SQLite** y producción es **MySQL**. SQLite **no valida
ENUMs**, así que los tests en verde **no prueban** que la migración del tipo `vuelto` funcione. La
validación en navegador contra MySQL local es **obligatoria**, no opcional.

## Prerequisitos

```bash
# MySQL de XAMPP levantado (la base local `contagram` tiene datos reales de referencia)
/c/xampp/mysql/bin/mysqld.exe --standalone

php artisan migrate
npm run build
```

## Paso 1 — Tests automáticos (cubre la lógica, NO el ENUM)

```bash
php artisan test --filter=Cobranzas
php artisan test --filter=VuletoCobranza   # tests nuevos de esta spec
```

Cubren: cálculo del neto, las validaciones de FR-006/007/011, la atomicidad de los dos movimientos,
y editar/anular. **No** cubren: que MySQL acepte `tipo='vuelto'`.

## Paso 2 — Verificar el ENUM contra MySQL (imprescindible)

```bash
/c/xampp/mysql/bin/mysql.exe -u root contagram -N -B \
  -e "SHOW COLUMNS FROM movimientos_tesoreria LIKE 'tipo'"
```

Esperado: el ENUM debe incluir `'vuelto'`. Si no aparece, la migración no corrió y todo lo demás va
a fallar en producción aunque los tests estén verdes.

## Paso 3 — Validación funcional en navegador

Credenciales en `CREDENCIALES_ACCESO.txt`.

### 3.1 Configurar la caja de vuelto (US2)

1. Configuración & Ajustes → Ventas
2. Elegir la cuenta por defecto para vueltos (ej. "Caja Local") y guardar
3. **Esperado**: guarda con toast de éxito, sin recargar la página

### 3.2 Cobranza con vuelto (US1 — el caso del cliente)

1. Abrir una venta con saldo pendiente. **Anotar el saldo exacto.**
2. Cobranza nueva. Verificar que la cuenta de vuelto viene **preseleccionada** con la del paso 3.1
3. Cargar: `monto` = saldo + 15.000, `vuelto` = 15.000
4. Guardar

**Esperado**:

- Toast de éxito, sin recargar
- La venta queda en **$0 / "Cobrada"**
- La ficha muestra el vuelto junto a la cobranza (FR-015)

### 3.3 Verificar los dos movimientos (el corazón de la spec)

```bash
/c/xampp/mysql/bin/mysql.exe -u root contagram -N -B -e \
 "SELECT tipo, cuenta_tesoreria_id, monto FROM movimientos_tesoreria
  WHERE origen_type='App\\\\Models\\\\Cobro' AND origen_id=<ID_DEL_COBRO> AND deleted_at IS NULL"
```

**Esperado**: exactamente 2 filas —

| tipo | monto |
|---|---|
| `cobro` | **+**(saldo + 15000) |
| `vuelto` | **−**15000 |

Si el vuelto sale positivo, el saldo de la caja queda inflado (`research.md` Decisión 2).

### 3.4 El vuelto NO es un gasto (FR-005, SC-002)

1. Informes → Gastos, filtrando el día de la prueba
2. **Esperado**: el vuelto **no aparece**
3. Tesorería → ledger de la cuenta de vuelto
4. **Esperado**: aparece etiquetado **"Vuelto"**, no en blanco ni como "Gasto"

### 3.5 Recibo (FR-016)

1. Menú de la cobranza → Recibo
2. **Esperado**: abre en el modal PDF (no en pestaña nueva) y muestra **recibido, vuelto y neto**

### 3.6 Validaciones que deben rechazar

| Caso | Esperado |
|---|---|
| `vuelto` ≥ `monto` | Error en `vuelto`, no guarda |
| `vuelto` > 0 sin cuenta | Error en `cuenta_vuelto_id` |
| Neto **menor** al saldo (ej. venta 140.000, monto 155.000, vuelto 30.000) | Error: el neto debe igualar el saldo (FR-007) |
| Neto **mayor** al saldo | Error: no se habilitan sobrepagos |

### 3.7 Editar y anular (US3 — el riesgo de saldo fantasma)

1. Editar la cobranza del 3.2 cambiando monto y vuelto → **ambos** movimientos reflejan lo nuevo
2. Quitar el vuelto (dejarlo en 0) → el movimiento `vuelto` desaparece, queda sólo el de cobro
3. **Anular** la cobranza y repetir la consulta del 3.3

**Esperado del paso 3**: **0 filas**. Si queda alguna viva, la relación `morphOne` no se filtró por
tipo y hay saldo fantasma en la cuenta (`data-model.md` §4). Es el bug más grave que puede
introducir esta spec.

## Paso 4 — No romper lo existente (FR-014, SC-006)

### 4.1 Cobranza normal, sin vuelto

Cargar una cobranza dejando el vuelto vacío. **Esperado**: un solo movimiento (`tipo='cobro'`),
idéntico a antes.

### 4.2 Los saldos históricos no se movieron

Antes de migrar, tomar la línea de base:

```bash
/c/xampp/mysql/bin/mysql.exe -u root contagram -N -B -e \
 "SELECT ROUND(SUM(monto),2) FROM movimientos_tesoreria WHERE deleted_at IS NULL"
```

Repetir después de migrar (antes de cargar cobranzas de prueba). **Esperado**: el mismo número. La
migración es aditiva y no debe mover ni un peso.

## Paso 5 — Producción

Seguir el procedimiento de deploy del proyecto. Específico de esta spec:

- **Backup antes de migrar** (`mysqldump`): hay un `ALTER TABLE` sobre `movimientos_tesoreria`
  (48.656 filas)
- `php artisan migrate:status | grep -i pending` antes de migrar, para ver exactamente qué corre
- **Nunca probar en producción**: la validación post-deploy es de sólo lectura (verificar el ENUM y
  que el total de movimientos no cambió). Las pruebas funcionales van en local.
