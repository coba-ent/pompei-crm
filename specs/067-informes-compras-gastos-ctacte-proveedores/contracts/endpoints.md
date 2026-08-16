# Contrato de endpoints — Módulo Informes, Tanda 1

**Feature**: `067-informes-compras-gastos-ctacte-proveedores`

Todas las rutas van dentro del grupo autenticado de `routes/web.php`, junto a las de
`informes/stock` e `informes/cuenta-corriente` ya existentes, y bajo el permiso `informes.ver`
(mismo `@can` que usa el sidebar hoy).

---

## 1. Informe de Compras

| Método | Ruta | Nombre | Devuelve |
|--------|------|--------|----------|
| GET | `/informes/compras` | `informes.compras.index` | Vista Blade (shell) |
| GET | `/informes/compras/data` | `informes.compras.data` | JSON DataTables server-side |
| GET | `/informes/compras/stats` | `informes.compras.stats` | JSON de KPIs |
| GET | `/informes/compras/exportar` | `informes.compras.exportar` | `.xlsx` (2 hojas), descarga |
| GET | `/informes/compras/pdf` | `informes.compras.pdf` | PDF `Content-Disposition: inline` |

### Parámetros de query (compartidos por `data`, `stats`, `exportar`, `pdf`)

```
fecha_desde        YYYY-MM-DD    (default: primer día del mes actual)
fecha_hasta        YYYY-MM-DD    (default: último día del mes actual)
id                 int
producto_servicio  string        (busca en la descripción del ítem)
tipo_producto_id   int[]
etiqueta_id        int[]
producto_id        int[]
facturado          si|no
categoria_id       int[]         (categorías de tipo `compra`)
proveedor_id       int[]
tipo_comprobante   string
nro_comprobante    string
usuario_id         int[]
observacion        string        (busca en nota_interna, "contiene")
estado_pago        a_pagar|parcial|pagado|vencido
```

Combinación: **AND entre campos distintos, OR dentro de un mismo campo multi-valor** (FR-020).

### `GET /informes/compras/stats` → 200

```json
{
  "total_compras_creadas": 2184.05,
  "total_nota_debito": 0.00,
  "total_nota_credito": 121.00,
  "total_compras": 2063.05,
  "cantidad_prod_serv": 32,
  "cantidad_compras_creadas": 3,
  "compra_promedio": 687.68,
  "costo_actual": 1980.00
}
```

Regla: `total_compras = total_compras_creadas + total_nota_debito − total_nota_credito`.
`compra_promedio` es `0` cuando `cantidad_compras_creadas` es `0` (nunca división por cero).

### `GET /informes/compras/data` → 200 (formato DataTables)

Cada fila trae **siempre** todas las columnas, incluidas las opcionales; la visibilidad se resuelve
en el cliente con colvis (FR-014, FR-017).

```json
{
  "draw": 1, "recordsTotal": 32, "recordsFiltered": 32,
  "data": [{
    "id": 12,
    "fecha": "2026-08-04",
    "comprobante": "A 0001-00000123",
    "proveedor": "Distribuidora SRL",
    "producto_servicio": "Pantalon Negro Hombre Slim T32",
    "cantidad": "10.000",
    "precio": 250.00,
    "total_comprobante": 3025.00,

    "vencimiento": "2026-09-03",
    "cuit_dni": "30712345678",
    "tipo": "Responsable Inscripto",
    "tipo_comprobante": "A",
    "punto_venta": "0001",
    "nro_factura": "00000123",
    "codigo": "P024",
    "tipo_producto": "Compra y Venta",
    "costo": 1800.00,
    "subtotal_sin_descuento": 2500.00,
    "descuento_monto": 0.00,
    "subtotal_con_descuento": 2500.00,
    "neto_no_gravado": 0.00,
    "neto_exento": 0.00,
    "neto_gravado": 2500.00,
    "iva_2_5": 0.00, "iva_5": 0.00, "iva_10_5": 0.00,
    "iva_21": 525.00, "iva_27": 0.00,
    "perc_iva": 0.00,
    "perc_iibb": 0.00,
    "otras_percepciones": 0.00,
    "imp_internos": 0.00,
    "total_compra": 3025.00,
    "etiquetas": "Temporada Invierno",
    "afecta_stock": "Si",
    "operacion": "compra"
  }]
}
```

`operacion ∈ {compra, nota_credito, nota_debito}`. Las filas de NC/ND traen sus importes con signo
negativo/positivo según corresponda, calculados **con la misma fórmula** que una compra (FR-016).

---

## 2. Informe de Gastos

| Método | Ruta | Nombre | Devuelve |
|--------|------|--------|----------|
| GET | `/informes/gastos` | `informes.gastos.index` | Vista Blade (shell) |
| GET | `/informes/gastos/data` | `informes.gastos.data` | JSON DataTables server-side |
| GET | `/informes/gastos/stats` | `informes.gastos.stats` | JSON: total + subtotales por grupo |
| GET | `/informes/gastos/exportar` | `informes.gastos.exportar` | `.xlsx` (2 hojas) |
| GET | `/informes/gastos/pdf` | `informes.gastos.pdf` | PDF inline |

### Parámetros

```
fecha_desde        YYYY-MM-DD    (default: primer día del mes actual)
fecha_hasta        YYYY-MM-DD    (default: último día del mes actual)
categoria_id       int[]         (categorías de tipo `gasto`, raíz)
subcategoria_id    int[]
cuenta_tesoreria_id int[]
estado_pago        pendiente|pagado
usuario_id         int[]
```

### `GET /informes/gastos/stats` → 200

Los subtotales se calculan sobre **todo el conjunto filtrado**, no sobre la página visible.

```json
{
  "fecha_desde": "2026-08-01",
  "fecha_hasta": "2026-08-31",
  "gasto_total": 1370.00,
  "grupos": [
    { "categoria": "Impuestos", "subtotal": 870.00,
      "subcategorias": [{ "subcategoria": "IVA", "subtotal": 870.00 }] },
    { "categoria": "Oficina", "subtotal": 500.00,
      "subcategorias": [
        { "subcategoria": "Luz", "subtotal": 300.00 },
        { "subcategoria": "Sin subcategoría", "subtotal": 200.00 }
      ] }
  ]
}
```

Invariante: `gasto_total = Σ grupos[].subtotal = Σ grupos[].subcategorias[].subtotal` (FR-026).

### `GET /informes/gastos/data` → 200

Ordenado por `categoria`, `subcategoria`, `fecha` para que RowGroup pueda agrupar correctamente.

```json
{
  "draw": 1, "recordsTotal": 14, "recordsFiltered": 14,
  "data": [{
    "id": 7,
    "fecha": "2026-08-03",
    "categoria": "Oficina",
    "subcategoria": "Luz",
    "descripcion": "Factura EDESUR agosto",
    "medio_pago": "Caja General",
    "total": 300.00,
    "pendiente": false
  }]
}
```

---

## 3. Cuenta Corriente Proveedores

| Método | Ruta | Nombre | Devuelve |
|--------|------|--------|----------|
| GET | `/informes/cuenta-corriente-proveedores` | `informes.cuenta-corriente-proveedores.index` | Vista Blade (2 tabs) |
| GET | `/informes/cuenta-corriente-proveedores/saldos` | `...saldos.data` | JSON DataTables |
| GET | `/informes/cuenta-corriente-proveedores/movimientos` | `...movimientos.data` | JSON DataTables |
| GET | `/informes/cuenta-corriente-proveedores/proveedor/{proveedor}` | `...proveedor.show` | JSON ficha (sólo lectura) |
| GET | `/informes/cuenta-corriente-proveedores/exportar` | `...exportar` | `.xlsx` (2 hojas) |
| GET | `/informes/cuenta-corriente-proveedores/pdf` | `...pdf` | PDF inline |

### `GET /informes/cuenta-corriente-proveedores?proveedor_id={id}` (deep-link, FR-038)

Precarga el filtro Proveedor y abre directo el tab **Movimientos** — mismo comportamiento que
`?cliente_id=` en el informe de clientes.

### `GET .../saldos` → 200

```json
{
  "draw": 1, "recordsTotal": 4, "recordsFiltered": 4,
  "data": [{
    "proveedor_id": 3,
    "proveedor_nombre": "Distribuidora SRL",
    "a_vencer": 1200.00,
    "vencido_0_30": 0.00,
    "vencido_31_60": 0.00,
    "vencido_61_90": 0.00,
    "vencido_mas_90": 0.00,
    "total": 1175.80
  }]
}
```

Parámetro opcional: `proveedor_id`. Los totales negativos (saldo a favor) **se devuelven**, no se
filtran (FR-031).

### `GET .../movimientos` → 200

Parámetros: `proveedor_id`, `operacion ∈ {compra, pago, nota_credito, nota_debito, saldo_inicial}`,
`fecha_desde`, `fecha_hasta`.

```json
{
  "draw": 1, "recordsTotal": 9, "recordsFiltered": 9,
  "data": [{
    "id": 12,
    "fecha_emision": "2026-08-04",
    "proveedor": "Distribuidora SRL",
    "operacion": "compra",
    "categoria": "Insumos",
    "total_compra": 3025.00,
    "pagado": 1000.00,
    "a_pagar": 2025.00,
    "nro_comprobante": "A 0001-00000123",
    "medio_pago": null,
    "descripcion": null
  }]
}
```

Las claves no aplicables al tipo de fila vienen en `null`, no ausentes.

### `GET .../proveedor/{proveedor}` → 200

```json
{
  "proveedor": "Distribuidora SRL",
  "nombre": "Juan", "apellido": "Pérez",
  "email": "compras@distribuidora.com",
  "telefono": "011-4444-5555", "celular": "11-6666-7777",
  "pagina_web": "https://distribuidora.com",
  "domicilio": "Av. Siempreviva 742",
  "localidad": "Avellaneda", "provincia": "Buenos Aires", "cp": "1870",
  "condicion_iva": "Responsable Inscripto",
  "comprobante_defecto": "Factura A",
  "nota": "Entrega los martes"
}
```

Sólo lectura. **No existe** ningún endpoint `PUT`/`PATCH`/`DELETE` en este informe (FR-037).

---

## 4. Contrato transversal de exportación

- **Excel** (`/exportar`): `.xlsx` con **exactamente dos hojas**:
  1. hoja formateada — respeta agrupaciones y subtotales de la pantalla;
  2. hoja plana — una fila por registro, una columna por campo, sin celdas combinadas ni encabezados
     de sección.
  Nombre de archivo: `Informe de <Nombre> DD-MM-YYYY HHMM Hs.xlsx`. Valores **ya calculados**, sin
  fórmulas (FR-044). En Compras la hoja plana incluye el desglose impositivo completo con
  independencia de la visibilidad de columnas en pantalla (FR-041).
- **PDF** (`/pdf`): `Content-Disposition: inline` para que el `<iframe>` del modal compartido lo
  renderice. Se abre siempre con `window.AppPdf.abrir(url, titulo)`; `window.open` sólo como
  *fallback* si `window.AppPdf` no está definido.
- Ambos respetan **los mismos parámetros de filtro** que `data`/`stats`, y sus totales deben coincidir
  al centavo con los de la pantalla (FR-043).

---

## 5. Errores

| Situación | Respuesta |
|-----------|-----------|
| Sin permiso `informes.ver` | 403 |
| Rango con `fecha_desde > fecha_hasta` | 422 con `{ "message": "..." }`, mostrado por Toastr |
| Proveedor inexistente en la ficha | 404 |
| Período sin datos | 200 con `data: []` y KPIs en cero — **no** es un error |
