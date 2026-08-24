# Data Model: Fidelidad del Informe de Ventas (spec 076)

**Fecha**: 2026-08-24

**Cambio de esquema: ninguno.** Sin tablas nuevas, sin columnas nuevas, sin migraciones. Todo lo que
el informe necesita ya está guardado; lo que falta es proyectarlo y, en un caso, repartirlo.

---

## 1. Entidades que se leen (ninguna se modifica)

| Entidad | Qué aporta | Columnas del export |
|---|---|---|
| `ventas` | cabecera del comprobante | Id, Emisión, Vencimiento, Categoría, Vendedor, Lista de Precios, Nota para el Cliente, Nota Interna, Total Venta |
| `venta_items` | una fila por línea | Cantidad, Precio Unitario, Subtotal sin/con Descuento, Descuento en $, y el desglose de IVA derivado de `iva_pct` |
| `notas_credito_debito` + sus ítems | las filas negativas y positivas de nota | mismas columnas, con el signo de la nota |
| `productos` | catálogo | Producto/Servicio, Código, Tipo (de producto), Costo Total Actual |
| `clientes` | cliente | Cliente, CUIT / DNI |
| `proveedores`, `tipos_producto`, `categorias`, `vendedores`, `listas_precio`, `etiquetas` | dimensiones | Proveedor, Tipo, Categoría, Vendedor, Lista de Precios, Etiquetas |
| `comprobantes_fiscales` | estado en ARCA | ARCA, Punto de Venta, N° Factura |
| `venta_conceptos` | percepciones, impuestos internos e intereses | Perc. IVA, Perc. IIBB, Imp. Internos — **prorrateados por línea** |

---

## 2. La única regla de cálculo nueva: el importe por línea

```
importe_linea = subtotal_neto_de_la_linea
              + IVA_de_la_linea
              + conceptos_extra_del_comprobante × (subtotal_neto_de_la_linea / subtotal_neto_del_comprobante)
```

Con dos propiedades que la definen y que deben tener test:

1. **Cierra exacto**: la suma de `importe_linea` de todas las líneas de un comprobante es igual al
   total de ese comprobante, al centavo. El residuo del redondeo lo absorbe la última línea.
2. **El signo lo aporta la línea**: una nota de crédito da importes negativos sin ninguna rama por
   tipo de comprobante, igual que el resto de las columnas del informe.

`venta_items.subtotal` **ya viene neto de IVA y con los dos descuentos aplicados** (el de línea y el
general), así que el primer término no requiere ningún cálculo adicional. Lo único que se agrega
respecto de lo que el motor ya hace es el tercer término.

### Caso especial: nota migrada sin detalle de ítems

Una nota importada de Contagram sin renglones aporta **una sola fila** con cantidades en cero pero
con su monto, que es lo que alimenta el KPI. Para esa fila el importe de línea **es** el monto de la
nota: es el único caso en que "importe de línea" e "importe del comprobante" coinciden, y es
correcto.

---

## 3. Imputación del desglose impositivo (derivada, no guardada)

`venta_items.iva_pct` es texto y puede ser un porcentaje o una condición. De ahí sale todo:

| `iva_pct` | Columna de neto | Columna de IVA | Columnas Exento / No Gravado |
|---|---|---|---|
| `'exento'` | Importe Neto Exento | — | Exento |
| `'no_gravado'` | Importe Neto No Gravado | — | No Gravado |
| `'2.5'`, `'5'`, `'10.5'`, `'21'`, `'27'` | Importe Neto Gravado | la columna de esa alícuota | — |
| nulo o vacío | Importe Neto No Gravado | — | No Gravado |

**Invariante**: cada línea imputa a **exactamente una** columna de neto y a **como mucho una** de
alícuota. Verificado contra el archivo real de Contagram.

---

## 4. Lectura del comprobante fiscal — el punto delicado

`comprobantes_fiscales` es **polimórfica** (`comprobantable_type` / `comprobantable_id`), tiene
`deleted_at`, y **una venta puede tener más de una fila**: un rechazo de ARCA y su reintento
aprobado conviven.

Un `LEFT JOIN` directo duplicaría la línea de venta y **rompería todos los totales del informe**.
Se resuelve con una **subconsulta que devuelve una sola fila** —el comprobante vigente—, el mismo
patrón que la proyección ya usa para las etiquetas, que también son muchos-a-muchos.

Valores de salida, tomados del export real:

| Situación | ARCA | Punto de Venta | N° Factura |
|---|---|---|---|
| Comprobante aprobado con CAE | `Aprobado` | número del punto de venta | número de factura |
| Emitido y sin respuesta / rechazado | `Sin Enviar` | número | número |
| Sin comprobante fiscal | `---` | `-` | `-` |

---

## 5. Entidades sin cambios

Ninguna entidad se crea, modifica o borra. La feature es de **sólo lectura** sobre el modelo
existente: no toca stock, ni tesorería, ni ARCA, ni costos congelados.
