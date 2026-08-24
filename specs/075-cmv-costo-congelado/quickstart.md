# Quickstart / validación: CMV con costo congelado (spec 075)

**Fecha**: 2026-08-24

Cómo verificar que la feature quedó bien. Las validaciones manuales van en el navegador porque, como
está documentado en `docs/`, la suite verde no garantiza nada en MySQL estricto (`ONLY_FULL_GROUP_BY`)
— los tests corren en SQLite.

---

## Prerrequisitos

- XAMPP corriendo, base `contagram_migracion` (o la que indique `.env`).
- Migración aplicada: `php artisan migrate`.
- Al menos un producto con costo cargado y un proveedor asociado.

---

## Escenario 1 — El costo se congela y no se mueve (US1, SC-002)

Es la validación central. Si esto falla, la feature no sirve.

1. Elegí un producto y anotá su costo actual (ej. $1.000).
2. Creá una venta con **3 unidades** de ese producto.
3. Abrí **Informes → Ventas** con un rango que incluya hoy. Anotá el CMV.
   - **Esperado**: la línea aporta $3.000 al CMV.
4. Andá a **Base de Datos → Productos** y cambiá el costo de ese producto a **$1.200**.
5. Volvé al informe **con el mismo rango** y refrescá.
   - **Esperado**: el CMV de esa línea **sigue en $3.000**.
   - **Esperado**: su "Costo Actual" pasó a **$3.600**.
   - Que las dos columnas difieran es correcto y es la razón de existir de ambas (FR-006).

---

## Escenario 2 — Cero regresión en lo histórico (US2, SC-003)

**Hacer ANTES de aplicar la migración**: abrir el Informe de Ventas para **julio 2026** y anotar
`Costo Mercadería Vendida` y `Resultado`.

Después de migrar y desplegar, abrir el mismo período.

- **Esperado**: los dos KPIs dan **exactamente lo mismo**. Ninguna venta histórica tiene costo
  congelado, así que todas caen al fallback de promedio de compras.
- Si el CMV se desploma a cerca de 0, el bug es el de `data-model.md §1`: la columna se creó con
  `default 0` en vez de nullable.

---

## Escenario 3 — Editar una venta vieja no mueve el Resultado (FR-009)

1. Tomá una venta creada después de la feature y anotá el CMV de su período.
2. Cambiá el costo del producto que vendió.
3. Editá la venta modificando **sólo la nota interna** y guardá.
4. Volvé al informe.
   - **Esperado**: el CMV **no cambió**. El `delete()` + recreación de ítems conservó el costo.
   - Este es el escenario que más fácil se rompe: ver `research.md §R5`.
5. Ahora editá la venta **agregando una línea nueva** de ese mismo producto.
   - **Esperado**: las líneas originales conservan el costo viejo y **sólo la nueva** toma el costo
     actualizado.

---

## Escenario 4 — Producto sin costo (FR-007, invariante I2)

1. Tomá un producto con costo 0 (hay 227 en la base) que **sí tenga compras registradas**.
2. Vendelo.
   - **Esperado**: la línea aporta **0** al CMV.
   - **Si aporta el promedio de compras, el bug es I2**: alguien escribió `NULLIF(costo_unitario, 0)`
     y el 0 congelado se está confundiendo con "sin congelar".

---

## Escenario 5 — Nota de crédito (FR-008)

1. Tomá una venta **nueva** (con costo congelado) y emitile una NC total con detalle de ítems.
2. Abrí el informe con un rango que incluya la venta y la NC.
   - **Esperado**: la NC aporta CMV **negativo** por el mismo importe que la venta aportó en positivo.
   - **Esperado**: el `Resultado` neto de las dos operaciones juntas es **0**.
3. Emití una NC **sin venta asociada** con una línea de producto.
   - **Esperado**: congela el costo vigente del producto al emitir; no falla por no encontrar venta.

---

## Escenario 6 — Todos los canales congelan (SC-004)

Verificar que una venta creada por **cada** vía queda con `costo_unitario` poblado en sus líneas con
producto:

```sql
SELECT v.origen, COUNT(*) AS lineas, SUM(vi.costo_unitario IS NULL) AS sin_congelar
  FROM venta_items vi
  JOIN ventas v ON v.id = vi.venta_id
 WHERE v.created_at >= '<fecha de despliegue>'
   AND vi.producto_id IS NOT NULL
 GROUP BY v.origen;
```

- **Esperado**: `sin_congelar = 0` en todas las filas (`manual`, `presupuesto`, `mercadolibre`,
  `tiendanube`).
- Si `mercadolibre` o `tiendanube` quedan sin congelar, faltó tocar su `ConversorOrdenAVenta`
  (`research.md §R4`).

---

## Escenario 7 — Contraste final contra Contagram (SC-001)

Válido sólo para un período compuesto **íntegramente** por ventas posteriores al despliegue —
mientras convivan históricas y nuevas, el número mezcla dos criterios a propósito.

1. Exportá de Contagram el "Informe de Ventas Detallado" del período.
2. Compará `SUM(CMV Total)` del export contra la card del CRM.
   - **Esperado**: diferencia menor al 0,1%.
   - Referencia de la línea de base rota: en julio 2026 la diferencia era del **39%** ($24,6M contra
     $40,57M).

---

## Regresión automatizada

```bash
php artisan test --filter=Cmv
php artisan test --filter=InformeVentas
```

Y la suite completa antes de deployar, porque `VentasInformeQuery` lo comparten el Informe de Ventas,
Rankings y "Arma tu Informe":

```bash
php artisan test
```
