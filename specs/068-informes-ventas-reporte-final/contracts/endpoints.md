# Phase 1 — Contrato de endpoints: Informes Tanda 2

Todas las rutas van dentro del grupo autenticado y bajo `middleware('permiso:informes.ver')`, igual
que las de la Tanda 1. Los endpoints de datos devuelven JSON; los de PDF devuelven
`Content-Disposition: inline`.

## Parámetros comunes

| Parámetro | Tipo | Notas |
|-----------|------|-------|
| `desde` | `YYYY-MM-DD` | inicio del rango de emisión; por defecto el día 1 del mes actual |
| `hasta` | `YYYY-MM-DD` | fin del rango; por defecto el último día del mes actual |

Si `hasta < desde` o alguna fecha es inválida → `422` con `{ "message": "..." }`, que el front
muestra por Toastr. Mismo comportamiento que `InformeComprasController::rangoInvalido()`.

---

## Informe de Ventas

### `GET /informes/ventas` → `informes.ventas.index`

Devuelve la vista con los catálogos precargados para los Select2: clientes, productos, tipos de
producto, proveedores, categorías de venta, vendedores, etiquetas, usuarios, transportistas.

### `GET /informes/ventas/data` → `informes.ventas.data`

Endpoint server-side de DataTables (`draw`, `start`, `length`, `order`, `search` estándar) más los
filtros del panel:

`id`, `producto_id[]`, `tipo_producto_id[]`, `cliente_id[]`, `solo_productos`, `facturado`,
`vendedor_id[]`, `categoria_id[]`, `proveedor_id[]`, `etiqueta_id[]`, `tipo_comprobante`,
`nro_comprobante`, `usuario_id[]`, `nota_cliente`, `nota_interna`, `estado_cobro`,
`tipo_operacion[]`, `remitos`, `tipo_remito`, `nro_remito`, `transportista_id[]`.

Reglas: AND entre campos distintos, OR dentro de un mismo campo multi-valor. Orden por defecto
`fecha desc, id desc`.

**Respuesta** (`data[]`): `id`, `tipo_operacion`, `fecha`, `comprobante`, `cliente`, `producto`,
`cantidad`, `precio_unitario`, `costo_total_actual`, `cmv_total`, `precio_neto`, `resultado`,
`total_comprobante`.

### `GET /informes/ventas/stats` → `informes.ventas.stats`

Mismos filtros que `data`, sin paginación. Devuelve los 11 KPIs sobre el conjunto filtrado completo:

```json
{
  "total_ventas_creadas": 0, "total_nota_debito": 0, "total_nota_credito": 0, "total_ventas": 0,
  "cantidad_prod_serv": 0, "cantidad_ventas_creadas": 0, "venta_promedio": 0, "costo_actual": 0,
  "precio_neto": 0, "cmv": 0, "resultado": 0
}
```

### `GET /informes/ventas/exportar` → `informes.ventas.exportar`

Mismos filtros. Descarga `Informe de Ventas Resumen <d-m-Y Hi> Hs.xlsx` con dos hojas:

1. **"Informe de Ventas Resumen"** (legible): los 3 bloques de KPIs y luego el detalle con las
   columnas del export de Contagram — Id · Emisión · Cliente · Tipo de Comprobante ·
   Producto/Servicio · Cantidad · Precio Unitario · Costo Total Actual · CMV Total · Precio de Venta
   · Resultado · Total Venta. **Aquí vive la réplica R1**: en las filas de NC, `Resultado` se
   escribe como `Precio de Venta + CMV Total`.
2. **"Ventas"** (plana): una fila por ítem, sin KPIs ni secciones, con `Resultado` correcto
   (`Precio − CMV`) en todas las filas y una columna `Tipo de Operación`.

### `GET /informes/ventas/pdf` → `informes.ventas.pdf`

Mismos filtros. PDF `inline` con KPIs y detalle, para el modal compartido (`window.AppPdf.abrir`).

---

## Reporte Final

### `GET /informes/reporte-final` → `informes.reporte-final.index`

Vista con las dos pestañas y el rango por defecto.

### `GET /informes/reporte-final/data` → `informes.reporte-final.data`

| Parámetro | Valores |
|-----------|---------|
| `vista` | `devengado` (Ventas Vs. Compras, por defecto) \| `caja` (Cobros Vs Pagos) |

**Respuesta**: árbol completo ya agregado, con montos **siempre positivos** y `naturaleza` por nodo.

```json
{
  "desde": "2026-06-12", "hasta": "2026-08-14",
  "totales": { "ingresos": 46485.35, "egresos": 14157.45, "resultado": 32327.90 },
  "bloques": [
    {
      "clave": "ventas", "etiqueta": "Ventas", "naturaleza": "ingreso", "total": 16135.35,
      "categorias": [
        { "id": 3, "etiqueta": "Online", "monto": 1893.65, "activo": true, "hijos": [] }
      ]
    }
  ]
}
```

En la vista `caja`, cada categoría trae `hijos[]` con las cuentas de tesorería (y, en el bloque
Gastos, un nivel intermedio de subcategoría). El campo `activo` siempre llega en `true`: el estado
de la simulación es del cliente.

### `GET /informes/reporte-final/exportar` → `informes.reporte-final.exportar`

| Parámetro | Notas |
|-----------|-------|
| `vista` | igual que en `data` |
| `excluidas[]` | **claves** `bloque\|id` de las categorías destildadas (p. ej. `ventas\|3`); se excluyen del archivo (FR-006) |

> **Corrección aplicada al implementar**: originalmente este contrato decía "ids de categorías". No
> alcanza: el nodo "Sin categoría" no tiene id y habría quedado imposible de destildar, siendo que
> `ventas.categoria_id` y `compras.categoria_id` son nullable. Se identifica por la clave
> `bloque|id`, que además evita cualquier ambigüedad entre bloques. La clave viene en cada nodo de
> categoría de la respuesta de `data`.

Descarga `Informe Final <d-m-Y Hi> Hs.xlsx` con dos hojas:

1. **"Informe Final"** (legible): réplica exacta del layout de Contagram para la vista pedida —
   cabecera Desde/Hasta/Total Ingresos/Total Egresos/Resultado, bloques con encabezado
   "Descripción … Total", subtotales por bloque y totales generales. **Aquí vive la réplica R2**
   (signos y fórmula de Resultado según la vista).
2. **"Detalle"** (plana): una fila por combinación `vista · bloque · categoría · subcategoría ·
   cuenta de tesorería · naturaleza · monto`, todo en positivo.

### `GET /informes/reporte-final/pdf` → `informes.reporte-final.pdf`

Mismos parámetros que `exportar` (incluido `excluidas[]`). PDF `inline` del árbol de la vista
pedida, con los signos de pantalla (egresos en positivo).

---

## Contrato de UI

- Los dos informes se agregan como ítems del desplegable "Informes" del sidebar: **Ventas** (primero
  de la lista, como en Contagram) y **Reporte Final**.
- El selector "Emisión" usa `window.RangoEmision` sin modificarlo.
- Todo select de catálogo del panel de filtros usa Select2 con `width: '100%'`.
- Los PDFs se abren con `window.AppPdf.abrir(url, titulo)`; `window.open` sólo como fallback.
- Los errores `422` se muestran con Toastr.
