# Contrato de endpoints — Informe "Información para tu Contador" (spec 077)

**Spec**: [../spec.md](../spec.md) · **Data model**: [../data-model.md](../data-model.md)

Todas las rutas van bajo el middleware `permiso:informes.ver` (FR-003) y son de **sólo lectura**: no hay
`POST` que escriba, `PUT`, `PATCH` ni `DELETE` en este bloque (FR-037).

> **El endpoint de datos usa `POST` a propósito** — es la lección del incidente 414 del 24/08/2026 en
> producción (research, Decisión 9). `POST` acá **no escribe nada**: transporta filtros. La ruta se
> excluye de cualquier expectativa de idempotencia de escritura, no de la de sólo lectura.

---

## 1. Pantalla

```
GET  informes/contador                      → informes.contador.index
```

Renderiza la pantalla con las dos pestañas. No consulta datos del libro: el período arranca sin elegir
(FR-006), así que la tabla se pinta vacía con el mensaje "Utilizá los filtros y generá tu informe a
medida".

**Payload a la vista**: catálogos de los filtros (tipos de comprobante, condiciones de IVA, cuentas de
tesorería, provincias) y el rango de años con datos para el `<select>` de Año.

---

## 2. Datos de la tabla

```
POST informes/contador/ventas/data          → informes.contador.ventas.data
POST informes/contador/compras/data         → informes.contador.compras.data
```

**Request** (además de los parámetros propios de DataTables server-side):

| Campo | Tipo | Requerido | Notas |
|---|---|---|---|
| `mes` | int 1–12 | sí | |
| `anio` | int | sí | |
| `arca` | bool | no | sólo Ventas; default `true` |
| `manuales` | bool | no | sólo Ventas; default `false` |
| `id` | int | no | |
| `tipo_comprobante[]` | array | no | |
| `nro_comprobante` | string | no | parcial |
| `cliente_id[]` / `proveedor_id[]` | array | no | según pestaña |
| `cuit` | string | no | parcial |
| `condicion_iva_id[]` | array | no | |
| `cuenta_tesoreria_id` | int | no | Medio de Cobro / de Pago |
| `provincia` | string | no | nombre |

**Response**: formato estándar de DataTables. Cada fila trae las 19 columnas de
[data-model.md §4](../data-model.md), con `imp_municipales` siempre en `0`.

**Sin período**: si falta `mes` o `anio` → **422** con
`{"message": "Elegí un mes y un año para generar el informe."}` (FR-007). El front lo muestra por Toastr.

**Orden por defecto**: `fecha_emision` ascendente, luego `id` ascendente (FR-022a).

---

## 3. Totales

```
POST informes/contador/ventas/stats         → informes.contador.ventas.stats
POST informes/contador/compras/stats        → informes.contador.compras.stats
```

Mismos filtros que `data`. **Response**:

```json
{
  "no_gravados_exentos": 0.00,
  "gravados": 21580897.56,
  "iva_total": 4531988.49,
  "perc_iva_iibb_total": 3217112.83,
  "total_facturado": 29329998.88
}
```

**Invariante del contrato (FR-011)**:

```
total_facturado === no_gravados_exentos + gravados + iva_total + perc_iva_iibb_total
```

exacto, sin tolerancia. Calculado en PHP sobre los cuatro componentes ya redondeados, nunca como un
quinto agregado en SQL. `imp_internos` e `imp_municipales` **no** aparecen acá (FR-011a).

Se calcula sobre el conjunto filtrado completo, no sobre la página visible (FR-012).

---

## 4. Exportación

```
GET  informes/contador/ventas/exportar      → informes.contador.ventas.exportar
GET  informes/contador/compras/exportar     → informes.contador.compras.exportar
```

Va por `GET` porque el navegador tiene que recibir el archivo. Manda **sólo los filtros**, no el
descriptor de columnas de DataTables — que es lo que inflaba la URL en el informe de Compras.

**Response**: `.xlsx` con una hoja: bloque de totales del período arriba, detalle completo debajo con las
19 columnas **siempre presentes**, independientemente de las ocultas en pantalla (FR-034).

**Nombre**: `Libro IVA Ventas 08-2026.xlsx` / `Libro IVA Compras 08-2026.xlsx` (FR-035).

**Sin período**: **422** con el mismo mensaje que `data` (FR-036).

---

## 5. Reglas transversales

- **Todo por AJAX**: cambiar pestaña, período, filtros o columnas visibles nunca recarga la página
  (FR-004). La descarga del Excel se dispara sobre la URL sin navegar.
- **Errores en JSON**: todo error de validación responde `422` con `{"message": "..."}` para que el front
  lo muestre por Toastr (`CLAUDE.md` #3). Sin mensajes flash ni recargas.
- **Estado por pestaña**: cada pestaña mantiene su propio período, filtros y columnas visibles; el
  cliente no comparte estado entre ellas (FR-030).
