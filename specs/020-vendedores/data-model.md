# Data Model: Vendedores como catálogo propio

**Spec**: [../spec.md](../spec.md) · **Plan**: [../plan.md](../plan.md) · **Research**: [../research.md](../research.md)

Una tabla nueva, dos alter con retarget de FK + migración de datos, dos alter simples (default de
integraciones).

## `vendedores` (NUEVA)

| Campo | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `nombre` | string(255), **unique** | Único requisito de negocio del catálogo (FR-001/FR-002). |
| `created_at` / `updated_at` | timestamps | |

Sin `activo`, sin `tipo`, sin `categoria_padre_id`, sin `es_sistema` — a diferencia de `Categoria`
(research.md R1). Sin soft delete: un vendedor sin uso se borra físicamente (FR-006); uno en uso no
se puede borrar en absoluto (bloqueo de integridad, no hay estado "inactivo" intermedio).

## `ventas` (ALTER — retarget de FK)

| Campo | Antes | Después | Notas |
|---|---|---|---|
| `vendedor_id` | FK → `users`, nullable | FK → `vendedores`, nullable, `restrictOnDelete` | Migración de datos obligatoria antes del retarget (ver abajo). Deja de autocompletarse con el usuario logueado (FR-009); pasa a ser un valor explícito del formulario. |

## `presupuestos` (ALTER — retarget de FK)

Idéntico a `ventas.vendedor_id` (mismo before/after, mismo comentario).

## `tn_configuracion` (ALTER — nuevo default, mismo patrón que `categoria_venta_id`)

| Campo | Tipo | Notas |
|---|---|---|
| `vendedor_id` | FK → `vendedores`, nullable, `nullOnDelete`... ver nota | FR-010. Análogo a `categoria_venta_id`. **Nota de integridad**: a diferencia de `categoria_venta_id` (que en la práctica nunca bloqueó el borrado porque Categoría de sistema no se borra), acá si un vendedor está configurado como default de una integración, `VendedorController::destroy()` debe rechazar el borrado (FR-006, Edge Cases) — se resuelve con `restrictOnDelete` en vez de `nullOnDelete`, igual que en `ventas`/`presupuestos`, para que la regla de "está en uso" sea una sola garantía de base para las cuatro tablas que referencian `vendedores`. |

## `ml_configuracion` (ALTER — idéntico a `tn_configuracion`)

| Campo | Tipo | Notas |
|---|---|---|
| `vendedor_id` | FK → `vendedores`, nullable, `restrictOnDelete` | Idéntico razonamiento que Tiendanube (research.md R5), independiente entre integraciones. |

## Migración de datos históricos (FR-008, SC-002)

Pasos, dentro de la misma migración pero en dos fases de distinta naturaleza (research.md R2,
corregido en análisis): los pasos 1-4 (datos) van envueltos en un `DB::transaction()` real y
commitean antes de tocar el esquema; los pasos 5-6 (DDL) no son atómicos con los anteriores en
MySQL/MariaDB, pero para ese momento los datos ya quedaron correctos y confirmados:

1. `CREATE TABLE vendedores (...)`.
2. `SELECT DISTINCT vendedor_id FROM ventas WHERE vendedor_id IS NOT NULL` **UNION**
   `SELECT DISTINCT vendedor_id FROM presupuestos WHERE vendedor_id IS NOT NULL` → conjunto de
   `user_id` a migrar.
3. Por cada `user_id` del conjunto: `INSERT INTO vendedores (nombre, created_at, updated_at) VALUES (users.name, now(), now())`, guardando el mapeo `user_id → vendedores.id` recién creado (en memoria, dentro de la misma migración).
4. `UPDATE ventas SET vendedor_id = <mapeado> WHERE vendedor_id = <user_id>` por cada entrada del
   mapeo (idem `presupuestos`).
5. Dropear la FK `ventas.vendedor_id → users` / `presupuestos.vendedor_id → users`.
6. Crear la FK nueva `ventas.vendedor_id → vendedores` (`nullable`, `restrictOnDelete`) / idem
   `presupuestos`.

**Edge case cubierto (research.md R3)**: si dos `user_id` distintos comparten `users.name`, el paso 3
crea dos vendedores con el mismo `nombre` — la unicidad de `vendedores.nombre` se exige sólo para
altas *nuevas* vía `VendedorController::store()`/`update()` (constraint de aplicación con
`Rule::unique`), no como constraint de base de datos, precisamente para no romper esta migración si
existiera ese caso. Se documenta como conocido y aceptado (spec, Assumptions).

## Relaciones (resumen)

- `Vendedor` 1—N `Venta` (opcional).
- `Vendedor` 1—N `Presupuesto` (opcional).
- `Vendedor` 1—1 `TiendanubeConfiguracion` (opcional, "vendedor por defecto").
- `Vendedor` 1—1 `MercadoLibreConfiguracion` (opcional, "vendedor por defecto").
- Ninguna relación inversa desde `Vendedor` hacia sus usos es necesaria para la spec (no hay pantalla
  que liste "ventas de este vendedor" fuera de los filtros ya existentes en Ventas/Presupuestos).
