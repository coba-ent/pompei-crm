# Data Model — Proveedores + Informe de Stock (Fase 1)

Derivado de `spec.md`, consistente con `docs/modelo_datos.md` §2 y las decisiones de `research.md`.
Motor SQL (MySQL 8), migraciones de Laravel 12, nombres en español snake_case, single-tenant (sin
`empresa_id`).

Tablas nuevas de esta feature: `proveedores`, `proveedor_contactos`. Reutilizadas/reincorporadas sin
migración nueva: `productos.proveedor_id` (columna ya existe, ver research §1). El Informe de Stock
**no crea tabla propia**: es una proyección de sólo lectura sobre `movimientos_stock`, `stocks`,
`productos`, `proveedores` y `usuarios`.

---

## `proveedores`

Espejo de `clientes`, con las diferencias documentadas en spec.md (FR-002).

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| nombre | string | **obligatorio** — "Proveedor" (empresa o nombre y apellido). Único campo requerido (FR-001) |
| nombre_pila | string, nullable | "Nombre" (de pila) |
| apellido | string, nullable | |
| pagina_web | string, nullable | (sin `apodo_ml` — FR-002, exclusivo de Cliente) |
| email, telefono, telefono_celular | string, nullable | contacto comercial |
| domicilio, localidad, provincia, cp | string, nullable | domicilio comercial |
| nota | text, nullable | nota general (no confundir con `nota_interna`, ver bloque Compras) |
| **— Bloque de facturación —** | | (idéntico a `clientes`) |
| razon_social | string, nullable | |
| tipo_documento | string, nullable | 'CUIT' (default) / 'CUIL' / 'DNI' / 'Pasaporte' / 'CDI' |
| cuit | string, nullable, único (ignora NULL) | mismo validador `CuitValido` que Cliente (FR-003) |
| condicion_iva_id | FK → condiciones_iva, nullable | |
| tipo_comprobante_defecto | string, nullable | A/B/C/E |
| domicilio_fiscal, localidad_fiscal, provincia_fiscal, cp_fiscal | string, nullable | |
| telefono_fiscal, telefono_celular_fiscal | string, nullable | |
| **— Compras (equivalente al bloque "Ventas" de Cliente) —** | | |
| categoria_id | FK → categorias (tipo=compra), nullable | "Categoría Compras" (FR-002) |
| nota_interna | text, nullable | reemplaza "Nota para el Cliente" (FR-002). Sin `lista_precio_id` (FR-002) |
| saldo_inicial | decimal | default 0 |
| saldo_inicial_fecha | date, nullable | apertura de cuenta corriente (sin uso funcional hoy, igual que en Cliente) |
| campos_personalizados | json, nullable | mismo formato que `clientes.campos_personalizados` |
| activo | boolean | default true (baja lógica) |
| timestamps | | |

Índices: `unique(cuit)` (ignora NULL vía `whereNotNull` en la regla de unicidad), `index(nombre)`,
`index(activo)`.

Métodos de dominio (modelo `Proveedor`, espejo de `Cliente`):
- `tieneOperaciones(): bool` → hoy: existe algún `Producto` con `proveedor_id` apuntando a este
  proveedor (FR-006). Costura para Compras futuras (igual patrón que `Cliente::tieneOperaciones()`).
- Relaciones: `contactos()` hasMany (`proveedor_contactos`), `condicionIva()` belongsTo,
  `categoria()` belongsTo, `productos()` hasMany (inversa de `Producto::proveedor()`).

## `proveedor_contactos`

Espejo exacto de `cliente_contactos`.

| Campo | Tipo | Notas |
|---|---|---|
| id | bigint PK | |
| proveedor_id | FK → proveedores | cascade on delete |
| nombre | string | |
| apellido | string, nullable | |
| telefono | string, nullable | |
| telefono_celular | string, nullable | |
| email | string, nullable | |
| enviar_mails | boolean | default false |
| timestamps | | |

## `productos.proveedor_id` (reincorporación, sin migración nueva)

Columna ya existente en el esquema (`unsignedBigInteger`, nullable, indexada) — ver research.md §1
para el detalle de por qué nunca tuvo FK real a nivel de base. Esta feature:

1. **Agrega** una migración chica (`xxxx_add_foreign_key_proveedor_id_to_productos.php`) que corre
   **después** de crear `proveedores`, agregando la FK real (`ON DELETE SET NULL`) como defensa en
   profundidad — no como único mecanismo de la regla de negocio.
2. **Reincorpora** en el modelo `Producto`: la relación `proveedor(): BelongsTo` y el campo
   `proveedor_id` de vuelta en `$fillable` (ambos habían sido removidos junto con el módulo
   Proveedores, sin tocar la columna de la tabla).

La regla de negocio real ("no eliminar proveedor con productos asociados", FR-006) se aplica **en
el controller** (`ProveedorController::destroy()`, vía `tieneOperaciones()`), igual que en Cliente y
Producto — la FK de base es sólo para que un `DELETE` directo por SQL no deje una referencia
colgando (research.md §1).

## Informe de Stock (proyección, sin tabla nueva)

No es una entidad persistida — es una consulta sobre datos ya existentes:

- **Base**: `movimientos_stock` (id, producto_id, variante_id, deposito_id, tipo, cantidad,
  descripcion, fecha, usuario_id — sin cambios de esquema).
- **Saldo corrido ("Stock Saldo")**: columna calculada por fila —
  `SUM(cantidad) OVER (PARTITION BY producto_id, variante_id, deposito_id ORDER BY fecha, id)` —
  sobre el histórico **completo** de `movimientos_stock`, no sobre el subconjunto filtrado
  (research.md §2). Se expone como columna adicional de la DataTable, no se persiste.
- **KPIs** (Unidades en Stock, Costo Total, Valor Venta Total): misma fórmula que
  `ProductoController::estadisticas()` (cantidad en `stocks` × `costo`/`precio_venta`), recalculados
  sobre el conjunto de productos que matchean los filtros vigentes de la pantalla.
- **Filtros**: Usuario (`movimientos_stock.usuario_id` → `usuarios`), Operación (`tipo` — hoy sólo
  `ajuste`/`transferencia`, ver FR-013), Proveedor (`productos.proveedor_id` → `proveedores`), Tipo
  de Producto (`productos.tipo_producto_id`), Productos (`producto_id` puntual), Estado del
  Producto/Servicio (`productos.activo`), rango de fechas (`movimientos_stock.fecha`).

---

## Reglas de validación (desde Requirements)

| Regla | Origen | Dónde se aplica |
|---|---|---|
| `nombre` requerido | FR-001 | Store/UpdateProveedorRequest |
| CUIT válido sólo si está completo (vacío = válido) | FR-003 | `ReglasProveedor` (clon de `ReglasCliente`), regla `CuitValido` |
| No eliminar proveedor con productos asociados | FR-006 | `ProveedorController::destroy()` vía `tieneOperaciones()` (409) |
| `proveedor_id` de Producto: nullable, exists en `proveedores` | FR-007 | Store/UpdateProductoRequest (`ReglasProducto`, ya tenía el esqueleto condicional) |
| Filtro "Operación" del informe limitado a tipos existentes | FR-013 | `InformeStockController` (opciones fijas, no derivadas de un enum más amplio) |
| Informe de Stock de sólo lectura (sin edición/eliminación de movimientos desde ahí) | FR-014 | No se agregan rutas de escritura en `InformeStockController` |

## Consistencia con docs de dominio

`docs/modelo_datos.md` ya documenta `proveedores`/`proveedor_contactos` en su sección "Tablas
descartadas (pendientes de re-relevamiento)" — este spec las reincorpora tal cual estaban descriptas
ahí, sin campos nuevos respecto de lo ya relevado, salvo la precisión de que `productos.proveedor_id`
no tiene FK real todavía (research.md §1) y la nota sobre el "Stock Saldo" calculado (ya agregada en
`modelo_datos.md` el 24/07/2026 cuando se documentó `precios_producto`/columnas dinámicas — este
spec agrega una entrada equivalente para el Informe de Stock en el mismo cambio de implementación).
**Acción pendiente durante `/speckit-implement`**: mover la sección `proveedores`/`proveedor_contactos`
de "Tablas descartadas" de vuelta a la sección activa de `modelo_datos.md`, y documentar el Informe
de Stock ahí (Principio I de la constitución: docs y código no divergen).
