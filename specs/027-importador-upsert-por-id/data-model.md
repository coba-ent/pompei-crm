# Data Model — Importador de Datos: Actualizar por Id (Upsert)

Sin tablas ni columnas nuevas: `id` ya existe como primary key de `clientes`, `proveedores` y `productos`. Esta
feature sólo agrega un campo destino nuevo al diccionario `DefinicionCamposImportables` y una capacidad de
resolución alta/actualización por fila en `ImportadorFilas` (research.md §1-3).

## Diccionario de campos importables — campo nuevo por entidad

| Entidad | Campo destino (etiqueta) | Columna | Marca | Persistible directamente |
|---|---|---|---|---|
| Clientes | Id | `id` | `'id' => true` | No — resuelve el registro a actualizar, se descarta antes de `update()`/`create()` |
| Proveedores | Id | `id` | `'id' => true` | Ídem |
| Productos | Id | `id` | `'id' => true` | Ídem |

## Flujo por fila (ampliado)

```text
mapearFila() → $datos (incluye 'id' crudo si la columna Id está mapeada y la celda no está vacía)
  │
  ├─ 'id' ausente en $datos (no mapeado, o celda vacía)  → ALTA (flujo spec 006/026, sin cambios)
  │
  ├─ 'id' presente pero no numérico                       → FILA FALLIDA: "Id \"{valor}\" no es un id válido"
  │
  └─ 'id' presente y numérico
        │
        ├─ no matchea ningún registro existente            → FILA FALLIDA: "Id {valor} no encontrado"
        │
        └─ matchea un registro existente                   → ACTUALIZACIÓN
              1. quitar 'id' de $datos
              2. validar $datos contra reglasActualizacion($id) (obligatorios relajados, unicidad con
                 ignore($id) — FR-006, FR-011)
              3. si válida: $registro->update($datos) (parcial — sólo pisa las claves presentes)
              4. si inválida: FILA FALLIDA (mismo mecanismo de motivo ya vigente)
```

## Reglas de validación — actualización (`Reglas*Importacion::reglasActualizacion()`)

| Regla | Origen | Detalle |
|---|---|---|
| `nombre`/`tipo` (Producto) pasan de `required` a `nullable` en actualización | FR-006 | No se exige lo que ya existe en el registro a actualizar |
| Reglas de unicidad (`cuit` Cliente/Proveedor, `codigo`/`variantes.*.sku` Producto) evaluadas con `ignore($id)`/`SkuUnico($id, $id)` | FR-011 | Ya soportado por `reglasCliente($id)`/`reglasProveedor($id)`/`reglasProducto($id)` — sólo hace falta pasar el `$id` de la fila en vez de `null` |
| El resto de las reglas (formato, longitud, `exists` de FKs no resueltos ya como `fk`) | Sin cambios | Idénticas a las de alta |

## Estado transitorio del asistente

Sin cambios respecto a spec 006/026 — el archivo subido, el mapeo de columnas y el resultado de la importación
siguen siendo estado transitorio no persistido.
