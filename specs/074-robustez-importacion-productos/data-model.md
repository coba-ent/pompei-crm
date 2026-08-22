# Data Model: Robustez del importador de Productos

**Feature**: 074-robustez-importacion-productos | **Fecha**: 2026-08-22

La feature introduce **un solo cambio de esquema**. El resto es comportamiento sobre tablas existentes.

---

## 1. Cambio de esquema

### `logs_auditoria.tipo_operacion` — nuevo valor de enum

| | Antes | Después |
|---|---|---|
| Valores | `venta`, `presupuesto`, `cobro`, `gasto`, `compra`, `movimiento_tesoreria`, `movimiento_stock` | los anteriores **+ `precio_producto`** |

**La migración tiene dos partes obligatorias.** Con una sola, algo se rompe:

1. **Migración nueva — `ALTER TABLE ... MODIFY`, sólo bajo MySQL.** Repitiendo el enum completo en
   `up()` y en `down()`, envuelto en `if (DB::getDriverName() === 'mysql')`. Es sintaxis exclusiva de
   MySQL: sin el guard, revienta en la suite de tests (que corre en SQLite) y tira abajo todo.
2. **Migración original `create_logs_auditoria_table` — agregar el valor a la lista del
   `$table->enum(...)`.** En SQLite el `enum()` se materializa como `varchar` + `CHECK (... IN (...))`;
   como el guard del punto 1 saltea el `ALTER`, la base de tests nunca aprendería el valor nuevo y toda
   inserción de un evento de precio violaría el `CHECK`.

Patrón ya establecido en el proyecto — es exactamente lo que se hizo para `ventas.origen`:
`2026_08_09_060006_add_tiendanube_to_origen_enum_ventas_table.php` (ALTER con guard) +
`2026_08_02_060005_add_origen_to_ventas_table.php` editada retroactivamente para incluir `tiendanube`.

`down()` sólo puede revertir el enum si no quedan filas con el valor nuevo; la migración de reversa debe
borrar (o rechazar) esas filas explícitamente en vez de fallar con un error críptico de MySQL.

> **Principio I**: este cambio obliga a actualizar `docs/modelo_datos.md` §`logs_auditoria`, cuya tabla
> de campos enumera hoy los valores viejos, y `docs/documentacion_principal_crm.md`.

**Nada más cambia de esquema.** En particular:

- `precios_producto` no gana columnas: el historial vive en `logs_auditoria`, no en la tabla de precios.
- `stocks` y `movimientos_stock` quedan idénticos: la corrección de concurrencia es de control de acceso
  (transacción + lock), no de modelo de datos.

---

## 2. Forma del evento de auditoría de precio

Cómo se completa una fila de `logs_auditoria` para un cambio de precio (contrato detallado en
[contracts/auditoria-precio-producto.md](./contracts/auditoria-precio-producto.md)):

| Campo | Valor |
|---|---|
| `usuario_id` | usuario autenticado; `null` si no hay (comando, integración) |
| `usuario_nombre` | nombre del usuario congelado al momento del evento, o `'Sistema'` |
| `origen_sistema` | `null` — **no se reutiliza** para el origen del cambio de precio (está reservado para acciones sin usuario humano: `mercadolibre` / `tiendanube`) |
| `tipo_accion` | `creo` \| `modifico` \| `elimino` |
| `tipo_operacion` | `precio_producto` |
| `entidad_tipo` | `App\Models\Producto` |
| `entidad_id` | `producto_id` (**no** el id de la fila de `precios_producto`) — ver research.md D8 |
| `detalle` | `"{Producto} — {Lista}: {anterior} → {nuevo} ({origen})"`, recortado a 255 caracteres |
| `total` | precio nuevo; `null` cuando la acción es `elimino` |
| `created_at` | momento del evento |

### Ejemplos de `detalle`

```text
Caño PVC 110mm — Mayorista: $ 7.137,04 → $ 8.564,45 (importación)
Caño PVC 110mm — Mayorista: sin precio → $ 8.564,45 (edición masiva)
Caño PVC 110mm — Minorista: $ 9.100,00 → sin precio (edición manual)
```

### Truncado

`detalle` es `string(255)`. Un nombre de producto largo (hasta 255 por sí solo) puede desbordar. Regla:
se recorta **el nombre del producto**, nunca los importes ni el rótulo de origen, que son la información
sustantiva del registro.

---

## 3. Entidades y reglas de negocio (sin cambio de esquema)

### `precios_producto`

Sin cambios estructurales: `producto_id` (FK), `lista_precio_id` (FK), `precio` `decimal(14,2)` ≥ 0,
único por `(producto_id, lista_precio_id)`.

**Reglas nuevas de comportamiento**:

- Toda escritura vía modelo (`create`, `update`, `updateOrCreate`, `delete`) genera un evento de
  auditoría, **salvo** que el precio guardado sea igual al anterior (FR-010).
- La comparación "¿cambió?" se hace sobre el valor normalizado a 2 decimales, para que `100` y `100.00`
  no cuenten como cambio.
- El borrado de precios pasa a hacerse **por modelo**, no por `whereNotIn(...)->delete()`, para que el
  evento `deleted` se dispare (research.md D5).

**Excepción documentada (FR-009a)**: `MigrarPuntoReposicion` borra precios con
`DB::table('precios_producto')->...->delete()`. Al no pasar por el modelo, no dispara eventos y **no
queda auditado**. Es un comando de migración de única vez; la excepción se documenta en
`docs/documentacion_principal_crm.md` en lugar de asumirse cubierta.

### `stocks` / `movimientos_stock`

Sin cambios estructurales.

**Regla nueva de comportamiento**: fijar el stock a un valor absoluto (el caso del importador) es una
operación **atómica**: la lectura de la cantidad actual, el cálculo de la diferencia y la escritura del
movimiento ocurren dentro de una única transacción que mantiene bloqueada la fila de `stocks`
correspondiente a `(producto_id, variante_id, deposito_id)`.

**Invariantes preservados**:

- El `MovimientoStock` resultante mantiene `tipo = 'ajuste'` y las descripciones actuales:
  `'Ajuste (importación)'` en actualizaciones y `'Registro inicial (importación)'` en altas.
- Si la cantidad deseada iguala a la actual, no se escribe ninguna fila en `movimientos_stock`.
- Los productos que no controlan stock (servicios) siguen fuera de este circuito.
- La suma del histórico de `movimientos_stock` sigue reconciliando con `stocks.cantidad`.

### `logs_auditoria`

Sin cambios más allá del valor de enum. Se mantienen todas sus propiedades vigentes: append-only, sin
`updated_at`, sin UPDATE ni DELETE expuestos desde la aplicación, retención indefinida, acceso por el
permiso `auditoria.ver`.

**Volumen esperado**: es el impacto más significativo de la feature sobre esta tabla. Una importación de
5.000 productos con 3 listas de precio activas puede generar del orden de 15.000 filas nuevas en una sola
corrida, contra el volumen actual de la tabla que proviene de operaciones de a una. Los índices vigentes
—`(created_at)`, `(tipo_operacion)`, `(entidad_tipo, entidad_id)`— cubren las consultas de la pantalla y
del historial por producto; **no se agregan índices nuevos**.
