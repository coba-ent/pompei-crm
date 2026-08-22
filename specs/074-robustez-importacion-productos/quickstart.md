# Quickstart / Validación: Robustez del importador de Productos

**Feature**: 074-robustez-importacion-productos | **Fecha**: 2026-08-22

Guía para validar que la feature funciona de punta a punta. No incluye código de implementación — eso va
en `tasks.md` y en la fase de implementación.

---

## Prerequisitos

- XAMPP corriendo (MySQL local, `root` sin password), base `contagram`.
- Migraciones al día: `php artisan migrate`.
- Credenciales de acceso vigentes en `CREDENCIALES_ACCESO.txt`.
- Al menos **2 listas de precio activas** y **2 depósitos activos** cargados (Configuración & Ajustes),
  para que el importador exponga las columnas dinámicas correspondientes.
- Usuario con el permiso `auditoria.ver` (rol Admin por defecto).

---

## A. Validación automatizada

```bash
# Suite completa
php artisan test

# Sólo lo que toca esta feature
php artisan test --filter="StockFijar|AuditoriaPrecio|ImportacionProductos"
```

Cobertura mínima esperada (Principio IV — la feature toca stock y precios, ambas áreas de testing
obligatorio):

| Área | Verifica | Referencia |
|---|---|---|
| Stock atómico | CV-1 a CV-5 | [contracts/stock-service-fijar.md](./contracts/stock-service-fijar.md) |
| Auditoría de precios | CV-1 a CV-9 | [contracts/auditoria-precio-producto.md](./contracts/auditoria-precio-producto.md) |

> **Ojo con la suite en SQLite**: el proyecto ya tiene registrado que una suite verde en SQLite no
> garantiza el comportamiento en MySQL (`ONLY_FULL_GROUP_BY`, barras invertidas en morphs). El
> comportamiento de `lockForUpdate()` es justamente uno de los que **no** se puede validar en SQLite: el
> test de concurrencia real (CV-4) debe correrse contra MySQL, o documentarse explícitamente como
> verificado a mano según la sección C.

---

## B. Validación manual — auditoría de precios

Para cada uno de los cuatro orígenes, el resultado esperado es el mismo: entrar a **Informes →
Auditoría**, filtrar por la operación **"Precio de producto"** del día, y encontrar el evento con el
precio anterior, el nuevo y el rótulo de origen correcto.

| # | Origen | Cómo dispararlo | Rótulo esperado en el detalle |
|---|---|---|---|
| B1 | Importación | Exportar Productos → cambiar el precio de una lista en el Excel → reimportar mapeando **Id** + esa columna de lista | `(importación)` |
| B2 | Edición manual | Abrir la ficha de un producto → cambiar el precio de una lista → Guardar | `(edición manual)` |
| B3 | Edición masiva | Listado de Productos → seleccionar productos → acción **Modificar Precio de Venta** → aumentar un % sobre una lista | `(edición masiva)` |
| B4 | Copia | Ficha de un producto → **Crear Copia** | `(copia de producto)` |
| B5 | Borrado | Ficha de un producto que tiene precio en 2 listas → guardar dejando una vacía | acción `Eliminó`, con el valor que tenía |

**B6 — Reimportación sin cambios (el caso que evita el ruido)**: exportar Productos y reimportar el
archivo **sin editarlo**, mapeando Id + las columnas de precio.
→ **Esperado: cero eventos nuevos** en Auditoría y **cero movimientos de stock** nuevos. Si aparecen
eventos, la comparación decimal de `wasChanged()` está mal (ver contrato §2).

**B7 — La auditoría no gatea**: forzar transitoriamente un fallo en la escritura de auditoría (p. ej.
renombrando la tabla `logs_auditoria` en una base de prueba) y repetir B1.
→ **Esperado**: la importación termina bien, los precios quedan guardados, y el fallo aparece en
`storage/logs/laravel.log`. Restaurar la tabla al terminar.

**B8 — Las integraciones siguen andando**: con Mercado Libre o Tiendanube configurados sobre una lista,
cambiar el precio de un producto vinculado en esa lista.
→ **Esperado**: se sigue disparando la sincronización de precio igual que antes (FR-017), además del
nuevo evento de auditoría.

---

## C. Validación manual — stock concurrente

Es el escenario que motivó FR-001 y el que ninguna prueba de un solo hilo detecta.

**C1 — Camino feliz**: producto con 10 unidades en un depósito. Importar una planilla que trae 50 para
ese producto/depósito.
→ **Esperado**: stock final 50, un movimiento de ajuste `+40` con descripción `Ajuste (importación)`.

**C2 — Sin diferencia**: reimportar la misma planilla.
→ **Esperado**: stock sigue en 50, **ningún movimiento nuevo**.

**C3 — Concurrencia real** (el importante). Con la importación de un archivo grande corriendo, registrar
en paralelo (otra pestaña/sesión) una venta de un producto que la planilla también toca.
→ **Esperado**: la cantidad final corresponde a una ejecución secuencial de ambas operaciones (según cuál
commiteó primero), **ambos movimientos aparecen en el histórico**, y la suma del histórico reconcilia
con la cantidad actual. Lo que se está verificando es que **la venta no desapareció**.

**Verificación de reconciliación** (para C1-C3), sobre el producto/depósito usado:

```sql
SELECT s.cantidad AS foto,
       (SELECT COALESCE(SUM(m.cantidad), 0)
          FROM movimientos_stock m
         WHERE m.producto_id = s.producto_id
           AND m.deposito_id = s.deposito_id
           AND (m.variante_id <=> s.variante_id)) AS suma_historico
  FROM stocks s
 WHERE s.producto_id = ? AND s.deposito_id = ?;
```

→ **Esperado**: `foto` = `suma_historico`. Si difieren, hubo un lost update.

---

## D. Validación de performance (SC-005)

Importar una planilla de **1.000 filas** con todas las columnas de lista de precio mapeadas y precios
efectivamente modificados (peor caso de auditoría).

→ **Esperado**: cada tanda del asistente completa sin corte por tiempo de espera, y el resumen final
reporta las 1.000 filas. Comparar el tiempo total contra una corrida equivalente previa a la feature: el
overhead atribuible a la auditoría debe ser marginal gracias al registro en lote (contrato §6).

Verificación de que el lote realmente se agrupó: durante la importación, el crecimiento de
`logs_auditoria` debe darse a saltos (de a ~200 filas), no de a una.

### Medición registrada (T002 / T037) — 22/08/2026

Banco de prueba automatizado: 1.000 productos existentes, todos con la columna de lista de precios
mapeada y **un precio distinto en cada fila** (peor caso: 1.000 eventos de auditoría, ninguno
descartado por FR-010). Misma máquina, misma planilla, SQLite en memoria.

| Corrida | Tiempo | Eventos de auditoría |
|---|---|---|
| **Antes** de la feature (T002, con el código en `stash`) | 5,46 s | 0 |
| **Después** de la feature (T037) | 5,23 s | 1.000 |

→ **SC-005 cumplido**: el overhead de la auditoría es indistinguible del ruido de medición (la corrida
posterior incluso dio algo más rápida). Las 1.000 filas se importan completas, sin fallidos.

Agrupación del lote verificada además de forma automatizada, que es más fuerte que mirar crecer la
tabla: `AuditoriaPrecioProductoTest::test_el_importador_agrupa_los_eventos_en_lote` cuenta las
sentencias `INSERT` contra `logs_auditoria` con `DB::listen()` y exige **1 sola** para los 5 eventos de
la tanda (sin buffer serían 5).

---

## E. Checklist de cierre

- [ ] `php artisan test` en verde.
- [ ] B1-B5: los cuatro orígenes + el borrado quedan auditados con su rótulo correcto.
- [ ] B6: reimportación idéntica genera cero eventos y cero movimientos.
- [ ] B7: un fallo de auditoría no rompe la importación.
- [ ] B8: la sincronización con ML/Tiendanube sigue funcionando.
- [ ] C1-C3: reconciliación `foto` = `suma_historico` en los tres casos.
- [ ] D: tanda de 1.000 filas dentro del margen de tiempo.
- [ ] `docs/modelo_datos.md` y `docs/documentacion_principal_crm.md` actualizados (Principio I),
      incluyendo la excepción de FR-009a.
- [ ] `CREDENCIALES_ACCESO.txt` actualizado si se creó o cambió algún acceso durante las pruebas.


---

## F. Validación en local contra la base real (22/08/2026)

Ejecutada sobre `contagram_p074`, **clon de la base local de migración** (9.632 productos, 100.975
precios, 23.788 ventas). La base real `contagram_migracion` no se tocó: quedó sin migrar y con sus
contadores idénticos.

### F1. Concurrencia real — SC-003 / §C3 (lo que el test automatizado no puede probar)

Dos conexiones simultáneas contra MySQL: una "atacante" toma `SELECT ... FOR UPDATE` sobre la fila de
`stocks` y la retiene 5 s; la otra intenta operar.

| Operación | Tardó | Lectura |
|---|---|---|
| `disponibilidad()` — el lector viejo | **0 s** | atravesó el lock: **ésta es la ventana del bug** |
| `fijar()` — el arreglo | **4,36 s** | esperó a que el lock se liberara |

Resultado: stock quedó en **50** (el valor absoluto pedido) y `foto = suma_histórico`. **SC-003
verificado en MySQL real.**

### F2. Round-trip completo: exportar → reimportar sin modificar

Exportación real por el botón de la app (9.187 filas × 25 columnas), reimportada por el asistente
**sin tocar el mapeo**.

| | Antes de los fixes | Después |
|---|---|---|
| Filas importadas | 9.118 | **9.187** |
| Filas fallidas | 68 (stock negativo) | **0** |
| Columnas de stock automapeadas | no (manual) | **sí** |
| Eventos de auditoría generados | 0 | **0** |
| Movimientos de stock generados | 0 | **0** |
| `productos` / `suma stock` | sin cambios | sin cambios |

Reimportar una exportación propia no produce **ningún** efecto colateral.

### F3. Los cuatro orígenes de precio, en MySQL

Ejercitados por navegador sobre la base real y contados en `logs_auditoria`:

| Origen | Eventos | Rótulo en el detalle |
|---|---|---|
| Importación | 27 | `(importación)` |
| Edición manual (ficha) | 11 | `(edición manual)` |
| Edición masiva | 1 | `(edición masiva)` |
| Copia de producto | 1 | `(copia de producto)` |
| **Sin origen declarado** | **0** | — |

Los 11 de edición manual incluyen **10 eventos `elimino`**, que confirman el cambio a borrado por
modelo: con el `delete()` de query builder anterior esos borrados no disparaban ningún evento.

### F4. Casos dirigidos

Planilla de 15 filas armada desde la exportación real: cambio de precio en una lista y en dos a la vez,
fila intacta, mismo precio con más decimales, stock que sube/baja/queda igual, alta con Id inexistente,
fila sin nombre, Id no numérico, precio no numérico, y `Stock Total` incoherente. Resumen del asistente:
**12 importados / 3 no importadas (con motivo por fila) / 1 advertencia**, y cada evento y movimiento
exactamente donde se esperaba. La reconciliación `foto = suma_histórico` dio OK.
