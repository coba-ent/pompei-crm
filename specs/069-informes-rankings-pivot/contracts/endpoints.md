# Phase 1 — Contrato de endpoints: Informes Tanda 3

Todas las rutas van dentro del grupo autenticado y bajo `middleware('permiso:informes.ver')`, el
mismo permiso de las tandas 1 y 2 (FR-042: sin permiso aparte para vistas guardadas).

## Parámetros comunes

Los mismos `desde`/`hasta` (o `fecha_desde`/`fecha_hasta`) y filtros del panel de cada informe
(FR-040): el dataset del pivot se pide con **los mismos parámetros** que ya usan `data` y `stats` de
ese informe. Rango inválido → `422` con `{ "message": "..." }`.

---

## Informe de Ventas

### `GET /informes/ventas/pivot/dataset` → `informes.ventas.pivot.dataset`

Mismos filtros que `informes.ventas.data`, sin paginación. Devuelve el dataset proyectado
(data-model.md §Dataset).

```json
{
  "filas": [
    { "fecha_emision": "2026-08-03", "cliente": "Juan Pérez", "categoria": "Online",
      "vendedor": "Sin vendedor", "tipo_comprobante": "B", "producto": "Camisa",
      "tipo_producto": "Indumentaria", "proveedor": "Textil SRL", "cantidad": 1,
      "descuento_pct": 0, "etiquetas": [], "total_venta": 447.70,
      "total_venta_sin_impuestos": 370.00, "comprobante_id": 128 }
  ],
  "dimensiones": ["fecha_emision", "cliente", "categoria", "vendedor", "tipo_comprobante",
    "producto", "tipo_producto", "proveedor", "cantidad", "descuento_pct", "etiquetas"],
  "datos": ["total_venta", "total_venta_sin_impuestos", "cantidad_productos", "cantidad_ventas"]
}
```

`422` si el conjunto filtrado supera **50.000 filas** (research R2):
`{ "message": "El período tiene demasiados movimientos para armar el cruce (más de 50.000). Acotá el rango o los filtros." }`

### `GET /informes/ventas/pivot/vistas` → `informes.ventas.pivot.vistas.index`

Lista las vistas guardadas del Informe de Ventas (`informe = 'ventas'`), ordenadas por
`created_at`: `[{ id, descripcion, config, creado_por }]`.

### `POST /informes/ventas/pivot/vistas` → `informes.ventas.pivot.vistas.store`

Body: `{ "descripcion": "...", "config": { filas, columnas, dato, accion, exclusiones } }`.

Validaciones servidor (FR-037, data-model invariante 7):
- `descripcion` requerida, no vacía.
- `accion` compatible con `dato` (si `dato` es un conteo, `accion` debe ser `suma`).
- Si ya existe una `descripcion` igual en `informe = 'ventas'`, se **acepta** pero la respuesta
  incluye `"aviso": "Ya existe una vista con ese nombre."` para que el front lo muestre por Toastr
  sin bloquear el guardado.

Respuesta `201`: la vista creada, con su `id`.

### `DELETE /informes/ventas/pivot/vistas/{vista}` → `informes.ventas.pivot.vistas.destroy`

`404` si la vista no pertenece a `informe = 'ventas'` (aunque exista con ese id en Compras).
`204` si se borró.

### `POST /informes/ventas/pivot/exportar` → `informes.ventas.pivot.exportar`

Body: la **matriz ya calculada** por el cliente (research R3):

```json
{
  "titulo": "Ranking de Clientes",
  "encabezados_fila": ["Clientes"],
  "encabezados_columna": ["2026 › Ago"],
  "filas": [
    { "etiqueta": ["Juan Pérez"], "valores": [1893.65], "total": 1893.65 }
  ],
  "totales_columna": [1893.65],
  "total_general": 1893.65
}
```

Descarga `<titulo> <d-m-Y Hi> Hs.xlsx` con dos hojas (patrón `HojaInforme` del módulo):
1. **legible**: título, encabezados de fila/columna anidados, celdas, fila y columna de Totales.
2. **plana**: una fila por combinación (fila × columna) con su valor, para reprocesar.

`422` si el body no trae `filas` o excede un tope de tamaño razonable (protección contra un POST
manual fuera del flujo normal de la UI).

---

## Informe de Compras

### `GET /informes/compras/pivot/dataset` → `informes.compras.pivot.dataset`

Análogo al de Ventas, sobre `ComprasPivotDataset` (sin `vendedor` — research R9).

### `GET/POST/DELETE /informes/compras/pivot/vistas[/{vista}]` → `informes.compras.pivot.vistas.*`

Mismo contrato que en Ventas, con `informe = 'compras'`.

### `POST /informes/compras/pivot/exportar` → `informes.compras.pivot.exportar`

Mismo contrato que en Ventas.

---

## Contrato de UI

- Cada informe gana una barra de pestañas: detalle (activa por defecto) · Rankings (desplegable con
  las vistas de FR-003) · Arma tu Informe (desplegable, con "Crear Informe" + una entrada por vista
  guardada) — más una pestaña directa por cada vista guardada, a continuación de "Arma tu Informe"
  (FR-001).
- Cambiar de pestaña actualiza la URL vía `history.pushState` sin recargar (FR-002, FR-004,
  research R6): `/informes/ventas/ranking/{dimension}` y `/informes/ventas/vista/{vista}`.
- El pivot se monta con PivotTable.js, `renderers: { Table: ... }` únicamente (research R1); si
  `rendererName` llegara a pedir otro renderer por manipulación externa, el wrapper lo ignora y
  fuerza `Table` (FR-020/021 como propiedad estructural, no sólo de UI).
- Selector "Mostrar Como" no se renderiza (FR-021): sólo "Dato" y "Accion".
- "Accion" se recalcula en el cliente cada vez que cambia "Dato" (FR-014); si la Accion vigente deja
  de aplicar, cae a "Suma".
- Superadas 1.000 columnas, no se renderiza: Toastr con el aviso de FR-019b.
- El modal "Guardar Informe" es un modal Bootstrap del template, enviado por AJAX (regla #2/#3 de
  CLAUDE.md), con el único campo "Descripción".
